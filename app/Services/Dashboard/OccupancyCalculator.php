<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Occupancy % for the trailing N days, computed as:
 *
 *     booked-room-nights / total-room-nights
 *
 * where total-room-nights = active_rooms × days and
 * booked-room-nights is the count of AvailabilityBlock rows with
 * type=Booked falling in [from, to). Only `Booked` blocks count —
 * operator-`Blocked` rows (PRD §6.2.3) are owner-driven and shouldn't
 * inflate the metric.
 *
 * Edge cases:
 *   - No active rooms: returns 0 (avoids div-by-zero, "0%" is the
 *     truthful answer — the operator has nothing to fill).
 *   - No bookings in window: returns 0.
 *   - "Rooms-only" denominator: archived/inactive rooms intentionally
 *     excluded so cleanup work doesn't tank the metric.
 *
 * All queries flow through BelongsToTenant scopes; the result is for
 * whichever tenant is currently bound.
 */
class OccupancyCalculator
{
    public const DEFAULT_WINDOW_DAYS = 30;

    /**
     * @param  int  $days  trailing window length (default 30)
     * @return array{
     *     percent: int,
     *     booked_nights: int,
     *     total_nights: int,
     *     window_from: string,
     *     window_to: string,
     * }
     */
    public function rolling(int $days = self::DEFAULT_WINDOW_DAYS): array
    {
        $today = Carbon::today();
        $from = $today->copy()->subDays($days - 1)->startOfDay();
        $to = $today->copy()->endOfDay();

        $activeRooms = Room::query()->where('status', RoomStatus::Active->value)->count();
        $totalNights = $activeRooms * $days;

        if ($totalNights === 0) {
            return [
                'percent' => 0,
                'booked_nights' => 0,
                'total_nights' => 0,
                'window_from' => $from->toDateString(),
                'window_to' => $today->toDateString(),
            ];
        }

        $bookedNights = AvailabilityBlock::query()
            ->where('type', AvailabilityBlockType::Booked->value)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->whereIn('room_id', Room::query()
                ->where('status', RoomStatus::Active->value)
                ->select('id'))
            ->count();

        // Cap at 100% — defensive (shouldn't happen with the unique constraint,
        // but if a data anomaly slips through we don't want "112%" in the UI).
        $percent = (int) round(min($bookedNights / $totalNights, 1) * 100);

        return [
            'percent' => $percent,
            'booked_nights' => $bookedNights,
            'total_nights' => $totalNights,
            'window_from' => $from->toDateString(),
            'window_to' => $today->toDateString(),
        ];
    }

    /**
     * Rooms occupied per night, the host app's "occupied tonight" rule
     * (host/today, host/properties, every owner screen) applied to any
     * date range so the numbers agree everywhere:
     *
     *   occupied(D) = rooms with a Booked availability block dated D
     *               + rooms whose guest is still checked in and was in
     *                 the room on night D past their booked checkout
     *                 (an overdue guest has no Booked block left),
     *                 counted for D <= today only, and never for a
     *                 guest due out today (they leave before tonight).
     *
     * Only active rooms count (denominator = active rooms now, like
     * {@see rolling()} and the web reports). Category-only holds
     * (no room yet) and operator Blocked rows are not occupancy.
     *
     * Where this differs from the web:
     *  - /dashboard/reports ({@see \App\Services\Reports\ReportSnapshot::occupancy()})
     *    counts Booked blocks only, so an overdue in-house guest is
     *    not counted there; otherwise identical.
     *  - The reservations year heatmap ({@see CalendarHeatmap}) counts
     *    Blocked (maintenance) rooms and category holds as taken: it
     *    answers "can I sell it", not "is it occupied".
     *
     * @param  list<int>|null  $propertyIds  null = whole tenant
     * @return array{
     *     rooms: Collection<int, Room>,
     *     nights: array<string, array<int, true>>,
     * }  rooms keyed by id (id, property_id, room_category_id); nights[date][room_id]
     */
    public function nightly(Carbon $from, Carbon $to, ?array $propertyIds = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $today = Carbon::today();

        $rooms = Room::query()
            ->where('status', RoomStatus::Active->value)
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->get(['id', 'property_id', 'room_category_id'])
            ->keyBy('id');

        $nights = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $nights[$d->toDateString()] = [];
        }
        if ($rooms->isEmpty()) {
            return ['rooms' => $rooms, 'nights' => $nights];
        }
        $roomIds = $rooms->keys()->all();

        $booked = AvailabilityBlock::query()
            ->where('type', AvailabilityBlockType::Booked->value)
            ->whereIn('room_id', $roomIds)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->toBase()
            ->get(['room_id', 'date']);
        foreach ($booked as $row) {
            $key = substr((string) $row->date, 0, 10);
            if (isset($nights[$key])) {
                $nights[$key][(int) $row->room_id] = true;
            }
        }

