<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OperatorRole;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Properties\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate + branch binding for the host app (`/api/mobile/v1/host/*`).
 * Runs after auth:sanctum + mobile.device.
 *
 * 1. Role gate: only manager / frontdesk / reservation_officer use the
 *    host app. Everyone else gets 403 `role_not_supported` (the staff
 *    app is theirs).
 * 2. Branch binding: resolves the operator's current branch through
 *    {@see PropertyContext} (operators.current_property_id, falling
 *    back to the first accessible property). An `X-Keverna-Property`
 *    header overrides it for this request only, when the operator can
 *    access that branch (403 `property_not_accessible` otherwise).
 *    Keverna Desk operator tokens are pinned to the desk's branch.
 *    The result is bound onto the request so every dashboard service
 *    that calls PropertyContext::current() with no arguments scopes to
 *    it, exactly like the web sidebar switcher does for the session.
 */
class EnsureHostAppOperator
{
    public const ALLOWED_ROLES = [
        OperatorRole::Manager,
        OperatorRole::Frontdesk,
        OperatorRole::ReservationOfficer,
    ];

    public const PROPERTY_HEADER = 'X-Keverna-Property';

    public const PROPERTY_ATTRIBUTE = 'host_property';

    public function __construct(private readonly PropertyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $operator = $request->user();
        if (! $operator instanceof Operator) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        if (! in_array($operator->role, self::ALLOWED_ROLES, true)) {
            return response()->json([
                'error' => 'role_not_supported',
                'detail' => 'This app is for owners, managers and front desk. Housekeeping uses the '.config('brand.name').' Staff app.',
            ], 403);
        }

        $tenant = Tenant::current();
        if ($tenant === null) {
            return response()->json(['error' => 'tenant_not_resolved'], 404);
        }

        $header = trim((string) $request->header(self::PROPERTY_HEADER, ''));
        if (EnsureMobileDeviceActive::isDeskOperatorRequest($request)) {
            // Keverna Desk operator tokens are pinned to the desk's branch:
            // no switching, and the header may only name that branch.
            $device = $request->attributes->get('mobile_device');
            $property = $device instanceof MobileDevice && $device->property_id !== null
                ? Property::query()
                    ->where('id', $device->property_id)
                    ->where('tenant_id', $tenant->getKey())
                    ->first()
                : null;

            if ($property === null
                || ($header !== '' && $header !== (string) $property->id)
                || ! $operator->hasAccessToBranch($property)) {
                return response()->json([
                    'error' => 'property_not_accessible',
                    'detail' => 'You do not have access to this desk\'s branch.',
                ], 403);
            }
        } elseif ($header !== '') {
            $property = ctype_digit($header)
                ? Property::query()
                    ->where('id', (int) $header)
                    ->where('tenant_id', $tenant->getKey())
                    ->first()
                : null;

            if ($property === null || ! $operator->hasAccessToBranch($property)) {
                return response()->json([
                    'error' => 'property_not_accessible',
                    'detail' => 'You do not have access to that branch.',
                ], 403);
            }
        } else {
            $property = $this->context->current($operator, $tenant);
        }

        if ($property === null) {
            return response()->json([
                'error' => 'no_property_access',
                'detail' => 'No branch is assigned to your account yet. Ask a manager to give you access.',
            ], 403);
        }

        PropertyContext::bindForRequest($request, $operator, $property);
        $request->attributes->set(self::PROPERTY_ATTRIBUTE, $property);

        return $next($request);
    }
}
