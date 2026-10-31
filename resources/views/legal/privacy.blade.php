@extends('layouts.legal')

@php($business = \App\Models\Tenant::current())
@php($name = $business?->name ?? config('brand.name'))

@section('title', 'Privacy notice')
@section('kicker', 'Legal')
@section('heading', 'Privacy notice')

{{-- Served on each business's own address: the business decides how its
     guests' information is used; Centurion runs the software for it. --}}
@section('content')
    <p>
        This notice explains how <strong>{{ $name }}</strong> handles
        the personal information you give when you book or stay with
        us through this website.
    </p>

    <h2>What we collect</h2>
    <ul>
        <li>Your name, phone number and email.</li>
        <li>Your booking: dates, guests, apartment, anything you tell us in the notes, and your company if you add one.</li>
        <li>Payment records. Card details are entered on Paystack's page; we never see or store them.</li>
        <li>ID details the front desk records at check-in, where the law requires them.</li>
    </ul>

    <h2>Why we hold it</h2>
    <ul>
        <li>To take and manage your booking and your stay.</li>
        <li>To send your receipt, booking link and reminders.</li>
        <li>To keep the records we're required to keep.</li>
    </ul>

    <h2>Who sees it</h2>
    <p>
        {{ $name }}'s staff, as their work needs. The booking software,
        {{ config('brand.name') }}, stores it on our behalf and keeps it
        apart from every other business that uses the software. We also
        use Paystack for payments and email and WhatsApp providers to
        send you messages. We never sell your information.
    </p>

    <h2>Your rights</h2>
    <p>
        Under Nigeria's data protection law you can ask to see the
        personal information we hold about you, correct anything wrong,
        or have it deleted where we don't need to keep it.
        @if ($business?->email)Email <a href="mailto:{{ $business->email }}">{{ $business->email }}</a> with your request.@else Contact the front desk with your request.@endif
    </p>

    <h2>Cookies</h2>
    <p>See the <a href="{{ route('legal.cookies') }}">cookie policy</a>.</p>

    <h2>Contact</h2>
    <p>
        <strong>{{ $name }}</strong><br>
        @if ($business?->phone){{ $business->phone }}<br>@endif
        @if ($business?->email)<a href="mailto:{{ $business->email }}">{{ $business->email }}</a>@endif
    </p>
@endsection
