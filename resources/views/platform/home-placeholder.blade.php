{{-- Temporary platform home until sign-up ships (docs/MULTITENANCY.md step 3). --}}
@extends('platform.layouts.plain')

@section('title', 'Software for apartments and hotels')

@section('content')
    <h1>{{ config('brand.name') }}</h1>
    <p class="lead">Bookings, check-in, housekeeping, payments and your own booking website, for serviced apartments and hotels in Nigeria.</p>
    <p class="note">Already on {{ config('brand.name') }}? Go to your business's own address and add <b>/login</b>, for example <b>yourbusiness{{ config('keverna.tenant_domain_suffix') }}/login</b>.</p>
@endsection
