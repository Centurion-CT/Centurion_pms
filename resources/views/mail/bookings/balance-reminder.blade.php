<x-mail::message>
# Balance reminder

Hi {{ $booking->guest_name }},

Your outstanding balance for booking **{{ $booking->booking_ref }}** is due
@if ($daysBefore === 1)
**tomorrow**
@elseif ($daysBefore === 7)
**in a week**
@else
**in {{ $daysBefore }} days**
@endif
on **{{ $booking->balance_due_at?->toFormattedDateString() }}**.

<x-mail::panel>
**Balance due** &nbsp; ₦{{ number_format($booking->balance_due) }}<br>
**Room** &nbsp; {{ $booking->room->name }}<br>
**Check-in** &nbsp; {{ $booking->checkin_date->toFormattedDateString() }}
</x-mail::panel>

If we don't receive the balance by the deadline, your booking will be cancelled automatically and your deposit forfeited per the room's cancellation policy.

Thanks,
{{ $booking->tenant->name }}
</x-mail::message>
