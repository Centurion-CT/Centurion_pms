<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomInspection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Room tiles for the host app Rooms board (docs/host-app/PHASE-3.md, 18)
 * and the rooms group of host search. Reads the same signals the web
 * Housekeeping board and the check-in readiness guard use:
 *
 *  housekeeping  out_of_order  room out of order, or the latest inspection
 *                              is Failed (manager flag / rejected clean,
 *                              which blocks check-in)
 *                dirty         room marked dirty, or an inspection is open
 *                              and the housekeeper has not submitted yet
 *                clean | inspected  the room's housekeeping status otherwise
 *  occupancy     occupied      a guest is checked in (incl. overdue)
 *                arriving      a booking checks in today (not yet in)
 *                free
 *  tile_state    out_of_order > occupied > dirty > arriving > ready
 */
class RoomBoard
{
    public const TILE_STATES = ['ready', 'dirty', 'occupied', 'arriving', 'out_of_order'];

    private const WAITING_STATUSES = [
        BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold,
    ];

    /**
     * Tiles for the branch's active rooms (or the given subset), in
     * board order (category, then number).
     *
     * @param  Collection<int, Room>|null  $rooms
     * @return list<array<string, mixed>>
     */
    public static function tiles(Property $property, ?Collection $rooms = null): array
    {
        $rooms ??= Room::query()
            ->where('property_id', $property->id)
            ->where('status', RoomStatus::Active->value)
            ->orderBy('room_category_id')
            ->orderBy('room_number')
            ->get();
        $rooms->loadMissing('category:id,name');
        $roomIds = $rooms->pluck('id')->all();
        if ($roomIds === []) {
            return [];
        }

        $today = Carbon::today();
        $todayIso = $today->toDateString();

        $latestIds = RoomInspection::query()
            ->whereIn('room_id', $roomIds)
            ->selectRaw('room_id, MAX(id) as max_id')
            ->groupBy('room_id')
            ->pluck('max_id', 'room_id');
        $latest = RoomInspection::query()
            ->whereIn('id', $latestIds->values()->all())
            ->get()
            ->keyBy('room_id');

        $cleanerIds = $latest->pluck('housekeeper_by_operator_id')->filter()->unique()->all();
        $cleaners = $cleanerIds === [] ? collect() : Operator::query()->whereIn('id', $cleanerIds)->pluck('name', 'id');

        $occupants = Booking::query()
            ->whereIn('room_id', $roomIds)
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->orderBy('checkin_date')
            ->get(['id', 'room_id', 'booking_ref', 'guest_name', 'checkout_date'])
            ->keyBy('room_id');

        // Next arrival per room (today or later), earliest first.
        $arrivals = Booking::query()
            ->whereIn('room_id', $roomIds)
            ->whereIn('booking_status', array_map(fn (BookingStatus $s) => $s->value, self::WAITING_STATUSES))
            ->whereDate('checkin_date', '>=', $todayIso)
            ->orderBy('checkin_date')
            ->orderBy('id')
            ->get(['id', 'room_id', 'booking_ref', 'guest_name', 'checkin_date'])
            ->groupBy('room_id')
            ->map(fn (Collection $g) => $g->first());

        $tiles = [];
        foreach ($rooms as $room) {
            $ins = $latest->get($room->id);
            $open = $ins !== null && ! in_array($ins->status, [RoomInspectionStatus::Passed, RoomInspectionStatus::Failed], true);
            $flagged = $ins?->status === RoomInspectionStatus::Failed;

            $housekeeping = match (true) {
                $flagged, $room->housekeeping_status === HousekeepingStatus::OutOfOrder => 'out_of_order',
                $room->housekeeping_status === HousekeepingStatus::Dirty,
                $open && ! $ins->status->unlocksCheckin() => 'dirty',
                default => ($room->housekeeping_status ?? HousekeepingStatus::Clean)->value,
            };

            $occupant = $occupants->get($room->id);
            $next = $arrivals->get($room->id);
            $arrivingToday = $next !== null && $next->checkin_date->toDateString() === $todayIso;
            $occupancy = match (true) {
                $occupant !== null => 'occupied',
                $arrivingToday => 'arriving',
                default => 'free',
            };

            $tile = match (true) {
                $housekeeping === 'out_of_order' => 'out_of_order',
                $occupancy === 'occupied' => 'occupied',
                $housekeeping === 'dirty' => 'dirty',
                $occupancy === 'arriving' => 'arriving',
                default => 'ready',
            };

            $cleanerId = $open ? $ins->housekeeper_by_operator_id : null;

            $tiles[] = [
                'id' => (int) $room->id,
                'number' => (string) $room->room_number,
                'category' => $room->category?->name,
                'floor' => null,
                'housekeeping' => $housekeeping,
                'occupancy' => $occupancy,
                'tile_state' => $tile,
                'guest' => $occupant !== null ? [
                    'ref' => (string) $occupant->booking_ref,
                    'name' => (string) $occupant->guest_name,
                    'checkout' => $occupant->checkout_date?->toDateString(),
                    'overdue' => $occupant->checkout_date !== null && $occupant->checkout_date->toDateString() < $todayIso,
                ] : null,
                'next_arrival' => $next !== null ? [
                    'ref' => (string) $next->booking_ref,
                    'name' => (string) $next->guest_name,
                    'time_label' => self::dayLabel($next->checkin_date, $today),
                ] : null,
                'dirty_since' => $housekeeping === 'dirty'
                    ? (($open ? $ins->created_at : null) ?? $room->updated_at)?->toIso8601String()
                    : null,
                'cleaner' => $cleanerId !== null ? [
                    'id' => (int) $cleanerId,
                    'name' => (string) ($cleaners[$cleanerId] ?? ''),
                ] : null,
                'open_inspection_id' => $open ? (int) $ins->id : null,
            ];
        }

        return $tiles;
    }

    /**
     * @param  list<array<string, mixed>>  $tiles
     * @return array{ready: int, dirty: int, occupied: int, arriving: int, out_of_order: int, total: int}
     */
    public static function counts(array $tiles): array
    {
        $counts = array_fill_keys(self::TILE_STATES, 0);
        foreach ($tiles as $t) {
            $counts[$t['tile_state']]++;
        }

        return [
            'ready' => $counts['ready'],
            'dirty' => $counts['dirty'],
            'occupied' => $counts['occupied'],
            'arriving' => $counts['arriving'],
            'out_of_order' => $counts['out_of_order'],
            'total' => count($tiles),
        ];
    }

    private static function dayLabel(Carbon $date, Carbon $today): string
    {
        $d = $date->copy()->startOfDay();

        return match (true) {
            $d->equalTo($today) => 'Today',
            $d->equalTo($today->copy()->addDay()) => 'Tomorrow',
            default => $d->format('D j M'),
        };
    }
}
