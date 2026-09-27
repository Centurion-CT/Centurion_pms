<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class ReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $room = $this->room;
        $customer = $this->customer;
        $charges = $this->relationLoaded('charges') ? $this->charges : collect();
        $payments = $this->relationLoaded('payments') ? $this->payments : collect();

        return [
            'id' => $this->id,
            'booking_ref' => $this->booking_ref,
            'guest_name' => $this->guest_name,
            'guest_email' => $this->guest_email,
            'guest_phone' => $this->guest_phone,
            'status' => $this->booking_status->value,
            'status_label' => ucwords(str_replace('_', ' ', $this->booking_status->value)),
            'payment_status' => $this->payment_status?->value,
            'checkin_date' => $this->checkin_date?->toDateString(),
            'checkout_date' => $this->checkout_date?->toDateString(),
            'nights' => (int) $this->nights,
            'adults' => (int) $this->adults,
            'children' => (int) $this->children,
            'total_amount' => (int) $this->total_amount,
            'balance_due' => (int) $this->outstandingTotal(),
            'source' => $this->source?->value,
            'special_requests' => $this->special_requests,
            'room' => $room ? [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'category_name' => $room->category?->name,
            ] : null,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'is_vip' => (bool) $customer->is_vip,
            ] : null,
            'charges' => $charges->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type?->value,
                'description' => $c->description,
                'amount' => (int) $c->amount,
                'settled' => (bool) $c->settled,
                'settled_method' => $c->settled_method,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values(),
            'payments' => $payments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (int) $p->amount,
                'method' => $p->method,
                'status' => $p->status?->value,
                'reference_note' => $p->reference_note,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
