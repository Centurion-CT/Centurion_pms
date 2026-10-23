<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Staff;

use App\Enums\OperatorRole;
use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phone number + staff PIN sign-in for the staff app (housekeepers and
 * porters). Lives with the central mobile routes because the app has
 * no tenant yet. Same token shape as the email login (`mobile-device-
 * {id}`), so EnsureMobileDeviceActive works unchanged.
 *
 * Unknown phone and wrong PIN give the same 401. Several tenants with
 * the same phone + PIN answer 300 with a picker; the client resends
 * with tenant_id. Rate-limited per ip + phone.
 */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 6;

    private const DECAY_SECONDS = 120;

    public function login(Request $request, RateLimiter $limiter): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'tenant_id' => ['nullable', 'integer'],
            'platform' => ['required', Rule::in(['ios', 'android', 'web'])],
            'device_name' => ['required', 'string', 'max:120'],
            'app_version' => ['required', 'string', 'max:32'],
        ]);

        $phoneKey = Operator::phoneKey($data['phone']);
        $throttleKey = 'mobile-staff-login:'.sha1($request->ip().'|'.$phoneKey);

        if ($limiter->tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $wait = $limiter->availableIn($throttleKey);

            return response()->json([
                'error' => 'too_many_attempts',
                'detail' => "Too many tries. Wait {$wait} seconds and try again.",
            ], 429);
        }

        // Every operator (any tenant) with this phone and a PIN that
        // matches. Phones are stored as typed, so compare in PHP on the
        // normalised key; the candidate set is small (PIN holders only).
        $matches = Operator::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNotNull('staff_pin_hash')
            ->whereNotNull('phone')
            ->whereNull('revoked_at')
            ->get()
            ->filter(fn (Operator $op): bool => $phoneKey !== null
                && Operator::phoneKey($op->phone) === $phoneKey
                && $op->checkStaffPin($data['pin']))
            ->values();

        if ($matches->isEmpty()) {
            $limiter->hit($throttleKey, self::DECAY_SECONDS);

            return response()->json([
                'error' => 'invalid_credentials',
                'detail' => "That phone number and PIN don't match.",
            ], 401);
        }

        $limiter->clear($throttleKey);

        if ($matches->count() > 1 && empty($data['tenant_id'])) {
            $tenants = Tenant::query()->whereIn('id', $matches->pluck('tenant_id'))->get(['id', 'name']);

            return response()->json([
                'status' => 'pick_tenant',
                'tenants' => $tenants->map(fn (Tenant $t) => ['id' => $t->id, 'name' => $t->name])->values(),
            ], 300);
        }

        $operator = ! empty($data['tenant_id'])
            ? $matches->firstWhere('tenant_id', (int) $data['tenant_id'])
            : $matches->first();

        if ($operator === null) {
            return response()->json([
                'error' => 'tenant_not_accessible',
                'detail' => "That hotel isn't on your account.",
            ], 403);
        }

        if (! in_array($operator->role, [OperatorRole::HouseCleaner, OperatorRole::Porter], true)) {
            return response()->json([
                'error' => 'staff_only',
                'detail' => 'This app is for housekeepers and porters. Use the dashboard on the web instead.',
            ], 403);
        }

        if ($operator->suspended_at !== null) {
            return response()->json([
                'error' => 'suspended',
                'detail' => 'Your account is paused. Ask a manager.',
            ], 403);
        }

        $tenant = Tenant::query()->findOrFail($operator->tenant_id);
        $tenant->makeCurrent();

        try {
            $device = MobileDevice::query()->create([
                'tenant_id' => $tenant->id,
                'operator_id' => $operator->id,
                'platform' => $data['platform'],
                'device_name' => $data['device_name'],
                'app_version' => $data['app_version'],
                'paired_ip' => $request->ip(),
                'paired_at' => now(),
                'last_seen_at' => now(),
            ]);

            $token = $operator->createToken(
                name: 'mobile-device-'.$device->id,
                abilities: ['mobile:*'],
            );

            $operator->forceFill(['last_login_at' => now()])->save();
        } finally {
            Tenant::forgetCurrent();
        }

        return response()->json([
            'token' => $token->plainTextToken,
            'base_url' => $request->getSchemeAndHttpHost(),
            'operator' => StaffPayload::operator($operator),
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
        ]);
    }
}
