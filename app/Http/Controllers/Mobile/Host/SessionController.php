<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Models\Property;
use App\Models\Tenant;
use App\Services\Properties\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Host app session + branch endpoints:
 *   GET  host/session
 *   GET  host/properties
 *   POST host/properties/{id}/switch
 */
class SessionController extends HostController
{
    public function __construct(private readonly PropertyContext $context) {}

    public function session(Request $request): JsonResponse
    {
        $operator = $this->operator($request);
        $tenant = Tenant::current();

        return response()->json([
            'data' => [
                'operator' => [
                    'id' => (int) $operator->id,
                    'name' => (string) $operator->name,
                    'email' => $operator->email,
                    'phone' => $operator->phone,
                    'role' => $operator->role?->value,
                    'role_label' => $operator->role?->label(),
                    'initials' => self::initials((string) $operator->name),
                ],
                'tenant' => [
                    'id' => (int) $tenant->id,
                    'name' => (string) $tenant->name,
                    'slug' => (string) $tenant->slug,
                ],
                'property' => $this->propertyBrief($this->property($request)),
                'capabilities' => self::capabilities($operator),
                'properties_count' => $this->context->all($tenant, $operator)->count(),
            ],
        ]);
    }

    public function properties(Request $request): JsonResponse
    {
        $operator = $this->operator($request);
        $current = $this->property($request);
        $properties = $this->context->all(Tenant::current(), $operator);

        $tonight = $this->tonightByProperty($properties);

        return response()->json([
            'data' => $properties->map(fn (Property $p) => $this->propertyRow($p, $tonight, (int) $current->id))->values(),
        ]);
    }

    public function switch(Request $request, string $id): JsonResponse
    {
        $operator = $this->operator($request);
        $tenant = Tenant::current();

        $property = ctype_digit($id)
            ? Property::query()->where('id', (int) $id)->where('tenant_id', $tenant->getKey())->first()
            : null;

        // Unknown id and "exists but not yours" answer the same so a
        // branch id from another tenant can't be probed.
        if ($property === null || ! $this->context->switchTo($property, $operator)) {
            return response()->json([
                'error' => 'property_not_accessible',
                'detail' => 'You do not have access to that branch.',
            ], 403);
        }

        $tonight = $this->tonightByProperty(collect([$property]));

        return response()->json([
            'status' => 'ok',
            'property' => $this->propertyRow($property, $tonight, (int) $property->id),
        ]);
    }

    /**
     * @param  array{rooms: array<int, int>, occupied: array<int, int>}  $tonight
     * @return array<string, mixed>
     */
    private function propertyRow(Property $p, array $tonight, int $currentId): array
    {
        $rooms = (int) ($tonight['rooms'][$p->id] ?? 0);
        $occupied = min($rooms, (int) ($tonight['occupied'][$p->id] ?? 0));

        return [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'city' => $p->city,
            'state' => $p->state,
            'rooms_total' => $rooms,
            'occupied_tonight' => $occupied,
            'occupancy_pct' => $rooms > 0 ? (int) round($occupied / $rooms * 100) : 0,
            'is_current' => (int) $p->id === $currentId,
        ];
    }
}
