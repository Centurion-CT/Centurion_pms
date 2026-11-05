@extends('site.layouts.app')

@section('title', 'Thanks for reviewing · '.\App\Models\Tenant::current()?->name)

@section('content')
@php
    $property = $property ?? $booking->room?->property;
@endphp
@include('site.partials.header', ['nav' => false, 'help' => true])
<main class="container container--narrow narrow" data-testid="stays-review-thanks">
    <p style="margin:0 0 4px;font-weight:600;color:var(--c-free)">Review published</p>
    <h1 class="title">Thanks for the review</h1>
    <p class="lead">It's live on <strong>{{ $property?->name ?? $booking->tenant?->name ?? 'the property page' }}</strong> for future guests to see.</p>
    @if ($property)
        <div class="actions"><a class="btn btn-secondary" href="{{ route('marketplace.stays.show', ['slug' => $property->public_slug]) }}">View the property page</a></div>
    @endif
</main>
@include('site.partials.footer')
@endsection
