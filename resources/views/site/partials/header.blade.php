{{-- Site header. $compact = true swaps the menu for the guest's stay and a
     Change control (results page); $help = true is the quieter header on
     checkout and the guest's own booking pages. --}}
@php($site = app(\App\Site\BookingSite::class))
@php($business = $site->business())
@php($tel = preg_replace('/[^0-9+]/', '', (string) $business->phone))
<header class="site-header">
    <div class="container">
        <a class="logo" href="{{ route('tenant.landing') }}">@if ($logo = $site->logo())<img src="{{ $logo }}" alt="">@endif<span>{{ $business->name }}</span></a>
        @if ($compact ?? false)
            <details class="change">
                <summary>
                    <b>{{ $stay->label() }}</b>
                    <span class="hide-sm">{{ $stay->adults }} {{ \Illuminate\Support\Str::plural('adult', $stay->adults) }}</span>
                    <span class="change-go">Change</span>
                </summary>
                <div class="change-panel">@include('site.partials.search', ['stay' => $stay, 'where' => $where ?? []])</div>
            </details>
        @elseif (($nav ?? true) && ! ($help ?? false))
            <nav class="site-nav" aria-label="Main">
                <a href="{{ route('site.results') }}">Apartments</a>
                @if ($site->branches()->count() > 1)<a href="{{ route('tenant.landing') }}#locations">Locations</a>@endif
                @if ($longStays ?? false)<a href="{{ route('tenant.landing') }}#long">Long stays</a>@endif
            </nav>
        @endif
        <div class="end">
            @if ($help ?? false)
                @if ($business->phone)<p class="help">Need help? <a href="tel:{{ $tel }}">{{ $business->phone }}</a></p>@endif
            @else
                @if ($business->phone)
                    <a class="tel" href="tel:{{ $tel }}" aria-label="Call {{ $business->phone }}">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg>
                        <span>{{ $business->phone }}</span>
                    </a>
                @endif
                <a class="btn btn-secondary btn-sm" href="{{ route('site.manage') }}"><span class="hide-sm">Manage booking</span><span class="show-sm">My booking</span></a>
            @endif
        </div>
    </div>
</header>
