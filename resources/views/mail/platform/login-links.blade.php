<x-mail::message>
# Your login links

Hi {{ $name }}, you work at {{ count($businesses) === 1 ? 'one business' : count($businesses).' businesses' }} on {{ config('brand.name') }}. Pick one to log in:

@foreach ($businesses as $business)
**{{ $business['name'] }}**
{{ $business['host'] }} · {{ $business['role'] }}

<x-mail::button :url="$business['url']">
Log in to {{ $business['name'] }}
</x-mail::button>
@endforeach

Didn't ask for this? You can ignore it. Nobody can log in without your password.

{{ config('brand.name') }}
</x-mail::message>
