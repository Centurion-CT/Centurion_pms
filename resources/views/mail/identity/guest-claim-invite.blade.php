<x-mail::message>
# One place for every Keverna stay

Thanks for booking at **{{ $propertyName }}**.

If you've stayed at (or booked with) other Keverna properties before, you can now see every reservation, receipt, and change request in a single dashboard, signed in with just this email.

<x-mail::button :url="$claimUrl">
See all my reservations
</x-mail::button>

This link is good for 7 days. It signs you in without a password; you can set one later from your account.

If you didn't book anything, you can safely ignore this email.

Thanks,
The {{ config('app.name') }} team
</x-mail::message>
