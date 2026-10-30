{{-- A business the platform has paused (status locked or archived).
     Guests get the business's own contact details; staff are told their
     manager has been emailed. Rendered with a 503 by EnsureBusinessIsOpen. --}}
@extends('platform.layouts.plain')

@section('title', $tenant->name)

@section('content')
    <div class="bar"><i></i><i></i><i></i><span>https://<b>{{ $host }}</b></span></div>
    <h1>{{ $tenant->name }} isn't taking online bookings right now</h1>
    <p class="lead">You can still reach them directly to book or ask about your stay.</p>
    @if ($tenant->phone || $tenant->email)
        <div class="card">
            @if ($place)
                <span>{{ $place }}</span>
            @endif
            @if ($tenant->phone)
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $tenant->phone) }}">{{ $tenant->phone }}</a>
            @endif
            @if ($tenant->email)
                <a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a>
            @endif
        </div>
    @endif
    <p class="note">Work at {{ $tenant->name }}? Your manager has been emailed about what to do next.</p>
@endsection
