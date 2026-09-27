<x-mail::message>
# Payment received

Hi {{ $subscription->tenant->name }},

We've recorded a payment on your Keverna subscription.

<x-mail::panel>
**Amount received** &nbsp; ₦{{ number_format($amount) }}<br>
**Recorded on** &nbsp; {{ $subscription->paid_at?->format('D, j M Y · H:i') ?? now()->format('D, j M Y · H:i') }}<br>
**Reference** &nbsp; {{ $subscription->paystack_reference }}
</x-mail::panel>

**Subscription summary**

- Plan: {{ ucfirst(str_replace('_', ' ', $subscription->plan->value)) }}
- Contract value: ₦{{ number_format($subscription->contract_amount) }}
- Received to date: ₦{{ number_format($subscription->amount_received) }}
@if ($subscription->outstanding() > 0)
- **Outstanding: ₦{{ number_format($subscription->outstanding()) }}** (a separate invoice follows)
@else
- **Fully paid.** Nothing further owing on this term.
@endif

Keep this email for your records.

Thanks,
The Keverna team
</x-mail::message>
