<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Operator;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Generic in-dashboard notification for booking-lifecycle events
 * (PRD §17 bell icon). Persisted in the `notifications` table so the bell
 * Livewire can paginate without holding any in-memory queue.
 *
 * The `event` discriminator (`'confirmed' | 'cancelled' | …`) lets the bell
 * pick an icon + colour without parsing the message text.
 */
class BookingActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $event,
        public readonly string $title,
        public readonly string $message,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(Operator $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(Operator $notifiable): array
    {
        return [
            'tenant_id' => $this->booking->tenant_id,
            'event' => $this->event,
            'booking_id' => $this->booking->id,
            'booking_ref' => $this->booking->booking_ref,
            'guest_name' => $this->booking->guest_name,
            'title' => $this->title,
            'message' => $this->message,
        ];
    }
}
