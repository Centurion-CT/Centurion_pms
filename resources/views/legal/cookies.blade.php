@extends('layouts.legal')

@section('title', 'Cookie policy')
@section('kicker', 'Legal')
@section('heading', 'Cookie policy')

{{-- Wording unchanged from the previous standalone page. --}}
@section('content')
    <h2>What cookies are</h2>
    <p>
        A cookie is a small text file a site puts on your device
        to remember something between visits. keverna.com uses a
        handful of them, all first-party.
    </p>

    <h2>What we set</h2>
    <p><strong>Strictly necessary.</strong> These keep the site working:</p>
    <ul>
        <li><code>keverna_session</code> - keeps you signed in.</li>
        <li><code>XSRF-TOKEN</code> - prevents cross-site form forgery.</li>
        <li><code>kv_cookie_consent</code> - remembers your cookie choice so we don't ask again.</li>
    </ul>
    <p>
        <strong>Optional (only if you tap "Accept all").</strong>
        If we add analytics or marketing tags in the future they
        will only load after you've explicitly opted in on the
        consent banner. Right now nothing else is set.
    </p>

    <h2>Changing your mind</h2>
    <p>
        Clear cookies for keverna.com in your browser to be
        re-prompted on your next visit. You can also block cookies
        entirely in your browser settings - the site will still
        work but you may need to sign in more often.
    </p>

    <h2>Contact</h2>
    <p>
        Questions? Email
        <a href="mailto:hello@keverna.com">hello@keverna.com</a>.
        Keverna is a product of FregateLab Limited, Abuja, Nigeria.
    </p>
@endsection
