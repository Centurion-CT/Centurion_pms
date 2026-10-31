{{-- Apartments free for a stay, beside the map (SiteController::results).
     ?open={type slug} swaps the map for that apartment's detail panel.
     Design: design/website/c-map/results.html. --}}
@extends('site.layouts.app')

@php($business = app(\App\Site\BookingSite::class)->business())
@php($base = $stay->query() + array_filter(['where' => $where->all(), 'size' => $size, 'sort' => $sort === 'free' ? 'free' : null]))
@php($link = fn (array $change) => route('site.results', array_filter(array_merge($base, $change), fn ($v) => $v !== null && $v !== '' && $v !== [])))

@section('page', 'pg-results')
@section('title', 'Apartments free '.$stay->label().' · '.$business->name)

@section('content')
@include('site.partials.header', ['compact' => true, 'where' => $where->all(), 'stay' => $stay])

<nav class="filters" aria-label="Filters">
    @if ($branches->count() > 1)
        <a class="chip" href="{{ $link(['where' => null]) }}" aria-pressed="{{ $where->isEmpty() ? 'true' : 'false' }}">All locations</a>
        @foreach ($branches as $branch)
            <a class="chip" href="{{ $link(['where' => [$branch->slug]]) }}" aria-pressed="{{ $where->count() === 1 && $where->first() === $branch->slug ? 'true' : 'false' }}">{{ $branch->name }} <span class="count">{{ $countByBranch[$branch->slug] ?? 0 }}</span></a>
        @endforeach
        <span class="sep"></span>
    @endif
    @if ($sizes->count() > 1)
        <a class="chip" href="{{ $link(['size' => null]) }}" aria-pressed="{{ $size === '' ? 'true' : 'false' }}">Any size</a>
        @foreach ($sizes as $s)
            <a class="chip" href="{{ $link(['size' => $s]) }}" aria-pressed="{{ $size === $s ? 'true' : 'false' }}">{{ $s }}</a>
        @endforeach
    @endif
    <a class="btn btn-ghost btn-sm end" href="{{ $link(['sort' => $sort === 'price' ? 'free' : null]) }}">Sort: {{ $sort === 'price' ? 'lowest price' : 'most free' }}</a>
</nav>

