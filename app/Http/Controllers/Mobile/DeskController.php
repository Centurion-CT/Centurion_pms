<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Enums\OperatorRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Host\HostController;
use App\Http\Middleware\EnsureHostAppOperator;
use App\Http\Middleware\MobileIdempotency;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Properties\PropertyContext;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Keverna Desk: a shared reception PC (docs/desk-app/PHASE-1.md).
 *
 * Set up: a manager / front desk / reservation officer signs in on the
 * central `POST login` with `X-Keverna-App: desk`. That creates a
 * mobile_devices row with app=desk and the desk's own token
 * (`mobile-device-{id}`). The desk token can only call:
 *
 *   GET  desk/session      desk + branch + branches it can sit in
 *   POST desk/property     pick the desk's branch
 *   GET  desk/operators    who can use this desk
 *   POST desk/unlock       operator PIN -> that operator's own token
 *
 * The operator token (`desk-operator-{device id}`, 12 h) works on the
 * host API like a phone token, pinned to the desk's branch, plus:
 *
 *   POST desk/lock         revoke this operator token
 *
 * Revoking the desk (web Staff > Devices, logout) deletes every
 * operator token issued on it.
 */
class DeskController extends Controller
{
    /** Wrong PINs per operator per desk before a lockout. */
    public const MAX_PIN_ATTEMPTS = 5;

    /** Wrong PINs / unknown operators per desk (any operator) before a lockout. */
    public const MAX_DESK_ATTEMPTS = 20;

    public const LOCKOUT_SECONDS = 15 * 60;

    public const TOKEN_TTL_HOURS = 12;

    public function __construct(private readonly PropertyContext $context) {}

    public function session(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $setUpBy = $this->operator($request);
        $tenant = Tenant::current();
        $property = $this->deskProperty($device, $setUpBy);

        return response()->json([
            'data' => [
                'device' => [
                    'id' => (int) $device->id,
                    'device_name' => (string) $device->device_name,
                    'app_version' => (string) $device->app_version,
                    'paired_at' => $device->paired_at?->toIso8601String(),
                ],
                'tenant' => [
                    'id' => (int) $tenant->id,
                    'name' => (string) $tenant->name,
                    'slug' => (string) $tenant->slug,
                ],
                'set_up_by' => [
                    'id' => (int) $setUpBy->id,
                    'name' => (string) $setUpBy->name,
                ],
                'property' => $property !== null ? $this->propertyBrief($property) : null,
                'properties' => $this->context->all($tenant, $setUpBy)
                    ->map(fn (Property $p) => $this->propertyBrief($p))
                    ->values(),
            ],
        ]);
    }

