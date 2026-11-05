{{-- One apartment type for a stay (SiteController::apartment). --}}
@extends('site.layouts.app')

@php($business = app(\App\Site\BookingSite::class)->business())
@php($type = $listing->type)
@php($branch = $listing->branch)
@php($q = $listing->quote)
@php($photos = $listing->photos())
@php($amenities = collect(is_array($branch->amenities) ? $branch->amenities : [])->filter(fn ($a) => is_string($a) && $a !== ''))
@php($near = collect($branch->nearby_places ?? []))
@php($bookable = $listing->freeForStay > 0 && $q !== null)

@section('page', 'pg-apartment')
@section('title', $type->name.' · '.$business->name)
@section('description', \Illuminate\Support\Str::limit(trim($type->name.' at '.$branch->name.', sleeps '.$listing->sleeps().($q ? ', from ₦'.number_format($q->perNight()).' a night' : '').'. '.$type->description), 200))
@section('share_image', $photos === [] ? '' : $listing->photo())

@section('content')
@include('site.partials.header', ['nav' => false])

<main class="container">
    <a class="back" href="{{ route('site.results', $stay->query()) }}">Back to all apartments</a>

    @if ($photos === [])
        <div class="gallery gallery--bars">@include('site.partials.bars')</div>
    @else
        <div class="gallery">
            @foreach ($photos as $photo)<img src="{{ $photo }}" alt="{{ $type->name }}, photo {{ $loop->iteration }}">@endforeach
        </div>
    @endif

    <div class="apartment">
        <div class="apartment-main">
            <h1 class="title">{{ $type->name }}</h1>
            <p class="meta">{{ $branch->address ? $branch->address.', ' : '' }}{{ $branch->name }} · sleeps {{ $listing->sleeps() }}</p>
            @if ($reviews['count'] > 0)
                <p class="review-link"><a href="#reviews"><span aria-hidden="true">★</span> {{ number_format($reviews['average'], 1) }} · {{ $reviews['count'] }} {{ \Illuminate\Support\Str::plural('review', $reviews['count']) }}</a></p>
            @endif
            @if ($type->description)<p class="lead">{{ $type->description }}</p>@endif

            @if ($amenities->isNotEmpty())
                <section class="section">
                    <h2 class="subhead">In the apartment</h2>
                    <ul class="ticks">@foreach ($amenities as $amenity)<li>{{ $amenity }}</li>@endforeach</ul>
                </section>
            @endif

            @if ($near->isNotEmpty())
                <section class="section">
                    <h2 class="subhead">Around {{ $branch->name }}</h2>
                    <ul class="ticks">@foreach ($near as $place)<li>{{ $place['label'] ?? '' }}@if (! empty($place['distance'])), {{ $place['distance'] }}@endif</li>@endforeach</ul>
                </section>
            @endif

            <section class="section">
                <h2 class="subhead">Your stay</h2>
                <div class="cal-wrap">
                    @foreach ($calendar as $month)
                        <p class="cal-month">{{ $month['month'] }}</p>
                        <div class="cal" aria-hidden="true">
                            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow)<span class="dow">{{ $dow }}</span>@endforeach
                            @foreach ($month['cells'] as $cell)<span class="day {{ $cell['state'] }}">{{ $cell['day'] }}</span>@endforeach
                        </div>
                    @endforeach
                    <p class="meta">{{ $stay->label() }}, {{ $stay->nights() }} {{ \Illuminate\Support\Str::plural('night', $stay->nights()) }}.@if ($t = \App\Site\Stay::time($rules['check_in_from'] ?? '')) Check in from {{ $t }}.@endif @if ($t = \App\Site\Stay::time($rules['check_out_to'] ?? ''))Check out by {{ $t }}.@endif</p>
                </div>
            </section>

            @if ($reviews['count'] > 0)
                @include('site.partials.reviews', ['reviews' => $reviews, 'business' => $business])
            @endif
        </div>

        <aside class="book-card" aria-label="Book {{ $type->name }}">
            @if ($q)<p class="price"><b>₦{{ number_format($q->perNight()) }}</b> a night</p>@endif
            <form action="{{ route('site.apartment', ['branch' => $branch->slug, 'type' => $type->slug]) }}" method="get">
                @include('site.partials.dates', ['stay' => $stay])
                <input type="hidden" name="adults" value="{{ $stay->adults }}">
                <button class="btn btn-secondary btn-sm" type="submit">Check these dates</button>
            </form>
            @include('site.partials.free', ['free' => $listing->freeForStay])
            @if ($q)
                <dl class="lines">
                    <dt>₦{{ number_format($q->wasPerNight() ?? $q->perNight()) }} × {{ $q->nights }} {{ \Illuminate\Support\Str::plural('night', $q->nights) }}</dt><dd>₦{{ number_format($q->subtotal) }}</dd>
                    @if ($q->discount > 0)<dt>Discount</dt><dd class="save">−₦{{ number_format($q->discount) }}</dd>@endif
                    @if ($q->fees > 0)<dt>Card and booking fees</dt><dd>₦{{ number_format($q->fees) }}</dd>@endif
                    <dt class="total">Total</dt><dd class="total">₦{{ number_format($q->total) }}</dd>
                </dl>
            @endif
            @if ($bookable)
                <a class="btn btn-primary btn-lg btn-block" href="{{ route('site.checkout', ['branch' => $branch->slug, 'type' => $type->slug] + $stay->query()) }}">{{ $canPay ? 'Book this apartment' : 'How to book' }}</a>
                @if ($canPay)<p class="meta">You won't pay yet.</p>@endif
            @else
                <span class="btn btn-lg btn-block" aria-disabled="true">Not free for these dates</span>
            @endif
            @if (($rules['cancellation_policy'] ?? '') !== '')<p class="meta">{{ $rules['cancellation_policy'] }}</p>@endif
        </aside>
    </div>
</main>

@include('site.partials.footer')
@endsection
