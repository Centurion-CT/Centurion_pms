<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;

/**
 * "Tonight's house": one bar per room, coloured by the room's state
 * tonight, height = share of the next 7 nights booked. Built from a
 * {@see DashboardSnapshot} build, so it agrees
 * with the calendar. Shared by the dashboard home (one branch) and the
 * All branches page (one strip per branch), so a branch shows the same
 * percentage in both places.
 */
final class TonightHouse
{
    public const LABELS = [
        'inhouse' => 'In-house',
        'arriving' => 'Arriving',
        'booked' => 'Reserved',
        'leaving' => 'Leaving today',
        'overdue' => 'Overstayed',
        'dirty' => 'Needs cleaning',
        'blocked' => 'Out of service',
        'free' => 'Free',
    ];

    private const SOLD_TYPES = ['booked', 'pending', 'checkedin', 'due_out', 'overdue'];

    /**
     * @param  array<string, mixed>  $snapshot  DashboardSnapshot::build() / buildFor()
     * @param  array<int, mixed>  $dirtyRoomIds  room ids waiting for cleaning (keys or values)
     * @return array{groups: list<array{name: string, bars: list<array{state: string, height: int, title: string}>}>, counts: array<string, int>, total: int, occupied: int, pct: int}
     */
    public static function from(array $snapshot, array $dirtyRoomIds = []): array
    {
        $heatmap = $snapshot['heatmap'] ?? [];
        $today = $snapshot['today'] ?? [];
        $dates = array_slice($heatmap['dates'] ?? [], 0, 7);
        $todayStr = $dates[0] ?? Carbon::today()->toDateString();
        $blocks = $heatmap['blocks'] ?? [];
        $arriving = collect($today['arrivals'] ?? [])->pluck('room_id')->filter()->flip();
        $dirty = array_flip(array_values(array_filter($dirtyRoomIds)));

        // Checked-in guests count from the booking itself, so a stay whose
        // availability rows are missing still shows as occupied.
        $stays = [];
        foreach (($today['inHouse'] ?? collect()) as $stay) {
            if ($stay->room_id === null || $stay->checkout_date === null) {
                continue;
            }
            $out = $stay->checkout_date->toDateString();
            $stays[$stay->room_id] = [
                'tonight' => $out < $todayStr ? 'overdue' : ($out === $todayStr ? 'due_out' : 'checkedin'),
                'until' => $out,
            ];
        }

        $counts = array_fill_keys(array_keys(self::LABELS), 0);
        $groups = [];
        foreach (($heatmap['grouped'] ?? collect()) as $group) {
            $bars = [];
            foreach ($group->rooms as $room) {
                $tonight = isset($stays[$room->id])
                    ? $stays[$room->id]['tonight']
                    : ($blocks[$room->id][$todayStr] ?? null);
                $state = match (true) {
                    $tonight === 'checkedin' => 'inhouse',
                    $tonight === 'overdue' => 'overdue',
                    $tonight === 'due_out' => 'leaving',
                    isset($arriving[$room->id]) => 'arriving',
                    in_array($tonight, ['booked', 'pending'], true) => 'booked',
                    $tonight === 'blocked' => 'blocked',
                    isset($dirty[$room->id]) => 'dirty',
                    default => 'free',
                };
                $sold = 0;
                foreach ($dates as $d) {
                    if (in_array($blocks[$room->id][$d] ?? null, self::SOLD_TYPES, true)
                        || (isset($stays[$room->id]) && $d < $stays[$room->id]['until'])) {
                        $sold++;
                    }
                }
                $counts[$state]++;
                $bars[] = [
                    'state' => $state,
                    'height' => max(6, (int) round(($sold / max(1, count($dates))) * 100)),
                    'title' => 'Room '.$room->room_number.': '.self::LABELS[$state].', '.$sold.' of '.count($dates).' nights booked',
                ];
            }
            if ($bars !== []) {
                $groups[] = ['name' => (string) $group->name, 'bars' => $bars];
            }
        }

        $total = array_sum($counts);
        $occupied = $counts['inhouse'] + $counts['arriving'] + $counts['booked'] + $counts['overdue'];

        return [
            'groups' => $groups,
            'counts' => $counts,
            'total' => $total,
            'occupied' => $occupied,
            'pct' => $total > 0 ? (int) round(($occupied / $total) * 100) : 0,
        ];
    }
}
