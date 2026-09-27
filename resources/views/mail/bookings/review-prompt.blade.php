<x-mail::message>
# How was your stay, {{ $booking->guest_name }}?

Thanks for staying at **{{ $booking->room->name }}** ({{ $booking->checkin_date->toFormattedDateString() }} -
{{ $booking->checkout_date->toFormattedDateString() }}).

If you have a minute, we'd love a quick review. It helps future guests
pick the right room, and helps the host keep improving the place.

@if (! empty($reviewUrl))
<x-mail::button :url="$reviewUrl">
Leave a review
</x-mail::button>
@endif

Thanks,
{{ $booking->tenant->name }}
</x-mail::message>
