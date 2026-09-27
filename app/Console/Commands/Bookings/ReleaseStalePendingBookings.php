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
 * Cleans up Pending bookings whose Paystack flow never completed (PRD §11):
 * the guest closed the tab on the checkout page, the webhook never arrived,
 * etc. After 15 minutes in Pending we cancel the booking + delete the
 * Booked AvailabilityBlock rows so the inventory frees up.
 *
 * Idempotent. Designed to run every minute via the scheduler.
 */
class ReleaseStalePendingBookings extends Command
{
    protected $signature = 'bookings:release-stale-pending';

    protected $description = 'Cancel Pending bookings older than 15 minutes and release their nights';

    private const STALE_AFTER_MINUTES = 15;

    public function handle(): int
    {
        $cutoff = Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES);
        $released = 0;

        Booking::query()
            ->withoutGlobalScope(TenantScope::class) // central cron, no tenant context
            ->where('booking_status', BookingStatus::Pending->value)
            ->where('created_at', '<', $cutoff)
            ->cursor()
            ->each(function (Booking $booking) use (&$released): void {
                DB::transaction(function () use ($booking): void {
                    AvailabilityBlock::query()
                        ->withoutGlobalScope(TenantScope::class)
                        ->where('booking_id', $booking->id)
                        ->where('type', AvailabilityBlockType::Booked->value)
                        ->delete();

                    $booking->forceFill(['booking_status' => BookingStatus::Cancelled])->save();
                });

                $released++;
            });

        $this->components->info("Released {$released} stale Pending booking(s).");

        return self::SUCCESS;
    }
}
