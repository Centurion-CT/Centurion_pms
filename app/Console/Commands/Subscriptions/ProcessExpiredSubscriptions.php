<?php

declare(strict_types=1);

namespace App\Console\Commands\Subscriptions;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily command that advances tenants through the post-expiry state machine
 * (PRD §4.2):
 *
 *   active/trial  --(expired)-->     grace  (3 days)
 *   grace         --(timeout)-->     locked
 *   locked        --(timeout)-->     archived
 *
 * The state is derived purely from `tenant.subscription_expires_at` and
 * `tenant.status` — no separate grace_entered_at column needed. Idempotent:
 * re-running the command on the same day won't change any state if all
 * thresholds were already passed.
 */
class ProcessExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:process-expired';

    protected $description = 'Move expired tenants through grace -> locked -> archived';

    public function handle(): int
    {
        $now = Carbon::now();
        $graceDays = (int) config('keverna.subscription.grace_period_days');
        $lockToArchiveDays = (int) config('keverna.subscription.lock_to_archive_days');

        $movedToGrace = 0;
        $movedToLocked = 0;
        $movedToArchived = 0;

        // active|trial -> grace when expiry has passed
        Tenant::query()
            ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Trial->value])
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '<', $now)
            ->cursor()
            ->each(function (Tenant $t) use (&$movedToGrace): void {
                $t->forceFill(['status' => TenantStatus::Grace])->save();
                $movedToGrace++;
            });

        // grace -> locked when expires_at + grace_days has passed
        Tenant::query()
            ->where('status', TenantStatus::Grace->value)
            ->whereNotNull('subscription_expires_at')
            ->cursor()
            ->each(function (Tenant $t) use (&$movedToLocked, $now, $graceDays): void {
                $expires = $t->subscription_expires_at;
                if ($expires === null) {
                    return;
                }
                if ($now->greaterThan($expires->copy()->addDays($graceDays))) {
                    $t->forceFill(['status' => TenantStatus::Locked])->save();
                    $movedToLocked++;
                }
            });

        // locked -> archived when expires_at + grace_days + lock_days has passed
        Tenant::query()
            ->where('status', TenantStatus::Locked->value)
            ->whereNotNull('subscription_expires_at')
            ->cursor()
            ->each(function (Tenant $t) use (&$movedToArchived, $now, $graceDays, $lockToArchiveDays): void {
                $expires = $t->subscription_expires_at;
                if ($expires === null) {
                    return;
                }
                if ($now->greaterThan($expires->copy()->addDays($graceDays + $lockToArchiveDays))) {
                    $t->forceFill(['status' => TenantStatus::Archived])->save();
                    $movedToArchived++;
                }
            });

        $this->components->info(
            "Transitions — grace: {$movedToGrace}, locked: {$movedToLocked}, archived: {$movedToArchived}",
        );

        return self::SUCCESS;
    }
}
