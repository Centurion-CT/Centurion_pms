{{-- Site header. $compact = true puts the search in the header (results page). --}}
@php($site = app(\App\Site\BookingSite::class))
@php($business = $site->business())
<header class="site-header">
    <div class="container">
        <a class="logo" href="{{ route('tenant.landing') }}">@if ($logo = $site->logo())<img src="{{ $logo }}" alt="">@endif{{ $business->name }}</a>
        @if ($compact ?? false)
            @include('site.partials.search', ['compact' => true])
        @elseif ($nav ?? true)
            <nav class="site-nav" aria-label="Main">
                <a href="{{ route('tenant.landing') }}#explore">Locations</a>
                <a href="{{ route('site.results') }}">Apartments</a>
                @if ($longStays ?? false)<a href="{{ route('tenant.landing') }}#long">Long stays</a>@endif
            </nav>
        @endif
        <div class="end" @if ($compact ?? false) style="margin-left:0" @endif>
            @if ($business->phone)
                <a class="btn btn-ghost btn-sm" href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}">@if ($help ?? false)<span class="hide-sm">Need help?</span> @endif{{ $business->phone }}</a>
            @endif
            @unless ($compact ?? false)
                <a class="btn btn-secondary btn-sm" href="{{ route('site.manage') }}">Manage booking</a>
            @endunless
        </div>
    </div>
</header>
