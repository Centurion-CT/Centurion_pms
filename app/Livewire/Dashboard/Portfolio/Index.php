<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Portfolio;

use App\Enums\BookingStatus;
use App\Enums\PropertyType;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Portfolio dashboard — one screen for the operator who runs
 * multiple standalone properties (Phase 2 of the single-unit
 * apartments unlock). Each Property carries its own address,
 * inventory, bookings, reviews — this page rolls them up into a
 * single card grid + org-wide totals so the operator sees the
 * whole portfolio without switching branch context.
 *
 * Per-property card metrics:
 *   * Live vs off-market chip
 *   * This month's confirmed booking count
 *   * This month's revenue (sum of booking.total_amount for the
 *     property's confirmed / checked-in / checked-out bookings)
 *   * Upcoming check-ins (next 7 days)
 *   * Switch-to-this-branch CTA
 *
 * Header rollup: total properties, listed count, aggregate
 * revenue-to-date this month.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    public function switchTo(int $propertyId, PropertyContext $context): void
    {
        $tenant = Tenant::current();
        if ($tenant === null) return;

        $property = Property::query()
            ->where('id', $propertyId)
            ->where('tenant_id', $tenant->getKey())
            ->first();
        if ($property === null) return;

        if ($context->switchTo($property)) {
            $this->redirectRoute('tenant.dashboard', navigate: true);
        }
    }

    public function render(PropertyContext $context): View
    {
        $tenant = Tenant::current();
        $properties = $context->all();

        $monthStart = Carbon::now()->startOfMonth();
        $weekEnd    = Carbon::today()->addDays(7);

        // Batched per-property rollups — one aggregate query per stat,
        // keyed by property id, so an operator with 30 apartments
        // isn't paying 30×3 round trips just to draw the grid.
        $inventoryPropertyIds = $properties->pluck('id')->all();
        $moneyStatuses = [
            BookingStatus::Confirmed->value,
            BookingStatus::CheckedIn->value,
            BookingStatus::CheckedOut->value,
            BookingStatus::OnHold->value,
        ];

        $bookingsThisMonth = Booking::query()
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->whereIn('rooms.property_id', $inventoryPropertyIds)
            ->whereIn('bookings.booking_status', $moneyStatuses)
            ->whereDate('bookings.created_at', '>=', $monthStart)
            ->selectRaw('rooms.property_id as prop_id, COUNT(*) as booking_count, SUM(bookings.total_amount) as revenue')
            ->groupBy('rooms.property_id')
            ->get()
            ->keyBy('prop_id');

        // Category-hold bookings have room_id NULL — union them in via
        // the category link so single-unit / category-mode properties
        // are represented too.
        $categoryHoldBookings = Booking::query()
            ->join('room_categories', 'room_categories.id', '=', 'bookings.room_category_id')
            ->whereNull('bookings.room_id')
            ->whereIn('room_categories.property_id', $inventoryPropertyIds)
            ->whereIn('bookings.booking_status', $moneyStatuses)
            ->whereDate('bookings.created_at', '>=', $monthStart)
            ->selectRaw('room_categories.property_id as prop_id, COUNT(*) as booking_count, SUM(bookings.total_amount) as revenue')
            ->groupBy('room_categories.property_id')
            ->get()
            ->keyBy('prop_id');

        $upcomingByProperty = Booking::query()
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->whereIn('rooms.property_id', $inventoryPropertyIds)
            ->whereIn('bookings.booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::OnHold->value,
            ])
            ->whereDate('bookings.checkin_date', '>=', Carbon::today())
            ->whereDate('bookings.checkin_date', '<', $weekEnd)
            ->selectRaw('rooms.property_id as prop_id, COUNT(*) as c')
            ->groupBy('rooms.property_id')
            ->pluck('c', 'prop_id')
            ->all();

        $rows = $properties->map(function (Property $p) use ($bookingsThisMonth, $categoryHoldBookings, $upcomingByProperty) {
            $roomStat = $bookingsThisMonth[$p->id] ?? null;
            $catStat  = $categoryHoldBookings[$p->id] ?? null;
            $bookingCount = (int) ($roomStat->booking_count ?? 0) + (int) ($catStat->booking_count ?? 0);
            $revenue = (int) ($roomStat->revenue ?? 0) + (int) ($catStat->revenue ?? 0);

            return [
                'property'         => $p,
                'is_live'          => (bool) $p->is_live,
                'is_listed'        => (bool) $p->is_listed_publicly,
                'is_single_unit'   => $p->isSingleUnit(),
                'city_line'        => trim(implode(', ', array_filter([$p->city, $p->state]))),
                'booking_count'    => $bookingCount,
                'revenue'          => $revenue,
                'upcoming_checkins'=> (int) ($upcomingByProperty[$p->id] ?? 0),
            ];
        });

        $totals = [
            'properties'   => $rows->count(),
            'live'         => $rows->where('is_live', true)->count(),
            'revenue'      => (int) $rows->sum('revenue'),
            'booking_count'=> (int) $rows->sum('booking_count'),
        ];

        return view('livewire.dashboard.portfolio.index', [
            'rows'    => $rows,
            'totals'  => $totals,
            'current' => $context->current(),
            'tenant'  => $tenant,
        ]);
    }
}
