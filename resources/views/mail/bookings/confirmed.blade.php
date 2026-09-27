<x-mail::message>
# Your booking is confirmed

Thanks for booking with us, {{ $booking->guest_name }}.

<x-mail::panel>
**Booking reference** &nbsp; {{ $booking->booking_ref }}<br>
**Room** &nbsp; {{ $booking->room->name }}<br>
**Check-in** &nbsp; {{ $booking->checkin_date->toFormattedDateString() }}<br>
**Check-out** &nbsp; {{ $booking->checkout_date->toFormattedDateString() }} ({{ $booking->nights }} nights)<br>
**Guests** &nbsp; {{ $booking->adults }} adult{{ $booking->adults === 1 ? '' : 's' }}{{ $booking->children > 0 ? ', '.$booking->children.' child'.($booking->children === 1 ? '' : 'ren') : '' }}<br>
**Total paid** &nbsp; ₦{{ number_format($booking->total_amount) }}
</x-mail::panel>

@if ($booking->special_requests)
**Special requests**

> {{ $booking->special_requests }}
@endif

We'll see you soon.

@if (! empty($portalUrl))
<x-mail::button :url="$portalUrl">
Manage your booking
</x-mail::button>
@endif

Thanks,
{{ $booking->tenant->name }}
</x-mail::message>
