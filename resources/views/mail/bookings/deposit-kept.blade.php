<x-mail::message>
# About your payment

Hi {{ $booking->guest_name }},

Your booking {{ $booking->booking_ref }} was {{ $booking->booking_status === \App\Enums\BookingStatus::NoShow ? 'marked as a no-show' : 'cancelled' }}. Under the property's policy, {{ $booking->tenant->name }} has kept ₦{{ number_format($amount) }} of what you paid.

<x-mail::panel>
**Booking reference** &nbsp; {{ $booking->booking_ref }}<br>
**Amount kept** &nbsp; ₦{{ number_format($amount) }}<br>
**Reason** &nbsp; {{ $reason }}
</x-mail::panel>

If you think this is a mistake, reply to this email and the property will get back to you.

Thanks,
{{ $booking->tenant->name }}
</x-mail::message>
