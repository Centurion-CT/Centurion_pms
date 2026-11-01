@extends('layouts.legal')

@php($business = \App\Models\Tenant::current())

@section('title', 'Cookie policy')
@section('kicker', 'Legal')
@section('heading', 'Cookie policy')

@section('content')
    <h2>What cookies are</h2>
    <p>
        A cookie is a small text file a site puts on your device to
        remember something between visits. This site uses a handful
        of them, all its own.
    </p>

    <h2>What we set</h2>
    <p><strong>Strictly necessary.</strong> These keep the site working:</p>
    <ul>
        <li><code>{{ config('session.cookie') }}</code> - keeps your booking form and any sign-in working.</li>
        <li><code>XSRF-TOKEN</code> - protects forms against cross-site forgery.</li>
        <li><code>kv_cookie_consent</code> - remembers your cookie choice so we don't ask again.</li>
    </ul>
    <p>
        <strong>Optional (only if you tap "Accept all").</strong>
        If analytics or marketing tags are added in future, they will
        only load after you opt in on the consent banner. Right now
        nothing else is set.
    </p>

    <h2>Changing your mind</h2>
    <p>
        Clear this site's cookies in your browser to be asked again on
        your next visit. You can also block cookies entirely; the site
        will still work, but you may need to fill in forms again.
    </p>

    <h2>Contact</h2>
    <p>
        Questions?
        @if ($business?->email)Email <a href="mailto:{{ $business->email }}">{{ $business->email }}</a>.@else Contact the front desk.@endif
    </p>
@endsection
