@extends('site.layouts.app')

@section('title', 'Review your stay · '.\App\Models\Tenant::current()?->name)

@section('content')
@include('site.partials.header', ['nav' => false, 'help' => true])
@php
    /** @var \App\Models\Booking $booking */
    /** @var \App\Models\Property|null $property */
    $city = trim((string) ($property?->city ?? ''));
    $propertyName = (string) ($property?->name ?? $booking->tenant?->name ?? 'your stay');
@endphp
<main class="container container--narrow narrow" data-testid="stays-review-form">
    <h1 class="title">How was your stay?</h1>
    <p class="lead">{{ $propertyName }}@if ($city) · {{ $city }} @endif · {{ $booking->booking_ref }}</p>

    <form class="review" method="POST" action="{{ route('marketplace.stays.review.store', ['booking_ref' => $booking->booking_ref]) }}?{{ request()->getQueryString() }}" data-testid="stays-review-form-el">
        @csrf

        {{-- Rating: five buttons acting like a radio group. Uses
             hidden input + Alpine for keyboard/click swaps. --}}
        <fieldset class="field">
            <legend>Overall rating</legend>
            <div class="stars" x-data="{ rating: 5 }">
                <input type="hidden" name="rating" :value="rating" data-testid="review-rating-input">
                @for ($i = 1; $i <= 5; $i++)
                    <button type="button" @click="rating = {{ $i }}" :class="rating >= {{ $i }} && 'is-on'" aria-label="{{ $i }} out of 5" data-testid="review-star-{{ $i }}">★</button>
                @endfor
            </div>
            @error('rating')<p class="error">{{ $message }}</p>@enderror
        </fieldset>

        <div class="field">
            <label for="body">Tell future guests about your stay</label>
            <textarea class="input" id="body" name="body" required minlength="6" maxlength="2000" style="height: 160px" placeholder="What stood out? Rooms, service, location, value…" data-testid="review-body">{{ old('body') }}</textarea>
            @error('body')<p class="error">{{ $message }}</p>@enderror
        </div>

        <button class="btn btn-primary btn-lg btn-block" type="submit" data-testid="review-submit">Submit review</button>
        <p class="meta">Reviews are shown publicly on the property page with your first name. The property never sees your email.</p>
    </form>
</main>
@include('site.partials.footer')
@endsection