<div class="split">
    <div class="list">
        <div class="list-head">
            <div>
                <h1 class="h2">{{ $listings->count() }} {{ \Illuminate\Support\Str::plural('apartment', $listings->count()) }} free for your dates</h1>
                <p class="meta">{{ $stay->label() }} · {{ $stay->adults }} {{ \Illuminate\Support\Str::plural('adult', $stay->adults) }}. Prices are for the whole apartment. The bars show the next {{ \App\Site\BookingSite::STRIP_NIGHTS }} nights; your {{ $stay->nights() }} {{ $stay->nights() === 1 ? 'is' : 'are' }} underlined.</p>
            </div>
        </div>
        @if ($listings->isEmpty())
            <div class="empty">
                <p class="lead">Nothing is free for every night of {{ $stay->label() }}.</p>
                @php($filtered = $where->isNotEmpty() || $size !== '')
                <p class="meta" style="margin-top:var(--s-2)">Try other dates or fewer guests{{ $filtered ? ', or ' : '.' }}@if ($filtered)<a href="{{ route('site.results', $stay->query()) }}">clear the filters</a>.@endif</p>
            </div>
        @else
            <div class="grid">
                @foreach ($listings as $listing)
                    @include('site.partials.listing', ['listing' => $listing, 'stay' => $stay, 'href' => $link(['open' => $listing->type->id]), 'active' => $open?->type->is($listing->type)])
                @endforeach
            </div>
        @endif
    </div>

    <div class="side {{ $open ? 'show' : '' }}" id="side">
        @if ($mapPins !== [])<div class="map" id="map"></div>@endif
        @if ($open)
            @php($q = $open->quote)
            @php($rules = $open->branch->houseRules())
            <aside class="detail" aria-label="{{ $open->type->name }}">
                <div class="detail-photo">
                    @if ($photo = $open->photo())<img src="{{ $photo }}" alt="">@endif
                    <a class="btn btn-inverse btn-sm" href="{{ $link(['open' => null]) }}">← Back{{ $mapPins !== [] ? ' to map' : '' }}</a>
                </div>
                <div class="detail-body">
                    <div class="full">
                        <div class="listing-head">
                            <h2 class="h1" style="font-size:var(--t-2xl);line-height:var(--lh-2xl)">{{ $open->type->name }}</h2>
                            @if ($open->freeForStay === 0)<span class="tag">Taken for your dates</span>@elseif ($open->freeForStay === 1)<span class="tag tag-last">Last one</span>@else<span class="tag tag-free">{{ $open->freeForStay }} free</span>@endif
                        </div>
                        <p class="meta">{{ $open->branch->name }}@if ($open->branch->address) · {{ $open->branch->address }}@endif · sleeps {{ $open->sleeps() }}</p>
                    </div>
                    <div>
                        @if ($open->type->description)
                            <h3 class="h3">The apartment</h3>
                            <p class="meta" style="margin-top:var(--s-2);color:var(--ink-700)">{{ $open->type->description }}</p>
                        @endif
                        @php($near = collect($open->branch->nearby_places ?? []))
                        @if ($near->isNotEmpty())
                            <h3 class="h3" style="margin-top:var(--s-5)">Around {{ $open->branch->name }}</h3>
                            <ul>@foreach ($near as $place)<li>{{ $place['label'] ?? '' }}@if (! empty($place['distance'])), {{ $place['distance'] }}@endif</li>@endforeach</ul>
                        @endif
                    </div>
                    <div>
                        @foreach ($calendar as $month)
                            <div class="cal-head" @if (! $loop->first) style="margin-top:var(--s-4)" @endif><h3 class="h3">{{ $month['month'] }}</h3>@if ($loop->first)<span class="meta">{{ $stay->nights() }} {{ \Illuminate\Support\Str::plural('night', $stay->nights()) }}</span>@endif</div>
                            <div class="cal" aria-hidden="true">
                                @foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $dow)<span class="dow">{{ $dow }}</span>@endforeach
                                @foreach ($month['cells'] as $cell)<span class="day {{ $cell['state'] }}">{{ $cell['day'] }}</span>@endforeach
                            </div>
                        @endforeach
                        @if ($q)
                            <hr class="divider">
                            <dl class="lines">
                                <dt>₦{{ number_format($q->wasPerNight() ?? $q->perNight()) }} × {{ $q->nights }} {{ \Illuminate\Support\Str::plural('night', $q->nights) }}</dt><dd>₦{{ number_format($q->subtotal) }}</dd>
                                @if ($q->discount > 0)<dt>Discount</dt><dd class="save">−₦{{ number_format($q->discount) }}</dd>@endif
                                @if ($q->fees > 0)<dt>Card and booking fees</dt><dd>₦{{ number_format($q->fees) }}</dd>@endif
                            </dl>
                        @endif
                    </div>
                </div>
                <div class="detail-foot">
                    <div class="sum">
                        @if ($q)<div class="h2">₦{{ number_format($q->total) }}</div>@endif
                        <p class="meta">{{ $stay->label() }}@if ($t = \App\Site\Stay::time($rules['check_in_from'] ?? null)) · check in from {{ $t }}@endif</p>
                    </div>
                    @if ($open->freeForStay > 0 && $q)
                        <a class="btn btn-primary btn-lg" href="{{ route('site.checkout', ['branch' => $open->branch->slug, 'type' => $open->type->slug] + $stay->query()) }}">Continue</a>
                    @else
                        <span class="btn btn-lg" aria-disabled="true" style="background:var(--ink-100);color:var(--ink-500)">Not free for these dates</span>
                    @endif
                </div>
            </aside>
        @endif
    </div>
</div>

@if ($mapPins !== [])
    @include('site.partials.map')
    @push('scripts')
        <script>
            (function () {
                var m = siteMap(document.getElementById('map'), @json($mapPins), { zoomPosition: 'topright' });
                var where = @json($where->values());
                m.dim(where.length ? where : null);
                @if ($open) m.activate(@json($open->branch->slug)); @endif
                var grid = document.querySelector('.grid');
                if (grid) grid.addEventListener('mouseover', function (e) { var c = e.target.closest('.listing'); if (c) m.activate(c.dataset.branch); });
                var links = @json($branches->mapWithKeys(fn ($b) => [$b->slug => $link(['where' => [$b->slug], 'open' => null])]));
                m.onPick = function (slug) { if (links[slug]) location.href = links[slug]; };
            })();
        </script>
    @endpush
@endif
@endsection
