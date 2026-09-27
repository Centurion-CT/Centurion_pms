<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape a Booking into the compact payload the dashboard search
 * cache stores in IndexedDB. Pre-lowered / pre-normalised search
 * fields so the client can filter without extra work on every
 * keystroke.
 *
 * @property Booking $resource
 */
class SearchCacheBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $booking = $this->resource;

        return [
            'id'                     => (int) $booking->id,
            'booking_ref'            => (string) $booking->booking_ref,
            'guest_name'             => (string) $booking->guest_name,
            'guest_name_lc'          => mb_strtolower((string) $booking->guest_name),
            'guest_email_lc'         => $booking->guest_email
                ? mb_strtolower((string) $booking->guest_email)
                : null,
            'guest_phone_normalised' => $booking->guest_phone
                ? preg_replace('/\D+/', '', (string) $booking->guest_phone)
                : null,
            'room_name'              => $booking->room?->name,
            'property_name'          => $booking->room?->property?->name,
            'checkin_date'           => $booking->checkin_date?->toDateString(),
            'checkout_date'          => $booking->checkout_date?->toDateString(),
            'status'                 => $booking->booking_status?->value,
            'updated_at'             => $booking->updated_at?->toIso8601String(),
            'tenant_id'              => (int) $booking->tenant_id,
            'property_id'            => $booking->room?->property_id,
        ];
    }
}
