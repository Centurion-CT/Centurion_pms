@extends('layouts.legal')

@section('title', 'Terms of service')
@section('kicker', 'Legal')
@section('heading', 'Terms of service')

{{-- Wording unchanged from the previous standalone page. --}}
@section('content')
    <p>
        These terms govern your use of Keverna, the property
        management platform provided by <strong>FregateLab
        Limited</strong> (Abuja, Nigeria). By signing up you
        accept them; if you disagree, don't sign up.
    </p>

    <h2>The service</h2>
    <p>
        Keverna is a hosted PMS - direct booking pages, front
        desk console, POS, housekeeping, guest self-service
        portal, and Paystack payouts. We do our best to keep
        the service running around the clock; scheduled
        maintenance is announced in advance.
    </p>

    <h2>Your account</h2>
    <ul>
        <li>You are responsible for keeping your operator + staff credentials safe.</li>
        <li>You must be authorised to represent the property you sign up for.</li>
        <li>Adding or removing staff, running bookings, and handling guest data are actions taken by you, not by us.</li>
    </ul>

    <h2>Payments</h2>
    <p>
        Subscription fees are billed annually per unit-count
        tier. Card payments guests make through your booking
        page are subject to a flat 2% Paystack split; cash
        and bank transfers are 0%. Keverna takes zero
        commission on the booking value itself.
    </p>

    <h2>Guest data</h2>
    <p>
        You remain the data controller for guests booking on
        your Keverna site. Keverna acts as a processor on
        your behalf. See our
        <a href="/privacy">privacy notice</a> for how
        personal data is handled.
    </p>

    <h2>Fair use</h2>
    <p>
        Don't use Keverna to run illegal, fraudulent, or
        abusive operations. We reserve the right to suspend
        accounts that damage the platform (spam booking
        forms, brute-force attempts, payment fraud).
    </p>

    <h2>Uptime + support</h2>
    <p>
        We target high availability but don't offer a
        contractual SLA at Starter / Growth / Pro tiers.
        Enterprise agreements can include specific SLAs.
        Real-human support runs on WhatsApp during business
        hours (WAT).
    </p>

    <h2>Ending the relationship</h2>
    <p>
        You can cancel at any time; the current billing
        period runs to term. Your booking data stays
        exportable for 30 days after cancellation.
    </p>

    <h2>Governing law</h2>
    <p>
        These terms are governed by the laws of the Federal
        Republic of Nigeria.
    </p>

    <h2>Contact</h2>
    <p>
        <strong>FregateLab Limited</strong><br>
        Abuja, Nigeria<br>
        <a href="mailto:hello@keverna.com">hello@keverna.com</a>
    </p>
@endsection
