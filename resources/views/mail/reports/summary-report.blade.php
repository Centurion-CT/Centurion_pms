<x-mail::message>
# {{ $tenant->name }}, {{ $periodLabel }} summary

Attached is the {{ strtolower($periodLabel) }} property summary for **{{ $periodRange }}**: revenue, occupancy, top sellers, and any price / cost / tax / stock edits made in the window.

You're receiving this because your email is on {{ $tenant->name }}'s summary report list. To stop these, ask a manager to remove you from the subscribers panel on the Summary page.

Thanks,<br>
Keverna
</x-mail::message>
