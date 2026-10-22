<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Support\Carbon;

/**
 * Pre-fills a public booking form's dates and guest counts from the
 * room search (`/rooms?in=…&out=…&adults=…&children=…`), so a guest who
 * picked dates there doesn't have to pick them again on the room page.
 *
 * The host component must expose `string $checkin`, `string $checkout`,
 * `int $adults` and `int $children`, and should call this from mount()
 * after setting its own defaults. Anything missing or invalid (past
 * dates, check-out not after check-in, guests outside the room's
 * capacity) is ignored and the defaults stand.
 */
trait SeedsStayFromQuery
{
    protected function seedStayFromQuery(int $maxAdults, int $maxChildren): void
    {
        $query = request()->query();

        try {
            $in = isset($query['in']) && is_string($query['in']) ? Carbon::createFromFormat('Y-m-d', $query['in'])->startOfDay() : null;
            $out = isset($query['out']) && is_string($query['out']) ? Carbon::createFromFormat('Y-m-d', $query['out'])->startOfDay() : null;
        } catch (\Throwable) {
            $in = $out = null;
        }

        if ($in !== null && $out !== null && $in->gte(Carbon::today()) && $out->gt($in)) {
            $this->checkin = $in->toDateString();
            $this->checkout = $out->toDateString();
        }

        $adults = filter_var($query['adults'] ?? null, FILTER_VALIDATE_INT);
        if ($adults !== false && $adults >= 1 && $adults <= max(1, $maxAdults)) {
            $this->adults = $adults;
        }

        $children = filter_var($query['children'] ?? null, FILTER_VALIDATE_INT);
        if ($children !== false && $children >= 0 && $children <= max(0, $maxChildren)) {
            $this->children = $children;
        }
    }
}
