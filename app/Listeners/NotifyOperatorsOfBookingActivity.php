<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\Operator;
use App\Multitenancy\TenantScope;
use App\Notifications\BookingActivityNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Fans booking-lifecycle events into one in-dashboard notification per
 * operator on the tenant (PRD §17). The bell icon Livewire reads from
 * the same `notifications` table without any extra plumbing.
 *
 * Operators are looked up with TenantScope LIFTED, then filtered by
 * tenant_id explicitly — the event may fire on a queue worker where
 * the tenant context isn't bound yet.
 */
class NotifyOperatorsOfBookingActivity
{
    public function handleConfirmed(BookingConfirmed $event): void
    {
        $b = $event->booking;
        $this->notify(
            $b,
            event: 'confirmed',
            title: 'New confirmed booking',
            message: "{$b->guest_name} confirmed {$b->booking_ref}.",
        );
    }

    public function handleCancelled(BookingCancelled $event): void
    {
        $b = $event->booking;
        $this->notify(
            $b,
            event: 'cancelled',
            title: 'Booking cancelled',
            message: "{$b->guest_name} cancelled {$b->booking_ref}.",
        );
    }

    private function notify(Booking $booking, string $event, string $title, string $message): void
    {
        $operators = Operator::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $booking->tenant_id)
            ->get();

        if ($operators->isEmpty()) {
            return;
        }

        Notification::send(
            $operators,
            new BookingActivityNotification($booking, $event, $title, $message),
        );
    }
}
