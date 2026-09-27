<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Illuminate\Support\Carbon;

/**
 * One night of stay in a pricing breakdown — the date plus the rate that
 * applies (either the room's base_rate or an active RoomPrice override).
 *
 * The `source` field lets the UI tag which nights came from a seasonal
 * override without re-querying.
 */
final readonly class NightlyRate
{
    public function __construct(
        public Carbon $date,
        public int $rate,
        /** 'base' | 'override' */
        public string $source,
        public ?string $overrideLabel = null,
    ) {}
}
