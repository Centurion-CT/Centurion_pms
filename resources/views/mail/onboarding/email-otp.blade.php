<x-mail::message>
# Verify your email

Hi {{ $operator->name }},

Use the code below to confirm your email address. It expires in 10 minutes.

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

If you didn't sign up for {{ config('app.name') }}, please ignore this email.

Thanks,
The {{ config('app.name') }} team
</x-mail::message>
