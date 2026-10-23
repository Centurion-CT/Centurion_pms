<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:sanctum` on every /api/mobile/v1 route. Resolves
 * the MobileDevice bound to the current bearer token (encoded in the
 * token's name — set by PairController::consume as
 * "mobile-device-{id}"), 401s if it's been revoked, bumps
 * last_seen_at so the staff/devices page shows fresh liveness.
 *
 * Keverna Desk adds a second token kind, `desk-operator-{device id}`:
 * an operator unlocked by PIN on a shared desk (docs/desk-app/PHASE-1.md).
 * Sanctum enforces its 12 h `expires_at`; here it must still belong to
 * an active desk of the operator's tenant.
 *
 * Web + POS device sessions have their own middleware
 * (EnsureLinkedDeviceActive). Mobile is intentionally a separate
 * pipeline — different auth surface, different revocation UX.
 */
class EnsureMobileDeviceActive
{
    /** Request attribute: which kind of token authenticated the call. */
    public const TOKEN_KIND_ATTRIBUTE = 'mobile_token_kind';

    public const KIND_DEVICE = 'device';

    public const KIND_DESK_OPERATOR = 'desk_operator';

    /** Routes a desk's own (locked) token may call. */
    private const DESK_DEVICE_ROUTES = [
        'mobile.desk.session',
        'mobile.desk.property',
        'mobile.desk.operators',
        'mobile.desk.unlock',
        'mobile.me',
        'mobile.logout',
    ];

    /** True when a request authenticated by a desk operator token. */
    public static function isDeskOperatorRequest(Request $request): bool
    {
        return $request->attributes->get(self::TOKEN_KIND_ATTRIBUTE) === self::KIND_DESK_OPERATOR;
    }

    private static function deviceRouteAllowed(string $route): bool
    {
        return in_array($route, self::DESK_DEVICE_ROUTES, true);
    }

    private static function operatorRouteAllowed(string $route): bool
    {
        // No branch switching from a desk: its tokens are pinned to the desk's branch.
        return $route === 'mobile.desk.lock'
            || (str_starts_with($route, 'mobile.host.') && $route !== 'mobile.host.properties.switch');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $operator = $request->user();
        $token = $operator?->currentAccessToken();
        if ($operator === null || $token === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // Token name patterns:
        //  mobile-device-{id}   the device's own token (phone, or a
        //                       Keverna Desk before anyone unlocks it)
        //  desk-operator-{id}   one operator unlocked on desk {id}
        // Anything else is a web-side or legacy token: refuse it.
        if (preg_match('/^mobile-device-(\d+)$/', $token->name, $m)) {
            $kind = self::KIND_DEVICE;
        } elseif (preg_match('/^'.preg_quote(MobileDevice::DESK_OPERATOR_TOKEN_PREFIX, '/').'(\d+)$/', $token->name, $m)) {
            $kind = self::KIND_DESK_OPERATOR;
        } else {
            return response()->json(['error' => 'invalid_token_scope'], 401);
        }

        $device = MobileDevice::query()->find((int) $m[1]);
        if ($device === null) {
            return response()->json(['error' => 'device_not_found'], 401);
        }
        if ($kind === self::KIND_DEVICE && $device->operator_id !== $operator->id) {
            return response()->json(['error' => 'device_not_found'], 401);
        }
        if ($kind === self::KIND_DESK_OPERATOR) {
            // Operator tokens only exist on desks, for operators of the
            // desk's own tenant.
            if (! $device->isDesk() || (int) $device->tenant_id !== (int) $operator->tenant_id) {
                return response()->json(['error' => 'device_not_found'], 401);
            }
        }
        // A desk stops working for anyone whose access was suspended or
        // revoked, including the person who set the desk up (their token
        // is the desk's own token).
        if ($device->isDesk() && (! $operator instanceof Operator || ! $operator->canSignIn())) {
            return response()->json(['error' => 'access_suspended'], 401);
        }
        if (! $device->isActive()) {
            return response()->json(['error' => 'device_revoked'], 401);
        }

        $tenant = Tenant::current();
        if ($tenant !== null && (int) $device->tenant_id !== (int) $tenant->getKey()) {
            return response()->json(['error' => 'device_not_found'], 401);
        }

        // A desk's own token only reaches the desk set-up / unlock
        // endpoints (and logout); an operator token only reaches the
        // host API and desk/lock. So nothing is ever done "as the desk".
        $route = (string) $request->route()?->getName();
        if ($device->isDesk() && $kind === self::KIND_DEVICE && ! self::deviceRouteAllowed($route)) {
            return response()->json([
                'error' => 'desk_locked',
                'detail' => 'Unlock the desk with your PIN first.',
            ], 403);
        }
        if ($kind === self::KIND_DESK_OPERATOR && ! self::operatorRouteAllowed($route)) {
            return response()->json(['error' => 'invalid_token_scope'], 403);
        }

        // Cheap update, no timestamps() bump — avoids a UPDATE ...
        // SET updated_at on every mobile request. last_seen_at is
        // the only column that changes, and the query uses the PK.
        MobileDevice::query()
            ->whereKey($device->id)
            ->update(['last_seen_at' => now()]);

        // Stash on the request so downstream controllers can read
        // the device without a second lookup.
        $request->attributes->set('mobile_device', $device);
        $request->attributes->set(self::TOKEN_KIND_ATTRIBUTE, $kind);

        return $next($request);
    }
}
