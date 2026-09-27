<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-stop builder for the /dashboard/reports page.
 *
 * Each public method returns a typed-shape array that the Livewire
 * component pumps straight into Blade — no aggregation lives in the
 * view. Centralising here means future surfaces (CSV exports,
 * scheduled email digests, the admin panel) can call the same methods
 * without rebuilding the SQL.
 *
 * Date semantics
 * --------------
 * Inputs are inclusive on both ends (a "from / to" range as the
 * operator picks dates from a date input). Internally we map onto
 * the half-open convention the booking engine uses elsewhere
 * (`checkin_date <= to`, `checkout_date > from`) so overlapping
 * stays at the boundary are counted exactly once.
 *
 * Revenue convention
 * ------------------
 * "Revenue" = sum(room_total) on Confirmed / CheckedIn / CheckedOut
 * bookings whose `checkin_date` falls in the range. We use
 * checkin-date as the anchor (vs created-at) so a booking made in
 * January for a March stay shows up in the March report — matches
 * what operators expect when they ask "what was our March revenue".
 * Pending / OnHold / Cancelled / NoShow are excluded.
 */
class ReportSnapshot
{
    /**
     * Lazily-resolved current-branch id, same pattern as
     * {@see FinanceSnapshot} — every internal query wraps through
     * scope* helpers below so /dashboard/reports honours the
     * sidebar's picked branch.
     */
    private ?int $cachedPropertyId = null;
    private bool $cachedPropertyIdResolved = false;

    private function currentPropertyId(): ?int
    {
        if (! $this->cachedPropertyIdResolved) {
            $this->cachedPropertyId = app(\App\Services\Properties\PropertyContext::class)->current()?->getKey();
            $this->cachedPropertyIdResolved = true;
        }
        return $this->cachedPropertyId;
    }

    private function scopeBookings(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $pid = $this->currentPropertyId();
        return $pid === null ? $q : $q->whereHas('room', fn ($r) => $r->where('property_id', $pid));
    }

    private function scopeRooms(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $pid = $this->currentPropertyId();
        return $pid === null ? $q : $q->where('property_id', $pid);
    }

    /** AvailabilityBlock reaches property via its room. */
    private function scopeByRoom(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $pid = $this->currentPropertyId();
        return $pid === null ? $q : $q->whereHas('room', fn ($r) => $r->where('property_id', $pid));
    }

