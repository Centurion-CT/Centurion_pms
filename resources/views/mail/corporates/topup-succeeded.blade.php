<x-mail::message>
# ₦{{ number_format($topup->amount_naira) }} added to your wallet

Hi {{ $corporate->name }} team,

Your top-up has cleared. The funds are now available for bookings, memberships, leases and desk sessions across Keverna.

<x-mail::panel>
**Reference** &nbsp; {{ $topup->reference }}<br>
**Amount** &nbsp; ₦{{ number_format($topup->amount_naira) }}<br>
**New balance** &nbsp; ₦{{ number_format($wallet?->balance_naira ?? 0) }}<br>
**Available** &nbsp; ₦{{ number_format($wallet?->available() ?? 0) }}<br>
@if ($topup->note)
**Note** &nbsp; {{ $topup->note }}
@endif
</x-mail::panel>

<x-mail::button :url="$walletUrl">
View wallet
</x-mail::button>

Thanks,
The {{ config('app.name') }} team
</x-mail::message>
