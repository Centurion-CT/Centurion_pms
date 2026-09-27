<x-mail::message>
# Booking confirmed · {{ $booking->booking_ref }}

Hi {{ $member?->name ?? $booking->guest_name }},

Your stay is locked in and billed to {{ $corporate->name }}'s corporate wallet, no card needed at check-in.

<x-mail::panel>
**Property** &nbsp; {{ $room?->property?->name ?? '-' }}<br>
**Room** &nbsp; {{ $room?->name ?? '-' }}<br>
**Check-in** &nbsp; {{ $booking->checkin_date?->toFormattedDateString() }}<br>
**Check-out** &nbsp; {{ $booking->checkout_date?->toFormattedDateString() }}<br>
**Nights** &nbsp; {{ $booking->nights }}<br>
**Total** &nbsp; ₦{{ number_format($booking->total_amount) }}
@if ($saving > 0)
<br>**Negotiated saving** &nbsp; ₦{{ number_format($saving) }}
@endif
</x-mail::panel>

A receipt has been recorded against your corporate wallet at {{ $corporate->name }}. The property team will receive your arrival details and welcome you on the day.

Thanks,
The {{ config('app.name') }} team
</x-mail::message>