    /**
     * @return array<string, mixed>
     */
    public function revenue(Carbon $from, Carbon $to): array
    {
        $revenueStatuses = $this->revenueStatuses();

        $base = $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()]);

        $total = (int) (clone $base)->sum('room_total');
        $bookings = (int) (clone $base)->count();
        $nights = (int) (clone $base)->sum('nights');

        $avgValue = $bookings > 0 ? (int) round($total / $bookings) : 0;

        // Comparison vs previous equal-length window.
        $span = (int) max(1, $from->diffInDays($to, absolute: true) + 1);
        $prevFrom = $from->copy()->subDays($span);
        $prevTo = $from->copy()->subDay();

        $prevTotal = (int) $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->sum('room_total');
        $totalDelta = $this->deltaPercent($total, $prevTotal);

        // Breakdown by booking source. Uses the enum's own label() so
        // new channels (Booking.com, Airbnb, WhatsApp, …) surface without
        // an extra mapping table. `$r->source` comes back as a BookingSource
        // instance because Booking casts it; falls back to the raw string
        // for any legacy row that no longer maps to an enum case.
        // toBase() drops down to the query builder so Booking's enum
        // cast doesn't fire on the aggregate — we get the raw string
        // and normalise via the enum ourselves.
        $bySource = (clone $base)
            ->toBase()
            ->selectRaw('source, COUNT(*) as bookings, SUM(room_total) as revenue')
            ->groupBy('source')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($r) use ($total): array {
                $value = (string) $r->source;
                $enum = BookingSource::tryFrom($value);

                return [
                    'label' => $enum?->label() ?? $value,
                    'source' => $value,
                    'bookings' => (int) $r->bookings,
                    'revenue' => (int) $r->revenue,
                    'share' => $total > 0 ? round(((int) $r->revenue) / $total * 100, 1) : 0.0,
                ];
            })
            ->values()
            ->all();

        // Breakdown by category — joins through the room's category.
        $byCategory = (clone $base)
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->join('room_categories', 'room_categories.id', '=', 'rooms.room_category_id')
            ->selectRaw('room_categories.id as cat_id, room_categories.name as cat_name, room_categories.type as cat_type, COUNT(bookings.id) as bookings, SUM(bookings.room_total) as revenue, SUM(bookings.nights) as nights')
            ->groupBy('room_categories.id', 'room_categories.name', 'room_categories.type')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->cat_id,
                'name' => (string) $r->cat_name,
                'type' => (string) $r->cat_type,
                'bookings' => (int) $r->bookings,
                'revenue' => (int) $r->revenue,
                'nights' => (int) $r->nights,
                'share' => $total > 0 ? round(((int) $r->revenue) / $total * 100, 1) : 0.0,
            ])
            ->values()
            ->all();

        // Top 5 individual rooms by revenue. Useful for spotting
        // your highest-yielding unit (operators do not always know).
        $topRooms = (clone $base)
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->leftJoin('room_categories', 'room_categories.id', '=', 'rooms.room_category_id')
            ->selectRaw('rooms.id as room_id, rooms.room_number as room_number, room_categories.name as cat_name, COUNT(bookings.id) as bookings, SUM(bookings.room_total) as revenue')
            ->groupBy('rooms.id', 'rooms.room_number', 'room_categories.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->room_id,
                'label' => trim((string) ($r->cat_name ?? '').' · '.($r->room_number ?? '—'), ' ·'),
                'bookings' => (int) $r->bookings,
                'revenue' => (int) $r->revenue,
            ])
            ->values()
            ->all();

        return [
            'total' => $total,
            'bookings' => $bookings,
            'nights' => $nights,
            'avgValue' => $avgValue,
            'prevTotal' => $prevTotal,
            'totalDelta' => $totalDelta,
            'bySource' => $bySource,
            'byCategory' => $byCategory,
            'topRooms' => $topRooms,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkin(Carbon $from, Carbon $to): array
    {
        $arrivals = $this->scopeBookings(Booking::query())
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('booking_status', [BookingStatus::Pending->value])
            ->count();

        $checkedIn = $this->scopeBookings(Booking::query())
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('booking_status', [BookingStatus::CheckedIn->value, BookingStatus::CheckedOut->value])
            ->count();

        $noShows = $this->scopeBookings(Booking::query())
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->where('booking_status', BookingStatus::NoShow->value)
            ->count();

        $cancellations = $this->scopeBookings(Booking::query())
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->where('booking_status', BookingStatus::Cancelled->value)
            ->count();

        $pendingArrivals = $this->scopeBookings(Booking::query())
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('booking_status', [BookingStatus::Confirmed->value, BookingStatus::OnHold->value])
            ->count();

        $checkinRate = $arrivals > 0 ? round($checkedIn / $arrivals * 100, 1) : 0.0;
        $noShowRate = $arrivals > 0 ? round($noShows / $arrivals * 100, 1) : 0.0;

        // Arrivals + no-shows broken out by category.
        $byCategory = $this->scopeBookings(Booking::query())
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::OnHold->value,
                BookingStatus::CheckedIn->value,
                BookingStatus::CheckedOut->value,
                BookingStatus::NoShow->value,
                BookingStatus::Cancelled->value,
            ])
            ->join('rooms', 'rooms.id', '=', 'bookings.room_id')
            ->leftJoin('room_categories', 'room_categories.id', '=', 'rooms.room_category_id')
            ->selectRaw('
                room_categories.id as cat_id,
                room_categories.name as cat_name,
                COUNT(bookings.id) as total,
                SUM(CASE WHEN bookings.booking_status IN ("checked_in", "checked_out") THEN 1 ELSE 0 END) as checked_in,
                SUM(CASE WHEN bookings.booking_status = "no_show" THEN 1 ELSE 0 END) as no_shows,
                SUM(CASE WHEN bookings.booking_status = "cancelled" THEN 1 ELSE 0 END) as cancelled
            ')
            ->groupBy('room_categories.id', 'room_categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) ($r->cat_id ?? 0),
                'name' => (string) ($r->cat_name ?? 'Uncategorised'),
                'total' => (int) $r->total,
                'checked_in' => (int) $r->checked_in,
                'no_shows' => (int) $r->no_shows,
                'cancelled' => (int) $r->cancelled,
                'checkin_rate' => (int) $r->total > 0 ? round((int) $r->checked_in / (int) $r->total * 100, 1) : 0.0,
            ])
            ->values()
            ->all();

        return [
            'arrivals' => $arrivals,
            'pendingArrivals' => $pendingArrivals,
            'checkedIn' => $checkedIn,
            'noShows' => $noShows,
            'cancellations' => $cancellations,
            'checkinRate' => $checkinRate,
            'noShowRate' => $noShowRate,
            'byCategory' => $byCategory,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function occupancy(Carbon $from, Carbon $to): array
    {
        // Active rooms at the moment we're running the report. For
        // perfect historical accuracy we'd snapshot active-at-date
        // counts; pragmatic v1 uses current active count which is
        // accurate for short look-back windows.
        $activeRooms = $this->scopeRooms(Room::query())->where('status', RoomStatus::Active->value)->count();

        $days = (int) ($from->diffInDays($to, absolute: true) + 1);
        $availableRoomNights = $activeRooms * $days;

        // Occupied nights = Booked-type availability_blocks inside the
        // half-open [from, to+1) window.
        $occupiedRoomNights = (int) $this->scopeByRoom(AvailabilityBlock::query())
            ->where('type', AvailabilityBlockType::Booked->value)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->count();

        $occupancyPercent = $availableRoomNights > 0
            ? round($occupiedRoomNights / $availableRoomNights * 100, 1)
            : 0.0;

        // ADR (Average Daily Rate) = revenue / occupied room-nights.
        // RevPAR (Revenue Per Available Room) = revenue / available
        // room-nights. Both restricted to revenue-counting statuses.
        $revenueStatuses = $this->revenueStatuses();
        $revenue = (int) $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->sum('room_total');
        $totalNights = (int) $this->scopeBookings(Booking::query())
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->sum('nights');

        $adr = $totalNights > 0 ? (int) round($revenue / $totalNights) : 0;
        $revpar = $availableRoomNights > 0 ? (int) round($revenue / $availableRoomNights) : 0;

        // Per-category occupancy.
        $categories = $this->scopeRooms(RoomCategory::query())
            ->active()
            ->with(['rooms' => fn ($q) => $q->where('status', RoomStatus::Active->value)])
            ->get();

        $byCategory = $categories->map(function (RoomCategory $cat) use ($from, $to, $days) {
            $roomIds = $cat->rooms->pluck('id')->all();
            $catActive = count($roomIds);
            $catAvailable = $catActive * $days;
            $catOccupied = $catActive === 0
                ? 0
                : (int) $this->scopeByRoom(AvailabilityBlock::query())
                    ->whereIn('room_id', $roomIds)
                    ->where('type', AvailabilityBlockType::Booked->value)
                    ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                    ->count();
            $pct = $catAvailable > 0 ? round($catOccupied / $catAvailable * 100, 1) : 0.0;

            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'type' => $cat->type,
                'active_rooms' => $catActive,
                'occupied_nights' => $catOccupied,
                'available_nights' => $catAvailable,
                'occupancy' => $pct,
            ];
        })->sortByDesc('occupancy')->values()->all();

        return [
            'activeRooms' => $activeRooms,
            'days' => $days,
            'availableRoomNights' => $availableRoomNights,
            'occupiedRoomNights' => $occupiedRoomNights,
            'occupancyPercent' => $occupancyPercent,
            'adr' => $adr,
            'revpar' => $revpar,
            'revenue' => $revenue,
            'byCategory' => $byCategory,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventory(): array
    {
        $total = $this->scopeRooms(Room::query())->count();
        $active = $this->scopeRooms(Room::query())->where('status', RoomStatus::Active->value)->count();
        $inactive = $total - $active;

        $categoryCount = $this->scopeRooms(RoomCategory::query())->count();
        $activeCategoryCount = $this->scopeRooms(RoomCategory::query())->active()->count();

        // Per-category breakdown — rooms count + photo coverage +
        // status. Operators can scan for problems (no photos, no
        // active rooms, etc.).
        $byCategory = $this->scopeRooms(RoomCategory::query())
            ->with('rooms')
            ->get()
            ->map(function (RoomCategory $cat) {
                $photoCount = $cat->getMedia('gallery')->count();
                $activeRooms = $cat->rooms->where('status', RoomStatus::Active)->count();
                $inactiveRooms = $cat->rooms->where('status', RoomStatus::Inactive)->count();

                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'type' => $cat->type,
                    'status' => $cat->status->value,
                    'active_rooms' => $activeRooms,
                    'inactive_rooms' => $inactiveRooms,
                    'total_rooms' => $activeRooms + $inactiveRooms,
                    'photos' => $photoCount,
                    'base_rate' => (int) $cat->base_rate,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        // Issues — actionable list the operator can fix.
        $issues = [];
        foreach ($byCategory as $row) {
            if ($row['photos'] === 0) {
                $issues[] = [
                    'kind' => 'no_photos',
                    'label' => $row['name'].' has no photos',
                    'detail' => 'Public booking page falls back to a placeholder until photos are uploaded.',
                    'category_id' => $row['id'],
                ];
            }
            if ($row['active_rooms'] === 0 && $row['total_rooms'] > 0) {
                $issues[] = [
                    'kind' => 'no_active_rooms',
                    'label' => $row['name'].' has no active rooms',
                    'detail' => 'Activate at least one room or hide the category to remove it from the booking engine.',
                    'category_id' => $row['id'],
                ];
            }
            if ($row['active_rooms'] === 0 && $row['total_rooms'] === 0) {
                $issues[] = [
                    'kind' => 'empty_category',
                    'label' => $row['name'].' has no rooms',
                    'detail' => 'Add rooms to this category or delete the category if it\'s no longer offered.',
                    'category_id' => $row['id'],
                ];
            }
        }

        return [
            'rooms' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
            ],
            'categories' => [
                'total' => $categoryCount,
                'active' => $activeCategoryCount,
            ],
            'byCategory' => $byCategory,
            'issues' => $issues,
        ];
    }

    /**
     * Statuses that contribute to revenue. Pending / OnHold are still
     * tentative (Paystack hasn't confirmed for Pending; OnHold has
     * deposit-only); Cancelled and NoShow obviously don't count.
     *
     * @return list<string>
     */
    private function revenueStatuses(): array
    {
        return [
            BookingStatus::Confirmed->value,
            BookingStatus::CheckedIn->value,
            BookingStatus::CheckedOut->value,
        ];
    }

    private function deltaPercent(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
