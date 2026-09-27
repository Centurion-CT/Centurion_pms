<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * Forward-looking revenue projection for /dashboard/finance.
 *
 * Method: on-books + seasonal-naive residual.
 *
 * For each forward day D in [today+1 .. today+horizon]:
 *
 *   on_books   = SUM(bookings.total_amount) on Confirmed/CheckedIn
 *                bookings whose checkin_date = D. Deterministic.
 *   historical = trailing-4-weeks average of same-weekday revenue
 *                (D-7, D-14, D-21, D-28, restricted to Confirmed/
 *                CheckedIn/CheckedOut so cancellations/no-shows don't
 *                skew the trend).
 *   projected  = max(0, historical - on_books). The residual we still
 *                expect based on past behaviour — walk-ins, last-minute
 *                OTA bookings, guests who haven't paid yet.
 *   is_estimate = projected > 0
 *
 * Total forecast = on_books + projected. When on_books already exceeds
 * the historical average, we trust the concrete number and stop
 * projecting on top of it — over-projecting a fully-booked night
 * would be worse than under-projecting.
 *
 * Why weekday-of-week and not month-of-year?
 * ------------------------------------------
 * Hospitality is dominated by weekly seasonality (weekends busier than
 * weekdays) at the timescales operators plan against (30/60/90 days).
 * Annual seasonality matters too but requires a year+ of data to fit
 * cleanly; four weeks is the shortest reliable window.
 */
class RevenueForecast
{
    private const LOOKBACK_WEEKS = 4;

    /**
     * @return list<array{date: string, on_books: int, projected: int, is_estimate: bool}>
     */
    public function project(int $horizonDays, ?Carbon $anchor = null): array
    {
        $horizonDays = max(1, min(90, $horizonDays));
        $today = ($anchor ?? Carbon::today())->copy()->startOfDay();

        $windowStart = $today->copy()->addDay();
        $windowEnd   = $today->copy()->addDays($horizonDays);

        $lookbackStart = $windowStart->copy()->subWeeks(self::LOOKBACK_WEEKS);
        $lookbackEnd   = $windowEnd->copy()->subWeek();

        // One SQL pass each — the loop just indexes into the maps.
        $onBooksByDate = $this->sumByDate(
            [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value],
            $windowStart,
            $windowEnd,
        );

        $historyByDate = $this->sumByDate(
            [
                BookingStatus::Confirmed->value,
                BookingStatus::CheckedIn->value,
                BookingStatus::CheckedOut->value,
            ],
            $lookbackStart,
            $lookbackEnd,
        );

        $out = [];
        $cursor = $windowStart->copy();
        while ($cursor->lessThanOrEqualTo($windowEnd)) {
            $key = $cursor->toDateString();
            $onBooks = (int) ($onBooksByDate[$key] ?? 0);

            $historical = $this->weekdayAverage($cursor, $historyByDate);
            $projected = max(0, $historical - $onBooks);

            $out[] = [
                'date'        => $key,
                'on_books'    => $onBooks,
                'projected'   => $projected,
                'is_estimate' => $projected > 0,
            ];

            $cursor->addDay();
        }

        return $out;
    }

    /**
     * Sum total_amount grouped by checkin_date. Returns [date => amount].
     *
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private function sumByDate(array $statuses, Carbon $from, Carbon $to): array
    {
        return Booking::query()
            ->whereIn('booking_status', $statuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw('DATE(checkin_date) as d, SUM(total_amount) as total')
            ->groupBy('d')
            ->pluck('total', 'd')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Average revenue on the same weekday over the last N=LOOKBACK_WEEKS
     * weeks. Zeroes count — a real "we had no bookings that Tuesday"
     * signal should pull the average down, not be excluded.
     *
     * @param  array<string, int>  $historyByDate
     */
    private function weekdayAverage(Carbon $day, array $historyByDate): int
    {
        $sum = 0;
        for ($w = 1; $w <= self::LOOKBACK_WEEKS; $w++) {
            $key = $day->copy()->subWeeks($w)->toDateString();
            $sum += (int) ($historyByDate[$key] ?? 0);
        }

        return (int) round($sum / self::LOOKBACK_WEEKS);
    }
}
