@extends('layouts.legal')

@php($business = \App\Models\Tenant::current())
@php($name = $business?->name ?? config('brand.name'))

@section('title', 'Terms of booking')
@section('kicker', 'Legal')
@section('heading', 'Terms of booking')

{{-- Served on each business's own address, so the business is the party
     guests deal with; Centurion is only the software it runs on. --}}
@section('content')
    <p>
        These terms cover bookings made on this website with
        <strong>{{ $name }}</strong>. By booking you accept them.
        The site runs on {{ config('brand.name') }}, booking
        software that {{ $name }} uses to take bookings and payments.
    </p>

    <h2>Your booking</h2>
    <p>
        A booking is with {{ $name }}. It is confirmed once payment,
        or the deposit where one is offered, has gone through, and
        you receive a booking reference by email. The dates, guests
        and price shown at checkout are what you book.
    </p>

    <h2>Payments</h2>
    <p>
        Card and bank transfer payments are processed by Paystack.
        Prices on this site include the card and booking fees shown at
        checkout. If you pay a deposit, the rest is due at check-in.
    </p>

    <h2>Changes and cancellations</h2>
    <p>
        The cancellation policy for your apartment is shown at checkout.
        To change or cancel, use <a href="{{ route('site.manage') }}">Manage booking</a>
        with your booking reference, or contact {{ $name }}.
    </p>

    <h2>Your stay</h2>
    <p>
        Check-in and check-out times and the house rules are set by
        {{ $name }} and shown when you book. Please follow them and
        any reasonable instructions from staff during your stay.
    </p>

    <h2>Your data</h2>
    <p>
        See the <a href="{{ route('legal.privacy') }}">privacy notice</a>
        for how your personal information is handled.
    </p>

    <h2>Governing law</h2>
    <p>
        These terms are governed by the laws of the Federal
        Republic of Nigeria.
    </p>

    <h2>Contact</h2>
    <p>
        <strong>{{ $name }}</strong><br>
        @if ($business?->phone){{ $business->phone }}<br>@endif
        @if ($business?->email)<a href="mailto:{{ $business->email }}">{{ $business->email }}</a>@endif
    </p>
@endsection
