{{-- The booking site's home page on each business's address (SiteController::home). --}}
@extends('site.layouts.app')

@php($business = app(\App\Site\BookingSite::class)->business())
@php($count = $branches->count())
@php($place = $cities->count() <= 2 ? $cities->implode(' and ') : '')
@php($checkIn = \App\Site\Stay::time($branches->first()?->houseRules()['check_in_from'] ?? ''))
@php($checkOut = \App\Site\Stay::time($branches->first()?->houseRules()['check_out_to'] ?? ''))
@php($cancellation = $branches->first()?->houseRules()['cancellation_policy'] ?? '')
@php($canPay = \App\Services\Paystack\BusinessPaystack::available($business))

@section('page', 'pg-home')
@section('title', $business->siteTitle().($place !== '' ? ' · Apartments in '.$place : ''))
@section('description', 'Book '.$business->name.' directly: live availability'.($count > 1 ? ' across '.$count.' buildings' : '').', paid securely online.')

@section('content')
@include('site.partials.header', ['longStays' => $longStays->isNotEmpty()])

<main>
    @if ($count === 0)
        {{-- Nothing bookable yet (no live building with an apartment
             type): the landing page still shows, with the contacts. --}}
        <section class="hero hero--quiet">
            <div class="container">
                <div class="hero-copy">
                    <h1 class="display">{{ $business->siteTitle() }}</h1>
                    <p class="lead">Online booking opens soon. Until then, call or email us to book.</p>
                    <div class="contact">
                        @if ($business->phone)
                            <a class="btn btn-inverse btn-lg" href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}">Call {{ $business->phone }}</a>
                        @endif
                        @if ($business->email)
                            <a class="btn btn-inverse btn-lg" href="mailto:{{ $business->email }}">Email {{ $business->email }}</a>
                        @endif
                    </div>
                </div>
                @include('site.partials.bars')
            </div>
        </section>
    @else
        <section class="hero">
            <div class="container">
                <div class="hero-copy">
                    <h1 class="display">
                        @if ($count === 1)
                            Stay at {{ $business->name }}{{ $place !== '' ? ' in '.$place : '' }}
                        @elseif ($place !== '')
                            Apartments in {{ $place }}
                        @else
                            Book your stay at {{ $business->name }}
                        @endif
                    </h1>
                    <p class="lead">Book straight with {{ $business->name }}. Every apartment you see here is free for your dates.</p>
                </div>
                @if ($heroPhoto)
                    <div class="hero-photo" style="background-image: url('{{ $heroPhoto }}');" role="img" aria-label="{{ $business->name }}"></div>
                @else
                    @include('site.partials.bars')
                @endif
            </div>
        </section>

        <section class="search-row" aria-label="Search">
            <div class="container">@include('site.partials.search', ['stay' => $stay])</div>
        </section>

        <section class="block" id="stays">
            <div class="container">
                <div class="block-head">
                    <h2 class="heading">Free for {{ $stay->label() }}</h2>
                    @if ($listingCount > $featured->count())
                        <a href="{{ route('site.results', $stay->query()) }}">See all {{ $listingCount }} apartments</a>
                    @endif
                </div>
                @if ($featured->isEmpty())
                    <div class="empty">
                        <p class="lead">Nothing is free for every night of {{ $stay->label() }}.</p>
                        <p class="meta">Try other dates, or call us and we'll help.</p>
                    </div>
                @elseif ($featured->count() === 1)
                    <div class="rows">@include('site.partials.listing', ['listing' => $featured->first(), 'stay' => $stay, 'row' => true])</div>
                @else
                    <div class="listings">
                        @foreach ($featured as $listing)
                            @include('site.partials.listing', ['listing' => $listing, 'stay' => $stay])
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        @if ($count > 1)
            <section class="block" id="locations">
                <div class="container">
                    <h2 class="heading block-head">Where we are</h2>
                    <div class="places">
                        @foreach ($branches as $branch)
                            @php($photo = $branch->getFirstMedia('hero') ?? $branch->getFirstMedia('gallery'))
                            @php($types = $branch->roomCategories->count())
                            <article class="place">
                                @if ($photo)<img src="{{ $photo->getUrl() }}" alt="" loading="lazy">@else @include('site.partials.bars') @endif
                                <div>
                                    <h3>{{ $branch->name }}</h3>
                                    <p>{{ $branch->address ?: $branch->city }}</p>
                                    <a href="{{ route('site.results', ['where' => [$branch->slug]] + $stay->query()) }}">See {{ $types }} apartment {{ \Illuminate\Support\Str::plural('type', $types) }}</a>
                                </div>
                            </article>
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
                            <h2 class="heading">Staying a month or more?</h2>
                            <p>Monthly rates on longer stays. Tell us your dates and we'll send a quote.</p>
                            @if ($business->email)
                                <a class="btn btn-inverse" href="mailto:{{ $business->email }}?subject={{ rawurlencode('Long-stay quote') }}">Get a long-stay quote</a>
                            @elseif ($business->phone)
                                <a class="btn btn-inverse" href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}">Call for a long-stay quote</a>
                            @endif
                        </div>
                        <table>
                            <thead><tr><th>Apartment</th><th class="r">Per night</th><th class="r">Per month</th></tr></thead>
                            <tbody>
                                @foreach ($longStays as $row)
                                    <tr><td>{{ $row['name'] }}<small>{{ $row['branch'] }}</small></td><td class="r">₦{{ number_format($row['night']) }}</td><td class="r">₦{{ number_format($row['month']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif

        @if ($checkIn || $cancellation !== '' || $canPay)
            <section class="block">
                <div class="container">
                    <h2 class="heading block-head">Good to know</h2>
                    <div class="facts">
                        @if ($checkIn)
                            <div><h3>Check in from {{ $checkIn }}</h3>@if ($checkOut)<p>Check out by {{ $checkOut }}.</p>@endif</div>
                        @endif
                        @if ($cancellation !== '')
                            <div><h3>Cancellation</h3><p>{{ $cancellation }}</p></div>
                        @endif
                        @if ($canPay)
                            <div><h3>Pay by card or bank transfer</h3><p>Paystack handles the payment, and your receipt comes by email.</p></div>
                        @endif
                    </div>
                </div>
            </section>
        @endif
    @endif
</main>

@include('site.partials.footer')
@endsection
