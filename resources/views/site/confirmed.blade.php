{{-- After a successful payment (StaysPaymentSuccessController, reached from
     the Paystack return page). The booking site's third step. --}}
@extends('site.layouts.app')

@php($property = $booking->room?->property)
@php($rules = $property?->houseRules() ?? [])
@php($status = $booking->booking_status)
@php($booked = in_array($status, [\App\Enums\BookingStatus::Confirmed, \App\Enums\BookingStatus::OnHold, \App\Enums\BookingStatus::CheckedIn, \App\Enums\BookingStatus::CheckedOut], true))
@php($due = $booked ? (int) $booking->balance_due : (int) $booking->total_amount)
@php($paid = $booked ? max(0, (int) $booking->total_amount - (int) $booking->balance_due) : 0)

@section('page', 'pg-checkout')
@section('title', 'Booked · '.$booking->booking_ref)

@section('content')
@include('site.partials.header', ['nav' => false, 'help' => true])

<main class="container" data-testid="stays-pay-success">
    <div class="top">
        <span></span>
        <ol class="stepper" aria-label="Booking steps"><li class="done">Apartment</li><li class="done">Your details</li><li class="{{ $booked ? 'done' : 'now' }}" aria-current="step">Payment</li></ol>
    </div>

    <div class="layout">
        <div class="form">
            <div class="group">
                @if ($booked)
                    <h1 class="h1 full">You're booked, {{ \Illuminate\Support\Str::of($booking->guest_name)->before(' ') }}</h1>
                    <p class="lead full">Booking reference <b style="color:var(--ink-900)">{{ $booking->booking_ref }}</b>. Your receipt is on its way by email. The front desk has your booking.</p>
                    @if ($due > 0)
                        <p class="full"><span class="tag tag-last">₦{{ number_format($due) }} to pay at check-in</span></p>
                    @endif
                @elseif ($status === \App\Enums\BookingStatus::Pending)
                    <h1 class="h1 full">We're waiting for your payment</h1>
                    <p class="lead full">Booking reference <b style="color:var(--ink-900)">{{ $booking->booking_ref }}</b>. If you've paid, this page confirms within a few minutes. Unpaid bookings are released after 15 minutes.</p>
                @else
                    <h1 class="h1 full">This booking isn't active</h1>
                    <p class="lead full">Booking reference <b style="color:var(--ink-900)">{{ $booking->booking_ref }}</b> was cancelled or has ended. Call the front desk if that's unexpected.</p>
                @endif
            </div>
            @if ($booked)
            <div class="group">
                <h2 class="h2">What happens next</h2>
                <ol class="full" style="margin:0;padding-left:var(--s-5);display:grid;gap:var(--s-2)">
                    <li>Arrive on {{ $booking->checkin_date?->format('l j F') }}@if ($t = \App\Site\Stay::time($rules['check_in_from'] ?? '')) from {{ $t }}@endif.</li>
                    <li>Show your booking reference at the front desk to collect your key.</li>
                    <li>Need to change something? Use <a href="{{ route('site.manage') }}">Manage booking</a> with your reference and email.</li>
                </ol>
            </div>
            @endif
            <div class="submit"><a class="btn btn-secondary" href="{{ route('tenant.landing') }}">Back to the home page</a></div>
        </div>

        <aside class="card summary">
            <div class="card-pad">
                <div><h2 class="h3">{{ $booking->room?->category?->name ?? 'Your apartment' }}</h2>@if ($property)<p class="meta">{{ $property->name }}@if ($property->address) · {{ $property->address }}@endif</p>@endif</div>
                <div class="when">
                    <div><span class="seg-label">Check in</span><div class="seg-value">{{ $booking->checkin_date?->format('D j M') }}</div></div>
                    <div><span class="seg-label">Check out</span><div class="seg-value">{{ $booking->checkout_date?->format('D j M') }}</div></div>
                </div>
                <dl class="lines">
                    <dt>Total</dt><dd>₦{{ number_format((int) $booking->total_amount) }}</dd>
                    <dt>Paid</dt><dd>₦{{ number_format($paid) }}</dd>
                    @if ($booked && $due > 0)<dt class="total">Due at check-in</dt><dd class="total">₦{{ number_format($due) }}</dd>@endif
                </dl>
            </div>
        </aside>
    </div>
</main>
@endsection
