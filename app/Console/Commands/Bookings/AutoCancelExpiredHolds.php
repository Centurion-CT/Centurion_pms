<?php

declare(strict_types=1);

namespace App\Console\Commands\Bookings;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cancels OnHold bookings whose balance was never paid by `balance_due_at`
 * (PRD §6.4.3) and releases their Booked nights so the inventory comes back.
 *
 * Only the Booked AvailabilityBlock rows are deleted — any operator manual
 * Blocked rows on the same dates survive. Idempotent; designed for a daily
 * cron alongside SendBalanceReminders.
 *
 * Deposit is NOT refunded — that's the operator's call per their stated
 * cancellation policy and is handled out-of-band via Paystack's dashboard.
 */
class AutoCancelExpiredHolds extends Command
{
    protected $signature = 'bookings:auto-cancel-expired-holds';

    protected $description = 'Cancel OnHold bookings past their balance deadline and release the nights';

    public function handle(): int
    {
        $now = Carbon::now();
        $cancelled = 0;

        Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_status', BookingStatus::OnHold->value)
            ->whereNotNull('balance_due_at')
            ->where('balance_due_at', '<', $now)
            ->cursor()
            ->each(function (Booking $b) use (&$cancelled): void {
                DB::transaction(function () use ($b): void {
                    AvailabilityBlock::query()
                        ->withoutGlobalScope(TenantScope::class)
                        ->where('booking_id', $b->id)
                        ->where('type', AvailabilityBlockType::Booked->value)
                        ->delete();

                    $b->forceFill(['booking_status' => BookingStatus::Cancelled])->save();
                });

                $cancelled++;
            });

        $this->components->info("Auto-cancelled expired holds: {$cancelled}");

        return self::SUCCESS;
    }
}
