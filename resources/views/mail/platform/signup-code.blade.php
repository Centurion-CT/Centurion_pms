<x-mail::message>
# Confirm your email

Hi {{ $name }},

Use this code to finish setting up {{ $businessName }} on {{ config('brand.name') }}. It expires in {{ \App\Platform\SignUpCode::TTL_MINUTES }} minutes.

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

Didn't try to sign up? You can ignore this email. Nothing is created without the code.

{{ config('brand.name') }}
</x-mail::message>
