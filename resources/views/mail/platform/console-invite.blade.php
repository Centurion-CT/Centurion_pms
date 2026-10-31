<x-mail::message>
# {{ $reset ? 'Set a new password' : 'You\'re invited to the console' }}

Hi {{ $name }},

@if ($reset)
An owner of the {{ config('brand.name') }} console reset your password. Choose a new one to sign in again.
@else
{{ $invitedBy ?? 'An owner' }} added you to the {{ config('brand.name') }} console, where you can see every business on {{ config('brand.name') }} and pause or resume one. Choose a password to get started.
@endif

<x-mail::button :url="$url">
Choose a password
</x-mail::button>

The link works for {{ \App\Models\ConsoleUser::INVITE_DAYS }} days. Each sign-in also asks for a code sent to this address.

{{ config('brand.name') }}
</x-mail::message>
