<x-mail::message>
# Your Keverna subscription is active

Hi {{ $subscription->tenant->name }},

Your subscription is set up. Here are the terms we agreed on.

<x-mail::panel>
**Plan** &nbsp; {{ ucfirst(str_replace('_', ' ', $subscription->plan->value)) }}<br>
**Term** &nbsp; {{ $subscription->duration_months }} month{{ $subscription->duration_months === 1 ? '' : 's' }}<br>
**Starts** &nbsp; {{ $subscription->starts_at->format('D, j M Y') }}<br>
**Expires** &nbsp; {{ $subscription->expires_at->format('D, j M Y') }}<br>
**Contract value** &nbsp; ₦{{ number_format($subscription->contract_amount) }}<br>
**Received to date** &nbsp; ₦{{ number_format($subscription->amount_received) }}
@if ($subscription->outstanding() > 0)
<br>**Outstanding** &nbsp; ₦{{ number_format($subscription->outstanding()) }}
@endif
</x-mail::panel>

@if ($subscription->agreement_notes)
> {{ $subscription->agreement_notes }}
@endif

<x-mail::button :url="'https://'.($subscription->tenant->primaryHost() ?: config('keverna.central_domain')).'/dashboard'">
Open your dashboard
</x-mail::button>

If any of the above looks off, reply to this email and we'll sort it before your first invoice.

Thanks for choosing Keverna,
The Keverna team
</x-mail::message>
