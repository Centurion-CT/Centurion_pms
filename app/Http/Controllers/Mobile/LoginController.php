<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureHostAppOperator;
use App\Http\Resources\Mobile\OperatorResource;
use App\Http\Resources\Mobile\TenantResource;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Email + password sign-in from the mobile app. Lives on the
 * CENTRAL domain (not tenant-scoped) so the client can hit a
 * stable URL for first-run without needing to know the tenant
 * subdomain yet.
 *
 * Flow:
 * 1. POST {email, password}
 *    - No match / wrong password → 401 (same message for both, so
 *      "which emails exist?" isn't discoverable pre-auth).
 * 2. Password matches → look up every tenant the identity belongs
 *    to via IdentityAccount + IdentityOperatorLink.
 *    - Zero linked tenants → 403 (rare — orphaned identity).
 *    - Exactly one linked tenant → complete sign-in immediately.
 *    - Multiple → return 300 Multiple Choices with a tenants list
 *      for the client to render a picker. Client re-POSTs with
 *      tenant_id to complete.
 * 3. Sign-in completes: creates a MobileDevice row on the chosen
 *    tenant + issues a Sanctum token named `mobile-device-{id}`
 *    (matches the pair flow's naming so EnsureMobileDeviceActive
 *    middleware works identically).
 *
 * Rate-limited by ip+email to make credential stuffing expensive
 * without punishing an operator who fumbles their password.
 */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 6;

    private const DECAY_SECONDS = 60;

    public function login(Request $request, RateLimiter $limiter): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'password' => ['required', 'string', 'max:120'],
            // Optional on the first call — required on the second when
            // the client is completing a multi-tenant pick.
            'tenant_id' => ['nullable', 'integer'],
            'platform' => ['required', Rule::in(['ios', 'android', 'web', 'desktop'])],
            'device_name' => ['required', 'string', 'max:120'],
            'app_version' => ['required', 'string', 'max:32'],
            'push_token' => ['nullable', 'string', 'max:500'],
        ]);

        $email = IdentityAccount::normaliseEmail($data['email']);
        $throttleKey = 'mobile-login:'.sha1($request->ip().'|'.$email);

        if ($limiter->tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $wait = $limiter->availableIn($throttleKey);

            return response()->json([
                'error' => 'too_many_attempts',
                'detail' => "Too many attempts. Try again in {$wait} seconds.",
            ], 429);
        }

        $identity = IdentityAccount::findForLogin($email);
        if ($identity === null || ! Hash::check($data['password'], (string) $identity->password)) {
            $limiter->hit($throttleKey, self::DECAY_SECONDS);

            return response()->json([
                'error' => 'invalid_credentials',
            ], 401);
        }

        // Successful password check clears the throttle (an operator
        // who mis-typed a few times still gets their sign-in through
        // once they land the right one).
        $limiter->clear($throttleKey);

        // Look up every tenant this identity is linked to. Bypass the
        // TenantScope on the Operator side because central-domain
        // flows have no current tenant.
        $links = $identity->operatorLinks()
            ->with(['tenant', 'operator' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->get();

        if ($links->isEmpty()) {
            return response()->json([
                'error' => 'no_tenants',
                'detail' => 'This account has no property access. Ask a manager to invite you.',
            ], 403);
        }

        // Suspended, revoked or not-yet-activated seats can't sign in on
        // the web, so they can't mint a mobile token either.
        $links = $links->filter(fn ($link) => $link->operator?->canSignIn() === true)->values();
        if ($links->isEmpty()) {
            return response()->json([
                'error' => 'access_suspended',
                'detail' => 'Your access is suspended or not activated yet. Ask your manager.',
            ], 403);
        }

        // Centurion Desk: only host roles may set up a desk, so the picker
        // only offers properties where this person can do that.
        if (self::appFromRequest($request) === MobileDevice::APP_DESK) {
            $links = self::deskLinks($links);
            if ($links->isEmpty()) {
                return self::deskRoleResponse();
            }
        }

        // Multi-tenant path: if no tenant_id was passed, hand back
        // the picker payload. 300 Multiple Choices is the RFC-correct
        // status for "the request is ambiguous — pick one of these".
        if ($links->count() > 1 && empty($data['tenant_id'])) {
            return $this->pickTenantResponse($links);
        }

        // Single-tenant path or client is completing a pick.
        $link = ! empty($data['tenant_id'])
            ? $links->firstWhere('tenant_id', (int) $data['tenant_id'])
            : $links->first();

        if ($link === null) {
            return response()->json([
                'error' => 'tenant_not_accessible',
                'detail' => 'That property isn\'t on your account.',
            ], 403);
        }

        return $this->completeSignIn($request, $link, $data);
    }

    /**
     * Which app is signing in, from the `X-Keverna-App` header
     * (host | staff | desk). Anything else (or no header) stays null.
     */
    public static function appFromRequest(Request $request): ?string
    {
        $app = strtolower(trim((string) $request->header('X-Keverna-App', '')));

        return in_array($app, [MobileDevice::APP_HOST, MobileDevice::APP_STAFF, MobileDevice::APP_DESK], true) ? $app : null;
    }

    /**
     * Links whose seat may set up a Centurion Desk (manager, front desk,
     * reservation officer).
     *
     * @param  Collection<int, IdentityOperatorLink>  $links
     * @return Collection<int, IdentityOperatorLink>
     */
    private static function deskLinks(Collection $links): Collection
    {
        return $links
            ->filter(fn ($link) => in_array($link->operator?->role, EnsureHostAppOperator::ALLOWED_ROLES, true))
            ->values();
    }

    private static function deskRoleResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'role_not_supported',
            'detail' => 'Only a manager or front desk officer can set up this desk.',
        ], 403);
    }

    /**
     * 300 Multiple Choices picker payload.
     *
     * @param  Collection<int, IdentityOperatorLink>  $links
     */
    private function pickTenantResponse(Collection $links): JsonResponse
    {
        return response()->json([
            'status' => 'pick_tenant',
            'tenants' => $links
                ->sortByDesc(fn ($l) => $l->last_login_at?->timestamp ?? 0)
                ->map(fn ($l) => [
                    'id' => $l->tenant_id,
                    'slug' => $l->tenant?->slug,
                    'name' => $l->tenant?->name,
                    'role' => $l->operator?->role?->value,
                    'last_login_at' => $l->last_login_at?->toIso8601String(),
                ])
                ->values(),
        ], 300);
    }

    /**
     * Shared tail of the mobile sign-in: mint the MobileDevice row + `mobile-device-{id}`
     * Sanctum token on the chosen tenant and return the standard
     * {token, operator, tenant, device} payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function completeSignIn(Request $request, IdentityOperatorLink $link, array $data): JsonResponse
    {
        $operator = $link->operator;
        $tenant = $link->tenant;
        if ($operator === null || $tenant === null) {
            return response()->json([
                'error' => 'link_broken',
                'detail' => 'Account link is broken. Ask a manager to re-invite you.',
            ], 500);
        }

        $app = self::appFromRequest($request);
        if ($app === MobileDevice::APP_DESK && ! in_array($operator->role, EnsureHostAppOperator::ALLOWED_ROLES, true)) {
            return self::deskRoleResponse();
        }

        // Make the tenant current for the token-mint side-effects —
        // MobileDevice::create needs Tenant::current() for its
        // BelongsToTenant global scope to populate tenant_id.
        $tenant->makeCurrent();

        try {
            $device = MobileDevice::query()->create([
                'tenant_id' => $tenant->id,
                'operator_id' => $operator->id,
                'platform' => $data['platform'],
                'device_name' => $data['device_name'],
                'app_version' => $data['app_version'],
                // `X-Keverna-App: host` marks a Keverna for Hosts install;
                // only those rows receive host push notifications.
                'app' => $app,
                'push_token' => $data['push_token'] ?? null,
                'push_platform' => ! empty($data['push_token']) && in_array($data['platform'], ['ios', 'android'], true) ? $data['platform'] : null,
                'push_token_updated_at' => ! empty($data['push_token']) ? now() : null,
                'paired_ip' => $request->ip(),
                'paired_at' => now(),
                'last_seen_at' => now(),
            ]);

            $token = $operator->createToken(
                name: 'mobile-device-'.$device->id,
                abilities: ['mobile:*'],
            );

            // Update the link's last-login timestamp so the picker
            // ranks recent tenants first on the next multi-tenant
            // sign-in.
            $link->forceFill(['last_login_at' => now()])->save();
        } finally {
            Tenant::forgetCurrent();
        }

        return response()->json([
            'token' => $token->plainTextToken,
            'operator' => new OperatorResource($operator),
            'tenant' => new TenantResource($tenant),
            'device' => [
                'id' => $device->id,
                'device_name' => $device->device_name,
                'paired_at' => $device->paired_at->toIso8601String(),
            ],
        ]);
    }
}
