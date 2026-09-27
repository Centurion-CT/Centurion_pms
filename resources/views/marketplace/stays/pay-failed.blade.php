@extends('layouts.marketplace', ['title' => 'Payment did not complete · Keverna Stays'])

@section('content')
@php
    $reason = (string) request()->query('reason', 'unknown');
    $friendly = match ($reason) {
        'missing-reference' => 'The payment provider did not return a transaction reference.',
        'unknown-reference' => "We couldn't find a booking for that transaction reference.",
        'not-success'       => 'The card issuer declined the transaction, or the payment was cancelled before it completed.',
        'amount-mismatch'   => 'The amount charged did not match the booking total. No booking was created.',
        'tenant-missing',
        'booking-missing'   => 'Your payment reached us but we could not tie it back to a booking. Our team has been notified.',
        default             => 'Something went wrong while completing your payment.',
    };
@endphp
<div style="max-width: 640px; margin: 0 auto; padding: 60px 24px;" data-testid="stays-pay-failed">
    <div style="text-align: center;">
        <div style="display: inline-flex; align-items: center; justify-content: center;
                    width: 68px; height: 68px; border-radius: 999px;
                    background: rgba(184, 92, 66, 0.14); color: #B85C42; margin: 0 auto 20px;">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 8v5M12 17h.01M4 12a8 8 0 1 0 16 0 8 8 0 0 0-16 0z"/>
            </svg>
        </div>
        <h1 style="font-size: clamp(22px, 4vw, 28px); font-weight: 700; margin: 0 0 8px;">
            Payment didn't go through
        </h1>
        <p style="color: #555; font-size: 15px; line-height: 1.6; max-width: 480px; margin: 0 auto 24px;">
            {{ $friendly }}
        </p>
        <p style="color: #6A6A6A; font-size: 12.5px; margin: 0 0 32px;">
            Reference code: <code>{{ $reason }}</code>
        </p>

        <a href="{{ route('marketplace.stays.index') }}"
           style="display: inline-block; padding: 12px 20px; border-radius: 999px;
                  font-size: 13.5px; font-weight: 600; color: #fff;
                  background: #14171A;">
            Try another stay
        </a>
    </div>
</div>
@endsection
