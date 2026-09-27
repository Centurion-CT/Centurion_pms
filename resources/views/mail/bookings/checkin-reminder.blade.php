<x-mail::message>
# See you tomorrow, {{ $booking->guest_name }}

Just a friendly reminder that your stay at **{{ $booking->room->name }}** starts tomorrow.

<x-mail::panel>
**Booking reference** &nbsp; {{ $booking->booking_ref }}<br>
**Check-in** &nbsp; {{ $booking->checkin_date->toFormattedDateString() }}<br>
**Check-out** &nbsp; {{ $booking->checkout_date->toFormattedDateString() }}
@if ($booking->balance_due > 0)
<br>**Balance due on arrival** &nbsp; ₦{{ number_format($booking->balance_due) }}
@endif
</x-mail::panel>

@if ($booking->tenant->phone)
If you need anything before then, the host can be reached on {{ $booking->tenant->phone }}.
@endif

Safe travels,
{{ $booking->tenant->name }}
</x-mail::message>
