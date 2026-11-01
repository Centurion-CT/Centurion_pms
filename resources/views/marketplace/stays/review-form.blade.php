@extends('layouts.marketplace', ['title' => 'Review your stay · '.\App\Models\Tenant::current()?->name])

@section('content')
@php
    /** @var \App\Models\Booking $booking */
    /** @var \App\Models\Property|null $property */
    $city = trim((string) ($property?->city ?? ''));
    $propertyName = (string) ($property?->name ?? $booking->tenant?->name ?? 'your stay');
@endphp
<div style="max-width: 640px; margin: 0 auto; padding: 40px 24px 60px;" data-testid="stays-review-form">
    <a href="/" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700; font-size: 18px; margin-bottom: 20px; color: #14171A;">
        <span style="width: 24px; height: 24px; border-radius: 6px; background: #14171A; color: #fff; display: inline-flex; align-items: center; justify-content: center;">
            <svg width="11" height="11" viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
        </span>
        {{ \App\Models\Tenant::current()?->name }}
    </a>

    <h1 style="font-size: clamp(22px, 3.5vw, 28px); font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px;">
        How was your stay?
    </h1>
    <p style="color: #6A6A6A; font-size: 14.5px; margin: 0 0 28px;">
        {{ $propertyName }}@if ($city) · {{ $city }} @endif · {{ $booking->booking_ref }}
    </p>

    <form method="POST" action="{{ route('marketplace.stays.review.store', ['booking_ref' => $booking->booking_ref]) }}?{{ request()->getQueryString() }}"
          style="display: grid; gap: 20px;" data-testid="stays-review-form-el">
        @csrf

        {{-- Rating: five buttons acting like a radio group. Uses
             hidden input + Alpine for keyboard/click swaps. --}}
        <fieldset style="border: 1px solid #EBEBEB; border-radius: 14px; padding: 18px 20px;">
            <legend style="font-size: 12px; font-weight: 600; color: #6A6A6A; text-transform: uppercase; letter-spacing: 0.05em; padding: 0 6px;">
                Overall rating
            </legend>
            <div x-data="{ rating: 5 }" style="display: flex; gap: 8px; margin-top: 4px;">
                <input type="hidden" name="rating" :value="rating" data-testid="review-rating-input">
                @for ($i = 1; $i <= 5; $i++)
                    <button type="button" @click="rating = {{ $i }}"
                            data-testid="review-star-{{ $i }}"
                            style="background: none; border: none; cursor: pointer; padding: 4px; font-size: 32px; line-height: 1;"
                            :style="rating >= {{ $i }} ? 'color:#F5A623;' : 'color:#DDD;'">
                        ★
                    </button>
                @endfor
            </div>
            @error('rating') <p style="font-size: 12.5px; color: #B85C42; margin-top: 6px;">{{ $message }}</p> @enderror
        </fieldset>

        <div>
            <label for="body" style="display: block; font-size: 12px; font-weight: 600; color: #6A6A6A; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                Tell future guests about your stay
            </label>
            <textarea id="body" name="body" required minlength="6" maxlength="2000"
                      style="width: 100%; min-height: 140px; padding: 12px 14px; font: inherit; color: #222;
                             border: 1px solid #DDDDDD; border-radius: 10px; resize: vertical;"
                      placeholder="What stood out? Rooms, service, location, value…"
                      data-testid="review-body">{{ old('body') }}</textarea>
            @error('body') <p style="font-size: 12.5px; color: #B85C42; margin-top: 6px;">{{ $message }}</p> @enderror
        </div>

        <button type="submit" data-testid="review-submit"
                style="padding: 14px 18px; border-radius: 12px; border: none; cursor: pointer;
                       background: #14171A; color: #fff; font-weight: 700; font-size: 15px;">
            Submit review
        </button>

        <p style="text-align: center; font-size: 11.5px; color: #6A6A6A;">
            Reviews are shown publicly on the property page with your first name.
            The property never sees your email.
        </p>
    </form>
</div>
@endsection