        // Guests still in the room. For D <= today a checked-in guest
        // occupied their room on every night from check-in; that covers
        // overdue nights past checkout that have no Booked block.
        if ($from->lte($today)) {
            $inRoom = Booking::query()
                ->whereIn('room_id', $roomIds)
                ->where('booking_status', BookingStatus::CheckedIn->value)
                ->whereDate('checkin_date', '<=', $to->copy()->min($today)->toDateString())
                ->get(['id', 'room_id', 'checkin_date', 'checkout_date']);
            foreach ($inRoom as $b) {
                $start = $b->checkin_date->copy()->startOfDay()->max($from);
                $end = $to->copy()->min($today);
                for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                    $dueOutToday = $d->isSameDay($today) && $b->checkout_date->isSameDay($today);
                    if ($dueOutToday) {
                        continue;
                    }
                    $nights[$d->toDateString()][(int) $b->room_id] = true;
                }
            }
        }

        return ['rooms' => $rooms, 'nights' => $nights];
    }

    /**
     * Occupancy % per night for the range, on the {@see nightly()} rule.
     *
     * @param  list<int>|null  $propertyIds
     * @return list<array{date: string, sold: int, total: int, pct: int}>
     */
    public function dailyPercent(Carbon $from, Carbon $to, ?array $propertyIds = null): array
    {
        $data = $this->nightly($from, $to, $propertyIds);
        $total = $data['rooms']->count();

        $out = [];
        foreach ($data['nights'] as $date => $rooms) {
            $sold = min($total, count($rooms));
            $out[] = [
                'date' => $date,
                'sold' => $sold,
                'total' => $total,
                'pct' => $total > 0 ? (int) round($sold / $total * 100) : 0,
            ];
        }

        return $out;
    }

    /**
     * Rooms + rooms occupied tonight, grouped by branch and by room
     * category (the host app's tonight figures).
     *
     * @param  list<int>|null  $propertyIds
     * @return array{
     *     total: int, sold: int,
     *     by_property: array<int, array{total: int, sold: int}>,
     *     by_category: array<int, array{total: int, sold: int}>,
     * }
     */
    public function tonight(?array $propertyIds = null): array
    {
        $today = Carbon::today();
        $data = $this->nightly($today, $today, $propertyIds);
        $occupied = $data['nights'][$today->toDateString()] ?? [];

        $byProperty = [];
        $byCategory = [];
        foreach ($data['rooms'] as $id => $room) {
            $pid = (int) $room->property_id;
            $cid = (int) $room->room_category_id;
            $byProperty[$pid] ??= ['total' => 0, 'sold' => 0];
            $byCategory[$cid] ??= ['total' => 0, 'sold' => 0];
            $byProperty[$pid]['total']++;
            $byCategory[$cid]['total']++;
            if (isset($occupied[$id])) {
                $byProperty[$pid]['sold']++;
                $byCategory[$cid]['sold']++;
            }
        }

        return [
            'total' => $data['rooms']->count(),
            'sold' => count(array_intersect_key($occupied, $data['rooms']->all())),
            'by_property' => $byProperty,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Occupancy across a window on the {@see nightly()} rule: occupied
     * room-nights / (active rooms x nights), plus per room category.
     *
     * @param  list<int>|null  $propertyIds
     * @return array{pct: float, sold_nights: int, available_nights: int, by_category: array<int, array{sold_nights: int, available_nights: int, pct: float}>}
     */
    public function window(Carbon $from, Carbon $to, ?array $propertyIds = null): array
    {
        $data = $this->nightly($from, $to, $propertyIds);
        $days = count($data['nights']);

        $sold = 0;
        $byCategory = [];
        foreach ($data['rooms'] as $room) {
            $byCategory[(int) $room->room_category_id] ??= ['sold_nights' => 0, 'available_nights' => 0];
            $byCategory[(int) $room->room_category_id]['available_nights'] += $days;
        }
        foreach ($data['nights'] as $rooms) {
            foreach (array_keys($rooms) as $roomId) {
                $room = $data['rooms']->get($roomId);
                if ($room === null) {
                    continue;
                }
                $sold++;
                $byCategory[(int) $room->room_category_id]['sold_nights']++;
            }
        }
        foreach ($byCategory as $cid => $row) {
            $byCategory[$cid]['pct'] = $row['available_nights'] > 0
                ? round($row['sold_nights'] / $row['available_nights'] * 100, 1)
                : 0.0;
        }
        $available = $data['rooms']->count() * $days;

        return [
            'pct' => $available > 0 ? round($sold / $available * 100, 1) : 0.0,
            'sold_nights' => $sold,
            'available_nights' => $available,
            'by_category' => $byCategory,
        ];
    }
}
