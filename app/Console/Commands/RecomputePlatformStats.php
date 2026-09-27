<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PlatformStats;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recomputes the platform-wide payment aggregate that feeds the
 * marketing kobo counter. Bypasses TenantScope so a single SUM query
 * sees every tenant's successful payments, not just the caller's.
 *
 * Scheduled every 30 minutes via routes/console.php + also invoked
 * from DatabaseSeeder so a fresh install starts with a real number.
 * Cheap by design (two SUM queries, no per-row iteration) so it stays
 * fast even when the payments table crosses 10 million rows.
 */
class RecomputePlatformStats extends Command
{
    protected $signature = 'keverna:recompute-platform-stats
                            {--quiet-if-fresh : skip if computed_at is within the last 5 minutes}';

    protected $description = 'Recomputes platform_stats singleton from successful Payment rows across every tenant.';

    public function handle(): int
    {
        if ($this->option('quiet-if-fresh')) {
            $existing = PlatformStats::singleton();
            $freshFor = 5 * 60; // seconds
            if ($existing->computed_at !== null
                && $existing->computed_at->diffInSeconds(Carbon::now()) < $freshFor) {
                return self::SUCCESS;
            }
        }

        // Two SUM aggregates against the payments table with
        // TenantScope disabled. Both use the same index (status +
        // paid_at) if one exists; on a large payments table these are
        // still both O(n) scans but they're one number each, not
        // per-row work.
        $total = (int) Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('status', PaymentStatus::Succeeded->value)
            ->sum('amount');

        $last24h = (int) Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('status', PaymentStatus::Succeeded->value)
            ->where('paid_at', '>=', Carbon::now()->subDay())
            ->sum('amount');

        PlatformStats::record($total, $last24h);

        $this->info(sprintf(
            'Platform stats recomputed: total ₦%s · last-24h ₦%s',
            number_format($total),
            number_format($last24h),
        ));

        return self::SUCCESS;
    }
}
