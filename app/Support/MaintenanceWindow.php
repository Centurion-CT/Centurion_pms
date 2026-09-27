<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Maintenance-window state machine (PRD §19). Three states:
 *
 *   - inactive  → no banner. Window disabled OR we're outside both the
 *     warn-ahead range and the window itself.
 *   - upcoming  → "downtime starts in N hours" banner.
 *   - active    → "in-progress maintenance" banner.
 *
 * Reads from `config('keverna.maintenance_window')`. All time math is in
 * the app timezone — `Africa/Lagos` is IANA's single zone for Nigeria
 * and covers Abuja where the team operates.
 */
final class MaintenanceWindow
{
    public const STATE_INACTIVE = 'inactive';

    public const STATE_UPCOMING = 'upcoming';

    public const STATE_ACTIVE = 'active';

    /**
     * @return array{state: string, start: ?Carbon, end: ?Carbon, hours_until: ?int}
     */
    public static function current(): array
    {
        if (! (bool) config('keverna.maintenance_window.enabled', false)) {
            return self::inactive();
        }

        $startHour = (int) config('keverna.maintenance_window.start_hour', 2);
        $endHour = (int) config('keverna.maintenance_window.end_hour', 4);
        $warnAhead = (int) config('keverna.maintenance_window.warn_hours_before', 2);

        $now = Carbon::now();
        $startToday = $now->copy()->setTime($startHour, 0, 0);
        $endToday = $now->copy()->setTime($endHour, 0, 0);

        // Within today's window.
        if ($now->between($startToday, $endToday, false)) {
            return [
                'state' => self::STATE_ACTIVE,
                'start' => $startToday,
                'end' => $endToday,
                'hours_until' => 0,
            ];
        }

        // Today's window not started yet — check warn-ahead.
        if ($now->lessThan($startToday) && $now->copy()->addHours($warnAhead)->greaterThanOrEqualTo($startToday)) {
            return [
                'state' => self::STATE_UPCOMING,
                'start' => $startToday,
                'end' => $endToday,
                'hours_until' => (int) ceil($now->diffInHours($startToday, absolute: true)),
            ];
        }

        // Tomorrow's window starts within the warn-ahead range (only if
        // start_hour is in the small-hours and we're past today's end).
        $startTomorrow = $startToday->copy()->addDay();
        if ($now->copy()->addHours($warnAhead)->greaterThanOrEqualTo($startTomorrow)) {
            return [
                'state' => self::STATE_UPCOMING,
                'start' => $startTomorrow,
                'end' => $endToday->copy()->addDay(),
                'hours_until' => (int) ceil($now->diffInHours($startTomorrow, absolute: true)),
            ];
        }

        return self::inactive();
    }

    /**
     * @return array{state: string, start: null, end: null, hours_until: null}
     */
    private static function inactive(): array
    {
        return [
            'state' => self::STATE_INACTIVE,
            'start' => null,
            'end' => null,
            'hours_until' => null,
        ];
    }
}
