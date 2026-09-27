<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Room;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Year-view occupancy heatmap. For each day in a range, computes how
 * many active rooms are unavailable (booked OR blocked). One grouped
 * SQL query walks the whole window so a 365-day grid renders cheap.
 *
 * Cells tier into three visual buckets by percentage — the UI colours
 * them accordingly.
 */
class CalendarHeatmap
{
    /**
     * @return array{
     *     days: array<string, array{
     *         date: Carbon, booked:int, total:int, pct:int, tier:string
     *     }>,
     *     total_rooms: int,
     * }
     */
    public function build(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $totalRooms = Room::query()
            ->where('status', RoomStatus::Active->value)
            ->count();

        // Group blocks per day. Booked + Blocked both make a room
        // unavailable; distinct room_id per date protects against
        // duplicate rows.
        $rows = AvailabilityBlock::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->whereIn('room_id', Room::query()
                ->where('status', RoomStatus::Active->value)
                ->select('id'))
            ->select(DB::raw('DATE(date) as day'), DB::raw('COUNT(DISTINCT room_id) as booked'))
            ->groupBy(DB::raw('DATE(date)'))
            ->pluck('booked', 'day');

        // Category-level holds (room_id NULL + room_category_id set)
        // represent inventory taken by unassigned bookings. They
        // don't have a room_id yet, so the distinct-room_id count
        // above misses them entirely - the heatmap would under-
        // report occupancy for tenants using category-only
        // reservations. Count each row once (one booking = one
        // held slot per day) since a category-only booking never
        // generates more than one block per date.
        $categoryRows = AvailabilityBlock::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->whereNull('room_id')
            ->whereNotNull('room_category_id')
            ->select(DB::raw('DATE(date) as day'), DB::raw('COUNT(*) as booked'))
            ->groupBy(DB::raw('DATE(date)'))
            ->pluck('booked', 'day');

        $days = [];
        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $iso = $cursor->toDateString();
            $booked = (int) ($rows[$iso] ?? 0) + (int) ($categoryRows[$iso] ?? 0);
            $pct = $totalRooms > 0
                ? (int) round(min($booked / $totalRooms, 1) * 100)
                : 0;

            $days[$iso] = [
                'date'   => $cursor->copy(),
                'booked' => $booked,
                'total'  => $totalRooms,
                'pct'    => $pct,
                'tier'   => $this->tier($pct),
            ];
            $cursor->addDay();
        }

        return ['days' => $days, 'total_rooms' => $totalRooms];
    }

    private function tier(int $pct): string
    {
        if ($pct === 0) {
            return 'free';       // green
        }
        if ($pct < 80) {
            return 'partial';    // amber
        }

        return 'full';           // red
    }
}
