@component('mail::message')
# Thanks for staying at {{ $propertyName }}

Hi {{ $booking->guest_name }},

We hope your stay was everything you'd hoped for. Would you mind
sharing a quick review? It takes about a minute and helps other
guests pick a great place, and helps {{ $propertyName }} keep
raising the bar.

@component('mail::button', ['url' => $reviewUrl])
Leave a review
@endcomponent

If anything went wrong during your stay, call the front desk and
we'll put it right.

Thanks,<br>
The team at {{ $propertyName }}
@endcomponent
