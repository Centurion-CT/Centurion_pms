{{--
    Keverna Stays marketplace - search results index.

    Cross-tenant view of every property that opted into the marketplace
    (properties.is_listed_publicly). Uses the same visual language as
    the /search stub (Airbnb-inspired 2-column: results grid + map)
    but every card is a real property with real rooms + prices.

    URL contract:
      /stays?q=<city or name>&from=<yyyy-mm-dd>&to=<yyyy-mm-dd>&adults=<int>&children=<int>&budget=<int>

    Design intent: results = real, map = still stubbed until a
    geocoded map layer lands. Empty state doubles as a soft "list your
    property" prompt for operator acquisition.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $seoCount = $results->count();
        $seoTitle = 'Keverna Stays · '.($q !== '' ? $q : 'Nigeria');
        $seoDescription = 'Verified stays across Nigeria. Book direct with the property, pay by card, zero commission on the operator\'s side.'
            .($q !== '' ? ' Now showing '.$seoCount.' '.($seoCount === 1 ? 'property' : 'properties').' in '.$q.'.' : '');
        $canonicalUrl = url()->to('/stays');  // strip query params for canonical
        $ogImage = url('/images/og-stays.jpg');  // fallback if not present — falls back to landing OG
    @endphp
    <title>{{ $seoTitle }}</title>
    <meta name="description" content="{{ $seoDescription }}" />
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="{{ $canonicalUrl }}">

    {{-- Open Graph + Twitter for shareable link previews on WhatsApp,
         Slack, LinkedIn, X, Facebook. Same image the landing uses so
         the brand card stays consistent across surfaces. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Keverna Stays">
    <meta property="og:title" content="{{ $seoTitle }}">
    <meta property="og:description" content="{{ $seoDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:alt" content="Keverna Stays — book direct, zero commission.">
    <meta property="og:locale" content="en_NG">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    <meta name="twitter:description" content="{{ $seoDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    {{-- Schema.org: SearchResultsPage wrapping an ItemList of stays.
         Search engines use this to build rich results (property cards
         with rating, price, image) and AI systems cite it directly
         without scraping the whole HTML. Kept small — first 20
         results only — so the JSON doesn't bloat the head. --}}
    @php
        $ldItems = [];
        foreach ($results->take(20)->values() as $i => $row) {
            $prop = $row['property'];
            $rating = $prop->ratingSummary();
            $ldItem = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'url' => url('/stays/'.$prop->public_slug),
                'name' => $prop->name,
            ];
            $heroForLd = $prop->getFirstMediaUrl('hero', 'medium')
                ?: $prop->getFirstMediaUrl('gallery', 'medium');
            if ($heroForLd !== '') {
                $ldItem['image'] = $heroForLd;
            }
            if ($rating['count'] > 0) {
                $ldItem['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => $rating['average'],
                    'reviewCount' => $rating['count'],
                    'bestRating' => 5,
                    'worstRating' => 1,
                ];
            }
            $ldItems[] = $ldItem;
        }
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'SearchResultsPage',
            'name' => $seoTitle,
            'description' => $seoDescription,
            'url' => $canonicalUrl,
            'inLanguage' => 'en-NG',
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => $seoCount,
                'itemListElement' => $ldItems,
            ],
        ];
    @endphp
    <script type="application/ld+json">
        {!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>

    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    {{-- Leaflet 1.9.4 - CDN + SRI. Loaded on the search index only;
         the property detail page loads its own tiny single-marker
         variant. Kept out of the Vite bundle so a marketplace pivot
         doesn't churn the operator dashboard build. --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    {{-- Alpine 3 for the card photo carousel + save heart. Loaded via
         CDN because this marketplace surface doesn't ship Livewire
         (which is where Alpine comes from on tenant pages), and the
         payload is ~15kB. Deferred so it doesn't block first paint. --}}
    <script defer src="https://unpkg.com/alpinejs@3.14.9/dist/cdn.min.js"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        .mp-page { background: #ffffff; color: #222; font-family: 'Inter', sans-serif; font-size: 14px; line-height: 1.45; margin: 0; }
        .mp-page a { color: inherit; text-decoration: none; }
        .mp-page *:focus-visible { outline: 2px solid #2E5788; outline-offset: 2px; border-radius: 4px; }

        .mp-nav { position: sticky; top: 0; z-index: 60; background: #fff; border-bottom: 1px solid #EBEBEB; }
        .mp-nav-inner {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            max-width: 1600px;
            margin: 0 auto;
        }
        @media (min-width: 900px) { .mp-nav-inner { padding: 14px 40px; gap: 20px; } }
        .mp-brand {
            display: inline-flex; align-items: center; gap: 8px;
            font-weight: 700; font-size: 20px; color: #14171A;
            letter-spacing: -0.02em;
        }
        /* Wordmark stays on across every viewport — the brand recall
           matters more than the extra 60px in header space. */
        .mp-brand__word { display: inline; }
        .mp-brand-mark {
            width: 30px; height: 30px; border-radius: 8px;
            background: #14171A; color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mp-brand-mark svg { width: 14px; height: 14px; }

        .mp-search-form {
            display: none;
        }
        @media (min-width: 900px) {
            .mp-search-form {
                display: inline-flex; align-items: stretch; gap: 0;
                justify-self: center;
                border: 1px solid #DDDDDD;
                border-radius: 999px;
                overflow: hidden;
                box-shadow: 0 1px 4px rgba(0,0,0,0.06);
                font-size: 13px; font-weight: 500;
                max-width: 720px;
                background: #fff;
            }
        }
        .mp-search-field {
            border: none; padding: 10px 16px; font: inherit;
            border-right: 1px solid #EEE; color: #222;
            background: transparent; min-width: 120px;
        }
        .mp-search-field:focus { outline: none; background: #FAFAFA; }
        .mp-search-field.mp-search-field-guests { width: 90px; }
        .mp-search-btn {
            width: 40px; margin: 4px; border-radius: 999px;
            background: #8AAE3A; color: #fff; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; flex-shrink: 0;
            transition: background 120ms ease;
        }
        .mp-search-btn:hover { background: #ADCB52; }
        .mp-search-btn svg { width: 14px; height: 14px; }

        .mp-nav-right { display: flex; align-items: center; gap: 6px; }
        .mp-nav-link { padding: 12px 14px; border-radius: 22px; font-size: 13px; font-weight: 500; color: #222; }
        .mp-nav-link:hover { background: #F7F7F7; }
        @media (max-width: 899.98px) {
            /* Mobile: "Become a host" is nice-to-have but the header
               is already busy with brand + search pill. Hide it and
               reclaim the space; desktop users still see it. */
            .mp-nav-right { display: none; }
        }

        /* Bottom-sheet search picker (mobile). Rendered on top of the
           results grid with a fade-in backdrop + slide-up card. Alpine
           owns the open/close state via a body-level `x-data` scope so
           the pill in the header and the sheet talk over `$dispatch`.
           Design intent: give a guest the same "Where / When / Who"
           trio the desktop form has, without stealing header space. */
        .mp-search-sheet {
            position: fixed; inset: 0;
            z-index: 610;
            display: none;
        }
        .mp-search-sheet.is-open { display: block; }
        .mp-search-sheet__backdrop {
            position: absolute; inset: 0;
            background: rgba(20, 15, 8, 0.55);
            opacity: 0;
            transition: opacity 200ms ease;
        }
        .mp-search-sheet.is-open .mp-search-sheet__backdrop { opacity: 1; }
        .mp-search-sheet__card {
            position: absolute; left: 0; right: 0; bottom: 0;
            background: #FFFFFF;
            border-radius: 20px 20px 0 0;
            padding: 18px 20px 24px;
            transform: translateY(100%);
            transition: transform 240ms cubic-bezier(0.16, 1, 0.3, 1);
            max-height: 92vh; overflow-y: auto;
        }
        .mp-search-sheet.is-open .mp-search-sheet__card { transform: translateY(0); }
        .mp-search-sheet__handle {
            width: 40px; height: 4px; border-radius: 4px;
            background: #E5E1D6;
            margin: 0 auto 14px;
        }
        .mp-search-sheet__head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px;
        }
        .mp-search-sheet__head h2 {
            margin: 0; font-size: 18px; font-weight: 700; color: #14171A; letter-spacing: -0.01em;
        }
        .mp-search-sheet__close {
            width: 34px; height: 34px; border-radius: 50%;
            background: #F4EFDF; border: 0; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            color: #14171A;
        }
        .mp-search-sheet__close svg { width: 13px; height: 13px; }
        .mp-search-sheet__field {
            display: block;
            margin-bottom: 14px;
        }
        .mp-search-sheet__label {
            display: block;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.08em; color: #6B6B6B;
            margin-bottom: 4px;
        }
        .mp-search-sheet__input {
            display: block; width: 100%; box-sizing: border-box;
            padding: 12px 14px;
            border: 1px solid #DDDDDD; border-radius: 12px;
            font: inherit; font-size: 15px; color: #14171A;
            background: #FFFFFF;
        }
        .mp-search-sheet__input:focus {
            outline: none; border-color: #14171A;
        }
        .mp-search-sheet__row {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
        }
        .mp-search-sheet__submit {
            display: block; width: 100%; box-sizing: border-box;
            margin-top: 8px;
            padding: 14px 18px;
            background: #14171A; color: #fff;
            border: 0; border-radius: 12px;
            font: inherit; font-size: 15px; font-weight: 700;
            cursor: pointer;
            transition: background 140ms;
        }
        .mp-search-sheet__submit:hover { background: #ADCB52; color: #14171A; }
        @media (min-width: 900px) { .mp-search-sheet { display: none !important; } }

        .mp-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
        }
        @media (min-width: 1024px) {
            /* Desktop: freeze the layout to one viewport tall and let
               the results column scroll internally, so the map on the
               right stays in view no matter how many cards render.
               Same pattern Airbnb uses for its listings page — simpler
               than `position: sticky` on the map because sticky
               detaches when the containing block is too short to hold
               the pinned rect + its top offset. */
            .mp-layout {
                grid-template-columns: minmax(0, 1fr) 44%;
                height: calc(100vh - 60px);
                overflow: hidden;
            }
        }

        .mp-results { padding: 24px 24px 48px; min-width: 0; }
        @media (min-width: 900px) { .mp-results { padding: 24px 40px 48px; } }
        @media (min-width: 1024px) {
            .mp-results { overflow-y: auto; min-height: 0; }
            .mp-results::-webkit-scrollbar { width: 10px; }
            .mp-results::-webkit-scrollbar-thumb { background: #E5E1D6; border-radius: 10px; border: 2px solid #FFFFFF; }
            .mp-results::-webkit-scrollbar-thumb:hover { background: #C8C1AF; }
        }
        .mp-results-head {
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 12px; margin-bottom: 20px; flex-wrap: wrap;
        }
        .mp-results-count { font-size: 22px; font-weight: 600; color: #222; letter-spacing: -0.01em; margin: 0; }
        .mp-results-fees {
            font-size: 12px; padding: 5px 10px;
            background: #F1F7E6; border-radius: 999px; color: #4A6B1E;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .mp-results-fees svg { width: 12px; height: 12px; }

        /* Horizontal-scrollable filter chip row above the results. Sits
           inside the results column so long chip lists don't push the
           map off-screen. Each chip is a form control that submits the
           search form (which owns q/from/to/adults/children/budget)
           so results reload with the picked filter applied. */
        .mp-filters {
            display: flex; gap: 10px; align-items: center;
            overflow-x: auto; scrollbar-width: none;
            padding: 2px 0 18px;
            margin-bottom: 6px;
            border-bottom: 1px solid #F0F0F0;
        }
        .mp-filters::-webkit-scrollbar { display: none; }
        .mp-filter-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px;
            border: 1px solid #DDDDDD;
            border-radius: 999px;
            background: #fff;
            font-size: 12.5px; font-weight: 600; color: #222;
            white-space: nowrap; cursor: pointer;
            transition: border-color 140ms, background 140ms, transform 100ms;
        }
        .mp-filter-chip:hover { border-color: #14171A; background: #FAFAFA; }
        .mp-filter-chip:active { transform: scale(0.98); }
        .mp-filter-chip svg { width: 12px; height: 12px; color: #6A6A6A; }
        .mp-filter-chip.is-active {
            background: #14171A; color: #fff; border-color: #14171A;
        }
        .mp-filter-chip.is-active svg { color: #C8CFB6; }
        .mp-filter-chip__count {
            font-size: 11px; font-weight: 700;
            background: rgba(20, 23, 26, 0.08); color: #14171A;
            border-radius: 999px; padding: 1px 7px;
        }
        .mp-filter-chip.is-active .mp-filter-chip__count {
            background: rgba(255, 255, 255, 0.16); color: #F4F1EA;
        }
        /* Desktop chips live inside the results column so they scroll
           with the cards. Mobile chips are a separate row anchored
           right below the nav (styles further down). */
        @media (max-width: 899.98px) {
            .mp-filters--desktop { display: none; }
        }
        /* Header filter icon button — mirrors the search icon so the
           two live side by side on the right of the mobile header. */
        .mp-mobile-actions {
            display: none;
        }
        @media (max-width: 899.98px) {
            .mp-mobile-actions {
                display: inline-flex; align-items: center; gap: 8px;
                justify-self: end;
            }
        }
        .mp-mobile-actions button {
            width: 40px; height: 40px; border-radius: 999px;
            background: #FFFFFF;
            border: 1px solid #DDDDDD;
            display: inline-flex; align-items: center; justify-content: center;
            color: #14171A;
            cursor: pointer;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
            transition: background 140ms, border-color 140ms, transform 100ms;
            font-family: inherit;
            position: relative;
        }
        .mp-mobile-actions button:hover { background: #FAFAFA; border-color: #14171A; }
        .mp-mobile-actions button:active { transform: scale(0.94); }
        .mp-mobile-actions button svg { width: 15px; height: 15px; }
        .mp-mobile-actions__badge {
            position: absolute; top: -3px; right: -3px;
            min-width: 16px; height: 16px; padding: 0 4px;
            border-radius: 999px;
            background: #ADCB52; color: #14171A;
            font-size: 10px; font-weight: 700; line-height: 16px;
            text-align: center;
        }

        /* Filter action sheet — reuses the search sheet's card + backdrop
           chrome for consistency. Renders every filter chip as a big
           tap target grouped by category. */
        .mp-filter-sheet__group { margin-bottom: 18px; }
        .mp-filter-sheet__group h3 {
            margin: 0 0 10px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.08em; color: #6B6B6B;
        }
        .mp-filter-sheet__options {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
        }
        .mp-filter-sheet__option {
            display: flex; align-items: center; gap: 8px;
            padding: 12px 14px;
            border: 1px solid #DDDDDD; border-radius: 12px;
            background: #FFFFFF;
            font-size: 14px; font-weight: 600; color: #14171A;
            text-align: left;
        }
        .mp-filter-sheet__option.is-active {
            background: #14171A; color: #fff; border-color: #14171A;
        }
        .mp-filter-sheet__option svg { width: 13px; height: 13px; }
        /* Clear filters is a secondary action — dark ink pill would
           read as the primary CTA, so render it as an inline
           underlined text link with muted ink. */
        .mp-filter-sheet__clear {
            display: inline-block;
            margin-top: 4px;
            padding: 12px 4px;
            font-size: 14px; font-weight: 700;
            color: #14171A;
            text-decoration: underline;
            text-underline-offset: 3px;
        }
        .mp-filter-sheet__clear:hover { color: #4A6B1E; }

        /* Full-viewport navigation spinner. Renders when a guest taps
           a card so the "something's happening" signal isn't the
           browser's own subtle top-bar tick. Fades in after 180ms so
           snappy transitions don't flash a spinner nobody needs to
           see. Same visual as the dashboard's nav-spinner so the two
           surfaces feel consistent. */
        .mp-nav-loader {
            position: fixed; inset: 0; z-index: 9999;
            display: none; align-items: center; justify-content: center;
            background: rgba(20, 17, 12, 0.28);
            -webkit-backdrop-filter: blur(1.5px);
            backdrop-filter: blur(1.5px);
            pointer-events: none;
        }
        .mp-nav-loader.is-visible { display: flex; }
        .mp-nav-loader__spinner {
            width: 52px; height: 52px; border-radius: 50%;
            background: #fff;
            box-shadow: 0 12px 40px rgba(20, 17, 12, 0.24);
            display: flex; align-items: center; justify-content: center;
        }
        .mp-nav-loader__spinner svg {
            width: 26px; height: 26px;
            animation: mp-nav-spin 0.9s linear infinite;
        }
        @keyframes mp-nav-spin { to { transform: rotate(360deg); } }

        .mp-results-grid {
            display: grid; grid-template-columns: minmax(0, 1fr);
            gap: 32px 20px;
        }
        @media (min-width: 720px)  { .mp-results-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1400px) { .mp-results-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }

        /* Card. Each card is a link that scrolls the map to its pin on
           hover (via the shared `data-mp-slug` handle) and dispatches
           a `mp-card-hover` event that the map JS listens for. */
        .mp-card { cursor: pointer; }
        .mp-card.is-hovered .mp-card-photo { transform: translateY(-2px); }

        .mp-card-photo {
            position: relative;
            aspect-ratio: 20 / 19;
            border-radius: 14px; overflow: hidden;
            background: linear-gradient(135deg, #F0F0F0 0%, #E4E4E4 100%);
            transition: transform 220ms cubic-bezier(0.2, 0.8, 0.2, 1);
        }
        .mp-card-photo-track {
            display: flex;
            width: 100%; height: 100%;
            transition: transform 320ms cubic-bezier(0.16, 1, 0.3, 1);
        }
        .mp-card-photo-slide {
            flex: 0 0 100%; height: 100%;
            background: #F0F0F0;
        }
        .mp-card-photo-slide img {
            width: 100%; height: 100%; object-fit: cover; display: block;
        }
        .mp-card-photo-stub {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            color: rgba(20,23,26,0.35);
            font-weight: 600; font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase;
            background: repeating-linear-gradient(45deg, #F1F1F1, #F1F1F1 8px, #EAEAEA 8px, #EAEAEA 16px);
        }

        /* Carousel arrows. Mounted as siblings of the photo track so
           they float above but sit inside the same rounded frame.
           Kept hidden until card hover on hover-capable devices,
           always visible on touch (where hover is a lie). */
        .mp-card-nav {
            position: absolute; top: 50%; transform: translateY(-50%);
            width: 30px; height: 30px; border-radius: 50%;
            background: rgba(255, 255, 255, 0.95); color: #14171A;
            border: 1px solid rgba(20, 23, 26, 0.08);
            display: inline-flex; align-items: center; justify-content: center;
            box-shadow: 0 3px 8px rgba(20, 23, 26, 0.22);
            cursor: pointer;
            opacity: 0; transition: opacity 160ms, transform 140ms, background 140ms;
            z-index: 2;
        }
        .mp-card-nav svg { width: 12px; height: 12px; }
        .mp-card-nav.mp-card-nav--prev { left: 10px; }
        .mp-card-nav.mp-card-nav--next { right: 10px; }
        .mp-card:hover .mp-card-nav { opacity: 1; }
        .mp-card-nav:hover { transform: translateY(-50%) scale(1.06); background: #FFFFFF; }
        .mp-card-nav[disabled] { opacity: 0 !important; pointer-events: none; }
        @media (hover: none) {
            .mp-card-nav { opacity: 0.92; }
        }

        .mp-card-dots {
            position: absolute; bottom: 12px; left: 50%;
            transform: translateX(-50%);
            display: inline-flex; gap: 4px;
            z-index: 2;
        }
        .mp-card-dots span {
            width: 6px; height: 6px; border-radius: 50%;
            background: rgba(255, 255, 255, 0.7);
            transition: transform 140ms, background 140ms;
        }
        .mp-card-dots span.is-active {
            background: #FFFFFF; transform: scale(1.3);
        }

        .mp-card-heart {
            position: absolute; top: 12px; right: 12px; z-index: 2;
            width: 32px; height: 32px; border: 0; padding: 0;
            background: transparent; cursor: pointer;
        }
        .mp-card-heart svg {
            width: 22px; height: 22px;
            color: #fff; fill: rgba(0,0,0,0.35);
            stroke-width: 2;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,0.35));
            transition: fill 140ms, transform 140ms;
        }
        .mp-card-heart:hover svg { transform: scale(1.08); }
        .mp-card-heart.is-saved svg {
            fill: #E4574A; color: #fff;
        }
        .mp-card-tag {
            position: absolute; top: 12px; left: 12px; z-index: 2;
            padding: 5px 10px; border-radius: 999px;
            background: #fff; font-size: 11px; font-weight: 700; color: #222;
            box-shadow: 0 2px 4px rgba(0,0,0,0.10);
        }
        .mp-card-info { display: block; padding-top: 18px; }
        .mp-card-title-row { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; }
        .mp-card-title { font-size: 15px; font-weight: 600; color: #222; letter-spacing: -0.01em; }
        .mp-card-rating { font-size: 13px; display: inline-flex; align-items: center; gap: 3px; color: #14171A; font-weight: 600; }
        .mp-card-rating svg { width: 11px; height: 11px; fill: currentColor; }
        .mp-card-rating__count { color: #6B6B6B; font-weight: 400; }
        .mp-card-sub { font-size: 13px; color: #6A6A6A; margin-top: 2px; }
        .mp-card-dates { font-size: 13px; color: #6A6A6A; margin-top: 2px; }
        .mp-card-price { font-size: 14px; color: #222; margin-top: 6px; }
        .mp-card-price b { font-weight: 700; text-decoration: underline; }
        /* Pin ↔ card hover linking. Both surfaces raise the same
           `is-linked` class so a guest scanning the map sees the
           matching card lift + a slim border, and vice versa. */
        .mp-card.is-linked .mp-card-photo {
            box-shadow: 0 0 0 2px #14171A, 0 14px 30px -18px rgba(20, 23, 26, 0.5);
        }

        .mp-map {
            position: relative;
            height: 100%;
            background: #E5E3DF;
            display: none;
            /* Leaflet's internal panes stack up to z-index 700 (attribution
               + tooltips + popups) which would leak over the sticky nav.
               Force a new stacking context so nothing inside the map can
               paint above the header. */
            isolation: isolate;
            z-index: 0;
        }
        @media (min-width: 1024px) { .mp-map { display: block; } }
        .mp-map-canvas { width: 100%; height: 100%; }
        /* Overlay hint for the empty-map case (no listed property has
           coordinates yet). Sits above the tiles + doesn't block map
           pan events - the map itself still works. */
        .mp-map-hint {
            position: absolute; top: 16px; left: 50%;
            transform: translateX(-50%);
            z-index: 500;
            padding: 6px 12px; border-radius: 999px;
            background: rgba(255,255,255,0.94);
            color: #4A6B1E; font-size: 12px; font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,0.14);
            pointer-events: none;
        }

        /* Airbnb-style price pin — floating white pill with a
           subtle drop-shadow, hover pop for feedback, selected
           state flips ink/paper so it reads as the active choice. */
        .mp-price-pin {
            display: inline-flex; align-items: center;
            padding: 7px 12px; border-radius: 999px;
            background: #fff; color: #14171A;
            font-family: 'Inter', sans-serif;
            font-size: 13px; font-weight: 700;
            box-shadow: 0 2px 6px rgba(0,0,0,0.18), 0 0 0 1px rgba(0,0,0,0.06);
            white-space: nowrap;
            transition: transform 140ms cubic-bezier(0.16, 1, 0.3, 1), background-color 140ms, color 140ms, box-shadow 140ms;
            cursor: pointer;
        }
        .mp-price-pin:hover,
        .mp-price-pin.is-linked {
            transform: scale(1.1);
            box-shadow: 0 6px 14px rgba(0,0,0,0.24), 0 0 0 1px rgba(0,0,0,0.08);
        }
        .mp-price-pin.selected,
        .mp-price-pin.is-visited {
            background: #14171A; color: #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,0.28);
        }
        /* Leaflet's default marker CSS drops a shadow + iconAnchor
           box that we don't need for a bare-DOM DivIcon. Clear it. */
        .mp-price-pin-wrap { background: transparent !important; border: none !important; }

        .mp-popup-card {
            width: 220px;
            font-family: 'Inter', sans-serif; color: #222;
        }
        .mp-popup-photo {
            width: 100%; aspect-ratio: 4 / 3; border-radius: 8px;
            overflow: hidden; background: #F1F1F1; margin-bottom: 8px;
        }
        .mp-popup-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .mp-popup-title { font-weight: 700; font-size: 14px; margin: 0 0 2px; }
        .mp-popup-sub { color: #6A6A6A; font-size: 12px; margin: 0 0 6px; }
        .mp-popup-price { font-size: 12.5px; }
        .mp-popup-price b { font-weight: 700; }
        /* Leaflet popup skin - flatter, whiter, less rounded than default */
        .leaflet-popup-content-wrapper { border-radius: 12px; padding: 10px; }
        .leaflet-popup-content { margin: 0; }

        .mp-empty {
            padding: 48px 24px; text-align: center;
            border: 1px dashed #DDDDDD; border-radius: 16px;
            color: #6A6A6A;
        }
        .mp-empty h3 { font-size: 18px; font-weight: 700; color: #222; margin: 0 0 4px; }
        .mp-empty p { margin: 6px 0 0; }
        .mp-empty a { color: #B85C42; font-weight: 600; text-decoration: underline; }

        /* Mobile "Show map" — floating pill at the bottom of the
           viewport that swaps to a full-screen overlay map. Desktop
           gets the sticky map column instead, so this is hidden
           there. Ink fill for high contrast against the results
           grid + a small map glyph for affordance. */
        .mp-map-toggle {
            position: fixed; left: 50%; bottom: 20px;
            transform: translateX(-50%);
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 20px 12px 16px;
            background: #14171A; color: #fff;
            border: 0; border-radius: 999px;
            font-family: inherit; font-size: 14px; font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 26px rgba(0,0,0,0.28);
            z-index: 45;
        }
        .mp-map-toggle svg { width: 15px; height: 15px; }
        @media (min-width: 1024px) { .mp-map-toggle { display: none; } }
        body.mp-map-open .mp-map-toggle { display: none; }

        /* Full-screen map overlay for mobile. Uses the same DOM as
           the desktop sticky map but position-fixed to the viewport
           when the toggle is active. Header pill exits + a back
           button. */
        .mp-map-fs-close {
            position: fixed; top: 14px; left: 14px;
            width: 40px; height: 40px; border-radius: 50%;
            background: #FFFFFF; color: #14171A; border: 0;
            display: none;
            align-items: center; justify-content: center;
            box-shadow: 0 6px 14px rgba(0,0,0,0.22);
            cursor: pointer;
            z-index: 601;
        }
        .mp-map-fs-close svg { width: 15px; height: 15px; }
        body.mp-map-open .mp-map-fs-close { display: inline-flex; }
        @media (min-width: 1024px) { .mp-map-fs-close { display: none !important; } }
        @media (max-width: 1023.98px) {
            body.mp-map-open .mp-map {
                display: block;
                position: fixed; inset: 0;
                z-index: 600;
                height: 100vh; height: 100dvh;
                top: 0;
            }
            body.mp-map-open .mp-results,
            body.mp-map-open .mp-nav { display: none; }
        }

        /* Compact card variant that renders inside the map popup.
           Wider than the pin popup, shows a hero photo + rating +
           price and links straight to the property page. Matches
           the grid card typography so guests aren't relearning. */
        .mp-map-popup-card {
            width: 240px;
            font-family: 'Inter', sans-serif; color: #14171A;
        }
        .mp-map-popup-card .mp-map-popup-photo {
            width: 100%; aspect-ratio: 4 / 3;
            border-radius: 10px; overflow: hidden;
            background: #F1F1F1; margin-bottom: 10px;
        }
        .mp-map-popup-card .mp-map-popup-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .mp-map-popup-card .mp-map-popup-title {
            font-weight: 700; font-size: 14px; letter-spacing: -0.01em;
            display: flex; justify-content: space-between; gap: 8px;
        }
        .mp-map-popup-card .mp-map-popup-sub { color: #6B6B6B; font-size: 12px; margin-top: 2px; }
        .mp-map-popup-card .mp-map-popup-price { font-size: 13px; margin-top: 6px; }
        .mp-map-popup-card .mp-map-popup-price b { font-weight: 700; }
        .mp-map-popup-card .mp-map-popup-rating {
            display: inline-flex; align-items: center; gap: 3px;
            font-size: 12.5px; font-weight: 600;
        }
        .mp-map-popup-card .mp-map-popup-cta {
            display: inline-block; margin-top: 10px;
            font-size: 12.5px; font-weight: 700; color: #14171A;
            border-bottom: 1.5px solid #14171A; padding-bottom: 1px;
        }
    </style>

    @include('partials.platform-pulse')
</head>
<body class="mp-page">

@php
    // Sum of listed properties (post-filter) shapes the H1.
    $count = $results->count();
    $locationLabel = $q !== '' ? $q : 'Nigeria';

    // Filter chip config, hoisted so both the mobile chip row (under
    // the nav) and the desktop chip row (inside .mp-results) render
    // from the same list.
    //
    // We split params into two buckets so filter interactions don't
    // wipe each other out:
    //   * $searchContext = q/from/to (search intent — always preserved)
    //   * everything else (budget/adults/children) is a filter that a
    //     chip can turn on or off independently.
    //
    // A chip's href always keeps searchContext + the OTHER filter
    // group's current value + toggles its OWN group's value. That way
    // tapping "Under ₦50k" while "2+ guests" is active keeps guests
    // and just swaps the budget; tapping an already-active chip a
    // second time clears that filter category ("Any budget" / no
    // guest filter).
    $searchContext = array_filter([
        'q' => $q !== '' ? $q : null,
        'from' => $from !== '' ? $from : null,
        'to' => $to !== '' ? $to : null,
    ]);
    $chipHref = function (array $extra) use ($searchContext) {
        $merged = array_merge($searchContext, $extra);
        // Drop null/empty so the URL stays short and clean.
        $merged = array_filter($merged, fn ($v) => $v !== null && $v !== '' && $v !== 0);
        return $merged
            ? route('marketplace.stays.index').'?'.http_build_query($merged)
            : route('marketplace.stays.index');
    };
    // Current guest filter — 4 if guests>=4, 2 if guests>=2, else null.
    $currentGuestBucket = $guests >= 4 ? 4 : ($guests >= 2 ? 2 : null);
    // Current budget filter — matches the chip ranges.
    $currentBudget = null;
    if ($budget > 0 && $budget <= 25000) $currentBudget = 25000;
    elseif ($budget > 25000 && $budget <= 50000) $currentBudget = 50000;
    elseif ($budget > 50000 && $budget <= 100000) $currentBudget = 100000;

    $filterChips = [
        ['label' => 'Any budget', 'icon' => 'sparkles',
         'active' => $currentBudget === null,
         'href' => $chipHref(['adults' => $currentGuestBucket])],
        ['label' => 'Under ₦25k', 'icon' => 'wallet',
         'active' => $currentBudget === 25000,
         'href' => $chipHref(['adults' => $currentGuestBucket, 'budget' => $currentBudget === 25000 ? null : 25000])],
        ['label' => 'Under ₦50k', 'icon' => 'wallet',
         'active' => $currentBudget === 50000,
         'href' => $chipHref(['adults' => $currentGuestBucket, 'budget' => $currentBudget === 50000 ? null : 50000])],
        ['label' => 'Under ₦100k', 'icon' => 'wallet',
         'active' => $currentBudget === 100000,
         'href' => $chipHref(['adults' => $currentGuestBucket, 'budget' => $currentBudget === 100000 ? null : 100000])],
        ['label' => '2+ guests', 'icon' => 'users',
         'active' => $currentGuestBucket === 2,
         'href' => $chipHref(['budget' => $currentBudget, 'adults' => $currentGuestBucket === 2 ? null : 2])],
        ['label' => '4+ guests', 'icon' => 'users',
         'active' => $currentGuestBucket === 4,
         'href' => $chipHref(['budget' => $currentBudget, 'adults' => $currentGuestBucket === 4 ? null : 4])],
    ];
    $activeFilterCount = ($currentBudget !== null ? 1 : 0) + ($currentGuestBucket !== null ? 1 : 0);
    // "Clear filters" — search context only, no budget/guests.
    $clearFiltersHref = $chipHref([]);
@endphp

<div class="mp-page">

    {{-- ═══ TOP NAV ═════════════════════════════════════════════ --}}
    <div x-data="{ sheet: false, filter: false }" @keydown.escape.window="sheet = false; filter = false">

    <header class="mp-nav">
        <div class="mp-nav-inner">
            <a href="/" class="mp-brand" data-testid="stays-brand">
                <span class="mp-brand-mark" aria-hidden>
                    <svg viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
                </span>
                <span class="mp-brand__word">Keverna</span>
            </a>

            <form method="GET" action="{{ route('marketplace.stays.index') }}" class="mp-search-form" data-testid="stays-search-form">
                @php
                    // Native picker `min` on both dates so past days
                    // can't be picked; server-side controller re-checks
                    // on submit as belt-and-braces.
                    $searchCheckinMin = \Illuminate\Support\Carbon::today()->toDateString();
                    $searchCheckoutMin = $from !== ''
                        ? \Illuminate\Support\Carbon::parse($from)->addDay()->toDateString()
                        : \Illuminate\Support\Carbon::today()->addDay()->toDateString();
                @endphp
                <input type="text" name="q" value="{{ $q }}" placeholder="Where to?" class="mp-search-field" aria-label="City or property">
                <input type="date" name="from" value="{{ $from }}"
                       min="{{ $searchCheckinMin }}"
                       class="mp-search-field" aria-label="Check-in">
                <input type="date" name="to" value="{{ $to }}"
                       min="{{ $searchCheckoutMin }}"
                       class="mp-search-field" aria-label="Check-out">
                <input type="number" name="adults" value="{{ $adults ?: '' }}" min="0" max="12" placeholder="Guests" class="mp-search-field mp-search-field-guests" aria-label="Guests">
                <button type="submit" class="mp-search-btn" aria-label="Search stays">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5l3 3"/></svg>
                </button>
            </form>

            {{-- Mobile: two icon buttons on the far right — Search
                 opens the search sheet, Filter opens the filter sheet.
                 Both are 40px tap targets with a subtle badge on the
                 filter button when any non-default chip is active. --}}
            <div class="mp-mobile-actions">
                <button type="button" data-testid="stays-mobile-search"
                        @click="sheet = true; document.body.style.overflow = 'hidden'"
                        aria-label="Open search">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5l3 3"/></svg>
                </button>
                <button type="button" data-testid="stays-mobile-filter"
                        @click="filter = true; document.body.style.overflow = 'hidden'"
                        aria-label="Open filters">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4h12M4 8h8M6 12h4"/></svg>
                    @if ($activeFilterCount > 0)
                        <span class="mp-mobile-actions__badge">{{ $activeFilterCount }}</span>
                    @endif
                </button>
            </div>

            <div class="mp-nav-right">
                <a href="https://keverna.com/demo?src=nav" class="mp-nav-link">Become a host</a>
            </div>
        </div>
    </header>

    {{-- Filter action sheet. Reuses the search sheet's card + backdrop
         chrome for consistency; renders every filter chip as a large
         tap target grouped by category (Budget, Guests). Each option
         is a link that layers the same query param the chip row uses,
         so tapping is exactly one round-trip. --}}
    <div class="mp-search-sheet"
         :class="{ 'is-open': filter }"
         data-testid="stays-filter-sheet"
         x-cloak
         @click.self="filter = false; document.body.style.overflow = ''">
        <div class="mp-search-sheet__backdrop"
             @click="filter = false; document.body.style.overflow = ''"></div>
        <div class="mp-search-sheet__card" @click.stop>
            <div class="mp-search-sheet__handle" aria-hidden="true"></div>
            <div class="mp-search-sheet__head">
                <h2>Filters</h2>
                <button type="button" class="mp-search-sheet__close"
                        @click="filter = false; document.body.style.overflow = ''"
                        aria-label="Close filters">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4l8 8M12 4l-8 8"/></svg>
                </button>
            </div>

            <div class="mp-filter-sheet__group">
                <h3>Budget</h3>
                <div class="mp-filter-sheet__options">
                    @foreach ($filterChips as $chip)
                        @if ($chip['icon'] === 'wallet' || $chip['label'] === 'Any budget')
                            <a href="{{ $chip['href'] }}"
                               class="mp-filter-sheet__option {{ $chip['active'] ? 'is-active' : '' }}"
                               data-testid="filter-sheet-{{ \Illuminate\Support\Str::slug($chip['label']) }}">
                                @if ($chip['icon'] === 'wallet')
                                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="12" height="9" rx="1.6"/><path d="M14 7.5h-2.2a1.3 1.3 0 0 0 0 2.6H14"/></svg>
                                @else
                                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h6M8 5v6"/><circle cx="8" cy="8" r="6"/></svg>
                                @endif
                                {{ $chip['label'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="mp-filter-sheet__group">
                <h3>Guests</h3>
                <div class="mp-filter-sheet__options">
                    @foreach ($filterChips as $chip)
                        @if ($chip['icon'] === 'users')
                            <a href="{{ $chip['href'] }}"
                               class="mp-filter-sheet__option {{ $chip['active'] ? 'is-active' : '' }}"
                               data-testid="filter-sheet-{{ \Illuminate\Support\Str::slug($chip['label']) }}">
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="2.4"/><path d="M2 13.4a4 4 0 0 1 8 0"/><circle cx="12" cy="6.5" r="1.7"/><path d="M11.5 13.4c0-1.4.9-2.6 2.5-3.1"/></svg>
                                {{ $chip['label'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>

            <a href="{{ $clearFiltersHref }}"
               class="mp-filter-sheet__clear"
               data-testid="filter-sheet-clear">
                Clear filters
            </a>
        </div>
    </div>

    {{-- Mobile bottom-sheet search picker. Same submit target as the
         desktop form so results reload with the picked filters via a
         normal GET, keeping URLs shareable. --}}
    <div class="mp-search-sheet"
         :class="{ 'is-open': sheet }"
         data-testid="stays-search-sheet"
         x-cloak
         @click.self="sheet = false; document.body.style.overflow = ''">
        <div class="mp-search-sheet__backdrop"
             @click="sheet = false; document.body.style.overflow = ''"></div>
        <form method="GET" action="{{ route('marketplace.stays.index') }}" class="mp-search-sheet__card" @click.stop>
            <div class="mp-search-sheet__handle" aria-hidden="true"></div>
            <div class="mp-search-sheet__head">
                <h2>Search stays</h2>
                <button type="button" class="mp-search-sheet__close"
                        @click="sheet = false; document.body.style.overflow = ''"
                        aria-label="Close search">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4l8 8M12 4l-8 8"/></svg>
                </button>
            </div>

            <label class="mp-search-sheet__field">
                <span class="mp-search-sheet__label">Where</span>
                <input type="text" name="q" value="{{ $q }}"
                       placeholder="City, area, or property name"
                       class="mp-search-sheet__input"
                       autocomplete="off"
                       data-testid="sheet-input-q">
            </label>

            <div class="mp-search-sheet__row">
                <label class="mp-search-sheet__field">
                    <span class="mp-search-sheet__label">Check-in</span>
                    <input type="date" name="from" value="{{ $from }}"
                           min="{{ $searchCheckinMin }}"
                           class="mp-search-sheet__input"
                           data-testid="sheet-input-from">
                </label>
                <label class="mp-search-sheet__field">
                    <span class="mp-search-sheet__label">Check-out</span>
                    <input type="date" name="to" value="{{ $to }}"
                           min="{{ $searchCheckoutMin }}"
                           class="mp-search-sheet__input"
                           data-testid="sheet-input-to">
                </label>
            </div>

            <div class="mp-search-sheet__row">
                <label class="mp-search-sheet__field">
                    <span class="mp-search-sheet__label">Adults</span>
                    <input type="number" name="adults" value="{{ $adults ?: '' }}"
                           min="0" max="12" placeholder="0"
                           class="mp-search-sheet__input"
                           data-testid="sheet-input-adults">
                </label>
                <label class="mp-search-sheet__field">
                    <span class="mp-search-sheet__label">Children</span>
                    <input type="number" name="children" value="{{ $children ?: '' }}"
                           min="0" max="12" placeholder="0"
                           class="mp-search-sheet__input"
                           data-testid="sheet-input-children">
                </label>
            </div>

            @if ($budget > 0)
                <input type="hidden" name="budget" value="{{ $budget }}">
            @endif

            <button type="submit" class="mp-search-sheet__submit" data-testid="sheet-submit">
                Search
            </button>
        </form>
    </div>

    {{-- ═══ TWO-COLUMN LAYOUT ═══════════════════════════════════ --}}
    <div class="mp-layout" data-testid="results-layout">

        <section class="mp-results" data-testid="results-column">
            {{-- Filter chip row. Each chip is a link that submits the
                 same q/from/to/adults/children context but layers on
                 a filter param, so the URL stays shareable. Cheap
                 wins — property-type filtering + guest count nudges
                 without needing a full filter drawer. Hidden below
                 900px in favour of the mobile chip row + filter sheet
                 that live under the nav. --}}
            <div class="mp-filters mp-filters--desktop" data-testid="results-filters" role="tablist">
                @foreach ($filterChips as $chip)
                    <a href="{{ $chip['href'] }}"
                       class="mp-filter-chip {{ $chip['active'] ? 'is-active' : '' }}"
                       data-testid="filter-chip-{{ \Illuminate\Support\Str::slug($chip['label']) }}">
                        @switch($chip['icon'])
                            @case('wallet')
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="12" height="9" rx="1.6"/><path d="M14 7.5h-2.2a1.3 1.3 0 0 0 0 2.6H14"/></svg>
                                @break
                            @case('users')
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="2.4"/><path d="M2 13.4a4 4 0 0 1 8 0"/><circle cx="12" cy="6.5" r="1.7"/><path d="M11.5 13.4c0-1.4.9-2.6 2.5-3.1"/></svg>
                                @break
                            @default
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h6M8 5v6"/><circle cx="8" cy="8" r="6"/></svg>
                        @endswitch
                        {{ $chip['label'] }}
                    </a>
                @endforeach
            </div>

            <div class="mp-results-head">
                <h1 class="mp-results-count" data-testid="results-count">
                    @if ($count === 0)
                        No stays in {{ $locationLabel }} yet
                    @else
                        {{ $count }} {{ $count === 1 ? 'stay' : 'stays' }} in {{ $locationLabel }}
                    @endif
                </h1>
                <span class="mp-results-fees">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6l6-4 6 4v6H2z"/></svg>
                    Paid direct to the property
                </span>
            </div>

            @if ($count === 0)
                <div class="mp-empty" data-testid="stays-empty">
                    <h3>No matches yet.</h3>
                    <p>Try a broader search, or run yours next.
                       <a href="{{ route('for-hosts') }}">List your property.</a></p>
                </div>
            @else
                <div class="mp-results-grid">
                    @foreach ($results as $row)
                        @php
                            /** @var \App\Models\Property $property */
                            $property = $row['property'];
                            $fromPrice = (int) $row['from_price'];
                            $fromPriceBase = (int) ($row['from_price_base'] ?? $fromPrice);
                            // Up to 5 photos per card carousel — property's own
                            // gallery first, falling back to a single hero image
                            // when the operator hasn't set the gallery yet. Order
                            // matches the property page: any is_hero-flagged
                            // slide leads the carousel.
                            $galleryMedia = $property->getMedia('gallery')
                                ->sortBy(fn ($m) => $m->getCustomProperty('is_hero') ? 0 : 1)
                                ->values()
                                ->take(5);
                            // Pair each URL with its Media instance so the
                            // <x-blur-image> renderer can pull the tiny
                            // placeholder conversion off the same file.
                            $cardPhotos = $galleryMedia
                                ->map(fn ($m) => ['url' => $m->getUrl('medium'), 'media' => $m])
                                ->filter(fn ($p) => ! empty($p['url']))
                                ->values();
                            if ($cardPhotos->isEmpty()) {
                                $heroMedia = $property->getFirstMedia('hero');
                                $heroUrl = $heroMedia?->getUrl('medium') ?: '';
                                if ($heroUrl !== '') {
                                    $cardPhotos = collect([['url' => $heroUrl, 'media' => $heroMedia]]);
                                }
                            }
                            $rating = $property->ratingSummary();
                            $city = trim((string) ($property->city ?? ''));
                            $subline = $city !== ''
                                ? $city.($property->state && $property->state !== $city ? ', '.$property->state : '')
                                : (string) ($property->address ?? '');
                            $detailUrl = route('marketplace.stays.show', ['slug' => $property->public_slug])
                                .($from !== '' || $to !== '' || $guests > 0
                                    ? '?'.http_build_query(array_filter([
                                        'from' => $from,
                                        'to' => $to,
                                        'adults' => $adults ?: null,
                                        'children' => $children ?: null,
                                    ]))
                                    : '');
                            $photoCount = $cardPhotos->count();
                        @endphp
                        <article class="mp-card"
                                 data-mp-slug="{{ $property->public_slug }}"
                                 data-mp-detail-url="{{ $detailUrl }}"
                                 data-testid="result-{{ $property->public_slug }}"
                                 x-data="{ slide: 0, total: {{ $photoCount }}, saved: false }"
                                 @mouseenter="window.dispatchEvent(new CustomEvent('mp-card-hover', { detail: { slug: '{{ $property->public_slug }}', on: true } }))"
                                 @mouseleave="window.dispatchEvent(new CustomEvent('mp-card-hover', { detail: { slug: '{{ $property->public_slug }}', on: false } }))"
                                 @click="if (! $event.defaultPrevented) window.location.href = '{{ $detailUrl }}'">
                            <div class="mp-card-photo">
                                @if ($photoCount === 0)
                                    <span class="mp-card-photo-stub">{{ $city !== '' ? $city : 'Photo coming soon' }}</span>
                                @else
                                    <div class="mp-card-photo-track"
                                         :style="`transform: translateX(${-slide * 100}%);`">
                                        @foreach ($cardPhotos as $photo)
                                            <div class="mp-card-photo-slide">
                                                <x-blur-image
                                                    :media="$photo['media']"
                                                    :src="$photo['url']"
                                                    conversion="medium"
                                                    :alt="$property->name.($loop->iteration > 1 ? ' — photo '.$loop->iteration : '')"
                                                    :loading="$loop->first ? 'eager' : 'lazy'" />
                                            </div>
                                        @endforeach
                                    </div>
                                    @if ($photoCount > 1)
                                        <button type="button" class="mp-card-nav mp-card-nav--prev"
                                                @click.stop.prevent="slide = Math.max(0, slide - 1)"
                                                :disabled="slide === 0"
                                                aria-label="Previous photo">
                                            <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 2.5-3 3.5 3 3.5"/></svg>
                                        </button>
                                        <button type="button" class="mp-card-nav mp-card-nav--next"
                                                @click.stop.prevent="slide = Math.min(total - 1, slide + 1)"
                                                :disabled="slide === total - 1"
                                                aria-label="Next photo">
                                            <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 2.5 3 3.5-3 3.5"/></svg>
                                        </button>
                                        <div class="mp-card-dots" aria-hidden="true">
                                            @for ($i = 0; $i < $photoCount; $i++)
                                                <span :class="{ 'is-active': slide === {{ $i }} }"></span>
                                            @endfor
                                        </div>
                                    @endif
                                @endif
                                <span class="mp-card-tag">Book direct</span>
                                <button type="button" class="mp-card-heart"
                                        :class="{ 'is-saved': saved }"
                                        @click.stop.prevent="saved = ! saved"
                                        aria-label="Save to your list">
                                    <svg viewBox="0 0 24 24" stroke="currentColor" fill="currentColor"><path d="M12 21s-7-4.35-7-10a4 4 0 0 1 7-2.65A4 4 0 0 1 19 11c0 5.65-7 10-7 10z"/></svg>
                                </button>
                            </div>
                            <a href="{{ $detailUrl }}" class="mp-card-info" data-testid="result-{{ $property->public_slug }}-link">
                                <div class="mp-card-title-row">
                                    <span class="mp-card-title">{{ $property->name }}</span>
                                    @if ($rating['count'] > 0)
                                        <span class="mp-card-rating">
                                            <svg viewBox="0 0 12 12" aria-hidden="true"><path d="M6 .8l1.6 3.4 3.7.5-2.7 2.6.7 3.7L6 9.3 2.7 11l.7-3.7L.7 4.7l3.7-.5L6 .8z"/></svg>
                                            {{ number_format($rating['average'], 1) }}
                                            <span class="mp-card-rating__count">· {{ $rating['count'] }}</span>
                                        </span>
                                    @endif
                                </div>
                                <div class="mp-card-sub">{{ $subline }}</div>
                                @if ($from !== '' || $to !== '')
                                    <div class="mp-card-dates">
                                        {{ $from }}@if ($from !== '' && $to !== '') &ndash; @endif{{ $to }}
                                    </div>
                                @endif
                                @if ($fromPrice > 0)
                                    <div class="mp-card-price"
                                         data-testid="card-price-{{ $property->public_slug }}">
                                        @if ($fromPriceBase > 0 && $fromPriceBase !== $fromPrice)
                                            From <s style="color: #9A9A9A; font-weight: 500;"
                                                    data-testid="card-price-was-{{ $property->public_slug }}">&#8358;{{ number_format($fromPriceBase) }}</s>
                                            <b>&#8358;{{ number_format($fromPrice) }}</b> / night
                                        @else
                                            From <b>&#8358;{{ number_format($fromPrice) }}</b> / night
                                        @endif
                                    </div>
                                @endif
                            </a>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <aside class="mp-map" data-testid="map-column" aria-label="Map">
            @php
                // Only results with real coordinates make it onto the
                // map - properties without lat/lng still show in the
                // list column, they just don't get a pin.
                $mapPoints = $results
                    ->filter(fn ($r) => $r['property']->hasCoordinates())
                    ->map(function ($r) use ($from, $to, $adults, $children, $guests) {
                        $rating = $r['property']->ratingSummary();
                        return [
                            'slug'         => (string) $r['property']->public_slug,
                            'name'         => (string) $r['property']->name,
                            'city'         => (string) ($r['property']->city ?? ''),
                            'lat'          => (float) $r['property']->latitude,
                            'lng'          => (float) $r['property']->longitude,
                            'price'        => (int) $r['from_price'],
                            'rating_avg'   => $rating['count'] > 0 ? number_format($rating['average'], 1) : null,
                            'rating_count' => $rating['count'] > 0 ? (int) $rating['count'] : null,
                            'hero'         => (string) ($r['property']->getFirstMediaUrl('hero', 'medium')
                                                ?: $r['property']->getFirstMediaUrl('gallery', 'medium')),
                            'url'          => route('marketplace.stays.show', ['slug' => $r['property']->public_slug])
                                                .($from !== '' || $to !== '' || $guests > 0
                                                    ? '?'.http_build_query(array_filter([
                                                        'from' => $from, 'to' => $to,
                                                        'adults' => $adults ?: null,
                                                        'children' => $children ?: null,
                                                    ]))
                                                    : ''),
                        ];
                    })
                    ->values();
            @endphp

            {{-- Map always renders. When no listed property has coords
                 yet, we still show the tiles (framed on Nigeria) plus
                 a small overlay hint so the map slot never reads as a
                 broken tile canvas. --}}
            <div id="stays-map" class="mp-map-canvas" data-testid="stays-map"
                 data-points="{{ json_encode($mapPoints, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}"></div>
            @if ($mapPoints->isEmpty())
                <div class="mp-map-hint" data-testid="map-empty-hint">
                    <span>No pinned locations yet</span>
                </div>
            @endif
            <script>
                (function () {
                    var el = document.getElementById('stays-map');
                    if (! el || typeof L === 'undefined') return;
                    var points = JSON.parse(el.dataset.points || '[]');

                    var map = L.map(el, {
                        zoomControl: true,
                        scrollWheelZoom: false,   // avoid trapping page scroll
                        attributionControl: true,
                    });

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; <a href="https://openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
                    }).addTo(map);

                    if (points.length === 0) {
                        // No pins - frame on Nigeria (Lagos-ish) so the
                        // map still reads as a real map + not a broken
                        // tile canvas.
                        map.setView([9.0820, 8.6753], 6);
                        return;
                    }

                    var latLngs = points.map(function (p) { return [p.lat, p.lng]; });
                    if (points.length === 1) {
                        // Single-marker fitBounds is a zero-area box +
                        // zooms to the max; setView is what we want.
                        map.setView(latLngs[0], 13);
                    } else {
                        map.fitBounds(latLngs, { padding: [40, 40] });
                    }

                    var markersBySlug = {};
                    var selectedMarker = null;
                    var visitedSlugs = new Set();

                    // Escape guest-typed strings before dropping them
                    // into the popup HTML. Cheap belt-and-braces on top
                    // of the server-side escaping already done in
                    // $mapPoints's JSON encode.
                    function esc(s) {
                        return String(s).replace(/[&<>"']/g, function (c) {
                            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                        });
                    }

                    function popupHtml(p) {
                        var rating = (p.rating_avg && p.rating_count)
                            ? '<span class="mp-map-popup-rating">★ ' + esc(p.rating_avg) + ' <span style="color:#6B6B6B; font-weight:400;">· ' + esc(p.rating_count) + '</span></span>'
                            : '';
                        var price = p.price > 0
                            ? '<div class="mp-map-popup-price">From <b>₦' + p.price.toLocaleString() + '</b> / night</div>'
                            : '';
                        return '<div class="mp-map-popup-card">' +
                            (p.hero ? '<div class="mp-map-popup-photo"><img src="' + esc(p.hero) + '" alt=""></div>' : '') +
                            '<div class="mp-map-popup-title"><span>' + esc(p.name) + '</span>' + rating + '</div>' +
                            (p.city ? '<div class="mp-map-popup-sub">' + esc(p.city) + '</div>' : '') +
                            price +
                            '<a href="' + esc(p.url) + '" class="mp-map-popup-cta">View property</a>' +
                            '</div>';
                    }

                    points.forEach(function (p) {
                        var priceLabel = p.price > 0
                            ? '₦' + p.price.toLocaleString()
                            : 'View';
                        var icon = L.divIcon({
                            className: 'mp-price-pin-wrap',
                            iconSize: null,
                            iconAnchor: [22, 12],
                            html: '<span class="mp-price-pin" data-mp-slug="' + esc(p.slug) + '">' + esc(priceLabel) + '</span>',
                        });
                        var marker = L.marker([p.lat, p.lng], { icon: icon }).addTo(map);
                        markersBySlug[p.slug] = marker;

                        marker.bindPopup(popupHtml(p), { maxWidth: 260, closeButton: true, autoPan: true });

                        marker.on('click', function () {
                            if (selectedMarker && selectedMarker !== marker) {
                                var prev = selectedMarker.getElement()?.querySelector('.mp-price-pin');
                                if (prev) prev.classList.remove('selected');
                            }
                            var pinEl = marker.getElement()?.querySelector('.mp-price-pin');
                            if (pinEl) pinEl.classList.add('selected');
                            selectedMarker = marker;
                            visitedSlugs.add(p.slug);
                            if (pinEl) pinEl.classList.add('is-visited');
                        });

                        // Bidirectional hover: hover pin → light up card
                        // in the grid. Both surfaces flip the same
                        // `is-linked` class so the visual affordance
                        // matches from either direction.
                        marker.on('mouseover', function () {
                            var pinEl = marker.getElement()?.querySelector('.mp-price-pin');
                            if (pinEl) pinEl.classList.add('is-linked');
                            var card = document.querySelector('.mp-card[data-mp-slug="' + p.slug + '"]');
                            if (card) card.classList.add('is-linked');
                        });
                        marker.on('mouseout', function () {
                            var pinEl = marker.getElement()?.querySelector('.mp-price-pin');
                            if (pinEl) pinEl.classList.remove('is-linked');
                            var card = document.querySelector('.mp-card[data-mp-slug="' + p.slug + '"]');
                            if (card) card.classList.remove('is-linked');
                        });
                    });

                    // Card → pin linking. The results grid dispatches
                    // this custom event on mouseenter/leave; we mirror
                    // the visual state on the pin so the guest sees
                    // which card corresponds to which map pin without
                    // having to click.
                    window.addEventListener('mp-card-hover', function (e) {
                        var slug = e.detail && e.detail.slug;
                        if (! slug) return;
                        var marker = markersBySlug[slug];
                        if (! marker) return;
                        var pinEl = marker.getElement()?.querySelector('.mp-price-pin');
                        if (! pinEl) return;
                        if (e.detail.on) {
                            pinEl.classList.add('is-linked');
                            pinEl.parentElement?.parentElement?.appendChild(pinEl.parentElement); // raise z
                        } else {
                            pinEl.classList.remove('is-linked');
                        }
                    });

                    // When the mobile map toggle flips the layout to
                    // full-screen, Leaflet's cached size is stale —
                    // ask it to recompute so the tiles fill the new
                    // container instead of showing a grey band.
                    window.addEventListener('mp-map-resize', function () {
                        setTimeout(function () { map.invalidateSize(); }, 40);
                    });

                    // Forward wheel events on the desktop map to the
                    // results column. Without this, wheeling while the
                    // cursor is over the map hits `.mp-layout` (which
                    // is `overflow: hidden` to keep the two columns
                    // pinned) and the page reads as scroll-hijacked.
                    // Skipped when the mobile full-screen overlay is
                    // open — in that mode the map should own its own
                    // pan gestures.
                    var mapEl = el;
                    var resultsEl = document.querySelector('.mp-results');
                    if (mapEl && resultsEl) {
                        mapEl.addEventListener('wheel', function (e) {
                            if (document.body.classList.contains('mp-map-open')) return;
                            if (window.innerWidth < 1024) return;
                            resultsEl.scrollTop += e.deltaY;
                            e.preventDefault();
                        }, { passive: false });
                    }
                })();

                // Mobile map toggle wiring. The full-screen overlay
                // is a body-class swap so the same Leaflet instance
                // reuses its tiles + pins — no re-mount cost. Sends
                // `mp-map-resize` after each toggle so the map re-
                // fits the new container.
                //
                // Runs after DOMContentLoaded because this <script>
                // sits inside the map aside — the toggle + close
                // buttons render after the aside so they don't exist
                // yet at parse-time.
                function wireMapToggle() {
                    var toggle = document.querySelector('[data-mp-map-toggle]');
                    var close = document.querySelector('[data-mp-map-close]');
                    if (! toggle || ! close) return;
                    toggle.addEventListener('click', function () {
                        document.body.classList.add('mp-map-open');
                        window.dispatchEvent(new CustomEvent('mp-map-resize'));
                    });
                    close.addEventListener('click', function () {
                        document.body.classList.remove('mp-map-open');
                        window.dispatchEvent(new CustomEvent('mp-map-resize'));
                    });
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', wireMapToggle);
                } else {
                    wireMapToggle();
                }
            </script>
        </aside>

    </div>

    @if ($mapPoints->isNotEmpty())
        <button type="button" class="mp-map-toggle" data-mp-map-toggle aria-label="Show map">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4l4-1.5 4 1.5 4-1.5v10L10 14 6 12.5 2 14V4Z"/><path d="M6 3v9M10 4v10"/></svg>
            Show map
        </button>
        <button type="button" class="mp-map-fs-close" data-mp-map-close aria-label="Close map">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4l8 8M12 4l-8 8"/></svg>
        </button>
    @endif

</div>

    </div> {{-- /x-data="{ sheet: false }" wrapper --}}

    {{-- Full-viewport spinner shown while a card navigation is in
         flight. The overlay is toggled via a click-delegation
         listener on any element carrying data-mp-loader, so cards
         (whole-article click + info-link tap) and any future
         "detail" affordance share the same signal. Delayed reveal
         so instant transitions don't flash a spinner nobody needs. --}}
    <div class="mp-nav-loader" id="mp-nav-loader" aria-hidden="true">
        <div class="mp-nav-loader__spinner">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="rgba(20,17,12,0.12)" stroke-width="2.5"/>
                <path d="M21 12a9 9 0 0 0-9-9" stroke="#14171A" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
        </div>
    </div>
    <script>
        (function () {
            var loader = document.getElementById('mp-nav-loader');
            if (! loader) return;
            var showTimer = null;
            function show() {
                loader.classList.add('is-visible');
            }
            function hide() {
                if (showTimer) { clearTimeout(showTimer); showTimer = null; }
                loader.classList.remove('is-visible');
            }
            // Delegate: any click that ends up inside a .mp-card or
            // on a [data-mp-loader] anchor kicks off the show timer.
            // Bubble phase — carousel arrows / save heart / etc. run
            // their own `preventDefault` before this fires, so we
            // can honour `defaultPrevented` to skip those.
            // stopPropagation on those buttons also means this
            // handler never sees them.
            document.addEventListener('click', function (e) {
                if (e.defaultPrevented) return;
                var card = e.target.closest('.mp-card');
                var trigger = e.target.closest('[data-mp-loader]');
                if (! card && ! trigger) return;
                if (showTimer) clearTimeout(showTimer);
                showTimer = setTimeout(show, 180);
            }, false);
            // If the navigation is cancelled (bfcache restore, back
            // button), hide so the spinner doesn't stick.
            window.addEventListener('pageshow', hide);
        })();
    </script>

@include('marketplace._partials.stays-footer')

{{-- Auto-advance checkout when the guest picks a check-in. Same
     pattern shipped on /stays/{slug}: only overwrites checkout
     when it's empty or before check-in +1 day. --}}
<script>
    (function () {
        var form = document.querySelector('form[data-testid="stays-search-form"]');
        if (! form) return;
        var ci = form.querySelector('input[name="from"]');
        var co = form.querySelector('input[name="to"]');
        if (! ci || ! co) return;
        ci.addEventListener('change', function () {
            if (! ci.value) return;
            var ciDate = new Date(ci.value + 'T00:00:00');
            if (isNaN(ciDate)) return;
            var next = new Date(ciDate);
            next.setDate(next.getDate() + 1);
            var nextIso = next.toISOString().slice(0, 10);
            if (! co.value) { co.value = nextIso; }
            else {
                var coDate = new Date(co.value + 'T00:00:00');
                if (isNaN(coDate) || coDate <= ciDate) co.value = nextIso;
            }
            // Checkout must be strictly after check-in, so bump the
            // picker's min to check-in +1 (not check-in itself).
            co.min = nextIso;
        });
    })();
</script>

@include('partials.platform-pulse-toast')
</body>
</html>
