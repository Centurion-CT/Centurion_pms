@props(['fallback' => null])
{{-- The business's logo, else the first letter of its name: never another
     product's mark. Sits inside whatever tile the page already draws. --}}
@php($business = \App\Models\Tenant::current())
@if ($logo = $business?->logoUrl())
    <img src="{{ $logo }}" alt="" style="width: 100%; height: 100%; object-fit: contain;">
@else
    {{ mb_strtoupper(mb_substr((string) ($fallback ?? $business?->name ?? config('brand.name')), 0, 1)) }}
@endif
