{{-- After a successful payment (StaysPaymentSuccessController, reached from
     the Paystack return page). --}}
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

<main class="band" data-testid="stays-pay-success">
    <div class="container container--narrow">
        @if ($booked)
            <h1 class="title">You're booked, {{ \Illuminate\Support\Str::of($booking->guest_name)->before(' ') }}</h1>
        @elseif ($status === \App\Enums\BookingStatus::Pending)
            <h1 class="title">We're waiting for your payment</h1>
        @else
            <h1 class="title">This booking isn't active</h1>
        @endif

        <div class="layout">
            <div class="form">
                <section class="panel">
                    @if ($booked)
                        <p class="lead">Booking reference <b style="color:var(--c-ink)">{{ $booking->booking_ref }}</b>. Your receipt is on its way by email, and we have your booking.</p>
                    @elseif ($status === \App\Enums\BookingStatus::Pending)
                        <p class="lead">Booking reference <b style="color:var(--c-ink)">{{ $booking->booking_ref }}</b>. If you've paid, this page confirms within a few minutes. Unpaid bookings are released after 15 minutes.</p>
                    @else
                        <p class="lead">Booking reference <b style="color:var(--c-ink)">{{ $booking->booking_ref }}</b> was cancelled or has ended. Call the front desk if that's unexpected.</p>
                    @endif
                </section>
                @if ($booked)
                    <section class="panel">
                        <h2 class="subhead">What happens next</h2>
                        <ol style="margin:0;padding-left:20px;display:grid;gap:8px">
                            <li>Arrive on {{ $booking->checkin_date?->format('l j F') }}@if ($t = \App\Site\Stay::time($rules['check_in_from'] ?? '')) from {{ $t }}@endif.</li>
                            <li>Show your booking reference at the front desk to collect your key.</li>
                            <li>Need to change something? Use <a href="{{ route('site.manage') }}">Manage booking</a> with your reference and email.</li>
                        </ol>
                    </section>
                @endif
                <p style="margin:0"><a class="btn btn-secondary" href="{{ route('tenant.landing') }}">Back to the home page</a></p>
            </div>

            <aside class="summary">
                <div class="summary-body">
                    <h2>{{ $booking->room?->category?->name ?? 'Your apartment' }}</h2>
                    @if ($property)<p class="meta">{{ $property->address ? $property->address.', ' : '' }}{{ $property->name }}</p>@endif
                    <dl class="lines">
                        <dt>Check in</dt><dd>{{ $booking->checkin_date?->format('D j M') }}</dd>
                        <dt>Check out</dt><dd>{{ $booking->checkout_date?->format('D j M') }}</dd>
                        <dt>Total</dt><dd>₦{{ number_format((int) $booking->total_amount) }}</dd>
                        <dt>Paid</dt><dd>₦{{ number_format($paid) }}</dd>
                        @if ($booked && $due > 0)<dt class="total">Due at check-in</dt><dd class="total">₦{{ number_format($due) }}</dd>@endif
                    </dl>
                </div>
            </aside>
        </div>
    </div>
</main>
@include('site.partials.footer')
@endsection
