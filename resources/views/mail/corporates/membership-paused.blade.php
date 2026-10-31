<x-mail::message>
# Membership paused, top up to resume

Hi {{ $corporate->name }} team,

A scheduled renewal couldn't complete because your wallet doesn't have enough available balance to cover the next period.

<x-mail::panel>
**Member** &nbsp; {{ $member?->name ?? 'Corporate-wide' }}<br>
**Membership** &nbsp; #{{ $membership->id }}<br>
**Period cost** &nbsp; ₦{{ number_format($membership->amount_naira) }}<br>
**Wallet shortfall** &nbsp; ₦{{ number_format($shortfall) }}
</x-mail::panel>

Top up the wallet, then either wait for tomorrow's renewal sweep at 02:00 WAT or hit **Mark renewed** from the membership row to resume immediately.

<x-mail::button :url="$walletUrl">
Top up now
</x-mail::button>

Thanks,
The {{ config('app.name') }} team
</x-mail::message>
