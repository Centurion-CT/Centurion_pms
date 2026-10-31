<?php

declare(strict_types=1);

namespace App\Site;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The dates and party a guest is searching for, read from the query
 * string (`in`, `out`, `adults`) and corrected to something bookable:
 * check-in today or later, at least one night, 1 to 12 adults.
 */
final class Stay
{
    public const DEFAULT_NIGHTS = 2;

    public const MAX_ADULTS = 12;

    public function __construct(
        public readonly Carbon $checkin,
        public readonly Carbon $checkout,
        public readonly int $adults,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::from((string) $request->query('in', ''), (string) $request->query('out', ''), (int) $request->query('adults', 2));
    }

    public static function from(string $in, string $out, int $adults): self
    {
        $today = Carbon::today();
        $checkin = self::date($in) ?? $today->copy();
        if ($checkin->lt($today)) {
            $checkin = $today->copy();
        }
        $checkout = self::date($out);
        if ($checkout === null || $checkout->lte($checkin)) {
            $checkout = $checkin->copy()->addDays(self::DEFAULT_NIGHTS);
        }

        return new self($checkin, $checkout, max(1, min(self::MAX_ADULTS, $adults)));
    }

    public function nights(): int
    {
        return (int) $this->checkin->diffInDays($this->checkout, absolute: true);
    }

    /** @return list<string> Y-m-d for each night of the stay */
    public function dates(): array
    {
        $dates = [];
        for ($d = $this->checkin->copy(); $d->lt($this->checkout); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    /** @return array{in: string, out: string, adults: int} */
    public function query(): array
    {
        return ['in' => $this->checkin->toDateString(), 'out' => $this->checkout->toDateString(), 'adults' => $this->adults];
    }

    /** "Fri 6 – Mon 9 Nov" */
    public function label(): string
    {
        $sameMonth = $this->checkin->month === $this->checkout->month;

        return $this->checkin->format($sameMonth ? 'D j' : 'D j M').' – '.$this->checkout->format('D j M');
    }

    /** "13:00" → "1pm", "13:30" → "1:30pm"; null for an unset time. */
    public static function time(?string $hhmm): ?string
    {
        if ($hhmm === null || ! preg_match('/^\d{1,2}:\d{2}$/', $hhmm)) {
            return null;
        }
        $t = Carbon::createFromFormat('H:i', $hhmm);

        return $t->format($t->minute === 0 ? 'ga' : 'g:ia');
    }

    private static function date(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }
    }
}
