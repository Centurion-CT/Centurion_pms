@extends('layouts.legal')

@section('title', 'Privacy notice')
@section('kicker', 'Legal')
@section('heading', 'Privacy notice')

{{-- Wording unchanged from the previous standalone page. --}}
@section('content')
    <p>
        Keverna is a product of <strong>FregateLab Limited</strong>,
        Abuja, Nigeria. This notice explains how we handle
        personal information collected through the marketing
        site (keverna.com) and the operator dashboard hosted
        on per-tenant subdomains.
    </p>

    <h2>What we collect</h2>
    <p><strong>On the marketing site:</strong> if you fill in the
    demo form, the API waitlist, or subscribe to our newsletter,
    we store what you gave us (name, property, email, WhatsApp
    phone, use-case notes) plus your IP address and user-agent
    string for spam prevention.</p>
    <p><strong>On the operator dashboard:</strong> the operator's
    own booking / guest / finance data lives inside their own
    tenant. Keverna never shares one tenant's data with another
    tenant, and the tenant remains the sole controller of the
    guest data they collect through their booking page.</p>

    <h2>Why we hold it</h2>
    <ul>
        <li>Contact you about the demo you booked.</li>
        <li>Issue you a licence code + welcome email.</li>
        <li>Send you the newsletter you subscribed to.</li>
        <li>Answer support questions on WhatsApp / email.</li>
        <li>Keep the sales pipeline organised inside our admin console.</li>
    </ul>

    <h2>Who sees it</h2>
    <p>Only the Keverna / FregateLab team, and any subprocessors
    strictly needed to deliver the service (Paystack for card
    payments, Sendpipe for WhatsApp templates, a transactional
    mail provider for confirmations). We never sell your data.</p>

    <h2>Your rights</h2>
    <p>Under the Nigeria Data Protection Regulation (NDPR) you
    can ask us to show you what personal data we hold about
    you, correct anything wrong, or delete it entirely. Email
    <a href="mailto:hello@keverna.com">hello@keverna.com</a>
    with the request and we'll action it within 30 days.</p>

    <h2>Cookies</h2>
    <p>See our <a href="/cookies">cookie policy</a> for the
    specific cookies we use and how to manage them.</p>

    <h2>Contact</h2>
    <p>
        <strong>FregateLab Limited</strong><br>
        Abuja, Nigeria<br>
        <a href="mailto:hello@keverna.com">hello@keverna.com</a>
    </p>
@endsection
