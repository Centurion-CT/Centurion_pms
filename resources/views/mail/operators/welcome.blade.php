<x-mail::message>
# Welcome to {{ config('brand.name') }}, {{ $operator->name }}!

Your booking engine for **{{ $tenant->name }}** is ready to be set up. Here's
what to do next:

1. Add at least one room with pricing and photos
2. Confirm your email and phone (if you haven't already)
3. Mark your landing page live and start taking bookings

<x-mail::button :url="$dashboardUrl">
Open your dashboard
</x-mail::button>

Your subdomain is **{{ $tenant->primaryHost() }}**, that's where your guests
will book.

If anything stops you along the way, reply to this email and a human will
help.

Thanks,
The {{ config('brand.name') }} team
</x-mail::message>
