<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\AvailabilityBlockType;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Room;
use Illuminate\Support\Carbon;

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
}
