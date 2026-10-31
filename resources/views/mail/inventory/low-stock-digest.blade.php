<x-mail::message>
# Low stock at {{ $tenant->name }}

The products below have hit or dropped past their low-stock threshold. Restock before the shelves run dry.

<x-mail::table>
| Outlet | Product | On hand | Threshold |
| :----- | :------ | ------: | --------: |
@foreach ($items as $it)
| {{ $it['outlet'] }} | {{ $it['name'] }}@if (! empty($it['sku'])) &nbsp;<span style="color:#6E767D">({{ $it['sku'] }})</span>@endif | {{ number_format($it['on_hand']) }} | {{ number_format($it['threshold']) }} |
@endforeach
</x-mail::table>

<x-mail::button :url="'https://'.$tenant->primaryHost().'/dashboard/inventory'">
Open Inventory
</x-mail::button>

You're receiving this because you're listed as a Manager on this workspace. Adjust thresholds per product from the Inventory page.

Thanks,
The {{ config('brand.name') }} team
</x-mail::message>
