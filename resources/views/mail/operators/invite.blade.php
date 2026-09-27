<x-mail::message>
# You're invited to {{ $tenant->name }} on Keverna.

Hi {{ $operator->name }},

**{{ $inviterName }}** added you to **{{ $tenant->name }}** as a **{{ $roleLabel }}**. Click below to pick a password and activate your account.

<x-mail::button :url="$magicLink">
Activate your account
</x-mail::button>

The link is good for **24 hours**. If it expires before you get to it, ask **{{ $inviterName }}** to send a new invite.

Your account isn't active until you set a password, nothing will happen if you don't accept this invite.

Thanks,
The {{ config('app.name') }} team
</x-mail::message>
