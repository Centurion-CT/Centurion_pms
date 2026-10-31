{{-- The booking site's home page on each business's address (SiteController::home).
     Design: design/website/c-map/home.html (Map Desk, v2). --}}
@extends('site.layouts.app')

@php($business = app(\App\Site\BookingSite::class)->business())
@php($count = $branches->count())

@section('page', 'pg-home')
@section('title', $business->name.($city ? ' · Apartments in '.$city : ''))
@section('description', 'Book '.$business->name.' directly: live availability'.($count > 1 ? ' across '.$count.' buildings' : '').', paid securely online.')

@section('content')
@include('site.partials.header', ['longStays' => $longStays->isNotEmpty()])

<main>
    <div class="container">
        <div class="hero">
            <h1 class="display">Find a free apartment {{ $count > 1 ? 'anywhere ' : '' }}{{ $city ? 'in '.$city : 'with us' }}</h1>
            <p class="lead">
                @if ($count > 1)
                    {{ $count }} buildings, live from our front desk. Pick a spot on the map and book online.
                @else
                    Live from our front desk. Pick your dates and book online.
                @endif
            </p>
        </div>
        <div class="search-row">
            @include('site.partials.search', ['stay' => $stay])
        </div>

        @if ($count === 0)
            <div class="empty"><p class="lead">Online booking isn't open yet. Call us to book.</p></div>
        @else
            <div class="stage" id="explore" x-data>
                @if ($mapPins !== [])
                    <div class="map" id="map"></div>
                @endif
                <aside class="rail" aria-label="Locations" @if ($mapPins === []) style="position:static;width:auto;margin-top:var(--s-12)" @endif>
                    <div class="rail-head"><span class="h3">{{ $count }} {{ \Illuminate\Support\Str::plural('location', $count) }}</span><span class="meta">{{ $freeTonight->sum() }} free tonight</span></div>
                    <div class="rail-list" id="rail">
                        @foreach ($branches as $branch)
                            @php($free = $freeTonight[$branch->id] ?? 0)
                            @php($photo = $branch->getFirstMedia('hero') ?? $branch->getFirstMedia('gallery'))
                            <div class="loc {{ $loop->first ? 'is-active' : '' }}" data-slug="{{ $branch->slug }}" role="button" tabindex="0" aria-expanded="{{ $loop->first ? 'true' : 'false' }}">
                                <div class="loc-top"><span class="h3">{{ $branch->name }}</span>
                                    @if ($free > 0)<span class="tag {{ $free <= 2 ? 'tag-last' : 'tag-free' }}">{{ $free }} free tonight</span>@else<span class="tag">Full tonight</span>@endif
                                </div>
                                <div class="loc-top"><span class="meta">{{ $branch->city ?: $branch->address }} · {{ $branch->roomCategories->count() }} apartment {{ \Illuminate\Support\Str::plural('type', $branch->roomCategories->count()) }}</span><span class="meta">from <span class="price">₦{{ number_format((int) $branch->roomCategories->min('base_rate')) }}</span></span></div>
                                <div class="loc-more">
                                    @if ($photo)<img src="{{ $photo->getUrl() }}" alt="" loading="lazy">@endif
                                    @php($near = collect($branch->nearby_places ?? [])->take(3))
                                    @if ($near->isNotEmpty())
                                        <ul>@foreach ($near as $place)<li>{{ $place['label'] ?? '' }}@if (! empty($place['distance'])), {{ $place['distance'] }}@endif</li>@endforeach</ul>
                                    @endif
                                    <a class="btn btn-primary btn-sm btn-block" href="{{ route('site.results', ['where' => [$branch->slug]] + $stay->query()) }}">See {{ $branch->name }} apartments</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </aside>
            </div>
        @endif
    </div>

    @if ($featured->isNotEmpty())
        <section class="block" id="stays">
            <div class="container">
                <div class="block-head">
                    <div><h2 class="h1">Free for {{ $stay->label() }}</h2><p class="lead">Each bar is a night. Green is free, amber is the last one, grey is taken.</p></div>
                    <a class="btn btn-secondary" href="{{ route('site.results', $stay->query()) }}">See all {{ $listingCount }} {{ \Illuminate\Support\Str::plural('apartment', $listingCount) }}</a>
                </div>
                <div class="listings">
                    @foreach ($featured as $listing)
                        @include('site.partials.listing', ['listing' => $listing, 'stay' => $stay, 'href' => route('site.results', ['open' => $listing->type->id] + $stay->query())])
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($longStays->isNotEmpty())
        <section class="block" id="long">
            <div class="container">
                <div class="long">
                    <div>
                        <h2 class="h1">Staying a month or more?</h2>
                        <p>Monthly rates on longer stays. Tell us your dates and we'll send a quote.</p>
                        @if ($business->email)
                            <a class="btn btn-inverse btn-lg" href="mailto:{{ $business->email }}?subject={{ rawurlencode('Long-stay quote') }}">Get a long-stay quote</a>
                        @elseif ($business->phone)
                            <a class="btn btn-inverse btn-lg" href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}">Call for a long-stay quote</a>
                        @endif
                    </div>
                    <table class="rates">
                        <thead><tr><th>Apartment</th><th class="r">Per night</th><th class="r">Per month</th></tr></thead>
                        <tbody>
                            @foreach ($longStays as $row)
                                <tr><td>{{ $row['name'] }}<span class="note">{{ $row['branch'] }}</span></td><td class="r">₦{{ number_format($row['night']) }}</td><td class="r m">₦{{ number_format($row['month']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif

    <section class="block">
        <div class="container">
            <div class="block-head"><h2 class="h1">How booking works</h2></div>
            <div class="steps">
                <div class="step"><h3 class="h3">Choose dates and an apartment</h3><p class="meta">Only apartments free for every night of your stay can be booked.</p></div>
                <div class="step"><h3 class="h3">Pay by card or transfer</h3><p class="meta">Paystack handles the payment. Your receipt comes by email.</p></div>
                @php($checkIn = $branches->first()?->houseRules()['check_in_from'] ?? '')
                <div class="step"><h3 class="h3">Arrive and collect your key</h3><p class="meta">@if ($t = \App\Site\Stay::time($checkIn))Check in from {{ $t }}. @endif The front desk already has your booking.</p></div>
            </div>
        </div>
    </section>
</main>

@include('site.partials.footer')

@if ($mapPins !== [])
    @include('site.partials.map')
    @push('scripts')
        <script>
            (function () {
                var wide = innerWidth > 720;
                var m = siteMap(document.getElementById('map'), @json($mapPins), { withName: wide, padTL: wide ? [440, 120] : [40, 40], padBR: wide ? [80, 60] : [40, 40] });
                var rail = document.getElementById('rail');
                function activate(slug, fly) {
                    rail.querySelectorAll('.loc').forEach(function (b) { var on = b.dataset.slug === slug; b.classList.toggle('is-active', on); b.setAttribute('aria-expanded', on); });
                    m.activate(slug);
                    if (fly) m.flyTo(slug, wide ? 0.012 : 0);
                }
                rail.addEventListener('click', function (e) { var b = e.target.closest('.loc'); if (b && !e.target.closest('a')) activate(b.dataset.slug, true); });
                rail.addEventListener('keydown', function (e) { var b = e.target.closest('.loc'); if (b && (e.key === 'Enter' || e.key === ' ') && !e.target.closest('a')) { e.preventDefault(); activate(b.dataset.slug, true); } });
                m.onPick = function (slug) { activate(slug, false); var b = rail.querySelector('[data-slug="' + slug + '"]'); if (b) b.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); };
                var first = rail.querySelector('.loc');
                if (first) activate(first.dataset.slug, false);
            })();
        </script>
    @endpush
@endif
@endsection
