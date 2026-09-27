@extends('layouts.marketplace', ['title' => 'Booking confirmed · Keverna Stays'])

@section('content')
@php
    /** @var \App\Models\Booking $booking */
    $property = $booking->room?->property;
    $city = trim((string) ($property?->city ?? ''));
    $checkin = optional($booking->checkin_date)->format('D, d M Y');
    $checkout = optional($booking->checkout_date)->format('D, d M Y');
@endphp
<div style="max-width: 720px; margin: 0 auto; padding: 60px 24px;" data-testid="stays-pay-success">

    <div style="text-align: center;">
        <div style="display: inline-flex; align-items: center; justify-content: center;
                    width: 68px; height: 68px; border-radius: 999px;
                    background: #DCFCE7; color: #1B7A3D; margin: 0 auto 20px;">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <h1 style="font-size: clamp(24px, 4vw, 32px); font-weight: 700; margin: 0 0 6px; letter-spacing: -0.02em;">
            Booking confirmed
        </h1>
        <p style="color: #6A6A6A; margin: 0 0 32px; font-size: 15px;">
            Reference <strong data-testid="stays-booking-ref">{{ $booking->booking_ref }}</strong>
        </p>
    </div>

    <div style="border: 1px solid #EBEBEB; border-radius: 16px; padding: 24px; background: #FBFAF7;">
        @if ($property)
            <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #6A6A6A; margin-bottom: 4px;">
                {{ $city }}
            </div>
            <div style="font-size: 20px; font-weight: 700; margin-bottom: 16px;">{{ $property->name }}</div>
        @endif

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
            <div>
                <div style="font-size: 11.5px; color: #6A6A6A; text-transform: uppercase; letter-spacing: 0.06em;">Check-in</div>
                <div style="font-size: 14.5px; font-weight: 600; margin-top: 2px;">{{ $checkin }}</div>
            </div>
            <div>
                <div style="font-size: 11.5px; color: #6A6A6A; text-transform: uppercase; letter-spacing: 0.06em;">Check-out</div>
                <div style="font-size: 14.5px; font-weight: 600; margin-top: 2px;">{{ $checkout }}</div>
            </div>
            <div>
                <div style="font-size: 11.5px; color: #6A6A6A; text-transform: uppercase; letter-spacing: 0.06em;">Guest</div>
                <div style="font-size: 14.5px; font-weight: 600; margin-top: 2px;">{{ $booking->guest_name }}</div>
            </div>
            <div>
                <div style="font-size: 11.5px; color: #6A6A6A; text-transform: uppercase; letter-spacing: 0.06em;">Total paid</div>
                <div style="font-size: 14.5px; font-weight: 700; margin-top: 2px; color: #14171A;">
                    &#8358;{{ number_format((int) $booking->total_amount) }}
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top: 24px; padding: 16px 18px; border: 1px solid #F1F7E6; background: #FBFEF5; border-radius: 12px;">
        <div style="font-size: 13.5px; color: #4A6B1E; line-height: 1.6;">
            <strong>What happens next</strong><br>
            A confirmation email is on its way to <strong>{{ $booking->guest_email }}</strong>.
            The property has been notified. Expect a message before check-in with any last-minute details.
        </div>
    </div>

    <div style="text-align: center; margin-top: 32px;">
        <a href="{{ route('marketplace.stays.index') }}"
           style="display: inline-block; padding: 12px 20px; border: 1px solid #DDDDDD; border-radius: 999px;
                  font-size: 13.5px; font-weight: 600; color: #222;">
            ← Browse more stays
        </a>
    </div>

</div>
@endsection
