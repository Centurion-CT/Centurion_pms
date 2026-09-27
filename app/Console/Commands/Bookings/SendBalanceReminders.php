<?php

declare(strict_types=1);

namespace App\Console\Commands\Bookings;

use App\Enums\BookingStatus;
use App\Mail\Bookings\BalanceReminderMail;
use App\Models\Booking;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Mails the guest at 7 / 3 / 1 days before `balance_due_at` for every OnHold
 * booking still carrying a balance (PRD §6.4.3).
 *
 * Idempotent via `bookings.balance_reminders_sent` JSON — the same pattern
 * subscriptions use for renewal reminders. Designed for a daily cron.
 */
class SendBalanceReminders extends Command
{
    protected $signature = 'bookings:send-balance-reminders';

    protected $description = 'Email guests with outstanding balances at 7/3/1 days before the deadline';

    /** @var list<int> */
    private const MILESTONES = [7, 3, 1];

    public function handle(): int
    {
        $today = Carbon::today();
        $sent = 0;

        Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_status', BookingStatus::OnHold->value)
            ->where('balance_due', '>', 0)
            ->whereNotNull('balance_due_at')
            ->cursor()
            ->each(function (Booking $b) use (&$sent, $today): void {
                $deadline = $b->balance_due_at?->copy()->startOfDay();
                if ($deadline === null) {
                    return;
                }

                $already = $b->balance_reminders_sent ?? [];

                foreach (self::MILESTONES as $days) {
                    $sendOn = $deadline->copy()->subDays($days);
                    if (! $today->isSameDay($sendOn)) {
                        continue;
                    }
                    if (in_array($days, $already, true)) {
                        continue;
                    }

                    Mail::to($b->guest_email)->queue(new BalanceReminderMail($b, $days));

                    $already[] = $days;
                    $b->forceFill(['balance_reminders_sent' => $already])->save();
                    $sent++;
                }
            });

        $this->components->info("Balance reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
