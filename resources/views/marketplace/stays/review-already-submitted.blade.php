@extends('layouts.marketplace', ['title' => 'Already reviewed · '.\App\Models\Tenant::current()?->name])

@section('content')
@php
    $property = $property ?? $booking->room?->property;
@endphp
<div style="max-width: 560px; margin: 0 auto; padding: 60px 24px; text-align: center;"
     data-testid="stays-review-already">
    <h1 style="font-size: clamp(22px, 4vw, 28px); font-weight: 700; margin: 0 0 10px;">
        You've already reviewed this stay
    </h1>
    <p style="color: #555; font-size: 15px; line-height: 1.6; margin: 0 0 26px;">
        Thanks for the {{ (int) $review->rating }}-star review of
        <strong>{{ $property?->name ?? $booking->tenant?->name ?? 'the property' }}</strong>.
        We only accept one review per stay.
    </p>

    @if ($property)
        <a href="{{ route('marketplace.stays.show', ['slug' => $property->public_slug]) }}"
           style="display: inline-block; padding: 12px 20px; border-radius: 999px;
                  border: 1px solid #DDDDDD; font-size: 13.5px; font-weight: 600; color: #222;">
            View the property page
        </a>
    @endif
</div>
@endsection
