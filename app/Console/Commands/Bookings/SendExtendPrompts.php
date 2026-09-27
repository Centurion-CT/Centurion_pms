<?php

declare(strict_types=1);

namespace App\Console\Commands\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Multitenancy\TenantScope;
use App\Services\GuestPortal\GuestNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily cron: nudges in-house guests whose stay ends tomorrow with
 * a "want to extend?" WhatsApp linking to their room portal.
 *
 * Skip conditions:
 *   - `extend_prompt_sent_at` already stamped (idempotency)
 *   - guest_phone missing (nothing to send to)
 *   - room is booked back-to-back - the guest can't extend it and
 *     the message would set the wrong expectation. Room-change is a
 *     separate desk conversation.
 *
 * Tenant scope lifted intentionally - central cron scans across
 * every tenant + subaccount in one pass.
 */
class SendExtendPrompts extends Command
{
    protected $signature = 'bookings:send-extend-prompts';

    protected $description = 'Nudge in-house guests the day before checkout to extend their stay via the room portal';

    public function handle(GuestNotifier $notifier): int
    {
        $tomorrow = Carbon::tomorrow()->toDateString();
        $sent = 0;
        $skippedBlocked = 0;

        Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with(['room.property.tenant'])
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereDate('checkout_date', $tomorrow)
            ->whereNull('extend_prompt_sent_at')
            ->whereNotNull('guest_phone')
            ->cursor()
            ->each(function (Booking $booking) use ($notifier, &$sent, &$skippedBlocked): void {
                $property = $booking->room?->property;
                $tenant = $property?->tenant;
                if ($property === null || $tenant === null || $property->portal_qr_slug === '') {
                    return;
                }

                // Don't offer an extension when the room is booked
                // back-to-back - sets an expectation we can't meet.
                $max = $booking->maxExtendableCheckoutDate();
                if ($max !== null && $booking->checkout_date !== null
                    && $max->lte($booking->checkout_date)) {
                    $skippedBlocked++;
                    // Still stamp so we don't reconsider it every day.
                    $booking->forceFill(['extend_prompt_sent_at' => Carbon::now()])->save();
                    return;
                }

                $url = 'https://'.$tenant->primaryHost().'/portal/'.$property->portal_qr_slug;

                $notifier->sendExtendPrompt(
                    phone: (string) $booking->guest_phone,
                    guestName: (string) ($booking->guest_name ?: 'guest'),
                    propertyName: (string) $property->name,
                    portalUrl: $url,
                );

                $booking->forceFill(['extend_prompt_sent_at' => Carbon::now()])->save();
                $sent++;
            });

        $this->components->info("Extend prompts sent: {$sent}. Skipped (room blocked): {$skippedBlocked}.");

        return self::SUCCESS;
    }
}
