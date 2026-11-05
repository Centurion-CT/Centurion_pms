@extends('site.layouts.app')

@section('title', 'Already reviewed · '.\App\Models\Tenant::current()?->name)
@section('noindex', '1')

@section('content')
@php
    $property = $property ?? $booking->room?->property;
@endphp
@include('site.partials.header', ['nav' => false, 'help' => true])
<main class="container container--narrow narrow" data-testid="stays-review-already">
    <h1 class="title">You've already reviewed this stay</h1>
    <p class="lead">Thanks for the {{ (int) $review->rating }}-star review of <strong>{{ $property?->name ?? $booking->tenant?->name ?? 'the property' }}</strong>. We only accept one review per stay.</p>
    @php($category = $booking->room?->category)
    @if ($property?->is_live && $category)
        <div class="actions"><a class="btn btn-secondary" href="{{ route('site.apartment', ['branch' => $property->slug, 'type' => $category->slug]) }}#reviews">See the apartment's page</a></div>
    @endif
</main>
@include('site.partials.footer')
@endsection
