<x-mail::message>
# Invoice for outstanding balance

Hi {{ $subscription->tenant->name }},

There's an outstanding balance on your current Keverna subscription term.

<x-mail::panel>
**Outstanding** &nbsp; ₦{{ number_format($outstanding) }}<br>
**Contract value** &nbsp; ₦{{ number_format($subscription->contract_amount) }}<br>
**Received to date** &nbsp; ₦{{ number_format($subscription->amount_received) }}<br>
**Term** &nbsp; {{ $subscription->starts_at->format('j M Y') }} → {{ $subscription->expires_at->format('j M Y') }}
</x-mail::panel>

Please settle the outstanding amount at your earliest convenience, either by transfer to the account we shared, or by replying to this email so we can send you a Paystack link.

Reference the invoice number below on any transfer so we can reconcile it against your account.

<x-mail::panel>
**Invoice reference** &nbsp; {{ $subscription->paystack_reference }}
</x-mail::panel>

Thanks,
The Keverna team
</x-mail::message>
