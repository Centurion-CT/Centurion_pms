<?php

declare(strict_types=1);

namespace App\Console\Commands\Bookings;

use App\Enums\BookingStatus;
use App\Mail\Bookings\CheckInReminderMail;
use App\Models\Booking;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Daily cron: mails the guest 24 hours before checkin (PRD §17).
 *
 * Sends ONLY when `checkin_date == tomorrow` — narrow window avoids the
 * "I booked far in advance and got 200 spammy reminders" antipattern.
 * Idempotency via `bookings.checkin_reminder_sent_at` — once stamped, the
 * row is skipped on every subsequent run.
 *
 * Tenant scope is lifted intentionally; this is a central cron that scans
 * across all tenants in one pass.
 */
class SendCheckInReminders extends Command
{
    protected $signature = 'bookings:send-checkin-reminders';

    protected $description = 'Email guests 24 hours before checkin (Confirmed + OnHold bookings)';

    public function handle(): int
    {
        $tomorrow = Carbon::tomorrow()->toDateString();
        $sent = 0;

        Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::OnHold->value,
            ])
            ->whereDate('checkin_date', $tomorrow)
            ->whereNull('checkin_reminder_sent_at')
            ->cursor()
            ->each(function (Booking $b) use (&$sent): void {
                Mail::to($b->guest_email)->queue(new CheckInReminderMail($b));
                $b->forceFill(['checkin_reminder_sent_at' => Carbon::now()])->save();
                $sent++;
            });

        $this->components->info("Check-in reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
