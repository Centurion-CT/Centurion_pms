{{-- The Stays marketplace on the central domain (Platform\StaysController).
     Shares the booking site's styles; no business is bound here. --}}
@php($brand = config('brand.name'))
@php($pageTitle = 'Places to stay · '.$brand.' Stays')
@php($description = 'Find apartments and hotels and book directly with them, on '.$brand.' Stays.')
@php($canonical = 'https://'.config('keverna.central_domain').'/stays')
@php($image = $stays->first()?->photo)
@php($signupOpen = config('keverna.signup_open'))
@php($link = fn (array $change) => route('platform.stays', array_filter(array_merge(request()->only(['q', 'city', 'in', 'out', 'adults']), $change), fn ($v) => $v !== null && $v !== '')))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand }} Stays">
    <meta property="og:locale" content="en_NG">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    @if ($image)<meta property="og:image" content="{{ $image }}">@endif
    <meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
    <script>document.documentElement.classList.add('js')</script>
    @include('partials.favicons')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:500,600|hanken-grotesk:400,500,600&display=swap">
    @include('site.partials.styles')
</head>
<body class="site pg-stays">
    <header class="site-header">
        <div class="container">
            <a class="logo" href="{{ route('platform.stays') }}"><img src="{{ asset(config('brand.logo')) }}" alt=""><span>{{ $brand }} Stays</span></a>
            <div class="end">
                <a class="btn btn-secondary btn-sm" href="{{ route('platform.home') }}"><span class="hide-sm">List your business</span><span class="show-sm">List yours</span></a>
            </div>
        </div>
    </header>

    <main>
        <section class="hero hero--quiet">
            <div class="container">
                <div class="hero-copy">
                    <h1 class="display">Find a place to stay</h1>
                    <p class="lead">Apartments and hotels you book directly, with the people who run them.</p>
                </div>
                @include('site.partials.bars')
            </div>
        </section>

        <section class="search-row" aria-label="Search">
            <div class="container">
                <form class="search" role="search" action="{{ route('platform.stays') }}" method="get">
                    <label class="seg"><span class="seg-label">Where</span><input class="seg-text" type="search" name="q" value="{{ $query }}" placeholder="City or business" maxlength="80"></label>
                    @if ($city !== '')<input type="hidden" name="city" value="{{ $city }}">@endif
                    @include('site.partials.dates', ['stay' => $stay])
                    <label class="seg"><span class="seg-label">Guests</span>
                        <select name="adults">
                            <option value="">Any</option>
                            @foreach (range(1, \App\Site\Stay::MAX_ADULTS) as $n)<option value="{{ $n }}" @selected($guests === $n)>{{ $n }} {{ \Illuminate\Support\Str::plural('adult', $n) }}</option>@endforeach
                        </select>
                    </label>
                    <button class="btn btn-primary btn-lg" type="submit">Search</button>
                </form>
            </div>
        </section>

        <section class="block">
            <div class="container">
                <div class="block-head">
                    <h2 class="heading">
                        @if ($stay)
                            {{ $stays->total() }} free for {{ $stay->label() }}
                        @elseif ($query !== '' || $city !== '')
                            {{ $stays->total() }} {{ \Illuminate\Support\Str::plural('place', $stays->total()) }} found
                        @else
                            All places to stay
                        @endif
                    </h2>
                </div>

                @if ($cities->count() > 1)
                    <nav class="filters" aria-label="Cities">
                        <a class="chip" href="{{ $link(['city' => null, 'page' => null]) }}" aria-pressed="{{ $city === '' ? 'true' : 'false' }}">All cities</a>
                        @foreach ($cities as $name)
                            <a class="chip" href="{{ $link(['city' => $name, 'page' => null]) }}" aria-pressed="{{ strcasecmp($city, $name) === 0 ? 'true' : 'false' }}">{{ $name }}</a>
                        @endforeach
                    </nav>
                @endif

                @if ($stays->isEmpty())
                    <div class="empty">
                        @if ($query === '' && $city === '' && ! $stay)
                            <p class="lead">Places are being added soon.</p>
                            <p class="meta">Run apartments or a hotel? <a href="{{ route('platform.home') }}">List your business on {{ $brand }}</a>.</p>
                        @else
                            <p class="lead">Nothing matches that search.</p>
                            <p class="meta">Try other dates, fewer guests or another city, or <a href="{{ route('platform.stays') }}">see every place</a>.</p>
                        @endif
                    </div>
                @else
                    <div class="listings">
                        @foreach ($stays as $place)
                            <a class="listing" href="{{ $place->url($carry) }}" data-testid="stay-{{ $place->propertyId }}">
                                @if ($place->photo)
                                    <img class="listing-photo" src="{{ $place->photo }}" alt="" loading="lazy">
                                @else
                                    <span class="listing-photo listing-photo--bars">@include('site.partials.bars')</span>
                                @endif
                                <span class="listing-body">
                                    <span class="listing-info">
                                        <span class="listing-head">
                                            <span class="listing-name">{{ $place->building }}</span>
                                            @if ($stay)@include('site.partials.free', ['free' => $place->free])@endif
                                        </span>
                                        <span class="meta">{{ $place->building !== $place->business ? $place->business.' · ' : '' }}{{ $place->city }}</span>
                                        @if ($place->reviews > 0)
                                            <span class="meta listing-rating"><span aria-hidden="true">★</span> {{ number_format($place->rating, 1) }} · {{ $place->reviews }} {{ \Illuminate\Support\Str::plural('review', $place->reviews) }}</span>
                                        @endif
                                    </span>
                                    <span class="listing-buy">
                                        <span class="listing-price">from <b>₦{{ number_format($place->fromPerNight) }}</b> a night</span>
                                        @if ($place->total !== null)
                                            <span class="meta">From ₦{{ number_format($place->total) }} for {{ $stay->nights() }} {{ \Illuminate\Support\Str::plural('night', $stay->nights()) }}, fees included</span>
                                        @endif
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    </div>

                    @if ($stays->hasPages())
                        <nav class="filters pager" aria-label="Pages">
                            @if ($stays->previousPageUrl())<a class="chip" href="{{ $stays->previousPageUrl() }}">Previous</a>@endif
                            <span class="meta">Page {{ $stays->currentPage() }} of {{ $stays->lastPage() }}</span>
                            @if ($stays->nextPageUrl())<a class="chip" href="{{ $stays->nextPageUrl() }}">Next</a>@endif
                        </nav>
                    @endif
                @endif

                <p class="meta stays-note">You book on each business's own site, and your booking and payment are with them.</p>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p class="foot-name">{{ $brand }} Stays</p>
            <nav aria-label="Footer">
                <a href="{{ route('platform.home') }}">For businesses</a>
                <a href="mailto:hello@{{ config('keverna.central_domain') }}">Contact</a>
                <span>© {{ date('Y') }} {{ $brand }}</span>
            </nav>
        </div>
    </footer>
    @stack('scripts')
</body>
</html>
