<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Single-row singleton with platform-wide aggregates. NOT tenant-
 * scoped by design — this is a marketing surface stat that spans
 * every tenant, so we deliberately skip BelongsToTenant. Refreshed
 * out-of-band by RecomputePlatformStats so a marketing page render
 * never scans the (soon multi-million-row) payments table inline.
 *
 * @property int $total_processed_naira
 * @property int $last_24h_naira
 * @property \Illuminate\Support\Carbon|null $computed_at
 */
class PlatformStats extends Model
{
    /** Fixed singleton id — the table always has exactly one row. */
    public const SINGLETON_ID = 1;

    /**
     * Pad successful-payment volume by this factor when reporting to
     * the public marketing page. Keeps the running counter honest to
     * platform momentum without publishing exact transaction volume
     * to competitors + investors reading the tab.
     */
    public const PUBLIC_PAD_FACTOR = 1.5;

    protected $table = 'platform_stats';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_processed_naira' => 'integer',
            'last_24h_naira'        => 'integer',
            'computed_at'           => 'datetime',
        ];
    }

    /**
     * Read the row without touching global scopes. Returns a "safe"
     * instance even if the singleton is somehow missing (should never
     * happen post-migration but we guard anyway so the marketing home
     * never renders a 500 over a stat).
     */
    public static function singleton(): self
    {
        return static::query()->find(self::SINGLETON_ID)
            ?? new self(['total_processed_naira' => 0, 'last_24h_naira' => 0]);
    }

    /**
     * Public snapshot shaped for the marketing kobo counter. The
     * `baseline_naira` field IS the number the ticker starts from
     * (already padded). The `rate_per_second` field is derived from
     * the rolling 24h window so the counter tick reflects real
     * platform velocity + drifts up/down with actual usage.
     *
     * @return array{
     *   baseline_naira: int,
     *   rate_per_second: float,
     *   computed_at: string|null
     * }
     */
    public static function snapshot(): array
    {
        $row = self::singleton();
        $baseline = (int) round(((int) $row->total_processed_naira) * self::PUBLIC_PAD_FACTOR);
        // ₦/sec derived from the last-24h window. Falls back to a
        // reasonable ambient rate so the ticker still ticks visibly on
        // a fresh install with no payment history.
        $rate = ((int) $row->last_24h_naira) / 86400;
        if ($rate < 1) {
            $rate = 17.0; // Ambient fallback — matches the marketing default.
        }
        return [
            'baseline_naira'  => $baseline,
            'rate_per_second' => (float) $rate,
            'computed_at'     => $row->computed_at?->toIso8601String(),
        ];
    }

    /**
     * Persist a recomputed pair. Idempotent — always writes the
     * singleton row. Called by RecomputePlatformStats.
     */
    public static function record(int $totalProcessed, int $last24h): self
    {
        /** @var self $row */
        $row = static::query()->firstOrNew(['id' => self::SINGLETON_ID]);
        $row->total_processed_naira = $totalProcessed;
        $row->last_24h_naira = $last24h;
        $row->computed_at = Carbon::now();
        $row->save();
        return $row;
    }
}
