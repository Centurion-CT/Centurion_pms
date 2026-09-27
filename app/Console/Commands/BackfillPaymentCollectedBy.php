<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Operator;
use App\Models\Payment;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;

/**
 * Backfill payments.collected_by_operator_id from the activity_log
 * audit trail. RecordManualPayment used to only log the operator via
 * `activity('booking')->causedBy($operator)` with the Payment id in
 * the properties JSON; forward-going we stamp collected_by directly,
 * but the historical rows need this one-shot to become useful for
 * the payments-page sort / filter / export by staff.
 *
 * Safe to re-run: skips any Payment that already has a value, and
 * only writes when the causer resolves to an Operator on the same
 * tenant (so a deleted operator + reused id can't cross-pollinate).
 *
 * Usage:
 *   php artisan payments:backfill-collected-by
 *   php artisan payments:backfill-collected-by --tenant=42
 *   php artisan payments:backfill-collected-by --dry-run
 */
class BackfillPaymentCollectedBy extends Command
{
    protected $signature = 'payments:backfill-collected-by
        {--tenant= : limit to a single tenant id}
        {--dry-run : count and report but do not write}';

    protected $description = 'Populate payments.collected_by_operator_id from the activity_log causer.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $paymentsQuery = Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNull('collected_by_operator_id');
        if ($tenantId !== null) {
            $paymentsQuery->where('tenant_id', $tenantId);
        }

        $total = (clone $paymentsQuery)->count();
        if ($total === 0) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d payment%s with null collected_by. Scanning activity_log for a manual_payment_recorded entry per row%s.',
            $total,
            $total === 1 ? '' : 's',
            $dryRun ? ' [dry-run]' : '',
        ));

        $matched = 0;
        $unmatched = 0;
        $mismatched = 0;

        $paymentsQuery->orderBy('id')->chunkById(200, function ($chunk) use (&$matched, &$unmatched, &$mismatched, $dryRun): void {
            foreach ($chunk as $payment) {
                $activity = Activity::query()
                    ->withoutGlobalScopes()
                    ->where('log_name', 'booking')
                    ->where('event', 'manual_payment_recorded')
                    ->where('tenant_id', $payment->tenant_id)
                    // JSON scan on properties.payment_id — works on both
                    // SQLite (test) and MySQL/Postgres (prod). The
                    // string cast side-steps the driver quirk where
                    // integers are stored as JSON numbers.
                    ->where(function ($q) use ($payment): void {
                        $q->whereJsonContains('properties->payment_id', $payment->id)
                          ->orWhereJsonContains('properties->payment_id', (string) $payment->id);
                    })
                    ->orderBy('id')
                    ->first();

                if ($activity === null) {
                    $unmatched++;
                    continue;
                }

                $operator = null;
                if ($activity->causer_type === Operator::class && $activity->causer_id !== null) {
                    $operator = Operator::query()
                        ->withoutGlobalScopes()
                        ->where('id', $activity->causer_id)
                        ->where('tenant_id', $payment->tenant_id)
                        ->first();
                }

                if ($operator === null) {
                    $mismatched++;
                    continue;
                }

                if (! $dryRun) {
                    $payment->forceFill([
                        'collected_by_operator_id' => $operator->id,
                    ])->save();
                }
                $matched++;
            }
        });

        $this->info(sprintf(
            'Matched: %d · Unmatched (no activity row): %d · Mismatched (causer not an operator on the tenant): %d%s',
            $matched,
            $unmatched,
            $mismatched,
            $dryRun ? '  [DRY RUN — no writes performed]' : '',
        ));

        return self::SUCCESS;
    }
}
