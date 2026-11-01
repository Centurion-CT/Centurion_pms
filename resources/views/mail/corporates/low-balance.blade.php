<x-mail::message>
# Wallet running low

Hi {{ $corporate->name }} team,

Your corporate wallet with {{ \App\Models\Tenant::current()?->name ?? 'us' }} has dropped below the threshold you set. Members can keep booking until the wallet hits zero, but you'll want to top up soon.

<x-mail::panel>
**Available balance** &nbsp; ₦{{ number_format($wallet->available()) }}<br>
**On hold** &nbsp; ₦{{ number_format($wallet->held_naira) }}<br>
**Threshold** &nbsp; ₦{{ number_format($wallet->low_balance_threshold_naira ?? 0) }}
</x-mail::panel>

<x-mail::button :url="$walletUrl">
Top up now
</x-mail::button>

Memberships set to auto-renew will pause if the wallet hits zero on renewal day, top up to keep your team's bookings running.

Thanks,
The {{ \App\Models\Tenant::current()?->name ?? config('brand.name') }} team
</x-mail::message>
