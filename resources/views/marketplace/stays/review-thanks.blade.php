@extends('layouts.marketplace', ['title' => 'Thanks for reviewing · Keverna Stays'])

@section('content')
@php
    $property = $property ?? $booking->room?->property;
@endphp
<div style="max-width: 560px; margin: 0 auto; padding: 60px 24px; text-align: center;"
     data-testid="stays-review-thanks">
    <div style="display: inline-flex; align-items: center; justify-content: center;
                width: 68px; height: 68px; border-radius: 999px; margin: 0 auto 20px;
                background: #DCFCE7; color: #1B7A3D;">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 13l4 4L19 7"/>
        </svg>
    </div>
    <h1 style="font-size: clamp(22px, 4vw, 28px); font-weight: 700; margin: 0 0 6px; letter-spacing: -0.02em;">
        Thanks for the review
    </h1>
    <p style="color: #6A6A6A; font-size: 15px; margin: 0 0 30px;">
        It's live on <strong>{{ $property?->name ?? $booking->tenant?->name ?? 'the property page' }}</strong>
        for future guests to see.
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
