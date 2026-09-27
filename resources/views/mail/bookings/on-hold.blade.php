<x-mail::message>
# Your booking is on hold

Thanks for booking with us, {{ $booking->guest_name }}.

<x-mail::panel>
**Booking reference** &nbsp; {{ $booking->booking_ref }}<br>
**Room** &nbsp; {{ $booking->room->name }}<br>
**Check-in** &nbsp; {{ $booking->checkin_date->toFormattedDateString() }}<br>
**Check-out** &nbsp; {{ $booking->checkout_date->toFormattedDateString() }} ({{ $booking->nights }} nights)<br>
**Deposit paid** &nbsp; ₦{{ number_format($booking->deposit_amount) }}<br>
**Balance due on check-in** &nbsp; ₦{{ number_format($booking->balance_due) }}
</x-mail::panel>

We'll send a reminder before your stay. See you soon.

@if (! empty($portalUrl))
<x-mail::button :url="$portalUrl">
Pay balance or manage your booking
</x-mail::button>
@endif

Thanks,
{{ $booking->tenant->name }}
</x-mail::message>
