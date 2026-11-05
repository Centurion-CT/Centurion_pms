{{-- A building with nothing to book yet (/branches/{slug}, PublicLandingController::show). --}}
@extends('site.layouts.app')

@php($site = app(\App\Site\BookingSite::class))
@php($business = $site->business())

@section('page', 'pg-branch')
@section('title', $branch->name.' · '.$business->name)

@section('content')
@include('site.partials.header')

<main>
    <section class="hero hero--quiet">
        <div class="container">
            <div class="hero-copy">
                <h1 class="display">{{ $branch->name }}</h1>
                <p class="lead">Online booking here opens soon. Until then, call or email us to book.</p>
                <div class="contact">
                    @if ($business->phone)
                        <a class="btn btn-inverse btn-lg" href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}">Call {{ $business->phone }}</a>
                    @endif
                    @if ($business->email)
                        <a class="btn btn-inverse btn-lg" href="mailto:{{ $business->email }}">Email {{ $business->email }}</a>
                    @endif
                    @if ($site->branches()->isNotEmpty())
                        <a class="btn btn-inverse btn-lg" href="{{ route('site.results') }}">See our other apartments</a>
                    @endif
                </div>
            </div>
            @include('site.partials.bars')
        </div>
    </section>
</main>

@include('site.partials.footer')
@endsection
