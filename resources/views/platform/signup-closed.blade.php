{{-- /signup while sign-up is off (keverna.signup_open, EnsureSignUpOpen). --}}
@extends('platform.layouts.plain')

@section('title', "Sign-up isn't open yet")

@section('content')
    <h1>Sign-up isn't open yet</h1>
    <p class="lead">We're bringing businesses onto {{ config('brand.name') }} one at a time. Email us about your apartments or hotel and we'll set you up.</p>
    <div class="actions">
        <a class="btn btn-primary" href="mailto:{{ $helpEmail }}?subject={{ rawurlencode('Setting up on '.config('brand.name')) }}">Email {{ $helpEmail }}</a>
        <a class="btn btn-secondary" href="{{ route('platform.login') }}">Log in to my business</a>
    </div>
@endsection
