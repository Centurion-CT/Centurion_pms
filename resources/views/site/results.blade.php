{{-- Apartments free for a stay, beside the map when the buildings have
     positions (SiteController::results). --}}
@extends('site.layouts.app')

@php($business = app(\App\Site\BookingSite::class)->business())
@php($base = $stay->query() + array_filter(['where' => $where->all(), 'size' => $size, 'sort' => $sort === 'free' ? 'free' : null]))
@php($link = fn (array $change) => route('site.results', array_filter(array_merge($base, $change), fn ($v) => $v !== null && $v !== '' && $v !== [])))

@section('page', 'pg-results')
@section('title', 'Apartments free '.$stay->label().' · '.$business->name)

@section('content')
@include('site.partials.header', ['compact' => true, 'where' => $where->all(), 'stay' => $stay])

<main class="container container--wide results">
    <div class="results-list">
        <h1 class="title">{{ $listings->count() }} {{ \Illuminate\Support\Str::plural('apartment', $listings->count()) }} free for your dates</h1>
        <p class="meta">{{ $stay->nights() }} {{ \Illuminate\Support\Str::plural('night', $stay->nights()) }}. Totals are for the whole apartment and include all fees.</p>

        @if ($branches->isNotEmpty())
            <nav class="filters" aria-label="Filters">
                @if ($branches->count() > 1)
                    <a class="chip" href="{{ $link(['where' => null]) }}" aria-pressed="{{ $where->isEmpty() ? 'true' : 'false' }}">All locations</a>
                    @foreach ($branches as $branch)
                        <a class="chip" href="{{ $link(['where' => [$branch->slug]]) }}" aria-pressed="{{ $where->count() === 1 && $where->first() === $branch->slug ? 'true' : 'false' }}">{{ $branch->name }}</a>
                    @endforeach
                @endif
                @if ($sizes->count() > 1)
                    <a class="chip" href="{{ $link(['size' => null]) }}" aria-pressed="{{ $size === '' ? 'true' : 'false' }}">Any size</a>
                    @foreach ($sizes as $s)
                        <a class="chip" href="{{ $link(['size' => $s]) }}" aria-pressed="{{ $size === $s ? 'true' : 'false' }}">{{ $s }}</a>
                    @endforeach
                @endif
                @if ($mapPins !== [])<a class="chip chip-map" href="#map">Map</a>@endif
                @if ($listings->count() > 1)
                    <span class="sort">
                        <a class="chip" href="{{ $link(['sort' => null]) }}" aria-pressed="{{ $sort === 'price' ? 'true' : 'false' }}">Lowest price</a>
                        <a class="chip" href="{{ $link(['sort' => 'free']) }}" aria-pressed="{{ $sort === 'free' ? 'true' : 'false' }}">Most free</a>
                    </span>
                @endif
            </nav>
        @endif

        @if ($branches->isEmpty())
            <div class="empty">
                <p class="lead">Online booking opens soon.</p>
                <p class="meta">Until then, call @if ($business->phone){{ $business->phone }} @endif or email @if ($business->email)<a href="mailto:{{ $business->email }}">{{ $business->email }}</a> @endif to book.</p>
            </div>
        @elseif ($listings->isEmpty())
            <div class="empty">
                <p class="lead">Nothing is free for every night of {{ $stay->label() }}.</p>
                @php($filtered = $where->isNotEmpty() || $size !== '')
                <p class="meta">Try other dates or fewer guests{{ $filtered ? ', or ' : '.' }}@if ($filtered)<a href="{{ route('site.results', $stay->query()) }}">clear the filters</a>.@endif</p>
            </div>
        @else
            <div class="rows">
                @foreach ($listings as $listing)
                    @include('site.partials.listing', ['listing' => $listing, 'stay' => $stay, 'row' => true])
                @endforeach
            </div>
        @endif
    </div>

    @if ($mapPins !== [])
        <aside class="results-map" id="map-box" aria-label="Map of locations"><div class="map" id="map"></div></aside>
    @endif
</main>

@if ($mapPins !== [])
    @include('site.partials.map')
    @push('scripts')
        <script>
            (function () {
                var m = siteMap(document.getElementById('map'), @json($mapPins), { withName: true, zoomPosition: 'topright' });
                var where = @json($where->values());
                m.dim(where.length ? where : null);
                var rows = document.querySelector('.rows');
                if (rows) rows.addEventListener('mouseover', function (e) { var c = e.target.closest('.listing'); if (c) m.activate(c.dataset.branch); });
                var links = @json($branches->mapWithKeys(fn ($b) => [$b->slug => $link(['where' => [$b->slug]])]));
                m.onPick = function (slug) { if (links[slug]) location.href = links[slug]; };
            })();
        </script>
    @endpush
@endif
@endsection
