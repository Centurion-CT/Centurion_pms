<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Enums\OperatorRole;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureHostAppOperator;
use App\Models\Operator;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Shared helpers for the host app endpoints. Every host route runs
 * behind `host.role`, so the operator is a manager / frontdesk /
 * reservation officer and the branch is already resolved + bound.
 */
abstract class HostController extends Controller
{
    protected function operator(Request $request): Operator
    {
        /** @var Operator $operator */
        $operator = $request->user();

        return $operator;
    }

    protected function property(Request $request): Property
    {
        /** @var Property $property */
        $property = $request->attributes->get(EnsureHostAppOperator::PROPERTY_ATTRIBUTE);

        return $property;
    }

    /**
     * Owner = manager role (owner-level), or any host role granted the
     * `finance.view` permission on the Staff permissions page.
     */
    public static function isOwner(Operator $operator): bool
    {
        return $operator->role === OperatorRole::Manager
            || $operator->hasPermission(Permission::FinanceView);
    }

    /**
     * What the app may show / offer. Mirrors the web module gates
     * (role modules unioned with permission grants).
     *
     * @return array{owner: bool, create_booking: bool, take_payment: bool, approve: bool, finance: bool}
     */
    public static function capabilities(Operator $operator): array
    {
        return [
            'owner' => self::isOwner($operator),
            'create_booking' => $operator->canAccessModule('reservations') || $operator->canAccessModule('bookings'),
            'take_payment' => $operator->canAccessModule('reservations') || $operator->canAccessModule('payments'),
            // /dashboard/approvals sits under role:reservations on the web.
            'approve' => $operator->canAccessModule('reservations'),
            'finance' => $operator->canAccessModule('finance'),
        ];
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice(array_values(array_filter($parts)), 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters;
    }

    /**
     * @return array{id: int, name: string, city: ?string}
     */
    protected function propertyBrief(Property $property): array
    {
        return [
            'id' => (int) $property->id,
            'name' => (string) $property->name,
            'city' => $property->city,
        ];
    }

    /**
     * Rooms + rooms sold tonight per branch, on the shared "occupied
     * tonight" rule ({@see \App\Services\Dashboard\OccupancyCalculator::nightly()}):
     * rooms booked for tonight plus rooms whose guest is still checked
     * in past checkout; guests due out today are not counted.
     *
     * @param  Collection<int, Property>  $properties
     * @return array{rooms: array<int, int>, occupied: array<int, int>}
     */
    protected function tonightByProperty(Collection $properties): array
    {
        $ids = $properties->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return ['rooms' => [], 'occupied' => []];
        }

        $tonight = app(\App\Services\Dashboard\OccupancyCalculator::class)->tonight($ids);

        return [
            'rooms' => array_map(fn (array $r) => $r['total'], $tonight['by_property']),
            'occupied' => array_map(fn (array $r) => $r['sold'], $tonight['by_property']),
        ];
    }
}
