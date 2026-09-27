<x-mail::message>
# Renewal reminder

Hi {{ $tenant->name }},

Your Keverna subscription expires
@if ($daysBefore === 1)
**tomorrow**
@elseif ($daysBefore === 7)
**in a week**
@else
**in {{ $daysBefore }} days**
@endif
on **{{ $expiresAt->toFormattedDateString() }}**.

Renew now to keep your booking engine live without interruption.

<x-mail::button :url="config('app.url')">
Renew your subscription
</x-mail::button>

Thanks,
The Keverna team
</x-mail::message>