    public function property(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'integer'],
        ]);
        $device = $this->device($request);
        $setUpBy = $this->operator($request);

        $property = Property::query()
            ->where('id', (int) $data['property_id'])
            ->where('tenant_id', Tenant::current()->getKey())
            ->first();

        // Unknown id and "exists but not yours" answer the same.
        if ($property === null || ! $setUpBy->hasAccessToBranch($property)) {
            return response()->json([
                'error' => 'property_not_accessible',
                'detail' => 'You do not have access to that branch.',
            ], 403);
        }

        if ((int) $device->property_id !== (int) $property->id) {
            // Moving a desk to another branch signs everyone out of it.
            $device->revokeDeskOperatorTokens();
            $device->forceFill(['property_id' => $property->id, 'desk_unlocks' => null])->save();
        }

        return response()->json([
            'status' => 'ok',
            'property' => $this->propertyBrief($property),
        ]);
    }

    public function operators(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $property = $this->deskProperty($device, $this->operator($request));
        if ($property === null) {
            return $this->branchRequired();
        }

        $unlocks = $device->desk_unlocks ?? [];

        $rows = $this->eligibleOperators($property)
            ->map(fn (Operator $op) => [
                'id' => (int) $op->id,
                'name' => (string) $op->name,
                'initials' => HostController::initials((string) $op->name),
                'role' => $op->role?->value,
                'role_label' => $op->role?->label(),
                'has_pin' => $op->hasStaffPin(),
                'last_unlock_at' => $unlocks[(string) $op->id] ?? null,
            ])
            // Most recently at the desk first, then by name.
            ->sort(fn (array $a, array $b) => [$b['last_unlock_at'] ?? '', $a['name']] <=> [$a['last_unlock_at'] ?? '', $b['name']])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function unlock(Request $request, RateLimiter $limiter): JsonResponse
    {
        // The body carries a bearer token: never keep it in the
        // idempotency cache. A retried unlock just issues a new token.
        $request->attributes->set(MobileIdempotency::NO_STORE, true);

        $data = $request->validate([
            'operator_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ]);

        $device = $this->device($request);
        $property = $this->deskProperty($device, $this->operator($request));
        if ($property === null) {
            return $this->branchRequired();
        }

        $operatorId = (int) $data['operator_id'];
        $deskKey = 'desk-unlock:'.$device->id;
        $operatorKey = 'desk-unlock:'.$device->id.':'.$operatorId;

        foreach ([[$operatorKey, self::MAX_PIN_ATTEMPTS], [$deskKey, self::MAX_DESK_ATTEMPTS]] as [$key, $max]) {
            if ($limiter->tooManyAttempts($key, $max)) {
                $wait = $limiter->availableIn($key);

                return response()->json([
                    'error' => 'too_many_attempts',
                    'detail' => 'Too many wrong PINs. Try again in '.max(1, (int) ceil($wait / 60)).' min.',
                    'retry_after' => $wait,
                ], 429);
            }
        }

        // Only operators listed for this desk. An id from another
        // tenant (or someone who can't use this desk) gets the same
        // answer as one that doesn't exist, and counts against the desk.
        $operator = $this->eligibleOperators($property)->firstWhere('id', $operatorId);
        if ($operator === null) {
            $limiter->hit($deskKey, self::LOCKOUT_SECONDS);

            return response()->json([
                'error' => 'operator_not_found',
                'detail' => 'That person can\'t use this desk.',
            ], 404);
        }

        if (! $operator->hasStaffPin()) {
            return response()->json([
                'error' => 'no_pin',
                'detail' => 'No PIN yet. A manager sets it on the Staff page.',
            ], 409);
        }

        if (! $operator->checkStaffPin($data['pin'])) {
            $limiter->hit($operatorKey, self::LOCKOUT_SECONDS);
            $limiter->hit($deskKey, self::LOCKOUT_SECONDS);
            $left = max(0, self::MAX_PIN_ATTEMPTS - $limiter->attempts($operatorKey));

            return response()->json([
                'error' => 'wrong_pin',
                'detail' => $left > 0
                    ? 'Wrong PIN. '.$left.' '.($left === 1 ? 'try' : 'tries').' left.'
                    : 'Wrong PIN. Try again in 15 min.',
                'attempts_left' => $left,
            ], 422);
        }

        $limiter->clear($operatorKey);

        // One live token per operator per desk.
        $device->revokeDeskOperatorTokens((int) $operator->id);
        $expiresAt = now()->addHours(self::TOKEN_TTL_HOURS);
        $token = $operator->createToken($device->deskOperatorTokenName(), ['mobile:*'], $expiresAt);

        $unlocks = $device->desk_unlocks ?? [];
        $unlocks[(string) $operator->id] = now()->toIso8601String();
        $device->forceFill(['desk_unlocks' => $unlocks])->save();

        return response()->json([
            'status' => 'ok',
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'operator' => [
                'id' => (int) $operator->id,
                'name' => (string) $operator->name,
                'initials' => HostController::initials((string) $operator->name),
                'role' => $operator->role?->value,
                'role_label' => $operator->role?->label(),
                'capabilities' => HostController::capabilities($operator),
            ],
            'property' => $this->propertyBrief($property),
        ]);
    }

    public function lock(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Host-role operators of this tenant who can sign in and work in
     * the desk's branch, by name.
     *
     * @return Collection<int, Operator>
     */
    private function eligibleOperators(Property $property): Collection
    {
        return Operator::query()
            ->whereIn('role', array_map(fn (OperatorRole $r) => $r->value, EnsureHostAppOperator::ALLOWED_ROLES))
            ->whereNotNull('activated_at')
            ->whereNull('suspended_at')
            ->whereNull('revoked_at')
            ->with('branches')
            ->orderBy('name')
            ->get()
            ->filter(fn (Operator $op) => $op->canSignIn() && (
                $op->role === OperatorRole::Manager
                || $op->branches->isEmpty()
                || $op->branches->contains('id', $property->id)
            ))
            ->values();
    }

    /**
     * The desk's branch. Picked once on set up; when the person who set
     * it up only has one branch, it is picked for them.
     */
    private function deskProperty(MobileDevice $device, Operator $setUpBy): ?Property
    {
        $tenant = Tenant::current();
        if ($device->property_id !== null) {
            return Property::query()
                ->where('id', $device->property_id)
                ->where('tenant_id', $tenant->getKey())
                ->first();
        }

        $options = $this->context->all($tenant, $setUpBy);
        if ($options->count() !== 1) {
            return null;
        }

        $property = $options->first();
        $device->forceFill(['property_id' => $property->id])->save();

        return $property;
    }

    private function branchRequired(): JsonResponse
    {
        return response()->json([
            'error' => 'desk_branch_required',
            'detail' => 'Pick the branch this desk is in first.',
        ], 409);
    }

    private function device(Request $request): MobileDevice
    {
        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');

        return $device;
    }

    private function operator(Request $request): Operator
    {
        /** @var Operator $operator */
        $operator = $request->user();

        return $operator;
    }

    /**
     * @return array{id: int, name: string, city: ?string}
     */
    private function propertyBrief(Property $property): array
    {
        return [
            'id' => (int) $property->id,
            'name' => (string) $property->name,
            'city' => $property->city,
        ];
    }
}
