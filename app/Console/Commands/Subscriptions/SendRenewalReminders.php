<?php

declare(strict_types=1);

namespace App\Console\Commands\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Mail\Subscriptions\RenewalReminderMail;
use App\Models\Subscription;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Daily command that mails renewal reminders at the milestones defined in
 * config('keverna.subscription.renewal_reminder_days') — PRD §4.2 specifies
 * 30, 14, 7, and 1 days before expiry.
 *
 * Idempotent: each subscription tracks which milestones were already mailed
 * in its `reminders_sent` JSON column, so re-running the command (e.g. after
 * a failure) won't double-send.
 */
class SendRenewalReminders extends Command
{
    protected $signature = 'subscriptions:send-renewal-reminders';

    protected $description = 'Send renewal reminders to operators whose subscription is nearing expiry';

    public function handle(): int
    {
        /** @var list<int> $milestones */
        $milestones = (array) config('keverna.subscription.renewal_reminder_days', [30, 14, 7, 1]);
        $today = Carbon::today();

        $live = [SubscriptionStatus::Trial->value, SubscriptionStatus::Active->value];

        $sent = 0;

        Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('status', $live)
            ->where('expires_at', '>=', $today)
            ->cursor()
            ->each(function (Subscription $sub) use (&$sent, $milestones, $today): void {
                $already = $sub->reminders_sent ?? [];

                foreach ($milestones as $days) {
                    $sendOn = $sub->expires_at->copy()->subDays($days)->startOfDay();
                    if (! $today->isSameDay($sendOn)) {
                        continue;
                    }

                    if (in_array($days, $already, true)) {
                        continue;
                    }

                    $tenant = $sub->tenant()->first();
                    if ($tenant === null) {
                        continue;
                    }

                    Mail::to($tenant->email)->queue(new RenewalReminderMail($sub, $days));

                    $already[] = $days;
                    $sub->forceFill(['reminders_sent' => $already])->save();
                    $sent++;
                }
            });

        $this->components->info("Renewal reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
