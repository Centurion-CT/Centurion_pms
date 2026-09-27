<x-mail::message>
# Wallet running low

Hi {{ $corporate->name }} team,

Your Keverna corporate wallet has dropped below the threshold you set. Members can keep booking until the wallet hits zero, but you'll want to top up soon.

<x-mail::panel>
**Available balance** &nbsp; ₦{{ number_format($wallet->available()) }}<br>
**On hold** &nbsp; ₦{{ number_format($wallet->held_naira) }}<br>
**Threshold** &nbsp; ₦{{ number_format($wallet->low_balance_threshold_naira ?? 0) }}
</x-mail::panel>

<x-mail::button :url="config('app.url').'/corporates/wallet'">
Top up now
</x-mail::button>

Memberships set to auto-renew will pause if the wallet hits zero on renewal day, top up to keep your team's bookings running.

Thanks,
The {{ config('app.name') }} team
</x-mail::message>
