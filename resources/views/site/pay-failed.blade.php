{{-- When a payment doesn't complete (/stays/pay/failed?reason=…). --}}
@extends('site.layouts.app')

@php($reason = (string) request()->query('reason', 'unknown'))
@php($friendly = match ($reason) {
    'not-success' => 'The card issuer declined the payment, or it was cancelled before it finished. You have not been charged.',
    'amount-mismatch' => "The amount charged didn't match the booking. Nothing was booked; the front desk will sort out any charge.",
    'missing-reference', 'unknown-reference' => "We couldn't match this payment to a booking. If you were charged, call the front desk with the time you paid.",
    'booking-missing' => 'Your payment reached us but we could not match it to a booking. Call the front desk and we will sort it out.',
    'verify-error' => "We couldn't confirm the payment with Paystack just now. If you were charged, your booking will confirm on its own within a few minutes.",
    default => 'Something went wrong while finishing your payment.',
})

@section('page', 'pg-checkout')
@section('title', "Payment didn't go through")

@section('content')
@include('site.partials.header', ['nav' => false, 'help' => true])

<main class="container page" data-testid="stays-pay-failed">
    <div class="narrow">
        <h1 class="h1">Payment didn't go through</h1>
        <p class="lead" style="margin-top:var(--s-3)">{{ $friendly }}</p>
        <div style="display:flex;gap:var(--s-3);flex-wrap:wrap;margin-top:var(--s-8)">
            <a class="btn btn-primary" href="{{ route('site.results') }}">Try again</a>
            <a class="btn btn-secondary" href="{{ route('site.manage') }}">Find my booking</a>
        </div>
        <p class="meta" style="margin-top:var(--s-6)">Reference code: {{ $reason }}</p>
    </div>
</main>
@endsection
