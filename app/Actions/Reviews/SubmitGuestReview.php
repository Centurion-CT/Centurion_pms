<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Review;
use RuntimeException;

/**
 * Records a guest-submitted review from the portal.
 *
 *  - Only allowed after check-out — pre-stay reviews are noise.
 *  - One per booking — enforced both here and via the DB unique index.
 *  - Auto-published — matches OTA convention; operator can hide via
 *    the dashboard if needed.
 *  - Tagged with the booking's `room_id` so it counts toward that
 *    room's per-room rating + carries a "Verified stay" badge.
 */
class SubmitGuestReview
{
    public function handle(Booking $booking, int $rating, string $body): Review
    {
        if ($booking->booking_status !== BookingStatus::CheckedOut) {
            throw new RuntimeException(
                'Reviews can only be submitted after check-out.',
            );
        }

        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException('Rating must be between 1 and 5.');
        }

        $trimmed = trim($body);
        if ($trimmed === '') {
            throw new RuntimeException('Review body cannot be empty.');
        }

        if ($booking->review()->exists()) {
            throw new RuntimeException(
                'A review has already been submitted for this booking.',
            );
        }

        return Review::query()->create([
            'tenant_id' => $booking->tenant_id,
            'property_id' => $booking->room->property_id,
            'room_id' => $booking->room_id,
            'booking_id' => $booking->id,
            'guest_name' => $booking->guest_name,
            'body' => $trimmed,
            'rating' => $rating,
            'is_displayed' => true,
            'order_column' => 0,
        ]);
    }
}
