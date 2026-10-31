<x-mail::message>
# Sign in to the console

Use this code to sign in to the {{ config('brand.name') }} console. It expires in {{ \App\Platform\ConsoleCode::TTL_MINUTES }} minutes.

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

Didn't try to sign in? Someone typed your address on the console sign-in page. Without the code they can't get in.

{{ config('brand.name') }}
</x-mail::message>
