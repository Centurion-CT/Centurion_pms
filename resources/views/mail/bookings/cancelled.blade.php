<x-mail::message>
# Your booking has been cancelled

Hi {{ $booking->guest_name }},

The booking below has been cancelled.

<x-mail::panel>
**Booking reference** &nbsp; {{ $booking->booking_ref }}<br>
**Room** &nbsp; {{ $booking->room->name }}<br>
**Check-in** &nbsp; {{ $booking->checkin_date->toFormattedDateString() }}<br>
**Check-out** &nbsp; {{ $booking->checkout_date->toFormattedDateString() }}
</x-mail::panel>

@if ($depositForfeit)
Because the cancellation falls inside the host's 7-day window, the deposit of ₦{{ number_format($booking->deposit_amount) }} cannot be refunded.
@else
The host will be in touch about your refund within a few business days.
@endif

Thanks,
{{ $booking->tenant->name }}
</x-mail::message>
