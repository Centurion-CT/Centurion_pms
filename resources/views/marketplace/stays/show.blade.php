{{--
    Keverna Stays - single-property detail page.

    /stays/{public_slug}
       ?from=<yyyy-mm-dd>&to=<yyyy-mm-dd>&adults=<int>&children=<int>

    Layout:
      * Sticky top nav (same as /stays index)
      * Property title band + rating
      * Photo strip (hero + gallery, capped at 5 tiles)
      * Two-column body: left = description + amenities + rooms +
                                house rules + reviews
                         right = sticky booking card (dates + guests +
                                 from-price + Reserve CTA)
      * Reserve CTA drops into the /stays/{slug}/book flow (Packet 4)
        with the currently-selected dates / guest count seeded.
--}}
@php
    /** @var \App\Models\Property $property */
    /** @var \Illuminate\Support\Collection $rooms */
    /** @var \Illuminate\Support\Collection $reviews */
    $city = trim((string) ($property->city ?? ''));
    $stateStr = trim((string) ($property->state ?? ''));
    $locationLine = $city !== ''
        ? $city.($stateStr !== '' && $stateStr !== $city ? ', '.$stateStr : '')
        : (string) ($property->address ?? '');

    $hero = $property->getFirstMediaUrl('hero', 'full')
        ?: $property->getFirstMediaUrl('gallery', 'full');
    // Up to four secondary tiles (skip the one we used as hero).
    $galleryMedia = $property->getMedia('gallery')->take(4);

    $amenities = is_array($property->amenities) ? $property->amenities : [];
    $houseRules = $property->houseRules();
    $hasRules = $property->hasHouseRules();
    $bookBase = route('marketplace.stays.book', ['slug' => $property->public_slug]);

    $qs = fn (array $extra = []) => http_build_query(array_filter(array_merge([
        'from' => $from, 'to' => $to,
        'adults' => $adults ?: null,
        'children' => $children ?: null,
    ], $extra), fn ($v) => $v !== null && $v !== ''));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $seoTitle = $property->name.' · Keverna Stays';
        $seoLoc = trim(collect([$property->city, $property->state])->filter()->unique()->implode(', '));
        // Description is authored HTML — strip tags for SEO meta so the
        // Google/Twitter/OG snippet doesn't leak `<b>` and `<br>`.
        $seoDescription = \Illuminate\Support\Str::limit(
            strip_tags((string) ($property->description ?? $property->name)).' '
                .($seoLoc ? '('.$seoLoc.')' : '')
                .' Book direct, no commission.',
            155
        );
        $canonicalUrl = url()->route('marketplace.stays.show', ['slug' => $property->public_slug]);
        $ogImage = $property->getFirstMediaUrl('hero', 'full')
            ?: $property->getFirstMediaUrl('gallery', 'full')
            ?: url('/images/og-stays.jpg');
    @endphp
    <title>{{ $seoTitle }}</title>
    <meta name="description" content="{{ $seoDescription }}" />
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Open Graph + Twitter link previews. Property's own hero photo
         becomes the share card so a WhatsApp / Slack link renders the
         room instead of a generic Keverna logo. --}}
    <meta property="og:type" content="place">
    <meta property="og:site_name" content="Keverna Stays">
    <meta property="og:title" content="{{ $seoTitle }}">
    <meta property="og:description" content="{{ $seoDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:alt" content="{{ $property->name }}">
    <meta property="og:locale" content="en_NG">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    <meta name="twitter:description" content="{{ $seoDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    {{-- Schema.org: LodgingBusiness so search engines can build rich
         property cards (rating stars, price, address, image) from
         Keverna Stays search results. --}}
    @php
        $ldRatingSummary = $property->ratingSummary();
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'LodgingBusiness',
            'name' => $property->name,
            'url' => $canonicalUrl,
            // JSON-LD description must be plain text; strip authored HTML.
            'description' => strip_tags((string) ($property->description ?? '')),
            'image' => $ogImage,
        ];
        if ($property->address || $property->city || $property->state) {
            $ld['address'] = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => (string) ($property->address ?? ''),
                'addressLocality' => (string) ($property->city ?? ''),
                'addressRegion' => (string) ($property->state ?? ''),
                'addressCountry' => 'NG',
            ]);
        }
        if ($property->hasCoordinates()) {
            $ld['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $property->latitude,
                'longitude' => (float) $property->longitude,
            ];
        }
        if ($ldRatingSummary['count'] > 0) {
            $ld['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $ldRatingSummary['average'],
                'reviewCount' => $ldRatingSummary['count'],
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }
        if (! empty($from_price) && (int) $from_price > 0) {
            $ld['priceRange'] = '₦'.number_format((int) $from_price).'+';
        }
    @endphp
    <script type="application/ld+json">
        {!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>

    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    {{-- Leaflet 1.9.4 for the "Where you'll be" mini-map. Only
         actually initialises when the property has lat/lng set. --}}
    @if ($property->hasCoordinates())
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
              integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
                integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- The availability browser embedded further down is a Livewire
         component; this static Blade view needs Livewire's own
         styles + script bundle to hydrate it. Kept below @vite so
         Alpine (bundled by Livewire) initialises after our own JS. --}}
    @livewireStyles

    <style>
        .mp-page {
            background: #ffffff; color: #222;
            font-family: 'Inter', sans-serif;
            font-size: 14px; line-height: 1.5; margin: 0;
            /* Safety net so a rogue grid child or long unbreakable string
               can't cause a full-document horizontal scroll on mobile.
               `clip` (vs `hidden`) preserves position:sticky descendants. */
            overflow-x: clip;
        }
        .mp-page a { color: inherit; text-decoration: none; }
        .mp-page *:focus-visible { outline: 2px solid #2E5788; outline-offset: 2px; border-radius: 4px; }

        .mp-nav {
            position: sticky; top: 0; z-index: 60;
            background: #fff; border-bottom: 1px solid #EBEBEB;
            transform: translateY(0);
            transition: transform 220ms ease;
            will-change: transform;
        }
        /* When the availability search bar is about to stick to the
           top, the JS sentinel-observer flips this class on <body>.
           The nav slides out of the way so the sticky search bar
           gets top:0 to itself + the guest doesn't lose the bar
           under the header. Slides back in on scroll-up. */
        body.mp-nav-hidden .mp-nav { transform: translateY(-100%); }
        .mp-nav-inner {
            display: flex; align-items: center; justify-content: space-between;
            gap: 20px; padding: 14px 24px; max-width: 1280px; margin: 0 auto;
        }
        @media (min-width: 900px) { .mp-nav-inner { padding: 14px 40px; } }
        .mp-brand { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; font-size: 20px; letter-spacing: -0.02em; }
        .mp-brand-mark { width: 26px; height: 26px; border-radius: 7px; background: #14171A; color: #fff; display: inline-flex; align-items: center; justify-content: center; }
        .mp-brand-mark svg { width: 13px; height: 13px; }
        .mp-back { font-size: 13.5px; color: #6A6A6A; }
        .mp-back:hover { color: #222; text-decoration: underline; }

        /* Tenant-context nav: replaces the "Back to search" affordance
           with self-service items (Rooms · Contact · Track booking · Sign in)
           when the guest is on a property subdomain. On desktop the top
           three items sit inline; the trailing avatar always opens the
           full menu. On mobile every item collapses into the avatar
           dropdown so the header stays compact. */
        .mp-nav-menu {
            display: flex; align-items: center; gap: 4px;
        }
        .mp-nav-link {
            padding: 8px 12px;
            font-size: 13.5px; font-weight: 500; color: #4B4A45;
            border-radius: 999px;
            transition: background 160ms, color 160ms;
        }
        .mp-nav-link:hover { background: #F4F1EA; color: #14171A; }
        @media (max-width: 720px) {
            .mp-nav-link { display: none; }
        }
        .mp-nav-avatar {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 4px 6px 4px 12px;
            background: #FFFFFF;
            border: 1px solid #E5E1D6;
            border-radius: 999px;
            cursor: pointer;
            transition: box-shadow 160ms, border-color 160ms;
            font-family: inherit;
        }
        .mp-nav-avatar:hover {
            border-color: #C8C1AF;
            box-shadow: 0 6px 16px -10px rgba(20, 15, 8, 0.28);
        }
        .mp-nav-avatar__lines {
            display: inline-flex; flex-direction: column; gap: 3px;
        }
        .mp-nav-avatar__lines span {
            width: 14px; height: 2px; background: #14171A; border-radius: 2px;
        }
        .mp-nav-avatar__circle {
            width: 28px; height: 28px; border-radius: 50%;
            background: #14171A; color: #F4F1EA;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mp-nav-avatar__circle svg { width: 14px; height: 14px; }
        .mp-nav-menu-wrap { position: relative; }
        .mp-nav-menu-panel {
            position: absolute; top: calc(100% + 10px); right: 0;
            min-width: 260px;
            background: #FFFFFF;
            border: 1px solid #EAE4D3;
            border-radius: 14px;
            padding: 8px;
            box-shadow: 0 20px 44px -18px rgba(20, 15, 8, 0.28);
            display: none;
            z-index: 70;
        }
        .mp-nav-menu-panel.is-open { display: block; }
        .mp-nav-menu-panel a,
        .mp-nav-menu-panel button {
            display: flex; align-items: center; gap: 12px;
            width: 100%;
            padding: 10px 12px;
            font-size: 14px; color: #14171A;
            background: transparent; border: 0; text-align: left;
            border-radius: 10px; cursor: pointer;
            font-family: inherit;
        }
        .mp-nav-menu-panel a:hover,
        .mp-nav-menu-panel button:hover { background: #F4F1EA; }
        .mp-nav-menu-panel .mp-nav-menu-item__icon {
            width: 30px; height: 30px; border-radius: 8px;
            background: rgba(173, 203, 82, 0.14); color: #4A6620;
            display: inline-flex; align-items: center; justify-content: center;
            flex: 0 0 30px;
        }
        .mp-nav-menu-panel .mp-nav-menu-item__icon svg { width: 14px; height: 14px; }
        .mp-nav-menu-panel .mp-nav-menu-item__label { display: block; font-weight: 600; line-height: 1.15; }
        .mp-nav-menu-panel .mp-nav-menu-item__sub { display: block; font-size: 12px; color: #6B6B6B; font-weight: 500; margin-top: 2px; }
        .mp-nav-menu-panel__divider {
            height: 1px; background: #F0EBDB;
            margin: 6px 4px;
        }
        .mp-nav-menu-panel__label {
            padding: 8px 12px 4px;
            font-size: 10.5px; font-weight: 700;
            letter-spacing: 0.1em; text-transform: uppercase;
            color: #8B8676;
        }
        .mp-nav-branch__thumb {
            width: 34px; height: 34px; flex: 0 0 34px;
            border-radius: 8px;
            background-size: cover; background-position: center;
            background-color: #F4EFDF;
            display: inline-flex; align-items: center; justify-content: center;
            color: #6A5D3B; font-weight: 700; font-size: 13px;
        }
        .mp-nav-menu-panel .mp-nav-branch__loc {
            display: block; font-size: 12px; color: #6B6B6B; font-weight: 500; margin-top: 2px;
        }

        .mp-container { max-width: 1280px; margin: 0 auto; padding: 24px; }
        @media (min-width: 900px) { .mp-container { padding: 32px 40px; } }

        .mp-title-band {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 24px; flex-wrap: wrap;
        }
        .mp-title-band > div:first-child { flex: 1; min-width: 220px; }
        .mp-title-band h1 {
            font-size: clamp(24px, 4vw, 32px); font-weight: 700;
            letter-spacing: -0.02em; margin: 0 0 6px; color: #222;
        }
        .mp-title-meta { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 13.5px; color: #6A6A6A; align-items: center; }
        .mp-title-meta .mp-rating { display: inline-flex; align-items: center; gap: 4px; color: #222; font-weight: 600; }
        .mp-title-meta .mp-rating svg { width: 12px; height: 12px; }
        .mp-title-meta .mp-dot { color: #C6C6C6; }

        /* Like / Share / Report actions in the top-right of the
           title band. Icon-only for Like + Share (universally
           understood), icon+label for Report (rarer action, worth
           spelling out so guests know it's a moderation channel
           not a "flag as favourite" gesture). */
        .mp-actions {
            display: inline-flex; align-items: center; gap: 4px;
            flex-shrink: 0;
        }
        .mp-action-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 12px; border-radius: 999px;
            background: transparent; border: 1px solid transparent;
            font: inherit; font-size: 13px; font-weight: 600; color: #222;
            cursor: pointer; text-decoration: none; line-height: 1;
            transition: background 120ms ease;
        }
        .mp-action-btn:hover { background: rgba(20,23,26,0.05); }
        .mp-action-btn:active { background: rgba(20,23,26,0.09); }
        .mp-action-btn svg { width: 16px; height: 16px; color: #333; }
        .mp-action-btn.icon-only { padding: 8px; }
        .mp-action-btn.mp-action-liked svg { fill: #E31C5F; color: #E31C5F; }

        .mp-action-toast {
            position: fixed; left: 50%; bottom: 32px; transform: translateX(-50%);
            padding: 10px 16px; border-radius: 999px;
            background: #14171A; color: #fff;
            font-size: 13px; font-weight: 600; z-index: 100;
            opacity: 0; pointer-events: none;
            transition: opacity 180ms ease, transform 180ms ease;
        }
        .mp-action-toast.visible {
            opacity: 1;
            transform: translateX(-50%) translateY(-6px);
        }

        /* Report modal - vanilla, matches the lightbox styling
           idiom on this page (backdrop + hidden by default,
           JS toggles the `hidden` attribute + `aria-hidden`). */
        .mp-report-backdrop {
            position: fixed; inset: 0; z-index: 95;
            background: rgba(20,23,26,0.62);
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
        }
        .mp-report-modal {
            background: #fff; border-radius: 16px;
            width: 100%; max-width: 460px;
            padding: 24px 26px 22px;
            box-shadow: 0 24px 60px rgba(0,0,0,0.28);
        }
        .mp-report-modal h3 {
            font-size: 18px; font-weight: 700; margin: 0 0 4px;
        }
        .mp-report-modal p.mp-report-sub {
            font-size: 13px; color: #6A6A6A; margin: 0 0 16px;
        }
        .mp-report-field { display: block; margin: 0 0 12px; }
        .mp-report-field .lbl {
            display: block; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em;
            color: #6A6A6A; margin-bottom: 4px;
        }
        .mp-report-field select,
        .mp-report-field textarea,
        .mp-report-field input {
            width: 100%; padding: 10px 12px; border: 1px solid #DDDDDD;
            border-radius: 10px; font: inherit; color: #222; background: #fff;
        }
        .mp-report-field textarea { min-height: 96px; resize: vertical; }
        .mp-report-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
        .mp-report-cancel {
            padding: 10px 16px; border-radius: 999px;
            background: transparent; border: 1px solid #DDDDDD;
            font: inherit; font-weight: 600; font-size: 13px;
            color: #222; cursor: pointer;
        }
        .mp-report-submit {
            padding: 10px 16px; border-radius: 999px;
            background: #14171A; color: #fff; border: none;
            font: inherit; font-weight: 700; font-size: 13px; cursor: pointer;
        }
        .mp-report-submit:disabled { opacity: 0.6; cursor: not-allowed; }
        .mp-report-error {
            padding: 10px 12px; border-radius: 8px;
            background: rgba(184, 92, 66, 0.1); color: #7A3927;
            font-size: 12.5px; margin-bottom: 12px;
        }

        /* Photo grid — mobile is a horizontal snap carousel with a
           1/N counter overlay; desktop lays out a 5-photo mosaic
           (1 big + 4 small) that collapses gracefully when we have
           fewer photos. No more stacked "list of images" on phones.

           Structure: `.mp-gallery` is the positioning parent that
           anchors the counter + showall overlays; the scroll
           carousel lives in `.mp-gallery-track` inside it. Splitting
           the layers keeps the overlays from drifting with the
           scrolled content on mobile browsers. */
        .mp-gallery {
            margin-top: 20px;
            position: relative;
            border-radius: 16px;
            overflow: hidden;
        }
        .mp-gallery-track {
            display: flex;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .mp-gallery-track::-webkit-scrollbar { display: none; }
        .mp-gallery-tile {
            position: relative;
            background: #F0F0F0;
            overflow: hidden;
            /* Mobile: one tile per viewport, aspect-locked. */
            flex: 0 0 100%;
            aspect-ratio: 4 / 3;
            scroll-snap-align: start;
            scroll-snap-stop: always;
        }
        .mp-gallery-tile img {
            width: 100%; height: 100%; object-fit: cover; display: block;
            transition: transform 700ms cubic-bezier(0.16, 1, 0.3, 1);
        }
        .mp-gallery-tile:hover img { transform: scale(1.02); }
        .mp-gallery-tile[role="button"] { cursor: pointer; }

        /* Counter pill overlaid on the hero tile — hidden on desktop. */
        .mp-gallery-counter {
            position: absolute;
            right: 14px; bottom: 14px;
            z-index: 3;
            padding: 5px 12px;
            background: rgba(20, 15, 8, 0.66);
            color: #FFFFFF;
            border-radius: 999px;
            font-family: system-ui, sans-serif;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.02em;
            pointer-events: none;
        }
        @media (min-width: 720px) { .mp-gallery-counter { display: none; } }

        @media (min-width: 720px) {
            /* Desktop: swap the track from flex carousel to a grid mosaic. */
            .mp-gallery-track {
                display: grid;
                gap: 8px;
                grid-template-columns: 1fr;
                overflow: hidden;
                scroll-snap-type: none;
            }
            .mp-gallery-tile {
                flex: initial;
                aspect-ratio: auto;
                scroll-snap-align: none;
            }
            /* 1 photo → single wide hero */
            .mp-gallery--count-1 { grid-template-columns: 1fr; grid-template-rows: 380px; }
            /* 2 photos → side by side */
            .mp-gallery--count-2 { grid-template-columns: 1fr 1fr; grid-template-rows: 400px; }
            /* 3 photos → 2fr + stacked 1fr */
            .mp-gallery--count-3 {
                grid-template-columns: 2fr 1fr;
                grid-template-rows: 200px 200px;
            }
            .mp-gallery--count-3 .mp-gallery-tile:nth-child(1) { grid-row: 1 / span 2; }
            /* 4 photos → 2fr + 3 stacked 1fr — actually 2fr + 1fr | 1fr | 1fr */
            .mp-gallery--count-4 {
                grid-template-columns: 2fr 1fr 1fr;
                grid-template-rows: 200px 200px;
            }
            .mp-gallery--count-4 .mp-gallery-tile:nth-child(1) { grid-row: 1 / span 2; }
            /* 5+ photos — classic Airbnb-style mosaic: 1 big + 4 small */
            .mp-gallery--count-5,
            .mp-gallery--count-many {
                grid-template-columns: 2fr 1fr 1fr;
                grid-template-rows: 200px 200px;
            }
            .mp-gallery--count-5 .mp-gallery-tile:nth-child(1),
            .mp-gallery--count-many .mp-gallery-tile:nth-child(1) { grid-row: 1 / span 2; }
        }
        .mp-gallery-showall {
            position: absolute;
            right: 16px; bottom: 16px;
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255, 255, 255, 0.96);
            color: #1F2937;
            border: 1px solid rgba(20, 23, 26, 0.12);
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 10px 24px -12px rgba(0, 0, 0, 0.25);
            transition: transform 180ms, background 180ms;
            z-index: 2;
        }
        .mp-gallery-showall:hover { transform: translateY(-1px); background: #FFFFFF; }
        /* On mobile the counter pill covers the "how many photos"
           signal; the guest reaches the full lightbox by tapping any
           tile, so hide the show-all button below the carousel breakpoint. */
        @media (max-width: 719px) { .mp-gallery-showall { display: none; } }
        .mp-gallery-showall svg { flex: 0 0 14px; }
        .mp-gallery-stub {
            width: 100%; height: 100%;
            background: linear-gradient(135deg, #F4F0EA 0%, #E6E0D3 100%);
            display: flex; align-items: center; justify-content: center;
            color: rgba(20,23,26,0.28); font-size: 11px; font-weight: 600;
            letter-spacing: 0.14em; text-transform: uppercase;
            text-align: center; padding: 12px;
        }

        .mp-body { display: grid; grid-template-columns: minmax(0, 1fr); gap: 32px; margin-top: 40px; }
        @media (min-width: 900px) { .mp-body { grid-template-columns: minmax(0, 1fr) 340px; gap: 56px; } }
        /* Every direct grid child gets min-width: 0 so long unbreakable
           content (map tiles, images, or intrinsic-min items like our
           room cards' image column) can't push the column wider than
           its share. Without this, `1fr` degrades to `min-content` and
           the whole body overflows the viewport. */
        .mp-body > * { min-width: 0; }

        .mp-section { border-top: 1px solid #EBEBEB; padding: 28px 0 0; margin-top: 28px; }
        .mp-section:first-child { border-top: none; padding-top: 0; margin-top: 0; }
        /* Rooms is the hero conversion moment — extra breathing room
           so it reads as a new chapter, not a subhead of amenities. */
        .mp-rooms-section {
            margin-top: 56px;
            padding-top: 40px;
            border-top: 1px solid #EBEBEB;
        }
        @media (min-width: 900px) { .mp-rooms-section { margin-top: 64px; padding-top: 48px; } }

        /* Return-to-checkout floating pill. Fixed to the
           bottom-right of the viewport so it's always visible
           while the guest scrolls the property page. Deep ink
           fill for high contrast against the white body + an
           olive-lime circular count badge on the left so it
           reads as a persistent cart at a glance.
           Scoped under .mp-page so the class-plus-class selector
           beats `.mp-page a { color: inherit }` and the label
           text stays white without needing !important. Same
           trick as .mp-page .mp-cat-reserve elsewhere. */
        .mp-page .mp-return-checkout {
            position: fixed;
            bottom: 24px; right: 24px;
            z-index: 60;
            display: inline-flex; align-items: center; gap: 12px;
            padding: 10px 18px 10px 10px;
            background: #14171A; color: #fff;
            border-radius: 999px;
            text-decoration: none;
            font-size: 13.5px; font-weight: 700;
            box-shadow: 0 10px 28px rgba(0,0,0,0.24), 0 2px 6px rgba(0,0,0,0.10);
            transition: transform 140ms ease, background 140ms ease;
        }
        .mp-page .mp-return-checkout:hover {
            background: #1F2226;
            color: #fff;
            transform: translateY(-2px);
        }
        .mp-page .mp-return-checkout svg { color: rgba(255,255,255,0.85); flex-shrink: 0; }
        .mp-page .mp-return-checkout-badge {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 999px;
            background: #8AAE3A; color: #fff;
            font-size: 13px; font-weight: 800;
            flex-shrink: 0;
        }
        .mp-page .mp-return-checkout-label { display: inline-flex; flex-direction: column; line-height: 1.2; }
        .mp-page .mp-return-checkout-title { font-size: 13px; font-weight: 700; color: #fff; }
        .mp-page .mp-return-checkout-count {
            font-size: 11px; color: rgba(255,255,255,0.7);
            font-weight: 500; margin-top: 1px;
        }
        @media (max-width: 640px) {
            .mp-page .mp-return-checkout { bottom: 16px; right: 16px; padding: 8px 14px 8px 8px; }
            .mp-page .mp-return-checkout-badge { width: 26px; height: 26px; font-size: 12px; }
        }
        .mp-section h2 { font-size: 20px; font-weight: 700; margin: 0 0 12px; color: #222; letter-spacing: -0.01em; }
        .mp-section-lede { color: #6B6B6B; font-size: 14px; line-height: 1.55; margin: 0; max-width: 640px; }
        .mp-desc { color: #333; font-size: 15px; line-height: 1.7; white-space: pre-line; }

        /* Video tour card. Poster (property's own hero image) sits under
           a big centred play button until the guest taps it, then Alpine
           swaps the poster for a YouTube nocookie iframe so the video
           plays inline without leaving the page. Nothing loads from
           YouTube until the guest actually asks for it. */
        .mp-video {
            position: absolute;
            inset: 0;
            border-radius: 16px;
            overflow: hidden;
            background: #14171A;
            isolation: isolate;
        }
        .mp-video__poster {
            position: absolute; inset: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
        }
        .mp-video__scrim {
            position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(20, 15, 8, 0.05) 0%, rgba(20, 15, 8, 0.55) 100%);
        }
        .mp-video__play {
            position: absolute; left: 50%; top: 50%;
            transform: translate(-50%, -50%);
            width: 84px; height: 84px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.94);
            border: 0;
            cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            color: #14171A;
            box-shadow: 0 20px 44px -14px rgba(20, 15, 8, 0.45), 0 0 0 0 rgba(255, 255, 255, 0.5);
            transition: transform 200ms cubic-bezier(0.16, 1, 0.3, 1), background 200ms, box-shadow 400ms;
            font-family: inherit;
        }
        .mp-video__play::after {
            content: '';
            position: absolute; inset: -6px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.6);
            opacity: 0;
            animation: mp-video-pulse 2.4s ease-out infinite;
        }
        @keyframes mp-video-pulse {
            0% { transform: scale(0.9); opacity: 0.7; }
            80% { transform: scale(1.28); opacity: 0; }
            100% { transform: scale(1.28); opacity: 0; }
        }
        .mp-video__play:hover {
            transform: translate(-50%, -50%) scale(1.05);
            background: #ADCB52;
            color: #14171A;
        }
        .mp-video__play svg {
            width: 30px; height: 30px;
            margin-left: 4px;
        }
        .mp-video__meta {
            position: absolute; left: 18px; bottom: 16px;
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 12px;
            background: rgba(20, 15, 8, 0.55);
            color: #FFFFFF;
            border-radius: 999px;
            font-size: 12px; font-weight: 600;
            letter-spacing: 0.02em;
            -webkit-backdrop-filter: blur(6px);
            backdrop-filter: blur(6px);
            pointer-events: none;
        }
        .mp-video__meta svg { width: 12px; height: 12px; }
        .mp-video__frame {
            position: absolute; inset: 0;
            width: 100%; height: 100%; border: 0;
        }
        @media (max-width: 640px) {
            .mp-video__play { width: 66px; height: 66px; }
            .mp-video__play svg { width: 24px; height: 24px; margin-left: 3px; }
        }

        /* Slot wrapper that reserves the card's spot in layout flow.
           The card sits absolutely inside this slot normally, so when
           it flips to position:fixed for PiP the slot keeps holding
           the height and the rooms section below doesn't jump up.
           Also serves as the IntersectionObserver target since it
           always reports a stable, in-flow bounding box. */
        .mp-video-slot {
            position: relative;
            aspect-ratio: 16 / 9;
            border-radius: 16px;
        }

        /* Sticky-scroll picture-in-picture. Kicks in only on desktop
           (>= 900px) when the visitor has actually started playback
           AND the card has scrolled out of view. Fixed to the bottom-
           right corner with a compact 360x203 footprint that plays
           smaller than the in-flow player without feeling toylike. */
        @media (min-width: 900px) {
            .mp-video--pip {
                position: fixed !important;
                top: auto !important; left: auto !important;
                right: 24px; bottom: 24px;
                width: 360px; height: 203px;
                z-index: 60;
                box-shadow: 0 24px 48px -12px rgba(20, 15, 8, 0.42);
                animation: mp-video-pip-in 240ms cubic-bezier(0.16, 1, 0.3, 1);
            }
            .mp-video--pip .mp-video__pip-close {
                position: absolute; top: 6px; right: 6px;
                width: 28px; height: 28px; border-radius: 50%;
                background: rgba(20, 15, 8, 0.72);
                color: #FFFFFF; border: 0; cursor: pointer;
                display: inline-flex; align-items: center; justify-content: center;
                z-index: 2;
            }
            .mp-video--pip .mp-video__pip-close:hover {
                background: rgba(20, 15, 8, 0.92);
            }
            .mp-video__pip-close { display: none; }
            .mp-video--pip .mp-video__pip-close { display: inline-flex; }
        }
        @keyframes mp-video-pip-in {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to   { opacity: 1; transform: translateY(0)    scale(1); }
        }

        .mp-amenities {
            display: grid; gap: 14px 20px; margin-top: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        @media (min-width: 640px) {
            .mp-amenities { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        .mp-amenity {
            display: flex; align-items: center; gap: 12px;
            font-size: 14px; color: #222;
            padding: 10px 12px; border: 1px solid #EBEBEB; border-radius: 12px;
            background: #fff;
        }
        .mp-amenity svg { width: 22px; height: 22px; color: #6E8E29; flex-shrink: 0; }

        /* Map wrapper — bigger canvas + rounded corner + a shield
           overlay that keeps mobile touches from panning the map
           until the guest deliberately taps into it. */
        .mp-map-wrap {
            position: relative;
            width: 100%;
            height: 400px;
            border-radius: 14px;
            overflow: hidden;
            background: #E5E3DF;
            /* Leaflet's internal panes stack up to z-index 700 (attribution
               + tooltips) which would leak over the sticky top bar. Force
               a new stacking context so the map is a self-contained sheet. */
            isolation: isolate;
            z-index: 0;
        }
        @media (max-width: 640px) { .mp-map-wrap { height: 320px; } }
        .mp-map-wrap #stays-show-map {
            width: 100%; height: 100%;
        }
        .mp-map-shield {
            position: absolute; inset: 0; z-index: 10;
            background: rgba(20, 15, 8, 0.06);
            display: flex; align-items: center; justify-content: center;
            border: 0; padding: 0; cursor: pointer;
            font-family: inherit;
        }
        .mp-map-shield::before {
            content: '';
            position: absolute; inset: 0;
            background: rgba(20, 15, 8, 0.28);
            opacity: 0; transition: opacity 220ms;
        }
        .mp-map-shield:hover::before { opacity: 1; }
        .mp-map-shield span {
            position: relative;
            background: #FFFFFF; color: #1F2937;
            padding: 10px 18px; border-radius: 999px;
            font-size: 12.5px; font-weight: 600;
            letter-spacing: 0.02em;
            box-shadow: 0 10px 24px -12px rgba(0, 0, 0, 0.28);
            opacity: 0; transition: opacity 220ms;
        }
        .mp-map-shield:hover span { opacity: 1; }
        /* On desktop the shield is invisible until hover (mouse users
           barely need it — scrollWheelZoom is off anyway). On mobile
           we always show the pill so the affordance is discoverable. */
        @media (hover: none) {
            .mp-map-shield::before { opacity: 1; background: rgba(20, 15, 8, 0.18); }
            .mp-map-shield span { opacity: 1; }
        }

        /* Custom Leaflet pin marker — SVG divIcon so no image request.
           Drop-shadow gives it lift above the tiles. */
        .mp-map-pin-icon { background: none; border: 0; }
        .mp-map-pin { filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.25)); }
        .mp-map-pin svg { display: block; }
        .leaflet-popup-content { margin: 10px 14px !important; font-size: 13px; }
        .leaflet-popup-content-wrapper { border-radius: 10px !important; }

        /* Numbered POI marker. Shared visual language with the .mp-
           nearby-num badge on the card below so a guest can pair
           "pin 1" on the map with "1" in the card list. */
        .mp-map-num-icon { background: none; border: 0; }
        .mp-map-num {
            width: 28px; height: 28px;
            background: #FFFFFF;
            color: #1F2937;
            border: 2px solid #1F2937;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 13px; font-weight: 700;
            box-shadow: 0 6px 14px -4px rgba(0, 0, 0, 0.28);
            line-height: 1;
        }
        .mp-nearby-num {
            flex: 0 0 26px;
            width: 26px; height: 26px;
            border-radius: 50%;
            background: #1F2937;
            color: #FFFFFF;
            display: inline-flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 12px; font-weight: 700;
            letter-spacing: 0;
        }

        /* Places nearby — one card per place in a 2/3-column grid.
           Each card carries a colored icon by mode, the place label
           as the primary line, and distance + mode label as a small
           second row. Reads more like a directory than a text list. */
        .mp-nearby {
            display: grid; gap: 12px; margin-top: 8px;
            grid-template-columns: minmax(0, 1fr);
        }
        @media (min-width: 640px) { .mp-nearby { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1000px) { .mp-nearby { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .mp-nearby-row {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 16px;
            border: 1px solid #EBEBEB; border-radius: 12px;
            background: #FFFFFF;
        }
        .mp-nearby-icon {
            width: 36px; height: 36px; flex: 0 0 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #F1F7E6 0%, #E4EED0 100%);
            color: #4A6B1E;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mp-nearby-icon svg { width: 16px; height: 16px; }
        .mp-nearby-body { flex: 1; min-width: 0; }
        .mp-nearby-label {
            display: block;
            font-size: 14px; font-weight: 600; color: #1F2937;
            letter-spacing: -0.005em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .mp-nearby-meta {
            display: block;
            font-size: 12px; color: #6B6B6B;
            margin-top: 2px;
            letter-spacing: 0.02em;
        }
        .mp-nearby-mode {
            display: inline; text-transform: capitalize;
        }

        /* Category photo lightbox - full-viewport dim + centred image
           strip, x-cloak hidden by default; the modal only opens when
           Alpine's `open` flag flips true. Alpine's `x-teleport`
           to body keeps it out of the sticky right column's
           positioning context. */
        [x-cloak] { display: none !important; }
        .mp-lightbox-backdrop {
            position: fixed; inset: 0; z-index: 90;
            background: rgba(20,23,26,0.86);
            display: flex; align-items: center; justify-content: center;
            padding: 24px;
        }
        .mp-lightbox-close, .mp-lightbox-nav {
            position: absolute; top: 20px; right: 20px;
            width: 40px; height: 40px; border-radius: 999px;
            background: rgba(255,255,255,0.14); color: #fff;
            border: none; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mp-lightbox-nav { top: 50%; transform: translateY(-50%); right: 24px; }
        .mp-lightbox-nav.prev { left: 24px; right: auto; }
        .mp-lightbox-fig { max-width: 100%; max-height: 100%; text-align: center; }
        .mp-lightbox-fig img {
            max-width: min(90vw, 1100px); max-height: 78vh;
            border-radius: 14px; box-shadow: 0 24px 60px rgba(0,0,0,0.4);
        }
        .mp-lightbox-count {
            position: absolute; bottom: 24px; left: 50%; transform: translateX(-50%);
            padding: 6px 14px; border-radius: 999px;
            background: rgba(255,255,255,0.14); color: #fff;
            font-size: 12.5px; font-weight: 600;
        }

        /* "N photos" pill overlaid on the category photo when the
           category actually has more than one image - signals the
           photo is clickable + a gallery is behind it. */
        .mp-cat-photo { position: relative; cursor: pointer; }
        .mp-cat-photo-count {
            position: absolute; bottom: 6px; right: 6px;
            padding: 3px 8px; border-radius: 999px;
            background: rgba(20,23,26,0.72); color: #fff;
            font-size: 11px; font-weight: 700;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .mp-cat-price { text-decoration: none !important; }

        /* Property policies — icon-led rows grouped into cards. Same
           visual language as the amenity list + operator card so the
           page reads as one coherent chapter. */
        .mp-policies {
            display: grid; gap: 20px;
            grid-template-columns: 1fr;
            margin-top: 20px;
        }
        @media (min-width: 720px) { .mp-policies { grid-template-columns: 1fr 1fr; } }
        .mp-policy-card {
            border: 1px solid #EBEBEB;
            border-radius: 14px;
            padding: 24px 26px;
            background: #FFFFFF;
            display: flex; flex-direction: column; gap: 18px;
        }
        .mp-policy-card__head {
            display: flex; align-items: center; gap: 10px;
            padding-bottom: 12px;
            border-bottom: 1px solid #F0F0F0;
        }
        .mp-policy-card__head h3 {
            margin: 0; font-size: 14px; font-weight: 700;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: #4B4A45;
        }
        .mp-policy-card__head-icon {
            width: 30px; height: 30px;
            border-radius: 8px;
            background: linear-gradient(135deg, #F4EFDF 0%, #E9DCB6 100%);
            color: #4A6620;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mp-policy-row {
            display: flex; align-items: flex-start; gap: 14px;
        }
        .mp-policy-row__icon {
            width: 28px; height: 28px; flex: 0 0 28px;
            border-radius: 8px;
            background: rgba(173, 203, 82, 0.14);
            color: #4A6620;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mp-policy-row__icon svg { width: 14px; height: 14px; }
        .mp-policy-row__body { flex: 1; min-width: 0; }
        .mp-policy-row__label {
            font-size: 12px; font-weight: 600; color: #6B6B6B;
            letter-spacing: 0.04em; text-transform: uppercase;
            margin-bottom: 2px;
        }
        .mp-policy-row__value {
            font-size: 14px; color: #1F2937; line-height: 1.5;
        }
        .mp-policy-chip-row {
            display: flex; flex-wrap: wrap; gap: 8px;
            margin-top: 4px;
        }
        .mp-policy-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 10px;
            background: rgba(173, 203, 82, 0.14);
            color: #4A6620;
            border-radius: 999px;
            font-size: 11.5px; font-weight: 600;
            letter-spacing: 0.02em;
        }
        .mp-policy-chip svg { flex: 0 0 12px; }
        /* Legacy classes retained so older callers don't break the grid. */
        .mp-rules { display: grid; gap: 10px 24px; grid-template-columns: 1fr; margin-top: 8px; }
        @media (min-width: 640px) { .mp-rules { grid-template-columns: 1fr 1fr; } }
        .mp-rule { font-size: 13.5px; color: #333; }
        .mp-rule b { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: #6A6A6A; font-weight: 600; margin-bottom: 2px; }

        /* Per-property availability search - dates + guests. Sits
           at the top of the Rooms section as a standard form; sticky
           was distracting when the guest was reading the rooms below,
           so we let it scroll away naturally. */
        .mp-avail-bar {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 8px;
            padding: 12px;
            margin: 0 0 20px;
            border: 1px solid #EBEBEB;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 4px 14px rgba(0,0,0,0.05);
        }
        @media (max-width: 640px) {
            .mp-avail-bar {
                grid-template-columns: 1fr 1fr;
            }
            .mp-avail-bar-submit { grid-column: 1 / -1; }
        }
        .mp-avail-field { display: flex; flex-direction: column; gap: 2px; padding: 6px 10px; border-right: 1px solid #EEE; }
        .mp-avail-field:last-of-type { border-right: none; }
        .mp-avail-label {
            font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #6A6A6A;
        }
        .mp-avail-field input {
            border: none; padding: 0; font: inherit; background: transparent;
            color: #222; font-size: 14px; min-width: 0;
            /* iOS Safari collapses an unset `input[type=date]` to zero
               height when the border is stripped, so the mm/dd/yyyy
               placeholder never paints. Force an explicit line-height +
               min-height and turn off the platform look so our own
               typography wins. `-webkit-min-logical-width: 0` is the
               idiomatic reset for iOS date fields that would otherwise
               refuse to shrink into a flex column. */
            line-height: 1.4;
            min-height: 20px;
            -webkit-appearance: none;
            appearance: none;
            -webkit-min-logical-width: 0;
            font-family: inherit;
        }
        /* iOS renders each date segment via ::-webkit-datetime-edit.
           Left-align the segment container so the placeholder + value
           sit flush with the label above instead of hugging the right
           edge of the control (which reads as "empty"). */
        .mp-avail-field input::-webkit-datetime-edit,
        .mp-avail-field input::-webkit-datetime-edit-fields-wrapper {
            padding: 0;
            color: inherit;
        }
        .mp-avail-field input::-webkit-date-and-time-value {
            text-align: left;
            min-height: 1.4em;
        }
        .mp-avail-field input:focus { outline: none; }
        .mp-avail-bar-submit {
            padding: 10px 18px; border-radius: 999px;
            background: #8AAE3A; color: #fff; border: none; cursor: pointer;
            font-weight: 700; font-size: 13.5px;
            transition: background 120ms ease;
        }
        .mp-avail-bar-submit:hover { background: #ADCB52; }

        /* Category card - the operator sells room categories, not
           individual door numbers; guests browse categories + we
           allocate a specific room only when they click Reserve. */
        .mp-cat-card {
            display: grid; grid-template-columns: 128px 1fr auto; gap: 18px;
            align-items: center;
            padding: 18px 0;
            border-top: 1px solid #F1F1F1;
        }
        .mp-cat-card:first-child { border-top: none; }
        @media (max-width: 640px) {
            .mp-cat-card { grid-template-columns: 96px 1fr; }
            /* Keep the CTA row tied to the meta column so it doesn't
               open a dead gutter under the photo. Split price ← / Reserve →
               so the row fills its width instead of hugging the right edge. */
            .mp-cat-card .mp-cat-cta {
                grid-column: 2;
                display: flex; align-items: center; justify-content: space-between;
                gap: 12px; flex-wrap: wrap;
                text-align: left;
            }
            .mp-cat-card .mp-cat-cta .mp-cat-price { text-align: left; }
            .mp-cat-card .mp-cat-cta .mp-cat-reserve,
            .mp-cat-card .mp-cat-cta .mp-cat-soldout { margin-top: 0; margin-left: auto; }
        }
        .mp-cat-card.sold-out { opacity: 0.62; }
        .mp-cat-photo {
            width: 128px; aspect-ratio: 4/3; border-radius: 12px;
            background: #F0F0F0; overflow: hidden;
        }
        @media (max-width: 640px) { .mp-cat-photo { width: 96px; } }
        .mp-cat-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .mp-cat-meta h3 { font-size: 16px; font-weight: 600; margin: 0 0 4px; color: #222; }
        .mp-cat-meta .mp-cat-sub {
            font-size: 12.5px; color: #6A6A6A; margin-bottom: 6px;
            display: flex; flex-wrap: wrap; gap: 4px 10px;
        }
        .mp-cat-meta .mp-cat-blurb {
            font-size: 13px; color: #444; margin: 0;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .mp-cat-avail {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 8px; border-radius: 999px;
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .mp-cat-avail-ok  { background: #F1F7E6; color: #4A6B1E; }
        .mp-cat-avail-low { background: #FCEFD4; color: #8B6300; }
        .mp-cat-avail-none { background: rgba(184, 92, 66, 0.12); color: #7A3927; }
        /* Trust chips sitting alongside the availability pill:
           category rating (compact ★ 4.8 · N) + total stays hosted. */
        .mp-cat-rating {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 12px; font-weight: 600; color: #14171A;
        }
        .mp-cat-rating-star { color: #14171A; font-size: 12px; }
        .mp-cat-rating-count { color: #6A6A6A; font-weight: 500; }
        .mp-cat-stays {
            font-size: 11.5px; color: #6A6A6A;
        }
        .mp-cat-cta { text-align: right; min-width: 140px; }
        .mp-cat-price { font-size: 14px; color: #222; font-weight: 700; }
        .mp-cat-price .mp-cat-suffix { font-weight: 400; color: #6A6A6A; font-size: 12.5px; margin-left: 2px; }
        /* Ghost button - the primary "book" action is the sticky
           right-column card + the checkout page; the category
           Reserve is a secondary "jump straight to this room type"
           entry point. Full-colour green pills on every row read
           as too loud when there are 4-5 categories. Outline
           default + ink fill on hover keeps the click affordance
           clear without dominating the row.
           Scoped under .mp-page to beat the `.mp-page a { color:
           inherit }` cascade (same trick as .mp-page .mkt-brand
           elsewhere in the codebase). */
        .mp-page .mp-cat-reserve {
            display: inline-block; margin-top: 8px;
            padding: 8px 16px; border-radius: 999px;
            background: transparent; color: #14171A;
            border: 1.5px solid #14171A;
            font-weight: 700; font-size: 13px;
            cursor: pointer; text-decoration: none;
            transition: background 120ms ease, color 120ms ease;
        }
        .mp-page .mp-cat-reserve:hover {
            background: #14171A; color: #fff;
        }
        .mp-cat-soldout {
            display: inline-block; padding: 9px 16px; border-radius: 999px;
            background: #F1F1F1; color: #6A6A6A;
            font-weight: 700; font-size: 13px; margin-top: 8px;
        }

        .mp-review-list { display: grid; gap: 20px; margin-top: 12px; }
        /* Reviews grid — 2 columns on desktop, 1 on mobile. Each
           review is a soft-bordered card with the guest name at
           the top-left and their star rating at the top-right. */
        .mp-review-grid {
            display: grid; gap: 24px;
            grid-template-columns: 1fr;
            margin-top: 20px;
        }
        @media (min-width: 720px) {
            .mp-review-grid { grid-template-columns: 1fr 1fr; gap: 28px 40px; }
        }
        .mp-review-grid .mp-review {
            padding: 0;
            border: none;
            border-radius: 0;
        }
        .mp-review-grid .mp-review-head { margin-bottom: 8px; }
        .mp-review-grid .mp-review-body {
            font-size: 14px; color: #444; line-height: 1.55;
            display: -webkit-box;
            -webkit-line-clamp: 5;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .mp-reviews-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 4px; }
        .mp-reviews-head h2 {
            font-size: 22px; font-weight: 700; margin: 0;
            display: inline-flex; align-items: baseline; gap: 8px; color: #1F2937;
        }
        .mp-reviews-star { font-size: 22px; }
        .mp-reviews-count { font-size: 16px; font-weight: 500; color: #6B6B6B; }
        /* Reviews modal (full list) — same open/close plumbing as the
           amenities modal. Shares .mp-amenities-modal-backdrop for
           the backdrop layer + card. */
        .mp-reviews-modal-list {
            display: grid; gap: 22px;
        }
        .mp-reviews-modal-list .mp-review {
            padding: 20px 0;
            border-top: 1px solid #F0F0F0;
            border-radius: 0;
        }
        .mp-reviews-modal-list .mp-review:first-child { border-top: none; padding-top: 0; }

        /* Click-to-open affordance for the review cards (both the
           inline grid + the all-reviews modal). Subtle opacity fade
           on hover so the row still reads as content, not a button,
           but responds to intent. */
        .mp-review-grid .mp-review,
        .mp-reviews-modal-list .mp-review {
            cursor: pointer;
            transition: opacity 160ms ease;
        }
        .mp-review-grid .mp-review:hover,
        .mp-reviews-modal-list .mp-review:hover { opacity: 0.7; }
        .mp-review-grid .mp-review:focus-visible,
        .mp-reviews-modal-list .mp-review:focus-visible {
            outline: 2px solid #14171A; outline-offset: 4px; border-radius: 8px;
        }

        /* Review-detail modal — reuses .mp-amenities-modal-backdrop / -card. */
        .mp-review-detail { padding: 4px 0 8px; }
        .mp-review-detail-head {
            display: flex; align-items: flex-start; gap: 14px;
            padding-bottom: 16px; border-bottom: 1px solid #F0F0F0; margin-bottom: 16px;
        }
        .mp-review-detail-avatar {
            width: 52px; height: 52px; border-radius: 999px; object-fit: cover; flex-shrink: 0;
            background: #F1EBE0; color: #6B6B6B;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: 17px;
        }
        .mp-review-detail-title { flex: 1; min-width: 0; }
        .mp-review-detail-name  { font-size: 16px; font-weight: 700; color: #222; }
        .mp-review-detail-country { font-size: 13px; color: #6B6B6B; margin-left: 6px; font-weight: 500; }
        .mp-review-detail-meta {
            display: flex; align-items: center; gap: 10px; margin-top: 6px; flex-wrap: wrap;
            font-size: 12px; color: #6B6B6B;
        }
        .mp-review-detail-chip {
            padding: 2px 8px; border-radius: 999px;
            font-size: 10px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
        }
        .mp-review-detail-rating { font-size: 14px; color: #222; white-space: nowrap; }
        .mp-review-detail-body {
            font-size: 15px; line-height: 1.65; color: #222; white-space: pre-wrap;
            margin: 0 0 18px;
        }
        .mp-review-detail-response {
            padding: 14px 16px; border-radius: 10px; background: #F7F5EE;
            border-left: 3px solid #B7A57C;
        }
        .mp-review-detail-response-head {
            display: flex; align-items: baseline; justify-content: space-between; gap: 10px;
            font-size: 12px; color: #6B6B6B; margin-bottom: 6px;
        }
        .mp-review-detail-response-label { font-weight: 600; color: #333; font-size: 13px; }
        .mp-review-detail-response-body { font-size: 14px; line-height: 1.6; color: #333; white-space: pre-wrap; }

        .mp-review { padding: 16px; border: 1px solid #EBEBEB; border-radius: 12px; }
        .mp-review-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
        .mp-review-name { font-weight: 600; font-size: 14px; color: #222; }
        .mp-review-rating { font-size: 12px; color: #222; }
        .mp-review-body { color: #333; font-size: 14px; line-height: 1.55; }
        .mp-review-badge {
            display: inline-flex; gap: 4px; align-items: center;
            margin-left: 8px;
            padding: 2px 8px; border-radius: 999px;
            background: #F1F7E6; color: #4A6B1E;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;
        }
        /* Operator reply block. Inset with a soft left rule so it reads
           as "threaded under this review" rather than a standalone card. */
        .mp-review-response {
            margin-top: 14px;
            padding: 12px 14px;
            background: #FAFAF7;
            border-left: 3px solid #D1D5DB;
            border-radius: 0 8px 8px 0;
        }
        .mp-review-response-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }
        .mp-review-response-label {
            font-weight: 600;
            font-size: 12.5px;
            color: #222;
            letter-spacing: -0.005em;
        }
        .mp-review-response-time {
            font-size: 11.5px;
            color: #6B7280;
        }
        .mp-review-response-body {
            color: #444;
            font-size: 13.5px;
            line-height: 1.55;
        }

        .mp-book-card {
            position: sticky; top: 90px;
            border: 1px solid #EBEBEB; border-radius: 16px; padding: 22px 22px 20px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.06);
            background: #fff;
        }
        .mp-book-price { display: flex; align-items: baseline; gap: 6px; margin-bottom: 12px; }
        .mp-book-price b { font-size: 22px; font-weight: 700; color: #222; }
        .mp-book-price .mp-suffix { color: #6A6A6A; font-size: 13px; }
        .mp-book-fields {
            display: grid; grid-template-columns: 1fr 1fr; gap: 0;
            border: 1px solid #DDDDDD; border-radius: 12px; overflow: hidden;
            margin-bottom: 10px;
        }
        .mp-book-field { padding: 10px 12px; border-right: 1px solid #EEE; }
        .mp-book-field:last-child { border-right: none; }
        .mp-book-field .mp-book-label {
            font-size: 10px; font-weight: 700; letter-spacing: 0.08em;
            text-transform: uppercase; color: #6A6A6A; margin-bottom: 2px;
        }
        .mp-book-field input {
            border: none; padding: 0; font: inherit; width: 100%;
            background: transparent; color: #222; font-size: 13.5px;
        }
        .mp-book-field input:focus { outline: none; }
        .mp-book-field.mp-book-field-guests { grid-column: 1 / span 2; border-right: none; border-top: 1px solid #EEE; }
        .mp-book-cta {
            display: block; width: 100%;
            padding: 14px 18px; border-radius: 12px;
            background: linear-gradient(135deg, #ADCB52 0%, #8AAE3A 100%); color: #fff;
            font-weight: 700; font-size: 14.5px; text-align: center;
            border: none; cursor: pointer; margin-top: 4px;
        }
        .mp-book-cta:hover { background: linear-gradient(135deg, #8AAE3A 0%, #6E8E29 100%); color: #fff; }
        .mp-book-note { font-size: 11.5px; color: #6A6A6A; text-align: center; margin-top: 10px; }

        /* Sticky bottom booking bar (mobile) — always-visible price
           + Reserve CTA. The single most important mobile conversion
           pattern; without it a guest has to scroll back up to reach
           the booking form. Anchored to the bottom of the viewport
           with a hairline top border + subtle shadow so it separates
           from body content without dominating it. Hidden on desktop
           where the right-rail booking card handles the same job. */
        .mp-book-bar {
            position: fixed; left: 0; right: 0; bottom: 0;
            z-index: 45;
            background: #FFFFFF;
            border-top: 1px solid rgba(20, 23, 26, 0.10);
            box-shadow: 0 -12px 32px -18px rgba(0, 0, 0, 0.14);
            padding: 12px 18px calc(12px + env(safe-area-inset-bottom));
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
        }
        .mp-book-bar__price { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .mp-book-bar__amount {
            font-size: 17px; font-weight: 700; color: #1F2937;
            letter-spacing: -0.01em;
        }
        .mp-book-bar__amount .mp-suffix {
            font-size: 12px; font-weight: 500; color: #6B6B6B; margin-left: 4px;
        }
        .mp-book-bar__meta { font-size: 12px; color: #6B6B6B; }
        .mp-book-bar__cta {
            flex: 0 0 auto;
            display: inline-flex; align-items: center; justify-content: center;
            padding: 12px 28px; border-radius: 999px;
            background: linear-gradient(135deg, #ADCB52 0%, #8AAE3A 100%); color: #FFFFFF;
            font-weight: 700; font-size: 14px; letter-spacing: 0.01em;
            border: none; cursor: pointer; text-decoration: none;
            box-shadow: 0 8px 20px -10px rgba(138, 174, 58, 0.55);
        }
        .mp-book-bar__cta:hover {
            background: linear-gradient(135deg, #8AAE3A 0%, #6E8E29 100%);
        }
        @media (min-width: 900px) {
            .mp-book-bar { display: none; }
        }
        /* Add breathing room at the bottom of the page so the fixed
           bar never overlaps the last section on mobile. */
        @media (max-width: 899px) {
            .mp-body { padding-bottom: 96px; }
        }

        /* Sticky top jump-nav (desktop). Hidden by default; JS toggles
           the `is-visible` class once the guest scrolls past the hero
           title, and `is-cta` once they scroll past the booking card
           so the nav grows a compact Reserve button on the right for
           conversion. Hidden on mobile — the bottom bar covers that. */
        .mp-jumpnav {
            position: fixed; left: 0; right: 0; top: 0; z-index: 42;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: saturate(180%) blur(12px);
            -webkit-backdrop-filter: saturate(180%) blur(12px);
            border-bottom: 1px solid rgba(20, 23, 26, 0.08);
            padding: 14px 24px;
            opacity: 0;
            transform: translateY(-100%);
            transition: opacity 220ms ease, transform 260ms cubic-bezier(0.2, 0.7, 0.2, 1);
            pointer-events: none;
        }
        .mp-jumpnav.is-visible {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .mp-jumpnav__inner {
            max-width: 1280px; margin: 0 auto;
            display: flex; align-items: center; justify-content: space-between; gap: 24px;
        }
        .mp-jumpnav__links {
            display: flex; gap: 28px;
            font-size: 14px; font-weight: 600; color: #444;
        }
        .mp-jumpnav__links a {
            color: inherit; text-decoration: none;
            padding: 6px 0; position: relative;
            border-bottom: 2px solid transparent;
            transition: color 180ms, border-color 180ms;
        }
        .mp-jumpnav__links a:hover { color: #111; border-bottom-color: #111; }
        .mp-jumpnav__reserve {
            display: none;
            align-items: center; gap: 14px;
        }
        .mp-jumpnav.is-cta .mp-jumpnav__reserve { display: inline-flex; }
        .mp-jumpnav__price { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
        .mp-jumpnav__amount {
            font-size: 15px; font-weight: 700; color: #1F2937;
            letter-spacing: -0.01em;
        }
        .mp-jumpnav__amount .mp-suffix {
            font-size: 12px; font-weight: 500; color: #6B6B6B; margin-left: 4px;
        }
        .mp-jumpnav__rating { font-size: 12px; color: #6B6B6B; }
        .mp-jumpnav__cta {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 10px 22px; border-radius: 999px;
            background: linear-gradient(135deg, #ADCB52 0%, #8AAE3A 100%); color: #FFFFFF;
            font-weight: 700; font-size: 13.5px;
            border: none; cursor: pointer; text-decoration: none;
            box-shadow: 0 8px 18px -10px rgba(138, 174, 58, 0.55);
        }
        .mp-jumpnav__cta:hover {
            background: linear-gradient(135deg, #8AAE3A 0%, #6E8E29 100%);
        }
        @media (max-width: 899px) { .mp-jumpnav { display: none !important; } }

        /* Trust chips row above the desktop booking card. Small,
           quiet, positioning the site as commission-free direct book. */
        .mp-trust-chips {
            display: flex; flex-wrap: wrap; gap: 6px;
            margin-bottom: 12px;
        }
        .mp-trust-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 10px;
            background: rgba(173, 203, 82, 0.14);
            color: #4A6620;
            border-radius: 999px;
            font-size: 11.5px; font-weight: 600;
            letter-spacing: 0.02em;
        }
        .mp-trust-chip svg { flex: 0 0 12px; }

        /* "Show all N amenities" button + modal. Button mimics
           Airbnb's ghost-style pill under the amenity grid; modal
           opens as a centered card with a scrollable list. */
        .mp-amenities-showall {
            display: inline-flex; align-items: center; gap: 8px;
            margin-top: 20px;
            padding: 11px 20px;
            background: #FFFFFF;
            color: #1F2937;
            border: 1px solid rgba(20, 23, 26, 0.28);
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 180ms, border-color 180ms;
        }
        .mp-amenities-showall:hover {
            background: #F5F5F5;
            border-color: rgba(20, 23, 26, 0.42);
        }
        .mp-amenities-modal-backdrop {
            position: fixed; inset: 0; z-index: 98;
            background: rgba(15, 15, 15, 0.62);
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
        }
        .mp-amenities-modal-backdrop[hidden] { display: none; }
        .mp-amenities-modal-card {
            background: #FFFFFF;
            border-radius: 14px;
            width: 100%; max-width: 640px;
            max-height: 88vh;
            display: flex; flex-direction: column;
            overflow: hidden;
            box-shadow: 0 30px 80px -20px rgba(0, 0, 0, 0.35);
        }
        .mp-amenities-modal-head {
            padding: 22px 26px;
            border-bottom: 1px solid #EBEBEB;
            display: flex; align-items: center; gap: 16px;
        }
        .mp-amenities-modal-head h3 {
            margin: 0; font-size: 18px; font-weight: 700;
            color: #1F2937; letter-spacing: -0.008em;
        }
        .mp-amenities-modal-close {
            width: 34px; height: 34px;
            border-radius: 999px;
            background: transparent;
            border: 1px solid rgba(20, 23, 26, 0.16);
            display: inline-flex; align-items: center; justify-content: center;
            color: #1F2937; cursor: pointer;
            transition: background 180ms;
        }
        .mp-amenities-modal-close:hover { background: #F5F5F5; }
        .mp-amenities-modal-body {
            padding: 20px 26px 26px;
            overflow-y: auto;
            display: grid; gap: 0;
        }
        .mp-amenities-modal-row {
            padding: 14px 0;
            border-bottom: 1px solid #F0F0F0;
            display: flex; align-items: center; gap: 14px;
            font-size: 14.5px; color: #1F2937;
        }
        .mp-amenities-modal-row:last-child { border-bottom: none; }
        .mp-amenities-modal-icon svg { width: 22px; height: 22px; color: #4A5A2E; }
        @media (max-width: 640px) {
            .mp-amenities-modal-card { max-height: 92vh; border-radius: 12px; }
            .mp-amenities-modal-head { padding: 18px 20px; }
            .mp-amenities-modal-body { padding: 16px 20px 22px; }
        }

        /* Meet-your-operator card — hotel-style host card with
           avatar/logo, name, stats row, and a plain-language "we're
           accountable, we respond quickly" meta list. Same-shaped as
           Airbnb's Meet-your-host but reads as an operator/manager
           since we're a PMS, not a peer-to-peer marketplace. */
        .mp-operator-card {
            border: 1px solid #EBEBEB;
            border-radius: 16px;
            padding: 24px 26px;
            background: #FFFFFF;
            display: grid;
            gap: 22px;
        }
        .mp-operator-identity {
            display: flex; align-items: center; gap: 18px;
        }
        .mp-operator-avatar {
            width: 68px; height: 68px;
            flex: 0 0 68px;
            border-radius: 999px;
            background: linear-gradient(135deg, #F4EFDF 0%, #E9DCB6 100%);
            display: inline-flex; align-items: center; justify-content: center;
            overflow: hidden;
            position: relative;
            border: 1px solid #EDEDED;
        }
        .mp-operator-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .mp-operator-avatar span {
            font-size: 26px; font-weight: 700; color: #4A5A2E;
        }
        .mp-operator-verified {
            position: absolute; right: -4px; bottom: -2px;
            width: 22px; height: 22px;
            color: #ADCB52;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mp-operator-name {
            font-size: 18px; font-weight: 700; color: #1F2937;
            letter-spacing: -0.008em; margin-bottom: 2px;
        }
        .mp-operator-tag {
            font-size: 12.5px; color: #6B6B6B; letter-spacing: 0.02em;
        }
        .mp-operator-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
            gap: 12px;
            padding: 18px 0;
            border-top: 1px solid #F0F0F0;
            border-bottom: 1px solid #F0F0F0;
        }
        .mp-operator-stat {
            display: flex; flex-direction: column; align-items: center; gap: 2px;
            text-align: center;
        }
        .mp-operator-stat-value {
            font-size: 20px; font-weight: 700; color: #1F2937;
            letter-spacing: -0.014em;
        }
        .mp-operator-stat-label {
            font-size: 11.5px; color: #6B6B6B; text-transform: lowercase;
            letter-spacing: 0.02em;
        }
        .mp-operator-meta {
            display: grid; gap: 10px;
            font-size: 13.5px; color: #4B4A45;
        }
        .mp-operator-meta-row {
            display: flex; align-items: center; gap: 10px;
        }
        .mp-operator-meta-row svg { flex: 0 0 14px; color: #4A5A2E; }

        /* Multi-branch band — sister property cards for a tenant
           that operates more than one property. Each card links to
           the sibling's own subdomain root (which now serves the
           same stays detail view). */
        .mp-branch-grid {
            display: grid; gap: 20px;
            grid-template-columns: 1fr;
        }
        @media (min-width: 640px) { .mp-branch-grid { grid-template-columns: 1fr 1fr; } }
        @media (min-width: 1000px) { .mp-branch-grid { grid-template-columns: 1fr 1fr 1fr; } }
        .mp-branch-card {
            display: flex; flex-direction: column;
            border: 1px solid #EBEBEB;
            border-radius: 14px;
            background: #FFFFFF;
            overflow: hidden;
            transition: transform 220ms cubic-bezier(0.16, 1, 0.3, 1), box-shadow 220ms;
        }
        .mp-branch-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 48px -22px rgba(0, 0, 0, 0.18);
            text-decoration: none;
        }
        .mp-branch-img {
            aspect-ratio: 16 / 10;
            background-size: cover;
            background-position: center;
            background-color: #F4EFDF;
            display: flex; align-items: center; justify-content: center;
        }
        .mp-branch-img-stub {
            font-family: system-ui;
            font-size: 32px; font-weight: 700; color: #B0A97E;
        }
        .mp-branch-body { padding: 14px 16px 18px; }
        .mp-branch-name { font-size: 14.5px; font-weight: 700; color: #1F2937; }
        .mp-branch-loc { font-size: 12.5px; color: #6B6B6B; margin-top: 2px; }

        /* Highlights ribbon — 3 bullet callouts under the fact strip
           to communicate what makes this place special before the
           guest hits the wall of amenities. */
        .mp-highlights {
            display: grid; gap: 14px;
            grid-template-columns: 1fr;
            margin-top: 20px;
            padding: 20px 22px;
            border: 1px solid #EBEBEB; border-radius: 14px;
            background: linear-gradient(180deg, #FAFAF7 0%, #FFFFFF 100%);
        }
        @media (min-width: 720px) {
            .mp-highlights { grid-template-columns: 1fr 1fr 1fr; }
        }
        .mp-highlight {
            display: flex; align-items: flex-start; gap: 12px;
        }
        .mp-highlight__icon {
            width: 36px; height: 36px; flex: 0 0 36px;
            border-radius: 10px;
            background: rgba(173, 203, 82, 0.16);
            color: #4A6620;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mp-highlight__body h4 {
            margin: 0 0 3px; font-size: 14px; font-weight: 700; color: #1F2937;
            letter-spacing: -0.005em;
        }
        .mp-highlight__body p {
            margin: 0; font-size: 12.5px; line-height: 1.5; color: #6B6B6B;
        }
    </style>
</head>
<body class="mp-page">

<div class="mp-page">

    @php
        // Tenant context = we're on the property's own subdomain (or a
        // verified custom domain). In that case the header is the
        // property's front door, not the marketplace's, so we drop the
        // "Back to search" affordance + swap in a self-service menu.
        $tenantCtx = \App\Models\Tenant::current();
        $tenantPhone = $tenantCtx?->phone;
        $tenantEmail = $tenantCtx?->email;

        // Sister branches: same-tenant properties that are live on the
        // marketplace. Rendered in the header menu so branch discovery
        // is one tap on every property page, not buried at the bottom.
        $navBranches = collect();
        if ($tenantCtx) {
            $navBranches = $tenantCtx->properties()
                ->where('id', '!=', $property->id)
                ->where('is_listed_publicly', true)
                ->whereNotNull('public_slug')
                ->orderBy('name')
                ->take(8)
                ->get();
        }
    @endphp
    <header class="mp-nav">
        <div class="mp-nav-inner">
            <a href="/" class="mp-brand">
                <span class="mp-brand-mark" aria-hidden>
                    <svg viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
                </span>
                <span>Keverna</span>
            </a>

            @if ($tenantCtx)
                <div class="mp-nav-menu" x-data="{ open: false }" @click.outside="open = false">
                    <a href="#stays-show-rooms" class="mp-nav-link">Rooms</a>
                    <a href="#stays-show-location-section" class="mp-nav-link">Location</a>
                    @if ($navBranches->isNotEmpty())
                        <a href="#stays-show-branches" class="mp-nav-link">Branches</a>
                    @endif
                    @if ($tenantPhone)
                        <a href="tel:{{ $tenantPhone }}" class="mp-nav-link">Contact</a>
                    @endif
                    <div class="mp-nav-menu-wrap">
                        <button type="button" class="mp-nav-avatar" @click="open = !open" :aria-expanded="open" aria-label="Open menu">
                            <span class="mp-nav-avatar__lines" aria-hidden>
                                <span></span><span></span><span></span>
                            </span>
                            <span class="mp-nav-avatar__circle" aria-hidden>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                            </span>
                        </button>
                        <div class="mp-nav-menu-panel" :class="{ 'is-open': open }" @click="open = false" x-cloak>
                            @if ($navBranches->isNotEmpty())
                                <div class="mp-nav-menu-panel__label">Other branches</div>
                                @foreach ($navBranches as $navBranch)
                                    @php
                                        $navBranchHost = $navBranch->tenant?->primaryHost();
                                        $navBranchImg = $navBranch->getFirstMediaUrl('hero', 'thumb')
                                            ?: $navBranch->getFirstMediaUrl('gallery', 'thumb');
                                        $navBranchLoc = collect([$navBranch->city, $navBranch->state])->filter()->unique()->implode(', ');
                                        $navBranchHref = $navBranchHost
                                            ? (request()->getScheme().'://'.$navBranchHost.(request()->getPort() && ! in_array(request()->getPort(), [80, 443], true) ? ':'.request()->getPort() : '').'/')
                                            : route('marketplace.stays.show', ['slug' => $navBranch->public_slug]);
                                    @endphp
                                    <a href="{{ $navBranchHref }}" data-testid="nav-branch-{{ $navBranch->public_slug }}">
                                        <span class="mp-nav-branch__thumb"
                                              @if ($navBranchImg) style="background-image: url('{{ $navBranchImg }}');" @endif>
                                            @if (! $navBranchImg){{ mb_substr($navBranch->name, 0, 1) }}@endif
                                        </span>
                                        <span>
                                            <span class="mp-nav-menu-item__label">{{ $navBranch->name }}</span>
                                            @if ($navBranchLoc)
                                                <span class="mp-nav-branch__loc">{{ $navBranchLoc }}</span>
                                            @endif
                                        </span>
                                    </a>
                                @endforeach
                                <div class="mp-nav-menu-panel__divider" role="separator"></div>
                            @endif

                            <a href="#stays-show-rooms">
                                <span class="mp-nav-menu-item__icon" aria-hidden>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V21H3Z"/><path d="M9 21v-6h6v6"/></svg>
                                </span>
                                <span>
                                    <span class="mp-nav-menu-item__label">Rooms</span>
                                    <span class="mp-nav-menu-item__sub">Browse categories + availability</span>
                                </span>
                            </a>
                            <a href="#stays-show-location-section">
                                <span class="mp-nav-menu-item__icon" aria-hidden>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6-7-11a7 7 0 1 1 14 0c0 5-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                </span>
                                <span>
                                    <span class="mp-nav-menu-item__label">Where you'll be</span>
                                    <span class="mp-nav-menu-item__sub">Map + nearby places</span>
                                </span>
                            </a>
                            <div class="mp-nav-menu-panel__divider" role="separator"></div>
                            @if ($tenantPhone)
                                <a href="tel:{{ $tenantPhone }}">
                                    <span class="mp-nav-menu-item__icon" aria-hidden>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.8.3 1.6.6 2.4a2 2 0 0 1-.5 2.1L8 9.7a16 16 0 0 0 6 6l1.4-1.3a2 2 0 0 1 2.1-.4c.8.3 1.6.5 2.4.6a2 2 0 0 1 1.7 2Z"/></svg>
                                    </span>
                                    <span>
                                        <span class="mp-nav-menu-item__label">Call the front desk</span>
                                        <span class="mp-nav-menu-item__sub">{{ $tenantPhone }}</span>
                                    </span>
                                </a>
                            @endif
                            @if ($tenantEmail)
                                <a href="mailto:{{ $tenantEmail }}?subject=Booking%20enquiry">
                                    <span class="mp-nav-menu-item__icon" aria-hidden>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                                    </span>
                                    <span>
                                        <span class="mp-nav-menu-item__label">Email us</span>
                                        <span class="mp-nav-menu-item__sub">{{ $tenantEmail }}</span>
                                    </span>
                                </a>
                            @endif
                            @if ($property->portal_qr_slug)
                                <a href="{{ route('tenant.guest.portal', ['qrSlug' => $property->portal_qr_slug]) }}">
                                    <span class="mp-nav-menu-item__icon" aria-hidden>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM19 14h2M14 19h3M19 19h2"/></svg>
                                    </span>
                                    <span>
                                        <span class="mp-nav-menu-item__label">Guest self-service</span>
                                        <span class="mp-nav-menu-item__sub">Extend, checkout, order, complaint, taxi</span>
                                    </span>
                                </a>
                            @endif
                            <a href="mailto:{{ $tenantEmail ?: 'hello@keverna.com' }}?subject=Track%20my%20booking&body=Booking%20reference%3A%20%0AName%3A%20%0APhone%3A%20">
                                <span class="mp-nav-menu-item__icon" aria-hidden>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3h7v7"/><path d="M10 21H3v-7"/><path d="M21 3 3 21"/></svg>
                                </span>
                                <span>
                                    <span class="mp-nav-menu-item__label">Track a booking</span>
                                    <span class="mp-nav-menu-item__sub">Use the link in your confirmation email</span>
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            @else
                <a href="{{ route('marketplace.stays.index') }}" class="mp-back">← Back to search</a>
            @endif
        </div>
    </header>

    {{-- Sticky top jump-nav appears once the guest scrolls past the
         hero (JS toggles `is-visible`) and gains a Reserve CTA once
         they've scrolled past the desktop booking card (`is-cta`). --}}
    <nav class="mp-jumpnav" data-testid="stays-show-jumpnav" aria-label="Property sections">
        <div class="mp-jumpnav__inner">
            <div class="mp-jumpnav__links">
                <a href="#stays-show-gallery">Photos</a>
                <a href="#stays-show-rooms">Rooms</a>
                <a href="#stays-show-amenities">Amenities</a>
                <a href="#stays-show-reviews-section">Reviews</a>
                <a href="#stays-show-location-section">Location</a>
            </div>
            <div class="mp-jumpnav__reserve">
                <div class="mp-jumpnav__price">
                    @if ($from_price > 0)
                        <span class="mp-jumpnav__amount">
                            &#8358;{{ number_format($from_price) }}
                            <span class="mp-suffix">from / night</span>
                        </span>
                        @if ($rating_avg !== null && $rating_count > 0)
                            <span class="mp-jumpnav__rating">
                                ★ {{ number_format($rating_avg, 2) }} · {{ $rating_count }} {{ $rating_count === 1 ? 'review' : 'reviews' }}
                            </span>
                        @endif
                    @endif
                </div>
                <a href="#stays-show-book-card" class="mp-jumpnav__cta">Reserve</a>
            </div>
        </div>
    </nav>

    <main class="mp-container" data-testid="stays-show">

        {{-- ═══ TITLE BAND ═══════════════════════════════════════ --}}
        <div class="mp-title-band">
            <div>
                <h1 data-testid="stays-show-title">{{ $property->name }}</h1>
                <div class="mp-title-meta">
                    @if ($rating_avg !== null && $rating_count > 0)
                        <span class="mp-rating" data-testid="stays-show-rating">
                            <svg viewBox="0 0 12 12" fill="currentColor"><path d="M6 1l1.5 3.2 3.5.5-2.5 2.4.6 3.4L6 8.9l-3.1 1.6.6-3.4L1 4.7l3.5-.5z"/></svg>
                            {{ number_format($rating_avg, 2) }}
                            <span style="color: #6A6A6A; font-weight: 400;">({{ $rating_count }} {{ $rating_count === 1 ? 'review' : 'reviews' }})</span>
                        </span>
                        <span class="mp-dot">·</span>
                    @endif
                    <span>{{ $locationLine }}</span>
                </div>
            </div>

            <div class="mp-actions" data-testid="stays-show-actions">
                <button type="button"
                        class="mp-action-btn icon-only"
                        data-mp-like
                        data-mp-slug="{{ $property->public_slug }}"
                        data-testid="stays-show-like"
                        aria-label="Save this stay"
                        title="Save">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-4.35-7-10a4 4 0 0 1 7-2.65A4 4 0 0 1 19 11c0 5.65-7 10-7 10z"/></svg>
                </button>

                <button type="button"
                        class="mp-action-btn icon-only"
                        data-mp-share
                        data-mp-share-title="{{ $property->name }} · Keverna Stays"
                        data-testid="stays-show-share"
                        aria-label="Share this stay"
                        title="Share">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 10.5l7-4M8.6 13.5l7 4"/></svg>
                </button>

                <button type="button"
                        class="mp-action-btn"
                        data-mp-report-open
                        data-testid="stays-show-report">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 21V5m0 0 h9l-2 3 2 3H5"/></svg>
                    Report
                </button>
            </div>
        </div>

        {{-- ═══ PHOTO GRID ══════════════════════════════════════
             5-photo Airbnb-style mosaic (1 big + 4 small) that
             degrades gracefully to a pair or single hero when the
             property has fewer gallery photos. Every visible tile
             opens the same lightbox seeded with the full gallery. --}}
        @php
            // Full gallery for the lightbox — hero first, then the
            // rest of the gallery in upload order.
            $fullGallery = $property->getMedia('gallery');
            $lightboxUrls = collect();
            if ($hero !== '') {
                $lightboxUrls->push($property->getFirstMediaUrl('hero', 'full')
                    ?: $property->getFirstMediaUrl('gallery', 'full'));
            }
            foreach ($fullGallery as $m) {
                $url = $m->getUrl('full');
                if ($url && $url !== $lightboxUrls->first()) {
                    $lightboxUrls->push($url);
                }
            }
            $lightboxUrls = $lightboxUrls->filter(fn ($u) => $u !== '')->values();
            $lightboxJson = json_encode($lightboxUrls->all(),
                JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?: '[]';
            $lightboxTitle = (string) $property->name;

            // Visible tiles (up to 5). Carry the Media instance through
            // so <x-blur-image> can pull the pre-generated placeholder
            // conversion off the same file for the blur-up reveal.
            $tiles = [];
            $heroMedia = $property->getFirstMedia('hero') ?: $property->getFirstMedia('gallery');
            if ($hero !== '') {
                $tiles[] = ['url' => $hero, 'alt' => $property->name, 'media' => $heroMedia, 'conversion' => 'full'];
            }
            $extraNeeded = max(0, 5 - count($tiles));
            foreach ($fullGallery->take($extraNeeded) as $i => $m) {
                $tiles[] = [
                    'url' => $m->getUrl('medium'),
                    'alt' => $property->name.' photo '.(count($tiles) + 1),
                    'media' => $m,
                    'conversion' => 'medium',
                ];
            }
            $tileCount = count($tiles);
            $totalPhotos = $lightboxUrls->count();
            $hiddenCount = max(0, $totalPhotos - $tileCount);
            $countClass = match (true) {
                $tileCount <= 1 => 'mp-gallery--count-1',
                $tileCount === 2 => 'mp-gallery--count-2',
                $tileCount === 3 => 'mp-gallery--count-3',
                $tileCount === 4 => 'mp-gallery--count-4',
                $totalPhotos > 5 => 'mp-gallery--count-many',
                default => 'mp-gallery--count-5',
            };
            $hasClickable = $lightboxUrls->isNotEmpty();
        @endphp
        <div class="mp-gallery" id="stays-show-gallery" data-testid="stays-show-gallery">
            <div class="mp-gallery-track {{ $countClass }}" data-mp-gallery-track>
            @forelse ($tiles as $i => $tile)
                <div class="mp-gallery-tile"
                     @if ($hasClickable)
                         data-mp-gallery='{{ $lightboxJson }}'
                         data-mp-gallery-title="{{ $lightboxTitle }}"
                         role="button" tabindex="0"
                     @endif
                     @if ($i === 0) data-testid="stays-show-hero-tile" @endif>
                    <x-blur-image
                        :media="$tile['media'] ?? null"
                        :src="$tile['url']"
                        :conversion="$tile['conversion'] ?? 'medium'"
                        :alt="$tile['alt']"
                        :loading="$i === 0 ? 'eager' : 'lazy'" />
                </div>
            @empty
                <div class="mp-gallery-tile">
                    <div class="mp-gallery-stub">{{ $city !== '' ? $city : 'Photo coming soon' }}</div>
                </div>
            @endforelse
            </div>
            @if ($hasClickable && $totalPhotos > 1)
                <button type="button" class="mp-gallery-showall"
                        data-mp-gallery='{{ $lightboxJson }}'
                        data-mp-gallery-title="{{ $lightboxTitle }}"
                        data-testid="stays-show-show-all-photos">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7"/>
                        <rect x="14" y="3" width="7" height="7"/>
                        <rect x="14" y="14" width="7" height="7"/>
                        <rect x="3" y="14" width="7" height="7"/>
                    </svg>
                    Show all {{ $totalPhotos }} photos
                </button>
            @endif
            @if ($tileCount > 1)
                <div class="mp-gallery-counter" data-mp-gallery-counter aria-hidden="true">
                    <span data-mp-gallery-index>1</span> / {{ $tileCount }}
                </div>
            @endif
        </div>

        {{-- ═══ HIGHLIGHTS RIBBON ═════════════════════════════════
             Three-up value props under the fact strip. Reads like
             a hotelier's "what makes us special" without needing
             the guest to scroll into the amenity list. --}}
        <div class="mp-highlights" data-testid="stays-show-highlights">
            <div class="mp-highlight">
                <span class="mp-highlight__icon" aria-hidden>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z"/>
                    </svg>
                </span>
                <div class="mp-highlight__body">
                    <h4>Book direct, no commission</h4>
                    <p>Same rate as the front desk. Every naira lands with the property, not a middleman.</p>
                </div>
            </div>
            <div class="mp-highlight">
                <span class="mp-highlight__icon" aria-hidden>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-12V4l-8-2-8 2v6c0 8 8 12 8 12z"/>
                    </svg>
                </span>
                <div class="mp-highlight__body">
                    <h4>Prices include all fees</h4>
                    <p>No surprises at check-out. What you see on the booking card is what you pay.</p>
                </div>
            </div>
            <div class="mp-highlight">
                <span class="mp-highlight__icon" aria-hidden>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2l0 20M2 12l20 0"/>
                        <circle cx="12" cy="12" r="9"/>
                    </svg>
                </span>
                <div class="mp-highlight__body">
                    <h4>Real-time availability</h4>
                    <p>The rooms shown are the rooms you can book right now. No calling to confirm.</p>
                </div>
            </div>
        </div>

        {{-- ═══ 2-COLUMN BODY ═══════════════════════════════════ --}}
        <div class="mp-body">

            <div>
                @php $videoEmbed = $property->videoEmbed(); @endphp
                @if (! empty($property->description))
                    <section class="mp-section" data-testid="stays-show-description">
                        <h2>About this place</h2>
                        {{-- Description is authored via the settings-page WYSIWYG
                             and sanitised through HtmlSanitizer on save (allow-list:
                             p/br/b/i/u/a/ul/ol/li, safe rel+target on anchors),
                             so rendering unescaped is safe. --}}
                        <div class="mp-desc mp-desc-html">{!! $property->description !!}</div>
                    </section>
                @endif

                @if ($amenities !== [])
                    @php
                        // Clamp to the first 10 visible amenities;
                        // remaining ones live inside the "Show all"
                        // modal below, mirroring Airbnb's pattern.
                        $amenityList = array_values(array_filter(
                            $amenities,
                            fn ($a) => is_string($a) && trim($a) !== '',
                        ));
                        $amenityCount = count($amenityList);
                        $visibleAmenities = array_slice($amenityList, 0, 10);
                        $hiddenAmenities = array_slice($amenityList, 10);
                    @endphp
                    <section class="mp-section" id="stays-show-amenities" data-testid="stays-show-amenities">
                        <h2>What this place offers</h2>
                        <div class="mp-amenities">
                            @foreach ($visibleAmenities as $amenity)
                                @php
                                    $iconBundle = \App\Support\Marketplace\AmenityIcon::iconFor($amenity);
                                @endphp
                                <div class="mp-amenity" data-testid="amenity-{{ \Illuminate\Support\Str::slug($amenity) }}">
                                    {!! $iconBundle['icon'] !!}
                                    <span>{{ $amenity }}</span>
                                </div>
                            @endforeach
                        </div>
                        @if ($amenityCount > 10)
                            <button type="button" class="mp-amenities-showall"
                                    data-mp-amenities-open
                                    data-testid="stays-show-amenities-showall">
                                Show all {{ $amenityCount }} amenities
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3l3 3-3 3"/></svg>
                            </button>
                        @endif
                    </section>
                @endif

                @if ($videoEmbed !== null)
                    @php
                        // Prefer the property's own hero shot as the poster
                        // so the video card feels like a natural extension
                        // of the gallery. Falls back to YouTube's own
                        // hqdefault when we have one, otherwise a mp-video
                        // treatment carries the black frame with a play cue.
                        $videoPoster = $hero ?: ($videoEmbed['poster'] ?? null);
                    @endphp
                    <section class="mp-section" data-testid="stays-show-video">
                        <h2>Video tour</h2>
                        <div class="mp-video-slot"
                             x-data="mpVideoPip()"
                             x-init="init($el)"
                             data-testid="stays-show-video-slot">
                        <div class="mp-video"
                             :class="{ 'mp-video--pip': pip }"
                             data-testid="stays-show-video-card">
                            <template x-if="! playing">
                                <div>
                                    @if ($videoPoster)
                                        <img class="mp-video__poster"
                                             src="{{ $videoPoster }}"
                                             alt="Watch a video tour of {{ $property->name }}"
                                             loading="lazy">
                                    @else
                                        <div class="mp-video__poster" style="background: #0b0b0b;"></div>
                                    @endif
                                    <div class="mp-video__scrim"></div>
                                    <button type="button" class="mp-video__play"
                                            @click="playing = true"
                                            aria-label="Play video tour">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M8 5.5v13a1 1 0 0 0 1.55.83l10-6.5a1 1 0 0 0 0-1.66l-10-6.5A1 1 0 0 0 8 5.5Z"/>
                                        </svg>
                                    </button>
                                    <span class="mp-video__meta">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="14" height="12" rx="2"/><path d="m21 8-4 4 4 4V8Z"/></svg>
                                        Video tour
                                    </span>
                                </div>
                            </template>
                            <template x-if="playing">
                                @if ($videoEmbed['kind'] === 'file')
                                    <video class="mp-video__frame"
                                           src="{{ $videoEmbed['src'] }}"
                                           controls autoplay playsinline
                                           preload="metadata"
                                           style="width: 100%; height: 100%; background: #000; display: block;"></video>
                                @else
                                    <iframe class="mp-video__frame"
                                            src="{{ $videoEmbed['embed_url'] }}"
                                            title="Video tour of {{ $property->name }}"
                                            allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                                            allowfullscreen></iframe>
                                @endif
                            </template>
                            <button type="button"
                                    class="mp-video__pip-close"
                                    x-show="pip"
                                    @click="dismiss()"
                                    aria-label="Close mini player">
                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 3l8 8M11 3l-8 8"/></svg>
                            </button>
                        </div>
                        </div> {{-- /mp-video-slot --}}
                    </section>
                    <script>
                        document.addEventListener('alpine:init', function () {
                            Alpine.data('mpVideoPip', function () {
                                return {
                                    playing: false,
                                    pip: false,
                                    dismissed: false,
                                    // TRUE only when the slot's BOTTOM edge is above the
                                    // viewport top — i.e. the user has scrolled PAST the
                                    // video. Was previously `cardOutOfView` which was
                                    // also true when the user was ABOVE the slot (page
                                    // top, video below the fold) → PiP appeared while
                                    // scrolling back to the top even though the slot
                                    // was just waiting to come into view. The rename
                                    // makes the intent read straight from the field.
                                    cardScrolledPast: false,
                                    footerInView: false,
                                    _slot: null,
                                    init: function (root) {
                                        var self = this;
                                        // Guard on desktop viewport; skip observation on
                                        // small screens where a mini-player would fight
                                        // the reader for space.
                                        if (window.matchMedia('(max-width: 899px)').matches) return;

                                        this._slot = root;

                                        // Anything that could flip PiP feeds into a
                                        // single derivation. $watch handles the
                                        // reactivity wiring so observer callbacks stay
                                        // dumb ("set the flag, walk away").
                                        var recompute = function () {
                                            self.pip = self.playing
                                                && ! self.dismissed
                                                && self.cardScrolledPast
                                                && ! self.footerInView;
                                        };
                                        this.$watch('playing', recompute);
                                        this.$watch('cardScrolledPast', recompute);
                                        this.$watch('footerInView', recompute);
                                        this.$watch('dismissed', recompute);

                                        // Direct rect-based detection: the slot has been
                                        // "scrolled past" when its bottom edge is above
                                        // the viewport top (rect.bottom <= 0). This
                                        // treats slot-below-viewport (user above slot)
                                        // as NOT scrolled past — which is the whole
                                        // point of the rewrite.
                                        var evalPast = function () {
                                            var rect = self._slot.getBoundingClientRect();
                                            var past = rect.bottom <= 0;
                                            if (past !== self.cardScrolledPast) {
                                                self.cardScrolledPast = past;
                                            }
                                        };
                                        window.addEventListener('scroll', evalPast, { passive: true });
                                        window.addEventListener('resize', evalPast, { passive: true });
                                        evalPast();

                                        // Footer intersection: exit PiP the moment the
                                        // footer scrolls into view, then re-enter on the
                                        // way back up.
                                        try {
                                            var footer = document.querySelector('footer, [data-mp-footer], .mp-footer');
                                            if (footer) {
                                                var footerObs = new IntersectionObserver(function (entries) {
                                                    self.footerInView = entries[0].isIntersecting;
                                                }, { threshold: 0 });
                                                footerObs.observe(footer);
                                            }
                                        } catch (e) {}
                                    },
                                    dismiss: function () {
                                        // Manual close: park the mini-player until the
                                        // slot is back on screen, at which point we let
                                        // the flow resume. Uses the same rect-based
                                        // check as the main gate so dismissal releases
                                        // exactly when the guest would expect the
                                        // in-flow player to be visible again.
                                        this.dismissed = true;
                                        var self = this;
                                        var reset = function () {
                                            var rect = self._slot.getBoundingClientRect();
                                            if (rect.bottom > 0 && rect.top < window.innerHeight) {
                                                self.dismissed = false;
                                                window.removeEventListener('scroll', reset);
                                            }
                                        };
                                        window.addEventListener('scroll', reset, { passive: true });
                                    },
                                };
                            });
                        });
                    </script>
                @endif

                {{-- Rooms section — visually the tenant's main "sell"
                     because a hotel booking a room type is Keverna's
                     differentiator over Airbnb (single-unit). Given
                     that weight, we set it apart from the amenities
                     grid above with generous top padding and a
                     hairline divider so it reads as a new chapter. --}}
                <section id="stays-show-rooms" data-testid="stays-show-rooms" class="mp-section mp-rooms-section">
                    <livewire:marketplace.stays-availability-browser
                        :slug="$property->public_slug"
                        :from="$from"
                        :to="$to"
                        :adults="$adults ?: 1"
                        :children="$children" />
                </section>


                @php
                    // Nearby-place enrichment must run BEFORE the map
                    // renders below so the `data-nearby` payload is
                    // ready. The nearby-cards section further down
                    // consumes the same `$nearbyPlaces` variable.
                    $nearbyPlaces = is_array($property->nearby_places)
                        ? array_values(array_filter($property->nearby_places, fn ($p) => is_array($p) && ! empty($p['label'])))
                        : [];
                    if ($nearbyPlaces !== [] && $property->hasCoordinates()) {
                        $geocoder = app(\App\Services\Marketplace\NearbyPlaceGeocoder::class);
                        $geoContext = trim(collect([
                            $property->city, $property->state, $property->country,
                        ])->filter()->implode(', '));
                        $nearbyPlaces = array_map(function (array $p) use ($geocoder, $geoContext) {
                            if (empty($p['lat']) || empty($p['lng'])) {
                                $coords = $geocoder->coordinates((string) $p['label'], $geoContext);
                                if ($coords !== null) {
                                    $p['lat'] = $coords['lat'];
                                    $p['lng'] = $coords['lng'];
                                }
                            }
                            return $p;
                        }, $nearbyPlaces);
                    }
                    $mappablePlaces = [];
                    foreach ($nearbyPlaces as $i => $p) {
                        if (! empty($p['lat']) && ! empty($p['lng'])) {
                            $mappablePlaces[] = [
                                'n' => $i + 1,
                                'label' => (string) $p['label'],
                                'lat' => (float) $p['lat'],
                                'lng' => (float) $p['lng'],
                            ];
                        }
                    }
                    $mappableJson = json_encode(
                        $mappablePlaces,
                        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP,
                    ) ?: '[]';
                @endphp

                @if ($property->hasCoordinates())
                    <section class="mp-section" id="stays-show-location-section" data-testid="stays-show-location">
                        <h2>Where you'll be</h2>
                        <p style="color: #6A6A6A; margin: 0 0 16px; font-size: 13.5px;">
                            {{ $locationLine }}
                        </p>
                        <div class="mp-map-wrap" data-testid="stays-show-map-wrap">
                            <div id="stays-show-map"
                                 data-testid="stays-show-map-canvas"
                                 data-lat="{{ (float) $property->latitude }}"
                                 data-lng="{{ (float) $property->longitude }}"
                                 data-name="{{ e($property->name) }}"
                                 data-locality="{{ e($locationLine) }}"
                                 data-nearby='{{ $mappableJson ?? '[]' }}'></div>
                            {{-- "Click to interact" overlay — prevents Leaflet
                                 from hijacking touch pan on mobile until the
                                 guest deliberately taps in. Removed by JS
                                 once the map has focus. --}}
                            <button type="button" class="mp-map-shield"
                                    data-mp-map-shield
                                    aria-label="Enable map interaction">
                                <span>Tap map to interact</span>
                            </button>
                        </div>
                        <p style="font-size: 11.5px; color: #8A8A8A; margin: 10px 0 0;">
                            Exact address shared with you after your booking is confirmed.
                        </p>
                        <script>
                            (function () {
                                var el = document.getElementById('stays-show-map');
                                var shield = document.querySelector('[data-mp-map-shield]');
                                if (! el || typeof L === 'undefined') return;
                                var lat = parseFloat(el.dataset.lat);
                                var lng = parseFloat(el.dataset.lng);
                                if (! isFinite(lat) || ! isFinite(lng)) return;

                                // Interaction is off by default so the guest
                                // can scroll the page without the map grabbing
                                // touches. The shield overlay is what enables it.
                                var map = L.map(el, {
                                    zoomControl: true,
                                    scrollWheelZoom: false,
                                    dragging: false,
                                    tap: false,
                                    doubleClickZoom: false,
                                    boxZoom: false,
                                    keyboard: false,
                                    attributionControl: true,
                                }).setView([lat, lng], 15);

                                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    maxZoom: 19,
                                    attribution: '&copy; <a href="https://openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
                                }).addTo(map);

                                // Approximate-area halo — matches the "exact
                                // address after booking" copy below.
                                L.circle([lat, lng], {
                                    radius: 380,
                                    color: 'rgba(138, 174, 58, 0.85)',
                                    weight: 2,
                                    fillColor: '#ADCB52',
                                    fillOpacity: 0.16,
                                }).addTo(map);

                                // Property marker — SVG-in-divIcon so we don't
                                // load an image resource. Opens a small popup
                                // with the property name + locality on click.
                                var pinHtml =
                                    '<div class="mp-map-pin" role="img" aria-label="' + (el.dataset.name || 'Property') + '">' +
                                    '<svg width="24" height="30" viewBox="0 0 24 30" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                                    '<path d="M12 0C5.4 0 0 5.4 0 12c0 8.4 12 18 12 18s12-9.6 12-18C24 5.4 18.6 0 12 0z" fill="#8AAE3A"/>' +
                                    '<circle cx="12" cy="12" r="4.5" fill="#FFFFFF"/>' +
                                    '</svg>' +
                                    '</div>';
                                var pinIcon = L.divIcon({
                                    html: pinHtml,
                                    className: 'mp-map-pin-icon',
                                    iconSize: [24, 30],
                                    iconAnchor: [12, 30],
                                    popupAnchor: [0, -28],
                                });
                                var marker = L.marker([lat, lng], { icon: pinIcon, keyboard: false }).addTo(map);
                                marker.bindPopup(
                                    '<strong>' + (el.dataset.name || '') + '</strong>' +
                                    (el.dataset.locality ? '<br><span style="color:#6B6B6B; font-size: 12px;">' + el.dataset.locality + '</span>' : ''),
                                    { maxWidth: 220, closeButton: false, offset: [0, 0] }
                                );
                                marker.openPopup();

                                // Nearby POI pins — one numbered circle per
                                // mappable place. The card grid below uses the
                                // same 1-indexed number so guests can pair
                                // "Airport (1)" on the map with card 1 below.
                                try {
                                    var nearby = JSON.parse(el.dataset.nearby || '[]');
                                    if (Array.isArray(nearby) && nearby.length > 0) {
                                        var bounds = L.latLngBounds([[lat, lng]]);
                                        nearby.forEach(function (n) {
                                            if (! isFinite(n.lat) || ! isFinite(n.lng)) return;
                                            bounds.extend([n.lat, n.lng]);
                                            var numHtml =
                                                '<div class="mp-map-num" role="img" aria-label="' + n.n + '. ' + (n.label || '') + '">' +
                                                n.n +
                                                '</div>';
                                            var numIcon = L.divIcon({
                                                html: numHtml,
                                                className: 'mp-map-num-icon',
                                                iconSize: [28, 28],
                                                iconAnchor: [14, 14],
                                                popupAnchor: [0, -14],
                                            });
                                            L.marker([n.lat, n.lng], { icon: numIcon, keyboard: false })
                                                .addTo(map)
                                                .bindPopup(
                                                    '<strong>' + (n.label || '') + '</strong>',
                                                    { maxWidth: 220, closeButton: false, offset: [0, 0] }
                                                );
                                        });
                                        // Fit the viewport to the property +
                                        // every POI, padded so nothing sits
                                        // right on the edge. Only re-fit when
                                        // we have >=1 nearby pin; otherwise
                                        // keep the original zoom on the pin.
                                        map.fitBounds(bounds.pad(0.18), { animate: false });
                                    }
                                } catch (e) {}

                                // Enable interaction when the shield is
                                // tapped/clicked. Once enabled, the shield
                                // is removed for the rest of the session.
                                if (shield) {
                                    var enable = function () {
                                        map.dragging.enable();
                                        map.tap && map.tap.enable();
                                        map.scrollWheelZoom.enable();
                                        map.doubleClickZoom.enable();
                                        map.boxZoom.enable();
                                        map.keyboard.enable();
                                        shield.remove();
                                    };
                                    shield.addEventListener('click', enable, { once: true });
                                    shield.addEventListener('touchend', enable, { once: true });
                                }
                            })();
                        </script>
                    </section>
                @endif

                @if ($nearbyPlaces !== [])
                    <section class="mp-section" data-testid="stays-show-nearby">
                        <h2>Nearby places</h2>
                        <p style="color: #6B6B6B; font-size: 13.5px; margin: 0 0 18px;">
                            Notable spots within an easy trip of the property. Distances are approximate.
                        </p>
                        <div class="mp-nearby">
                            @foreach ($nearbyPlaces as $i => $place)
                                @php
                                    $mode = in_array($place['mode'] ?? 'drive', ['walk', 'drive', 'transit'], true)
                                        ? (string) $place['mode']
                                        : 'drive';
                                    $modeIcon = match ($mode) {
                                        'walk'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="13" cy="4" r="2"/><path d="M8 20l3-6-2-4-4 2M14 20l-1-6-3-2v-3l3-1 3 2M18 12l2 3"/></svg>',
                                        'transit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="14" rx="2"/><path d="M4 12h16M8 18v2M16 18v2"/><circle cx="8" cy="15" r=".5"/><circle cx="16" cy="15" r=".5"/></svg>',
                                        default   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16l1-4 2-3h10l2 3 1 4M5 16v3h2v-3M17 16v3h2v-3"/><circle cx="8" cy="14" r="1"/><circle cx="16" cy="14" r="1"/></svg>',
                                    };
                                    $isMapped = ! empty($place['lat']) && ! empty($place['lng']);
                                @endphp
                                <div class="mp-nearby-row"
                                     data-testid="nearby-{{ \Illuminate\Support\Str::slug($place['label']) }}">
                                    @if ($isMapped)
                                        <span class="mp-nearby-num" aria-hidden="true">{{ $i + 1 }}</span>
                                    @endif
                                    <span class="mp-nearby-icon" aria-hidden="true">
                                        {!! $modeIcon !!}
                                    </span>
                                    <span class="mp-nearby-body">
                                        <span class="mp-nearby-label">{{ $place['label'] }}</span>
                                        <span class="mp-nearby-meta">
                                            @if (! empty($place['distance']))
                                                {{ $place['distance'] }}
                                                <span style="color: #C6C6C6;">·</span>
                                            @endif
                                            <span class="mp-nearby-mode">{{ $mode }}</span>
                                        </span>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- Property policies. Always renders - check-in +
                     check-out get sensible defaults when the operator
                     hasn't set them (guests still need to know a
                     ballpark). Other keys are rendered only when the
                     operator has typed something. --}}
                <section class="mp-section" data-testid="stays-show-rules">
                    <h2>Property policies</h2>
                    <p class="mp-section-lede">The house rules and booking terms every guest agrees to at check-in.</p>
                    @php
                        // Time defaults so the arrival / departure block is
                        // never blank. Operators can still override in
                        // Property Settings, but a guest browsing at 11pm
                        // should never see "Check-in: —".
                        $ciFrom = $houseRules['check_in_from'] ?: '14:00';
                        $ciTo   = $houseRules['check_in_to'] ?? '';
                        $coFrom = $houseRules['check_out_from'] ?? '';
                        $coTo   = $houseRules['check_out_to'] ?: '12:00';

                        // Operators enter times as HH:MM (24h) in Property
                        // Settings, but guests read this policy card on
                        // the marketplace — 12h with AM/PM matches the
                        // rest of the marketplace booking UI and the
                        // localised booking confirmation emails.
                        $fmt12 = function (string $time): string {
                            $time = trim($time);
                            if ($time === '' || ! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
                                return $time;
                            }
                            try {
                                return \Illuminate\Support\Carbon::createFromTime((int) $m[1], (int) $m[2])
                                    ->format($m[2] === '00' ? 'g A' : 'g:i A');
                            } catch (\Throwable) {
                                return $time;
                            }
                        };

                        $ciValue = $ciTo !== ''
                            ? 'From '.$fmt12($ciFrom).' to '.$fmt12($ciTo)
                            : 'From '.$fmt12($ciFrom);
                        $coValue = $coFrom !== ''
                            ? $fmt12($coFrom).' to '.$fmt12($coTo)
                            : 'By '.$fmt12($coTo);

                        // Cancellation is featured on its own card because
                        // it's the single most-asked-about policy on any
                        // OTA-style detail page. Falls back to a neutral
                        // Keverna default when the operator hasn't set one.
                        $cancellation = ! empty($houseRules['cancellation_policy'])
                            ? $houseRules['cancellation_policy']
                            : 'Free cancellation up to 24 hours before check-in. After that, the first night is non-refundable.';

                        $houseRuleRows = [
                            'children' => [
                                'label' => 'Children',
                                'value' => $houseRules['children_policy'] ?? '',
                            ],
                            'pets' => [
                                'label' => 'Pets',
                                'value' => $houseRules['pets_policy'] ?? '',
                            ],
                            'smoking' => [
                                'label' => 'Smoking',
                                'value' => $houseRules['smoking_policy'] ?? '',
                            ],
                            'quiet_hours' => [
                                'label' => 'Quiet hours',
                                'value' => $houseRules['quiet_hours'] ?? '',
                            ],
                        ];
                    @endphp
                    <div class="mp-policies">
                        <div class="mp-policy-card" data-testid="policy-card-house-rules">
                            <div class="mp-policy-card__head">
                                <span class="mp-policy-card__head-icon" aria-hidden="true">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V21a1 1 0 0 1-1 1h-5v-7h-6v7H4a1 1 0 0 1-1-1V9.5Z"/></svg>
                                </span>
                                <h3>House rules</h3>
                            </div>
                            <div class="mp-policy-row" data-testid="policy-checkin">
                                <span class="mp-policy-row__icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h13"/><path d="m12 6 6 6-6 6"/><path d="M21 4v16"/></svg>
                                </span>
                                <div class="mp-policy-row__body">
                                    <div class="mp-policy-row__label">Check-in</div>
                                    <div class="mp-policy-row__value">{{ $ciValue }}</div>
                                </div>
                            </div>
                            <div class="mp-policy-row" data-testid="policy-checkout">
                                <span class="mp-policy-row__icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12H8"/><path d="m12 6-6 6 6 6"/><path d="M3 4v16"/></svg>
                                </span>
                                <div class="mp-policy-row__body">
                                    <div class="mp-policy-row__label">Check-out</div>
                                    <div class="mp-policy-row__value">{{ $coValue }}</div>
                                </div>
                            </div>
                            @foreach ($houseRuleRows as $key => $row)
                                @if (! empty($row['value']))
                                    <div class="mp-policy-row" data-testid="policy-{{ $key }}_policy">
                                        <span class="mp-policy-row__icon" aria-hidden="true">
                                            @switch($key)
                                                @case('children')
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="6" r="3"/><path d="M8 22v-6l-2-3 3-3h6l3 3-2 3v6"/></svg>
                                                    @break
                                                @case('pets')
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="4.5" cy="9.5" r="1.75"/><circle cx="9" cy="5" r="1.75"/><circle cx="15" cy="5" r="1.75"/><circle cx="19.5" cy="9.5" r="1.75"/><path d="M12 10c-2.6 0-5 3.4-5 6a3 3 0 0 0 3 3c.9 0 1.5-.4 2-.9.5.5 1.1.9 2 .9a3 3 0 0 0 3-3c0-2.6-2.4-6-5-6Z"/></svg>
                                                    @break
                                                @case('smoking')
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18h14"/><path d="M18 18h3"/><path d="M15 14a3 3 0 0 0 3-3 4 4 0 0 0-4-4"/><path d="M13 10a3 3 0 0 0 3-3"/></svg>
                                                    @break
                                                @case('quiet_hours')
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.5A9 9 0 1 1 11.5 3a7 7 0 0 0 9.5 9.5Z"/></svg>
                                                    @break
                                            @endswitch
                                        </span>
                                        <div class="mp-policy-row__body">
                                            <div class="mp-policy-row__label">{{ $row['label'] }}</div>
                                            <div class="mp-policy-row__value">{{ $row['value'] }}</div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        <div class="mp-policy-card" data-testid="policy-card-terms">
                            <div class="mp-policy-card__head">
                                <span class="mp-policy-card__head-icon" aria-hidden="true">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>
                                </span>
                                <h3>Booking terms</h3>
                            </div>
                            <div class="mp-policy-row" data-testid="policy-cancellation_policy">
                                <span class="mp-policy-row__icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>
                                </span>
                                <div class="mp-policy-row__body">
                                    <div class="mp-policy-row__label">Cancellation</div>
                                    <div class="mp-policy-row__value">{{ $cancellation }}</div>
                                </div>
                            </div>
                            <div class="mp-policy-row">
                                <span class="mp-policy-row__icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/></svg>
                                </span>
                                <div class="mp-policy-row__body">
                                    <div class="mp-policy-row__label">Payment</div>
                                    <div class="mp-policy-row__value">Pay online with card or bank transfer, or settle at the front desk on arrival.</div>
                                </div>
                            </div>
                            <div class="mp-policy-row">
                                <span class="mp-policy-row__icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg>
                                </span>
                                <div class="mp-policy-row__body">
                                    <div class="mp-policy-row__label">Booked direct</div>
                                    <div class="mp-policy-row__value">No commissions, no service fees. The rate you see is the rate you pay.</div>
                                </div>
                            </div>
                            <div class="mp-policy-chip-row">
                                <span class="mp-policy-chip">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    ID required at check-in
                                </span>
                                <span class="mp-policy-chip">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    24/7 front desk
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- ═══ MEET-YOUR-OPERATOR CARD ═════════════════════
                     Hotel-style host card: tenant name as the
                     "operator", a hosting-duration stat pulled from
                     the tenant's created_at, and the property review
                     count so guests get a sense of track record.
                     Uses the property logo (Property::media('logo')
                     if uploaded, otherwise a generated initial). --}}
                @php
                    /** @var \App\Models\Tenant|null $operatorTenant */
                    $operatorTenant = $property->tenant ?? null;
                    $operatorName = $operatorTenant?->name ?? $property->name;
                    $operatorInitial = mb_substr(trim($operatorName ?? 'K'), 0, 1);
                    $operatorLogoUrl = $property->getFirstMediaUrl('logo') ?: null;
                    $hostingSince = $operatorTenant?->created_at
                        ? $operatorTenant->created_at->format('Y')
                        : null;
                    $hostingYears = $operatorTenant?->created_at
                        ? max(1, (int) \Illuminate\Support\Carbon::now()->diffInYears($operatorTenant->created_at))
                        : null;
                @endphp
                <section class="mp-section mp-operator" data-testid="stays-show-operator">
                    <h2 style="margin-bottom: 20px;">Meet your operator</h2>
                    <div class="mp-operator-card">
                        <div class="mp-operator-identity">
                            <div class="mp-operator-avatar" aria-hidden="true">
                                @if ($operatorLogoUrl)
                                    <img src="{{ $operatorLogoUrl }}" alt="{{ $operatorName }} logo">
                                @else
                                    <span>{{ $operatorInitial }}</span>
                                @endif
                                <span class="mp-operator-verified" title="Verified operator on Keverna">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.5 3.5L20 4l-1.5 4.5L22 12l-3.5 3.5L20 20l-4.5-1.5L12 22l-3.5-3.5L4 20l1.5-4.5L2 12l3.5-3.5L4 4l4.5 1.5z"/><path d="M8 12l3 3 5-6" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                            </div>
                            <div class="mp-operator-bio">
                                <div class="mp-operator-name">{{ $operatorName }}</div>
                                <div class="mp-operator-tag">Property manager · Keverna verified</div>
                            </div>
                        </div>
                        <div class="mp-operator-stats">
                            @if ($rating_count > 0)
                                <div class="mp-operator-stat">
                                    <span class="mp-operator-stat-value">{{ $rating_count }}</span>
                                    <span class="mp-operator-stat-label">{{ $rating_count === 1 ? 'review' : 'reviews' }}</span>
                                </div>
                            @endif
                            @if ($rating_avg !== null)
                                <div class="mp-operator-stat">
                                    <span class="mp-operator-stat-value">★ {{ number_format($rating_avg, 2) }}</span>
                                    <span class="mp-operator-stat-label">rating</span>
                                </div>
                            @endif
                            @if ($hostingYears)
                                <div class="mp-operator-stat">
                                    <span class="mp-operator-stat-value">{{ $hostingYears }}</span>
                                    <span class="mp-operator-stat-label">{{ $hostingYears === 1 ? 'year' : 'years' }} hosting</span>
                                </div>
                            @endif
                        </div>
                        <div class="mp-operator-meta">
                            <div class="mp-operator-meta-row">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12A10 10 0 1 1 12 2v10z"/></svg>
                                <span>Responds within an hour</span>
                            </div>
                            @if ($hostingSince)
                                <div class="mp-operator-meta-row">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                    <span>Hosting on Keverna since {{ $hostingSince }}</span>
                                </div>
                            @endif
                            <div class="mp-operator-meta-row">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6h20v12H2z M2 6l10 8 10-8"/></svg>
                                <span>Direct booking · no OTA commission</span>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- ═══ MULTI-BRANCH BAND ═══════════════════════════
                     Surface other properties the same operator runs
                     so a returning guest can jump to the sister
                     property in a different city. Skipped when this
                     is the only listed property under the tenant. --}}
                @php
                    $siblingBranches = collect();
                    if ($operatorTenant) {
                        $siblingBranches = $operatorTenant->properties()
                            ->where('id', '!=', $property->id)
                            ->where('is_listed_publicly', true)
                            ->whereNotNull('public_slug')
                            ->take(6)
                            ->get();
                    }
                @endphp
                @if ($siblingBranches->isNotEmpty())
                    <section id="stays-show-branches" class="mp-section mp-branches" data-testid="stays-show-branches">
                        <h2 style="margin-bottom: 6px;">Also stay with {{ $operatorName }}</h2>
                        <p style="margin: 0 0 22px; color: #6B6B6B; font-size: 14px;">
                            Same operator, other cities. Book direct at any of these branches.
                        </p>
                        <div class="mp-branch-grid">
                            @foreach ($siblingBranches as $branch)
                                @php
                                    $branchHost = $branch->tenant?->primaryHost();
                                    $branchLoc = collect([$branch->city, $branch->state])->filter()->implode(', ');
                                    $branchImg = $branch->getFirstMediaUrl('hero', 'medium')
                                        ?: $branch->getFirstMediaUrl('gallery', 'medium');
                                @endphp
                                <a href="{{ $branchHost ? 'https://'.$branchHost.'/' : route('marketplace.stays.show', $branch->public_slug) }}"
                                   class="mp-branch-card"
                                   data-testid="branch-{{ $branch->public_slug }}">
                                    <div class="mp-branch-img"
                                         @if ($branchImg) style="background-image: url('{{ $branchImg }}');" @endif>
                                        @if (! $branchImg)
                                            <span class="mp-branch-img-stub">{{ mb_substr($branch->name, 0, 1) }}</span>
                                        @endif
                                    </div>
                                    <div class="mp-branch-body">
                                        <div class="mp-branch-name">{{ $branch->name }}</div>
                                        @if ($branchLoc)
                                            <div class="mp-branch-loc">{{ $branchLoc }}</div>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($reviews->isNotEmpty())
                    @php
                        $visibleReviews = $reviews->take(6);

                        // Snapshot every displayed review as JSON so the
                        // click-to-open detail modal below can populate
                        // itself without a round-trip. Structured strings
                        // only, so `@json()` HTML-escapes safely inline.
                        $reviewsPayload = $reviews->mapWithKeys(function ($review) use ($property) {
                            $chColors = $review->channel?->badgeColors() ?? ['bg' => '#E5E7EB', 'ink' => '#374151'];
                            return [(int) $review->id => [
                                'name'            => (string) $review->guest_name,
                                'initial'         => (string) \Illuminate\Support\Str::of($review->guest_name ?? '?')->substr(0, 1)->upper(),
                                'country'         => (string) ($review->guest_country ?? ''),
                                'flag'            => \App\Services\Locale\Countries::flagFor($review->guest_country),
                                'verified'        => $review->isVerifiedStay(),
                                'rating'          => (int) $review->rating,
                                'channel_label'   => ($review->channel ?? \App\Enums\ReviewChannel::Direct)->label(),
                                'channel_bg'      => (string) $chColors['bg'],
                                'channel_ink'     => (string) $chColors['ink'],
                                'date'            => $review->published_at?->format('F j, Y'),
                                'date_iso'        => $review->published_at?->toIso8601String(),
                                'body'            => (string) $review->body,
                                'photo_url'       => $review->photoUrl(),
                                'response_body'   => $review->hasResponse() ? (string) $review->response_body : null,
                                'response_by'     => 'Response from '.($property->name ?? 'the property'),
                                'response_at'     => $review->responded_at?->diffForHumans(),
                                'response_at_iso' => $review->responded_at?->toIso8601String(),
                            ]];
                        })->all();
                    @endphp
                    <section class="mp-section" id="stays-show-reviews-section" data-testid="stays-show-reviews">
                        <div class="mp-reviews-head">
                            <h2>
                                @if ($rating_avg !== null)
                                    <span class="mp-reviews-star">★</span>
                                    {{ number_format($rating_avg, 2) }}
                                    <span class="mp-reviews-count">· {{ $rating_count }} {{ $rating_count === 1 ? 'review' : 'reviews' }}</span>
                                @else
                                    {{ $rating_count }} {{ $rating_count === 1 ? 'review' : 'reviews' }}
                                @endif
                            </h2>
                        </div>
                        <div class="mp-review-grid">
                            @foreach ($visibleReviews as $review)
                                @php $chColors = $review->channel?->badgeColors() ?? ['bg' => '#E5E7EB', 'ink' => '#374151']; @endphp
                                <div class="mp-review"
                                     role="button" tabindex="0"
                                     data-mp-review-open="{{ $review->id }}"
                                     data-testid="review-{{ $review->id }}">
                                    <div class="mp-review-head" style="display: flex; align-items: flex-start; gap: 12px;">
                                        @if ($review->photo_path)
                                            <img src="{{ $review->photoUrl() }}"
                                                 alt="{{ $review->guest_name }}"
                                                 loading="lazy"
                                                 data-testid="review-photo-{{ $review->id }}"
                                                 style="width: 44px; height: 44px; border-radius: 999px; object-fit: cover; flex-shrink: 0;">
                                        @else
                                            <span aria-hidden="true"
                                                  style="width: 44px; height: 44px; border-radius: 999px; background: #F1EBE0; color: #6B6B6B; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 15px; flex-shrink: 0;">
                                                {{ \Illuminate\Support\Str::of($review->guest_name)->substr(0, 1)->upper() }}
                                            </span>
                                        @endif
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                                <span class="mp-review-name">
                                                    {{ $review->guest_name }}
                                                    @if (! empty($review->guest_country))
                                                        @php $reviewFlag = \App\Services\Locale\Countries::flagFor($review->guest_country); @endphp
                                                        <span style="font-size: 12px; color: #6B6B6B; font-weight: 400; margin-left: 4px;">
                                                            · @if ($reviewFlag)<span style="font-size: 14px; line-height: 1;">{{ $reviewFlag }}</span> @endif{{ $review->guest_country }}
                                                        </span>
                                                    @endif
                                                    @if ($review->isVerifiedStay())
                                                        <span class="mp-review-badge">
                                                            <svg width="9" height="9" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 6l3 3 5-6"/></svg>
                                                            Verified
                                                        </span>
                                                    @endif
                                                </span>
                                                <span class="mp-review-rating" style="white-space: nowrap;">
                                                    @for ($s = 1; $s <= 5; $s++)
                                                        {{ $s <= (int) $review->rating ? '★' : '☆' }}
                                                    @endfor
                                                </span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap;">
                                                <span style="font-size: 10px; padding: 2px 8px; border-radius: 999px; letter-spacing: 0.06em; text-transform: uppercase;
                                                             background: {{ $chColors['bg'] }}; color: {{ $chColors['ink'] }}; font-weight: 600;"
                                                      data-testid="review-channel-{{ $review->id }}">
                                                    {{ ($review->channel ?? \App\Enums\ReviewChannel::Direct)->label() }}
                                                </span>
                                                @if ($review->published_at)
                                                    <time datetime="{{ $review->published_at->toIso8601String() }}"
                                                          style="font-size: 11px; color: #6B6B6B;">
                                                        {{ $review->published_at->format('M Y') }}
                                                    </time>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mp-review-body" style="margin-top: 12px;">{{ $review->body }}</div>
                                    @if ($review->hasResponse())
                                        {{-- Operator reply. Rendered as an inset block under
                                             the guest's words with a light left rule so the
                                             mental model is "reply threaded to this review",
                                             not a second review. --}}
                                        <div class="mp-review-response"
                                             data-testid="review-response-{{ $review->id }}">
                                            <div class="mp-review-response-head">
                                                <span class="mp-review-response-label">
                                                    Response from {{ $property->name ?? 'the property' }}
                                                </span>
                                                @if ($review->responded_at)
                                                    <time class="mp-review-response-time"
                                                          datetime="{{ $review->responded_at->toIso8601String() }}">
                                                        {{ $review->responded_at->diffForHumans() }}
                                                    </time>
                                                @endif
                                            </div>
                                            <div class="mp-review-response-body">{{ $review->response_body }}</div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @if ($reviews->count() > 6)
                            <button type="button" class="mp-amenities-showall"
                                    data-mp-reviews-open
                                    data-testid="stays-show-reviews-showall"
                                    style="margin-top: 24px;">
                                Show all {{ $reviews->count() }} reviews
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3l3 3-3 3"/></svg>
                            </button>
                        @endif
                    </section>
                @endif
            </div>

            {{-- ─── STICKY BOOK CARD ───────────────────────────── --}}
            <aside>
                <form method="GET" action="{{ $bookBase }}" id="stays-show-book-card" class="mp-book-card" data-testid="stays-show-book-card">
                    <div class="mp-trust-chips" data-testid="stays-show-trust-chips">
                        <span class="mp-trust-chip">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                            Prices include all fees
                        </span>
                        <span class="mp-trust-chip">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                            Book direct
                        </span>
                    </div>
                    <div class="mp-book-price" data-testid="stays-show-book-from">
                        @if ($from_price > 0)
                            @if (isset($from_price_base) && $from_price_base > 0 && $from_price_base !== $from_price)
                                <s style="color: #9A9A9A; font-weight: 500; margin-right: 6px; font-size: 15px;"
                                   data-testid="stays-show-book-from-was">&#8358;{{ number_format($from_price_base) }}</s>
                            @endif
                            <b>&#8358;{{ number_format($from_price) }}</b>
                            <span class="mp-suffix">from / night</span>
                        @else
                            <b>Contact host</b>
                        @endif
                    </div>

                    @php
                        // The rooms section moved to a Livewire
                        // component so its `@php` block that defined
                        // these mins isn't in scope here anymore.
                        // Right-column book card needs its own copy.
                        $checkinMin = \Illuminate\Support\Carbon::today()->toDateString();
                        $checkoutMin = $from !== ''
                            ? \Illuminate\Support\Carbon::parse($from)->addDay()->toDateString()
                            : \Illuminate\Support\Carbon::today()->addDay()->toDateString();
                    @endphp
                    <div class="mp-book-fields">
                        <div class="mp-book-field">
                            <div class="mp-book-label">Check-in</div>
                            <input type="date" name="from" value="{{ $from }}"
                                   min="{{ $checkinMin }}"
                                   data-testid="book-from">
                        </div>
                        <div class="mp-book-field">
                            <div class="mp-book-label">Check-out</div>
                            <input type="date" name="to" value="{{ $to }}"
                                   min="{{ $checkoutMin }}"
                                   data-testid="book-to">
                        </div>
                        <div class="mp-book-field mp-book-field-guests">
                            <div class="mp-book-label">Guests</div>
                            <input type="number" name="adults" min="1" max="12" value="{{ $adults ?: 1 }}" data-testid="book-guests">
                        </div>
                    </div>

                    <button type="submit" class="mp-book-cta" data-testid="book-cta">Reserve</button>
                    <p class="mp-book-note">You won't be charged yet</p>
                </form>
            </aside>

        </div>

    </main>

    {{-- ═══ MOBILE STICKY BOOKING BAR ══════════════════════════
         Anchored to the bottom of the viewport on mobile.
         Scrolls the desktop booking form into view instead of
         submitting directly so the guest can pick dates first.
         Desktop hides it via CSS. --}}
    <div class="mp-book-bar" role="region" aria-label="Booking" data-testid="stays-show-book-bar">
        <div class="mp-book-bar__price">
            @if ($from_price > 0)
                <span class="mp-book-bar__amount">
                    &#8358;{{ number_format($from_price) }}
                    <span class="mp-suffix">from / night</span>
                </span>
                @if ($rating_avg !== null && $rating_count > 0)
                    <span class="mp-book-bar__meta">
                        ★ {{ number_format($rating_avg, 2) }} · {{ $rating_count }} {{ $rating_count === 1 ? 'review' : 'reviews' }}
                    </span>
                @else
                    <span class="mp-book-bar__meta">Book direct · no commission</span>
                @endif
            @else
                <span class="mp-book-bar__amount">Contact the front desk</span>
            @endif
        </div>
        <a href="#stays-show-book-card"
           class="mp-book-bar__cta"
           data-testid="stays-show-book-bar-cta">
            Reserve
        </a>
    </div>

    @include('marketplace._partials.stays-footer')

</div>

{{-- Nav hide-on-scroll-down, show-on-scroll-up.

     Earlier revision watched a 1px sentinel above the availability
     search bar so the nav only slid back in when scrolling all the
     way past the search bar. Guests reported the header felt "gone"
     until they scrolled up all the way, so we switched to the
     canonical Airbnb pattern:
       * near the top (< 80px) - always show
       * scrolling down past the search bar - hide
       * scrolling up ANY amount past the search bar - show

     rAF-throttled so the scroll handler doesn't fire per-pixel. --}}
<script>
    (function () {
        var sentinel = document.querySelector('[data-mp-availbar-sentinel]');
        if (! sentinel) return;

        var lastY = window.scrollY || 0;
        var ticking = false;
        var searchTop = 0;

        function recalc() {
            searchTop = sentinel.getBoundingClientRect().top + window.scrollY;
        }
        recalc();
        window.addEventListener('resize', recalc);
        window.addEventListener('load', recalc);

        function apply() {
            var y = window.scrollY;
            var delta = y - lastY;

            if (y < 80) {
                // Near the top - always show. Also covers the "guest
                // scrolls back all the way up" case cleanly.
                document.body.classList.remove('mp-nav-hidden');
            } else if (y > searchTop) {
                if (delta > 4) {
                    document.body.classList.add('mp-nav-hidden');
                } else if (delta < -4) {
                    document.body.classList.remove('mp-nav-hidden');
                }
            } else {
                document.body.classList.remove('mp-nav-hidden');
            }

            lastY = y;
            ticking = false;
        }
        window.addEventListener('scroll', function () {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(apply);
        }, { passive: true });
    })();
</script>

{{-- Category photo lightbox. Vanilla JS (no Alpine on this page).
     Click any .mp-cat-photo with a data-mp-gallery JSON attribute
     -> we build the strip from the parsed URLs + open the modal.
     Prev/next/close buttons + keyboard (Esc/←/→) covered. --}}
<div id="mp-lightbox" hidden aria-hidden="true"
     class="mp-lightbox-backdrop" data-testid="stays-show-lightbox">
    <button type="button" class="mp-lightbox-close" data-mp-lightbox-close aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>
    </button>
    <button type="button" class="mp-lightbox-nav prev" data-mp-lightbox-prev aria-label="Previous photo">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <button type="button" class="mp-lightbox-nav" data-mp-lightbox-next aria-label="Next photo">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
    </button>
    <figure class="mp-lightbox-fig">
        <img data-mp-lightbox-img src="" alt="">
    </figure>
    <div class="mp-lightbox-count" data-mp-lightbox-count>1 / 1</div>
</div>

{{-- Amenities modal — full list of what the property offers.
     Opened by the "Show all N amenities" button, closed via the X,
     Esc, or a backdrop click. Uses the same modal pattern as the
     lightbox + report modals so animation + keyboard handling are
     consistent. --}}
@php
    $modalAmenityList = array_values(array_filter(
        $amenities ?? [],
        fn ($a) => is_string($a) && trim($a) !== '',
    ));
@endphp
@if ($modalAmenityList !== [])
    <div id="mp-amenities-modal" hidden aria-hidden="true"
         class="mp-amenities-modal-backdrop" data-testid="stays-show-amenities-modal">
        <div class="mp-amenities-modal-card" role="dialog" aria-modal="true" aria-labelledby="mp-amenities-modal-title">
            <div class="mp-amenities-modal-head">
                <button type="button" class="mp-amenities-modal-close" data-mp-amenities-close aria-label="Close">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>
                </button>
                <h3 id="mp-amenities-modal-title">Everything this place offers</h3>
            </div>
            <div class="mp-amenities-modal-body">
                @foreach ($modalAmenityList as $amenity)
                    @php
                        $iconBundle = \App\Support\Marketplace\AmenityIcon::iconFor($amenity);
                    @endphp
                    <div class="mp-amenities-modal-row">
                        <span class="mp-amenities-modal-icon" aria-hidden="true">
                            {!! $iconBundle['icon'] !!}
                        </span>
                        <span>{{ $amenity }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

{{-- Reviews modal — full list of guest reviews. Same pattern as
     amenities: opens on trigger click, closes via X, backdrop or
     Escape. Reuses the .mp-amenities-modal-* chrome so we don't
     re-implement the shell. --}}
@if (isset($reviews) && $reviews->count() > 6)
    <div id="mp-reviews-modal" hidden aria-hidden="true"
         class="mp-amenities-modal-backdrop" data-testid="stays-show-reviews-modal">
        <div class="mp-amenities-modal-card" role="dialog" aria-modal="true" aria-labelledby="mp-reviews-modal-title">
            <div class="mp-amenities-modal-head">
                <button type="button" class="mp-amenities-modal-close" data-mp-reviews-close aria-label="Close">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>
                </button>
                <h3 id="mp-reviews-modal-title">
                    @if ($rating_avg !== null)
                        ★ {{ number_format($rating_avg, 2) }} · {{ $rating_count }} {{ $rating_count === 1 ? 'review' : 'reviews' }}
                    @else
                        {{ $rating_count }} {{ $rating_count === 1 ? 'review' : 'reviews' }}
                    @endif
                </h3>
            </div>
            <div class="mp-amenities-modal-body">
                <div class="mp-reviews-modal-list">
                    @foreach ($reviews as $review)
                        @php $chColors = $review->channel?->badgeColors() ?? ['bg' => '#E5E7EB', 'ink' => '#374151']; @endphp
                        <div class="mp-review"
                             role="button" tabindex="0"
                             data-mp-review-open="{{ $review->id }}"
                             data-testid="review-modal-{{ $review->id }}">
                            <div class="mp-review-head" style="display: flex; align-items: flex-start; gap: 12px;">
                                @if ($review->photo_path)
                                    <img src="{{ $review->photoUrl() }}"
                                         alt="{{ $review->guest_name }}"
                                         loading="lazy"
                                         style="width: 44px; height: 44px; border-radius: 999px; object-fit: cover; flex-shrink: 0;">
                                @else
                                    <span aria-hidden="true"
                                          style="width: 44px; height: 44px; border-radius: 999px; background: #F1EBE0; color: #6B6B6B; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 15px; flex-shrink: 0;">
                                        {{ \Illuminate\Support\Str::of($review->guest_name)->substr(0, 1)->upper() }}
                                    </span>
                                @endif
                                <div style="flex: 1; min-width: 0;">
                                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                        <span class="mp-review-name">
                                            {{ $review->guest_name }}
                                            @if (! empty($review->guest_country))
                                                @php $reviewFlag = \App\Services\Locale\Countries::flagFor($review->guest_country); @endphp
                                                <span style="font-size: 12px; color: #6B6B6B; font-weight: 400; margin-left: 4px;">
                                                    · @if ($reviewFlag)<span style="font-size: 14px; line-height: 1;">{{ $reviewFlag }}</span> @endif{{ $review->guest_country }}
                                                </span>
                                            @endif
                                            @if ($review->isVerifiedStay())
                                                <span class="mp-review-badge">
                                                    <svg width="9" height="9" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 6l3 3 5-6"/></svg>
                                                    Verified
                                                </span>
                                            @endif
                                        </span>
                                        <span class="mp-review-rating" style="white-space: nowrap;">
                                            @for ($s = 1; $s <= 5; $s++)
                                                {{ $s <= (int) $review->rating ? '★' : '☆' }}
                                            @endfor
                                        </span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap;">
                                        <span style="font-size: 10px; padding: 2px 8px; border-radius: 999px; letter-spacing: 0.06em; text-transform: uppercase;
                                                     background: {{ $chColors['bg'] }}; color: {{ $chColors['ink'] }}; font-weight: 600;">
                                            {{ ($review->channel ?? \App\Enums\ReviewChannel::Direct)->label() }}
                                        </span>
                                        @if ($review->published_at)
                                            <time datetime="{{ $review->published_at->toIso8601String() }}"
                                                  style="font-size: 11px; color: #6B6B6B;">
                                                {{ $review->published_at->format('M Y') }}
                                            </time>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="mp-review-body" style="margin-top: 12px;">{{ $review->body }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif

{{-- Per-review detail modal. Populated on click via the JSON
     payload emitted in the reviews section — one shared modal
     shell instead of one hidden card per review. --}}
{{-- Advisory: when at least one category on the page is currently
     priced by a rate rule, warn the guest that their final price may
     shift once they enter dates. Fires once per property per session
     on the first meaningful scroll — dismissible via X, Esc or the
     backdrop. --}}
@if (! empty($has_active_rate_rule ?? false))
    <div id="mp-rate-advisory-modal" hidden aria-hidden="true"
         class="mp-amenities-modal-backdrop"
         data-mp-rate-advisory-property="{{ $property->public_slug ?? 'default' }}"
         data-testid="stays-show-rate-advisory">
        <div class="mp-amenities-modal-card" role="dialog" aria-modal="true"
             aria-labelledby="mp-rate-advisory-title" style="max-width: 460px;">
            <div class="mp-amenities-modal-head">
                <button type="button" class="mp-amenities-modal-close"
                        data-mp-rate-advisory-close aria-label="Close">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>
                </button>
                <h3 id="mp-rate-advisory-title">Rates may change</h3>
            </div>
            <div class="mp-amenities-modal-body" style="padding-bottom: 20px;">
                <p style="margin: 0 0 14px; font-size: 14.5px; line-height: 1.6; color: #333;">
                    Prices shown here reflect today's default rates. The final rate
                    can change once you enter your check-in and check-out dates,
                    based on availability, the length of your stay and any
                    ongoing offers.
                </p>
                <p style="margin: 0; font-size: 13px; color: #6B6B6B;">
                    Enter your dates in "Check availability" for an accurate quote before you reserve.
                </p>
                <div style="display: flex; justify-content: flex-end; margin-top: 18px;">
                    <button type="button" data-mp-rate-advisory-close
                            style="padding: 9px 18px; border-radius: 999px; background: #14171A; color: #fff; border: none; font-weight: 600; font-size: 13px; cursor: pointer;">
                        Got it
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var modal = document.getElementById('mp-rate-advisory-modal');
            if (! modal) return;
            var storageKey = 'mp-rate-advisory-dismissed:' + (modal.dataset.mpRateAdvisoryProperty || 'default');
            try {
                if (sessionStorage.getItem(storageKey) === '1') return;
            } catch (e) { /* private mode or storage disabled — show once */ }

            var card = modal.querySelector('.mp-amenities-modal-card');
            var closers = modal.querySelectorAll('[data-mp-rate-advisory-close]');
            var opened = false;

            function open() {
                if (opened) return;
                opened = true;
                modal.hidden = false;
                modal.setAttribute('aria-hidden', 'false');
            }
            function close() {
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                try { sessionStorage.setItem(storageKey, '1'); } catch (e) {}
                window.removeEventListener('scroll', onScroll, { passive: true });
            }
            function onScroll() {
                // Fire once the guest has actually engaged with the
                // page — a quarter of viewport height is far enough
                // past the hero for the advisory to feel contextual
                // (they've seen prices), not intrusive.
                if (window.scrollY > window.innerHeight * 0.25) {
                    open();
                }
            }

            closers.forEach(function (c) { c.addEventListener('click', close); });
            modal.addEventListener('click', function (e) {
                if (card && ! card.contains(e.target)) close();
            });
            document.addEventListener('keydown', function (e) {
                if (! modal.hidden && e.key === 'Escape') close();
            });
            window.addEventListener('scroll', onScroll, { passive: true });
        })();
    </script>
@endif

@if (! empty($reviewsPayload ?? []))
    <div id="mp-review-detail-modal" hidden aria-hidden="true"
         class="mp-amenities-modal-backdrop" data-testid="stays-show-review-detail-modal">
        <div class="mp-amenities-modal-card" role="dialog" aria-modal="true" aria-labelledby="mp-review-detail-title">
            <div class="mp-amenities-modal-head">
                <button type="button" class="mp-amenities-modal-close" data-mp-review-detail-close aria-label="Close">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>
                </button>
                <h3 id="mp-review-detail-title">Guest review</h3>
            </div>
            <div class="mp-amenities-modal-body">
                <div class="mp-review-detail">
                    <div class="mp-review-detail-head">
                        <img class="mp-review-detail-avatar" data-field="photo" alt="" hidden>
                        <span class="mp-review-detail-avatar" data-field="initial">?</span>
                        <div class="mp-review-detail-title">
                            <div>
                                <span class="mp-review-detail-name" data-field="name"></span>
                                <span class="mp-review-detail-country" data-field="country_wrap" hidden>
                                    · <span data-field="flag" style="font-size: 15px;"></span> <span data-field="country"></span>
                                </span>
                            </div>
                            <div class="mp-review-detail-meta">
                                <span class="mp-review-detail-chip" data-field="channel"></span>
                                <time data-field="date"></time>
                                <span data-field="verified_badge" class="mp-review-badge" hidden>
                                    <svg width="9" height="9" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 6l3 3 5-6"/></svg>
                                    Verified stay
                                </span>
                            </div>
                        </div>
                        <span class="mp-review-detail-rating" data-field="rating"></span>
                    </div>
                    <p class="mp-review-detail-body" data-field="body"></p>
                    <div class="mp-review-detail-response" data-field="response_wrap" hidden>
                        <div class="mp-review-detail-response-head">
                            <span class="mp-review-detail-response-label" data-field="response_by"></span>
                            <time data-field="response_at"></time>
                        </div>
                        <div class="mp-review-detail-response-body" data-field="response_body"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        window.mpReviewsData = @json($reviewsPayload);
    </script>
@endif

<script>
    (function () {
        var backdrop = document.getElementById('mp-lightbox');
        if (! backdrop) return;
        var imgEl = backdrop.querySelector('[data-mp-lightbox-img]');
        var countEl = backdrop.querySelector('[data-mp-lightbox-count]');
        var prevEl = backdrop.querySelector('[data-mp-lightbox-prev]');
        var nextEl = backdrop.querySelector('[data-mp-lightbox-next]');
        var closeEl = backdrop.querySelector('[data-mp-lightbox-close]');
        var state = { images: [], index: 0 };

        function render() {
            if (state.images.length === 0) return;
            imgEl.src = state.images[state.index];
            countEl.textContent = (state.index + 1) + ' / ' + state.images.length;
            prevEl.style.display = state.images.length > 1 ? '' : 'none';
            nextEl.style.display = state.images.length > 1 ? '' : 'none';
        }
        function open(images, title) {
            state.images = images;
            state.index = 0;
            imgEl.alt = title || '';
            backdrop.hidden = false;
            backdrop.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            render();
        }
        function close() {
            backdrop.hidden = true;
            backdrop.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
        function step(delta) {
            if (state.images.length === 0) return;
            state.index = (state.index + delta + state.images.length) % state.images.length;
            render();
        }

        // Delegated click - fires for any .mp-cat-photo the operator
        // has uploaded gallery images to (data-mp-gallery attr).
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-mp-gallery]');
            if (! trigger) return;
            try {
                var images = JSON.parse(trigger.dataset.mpGallery || '[]');
                if (images.length === 0) return;
                open(images, trigger.dataset.mpGalleryTitle || '');
            } catch (_) {}
        });
        // Keyboard-triggered open (Enter/Space on focused tile).
        document.addEventListener('keydown', function (e) {
            if (backdrop.hidden) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var trigger = document.activeElement?.closest?.('[data-mp-gallery]');
                if (! trigger) return;
                try {
                    var images = JSON.parse(trigger.dataset.mpGallery || '[]');
                    if (images.length === 0) return;
                    e.preventDefault();
                    open(images, trigger.dataset.mpGalleryTitle || '');
                } catch (_) {}
                return;
            }
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowLeft') step(-1);
            if (e.key === 'ArrowRight') step(1);
        });

        prevEl.addEventListener('click', function () { step(-1); });
        nextEl.addEventListener('click', function () { step(1); });
        closeEl.addEventListener('click', close);
        // Click-outside closes; click on the image itself doesn't.
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) close();
        });
    })();
</script>

{{-- Report modal + Like / Share / Report handlers.

     Like: client-side only (localStorage) - a proper favourites
     table needs guest auth which we haven't shipped for the
     marketplace yet. The heart still flips instantly for the
     current device, which is what the guest expects.

     Share: navigator.share() where available (Android / iOS),
     clipboard copy + toast fallback everywhere else.

     Report: opens a modal with reason + free-text; on submit
     POSTs JSON to /stays/{slug}/report with the CSRF token
     harvested from the meta tag. Success closes the modal +
     shows a toast; error shows an inline message.
--}}
<div id="mp-report-backdrop" hidden aria-hidden="true"
     class="mp-report-backdrop" data-testid="stays-show-report-modal">
    <div class="mp-report-modal" role="dialog" aria-labelledby="mp-report-title">
        <h3 id="mp-report-title">Report this stay</h3>
        <p class="mp-report-sub">
            Something wrong with this listing? Let us know and our team will look into it.
        </p>
        <form data-mp-report-form novalidate>
            <div class="mp-report-error" hidden data-mp-report-error></div>
            <label class="mp-report-field">
                <span class="lbl">Reason</span>
                <select name="reason" required data-testid="report-reason">
                    <option value="">Pick one…</option>
                    <option value="inaccurate">Inaccurate listing details</option>
                    <option value="inappropriate">Inappropriate content or photos</option>
                    <option value="scam">Looks like a scam</option>
                    <option value="duplicate">Duplicate of another listing</option>
                    <option value="other">Other</option>
                </select>
            </label>
            <label class="mp-report-field">
                <span class="lbl">What happened?</span>
                <textarea name="message" maxlength="2000" required
                          data-testid="report-message"
                          placeholder="Add any details that would help our team investigate."></textarea>
            </label>
            <label class="mp-report-field">
                <span class="lbl">Your email (so we can follow up)</span>
                <input type="email" name="email" maxlength="180" required
                       data-testid="report-email"
                       placeholder="you@example.com">
            </label>
            <div class="mp-report-actions">
                <button type="button" class="mp-report-cancel" data-mp-report-close>Cancel</button>
                <button type="submit" class="mp-report-submit" data-testid="report-submit">Submit report</button>
            </div>
        </form>
    </div>
</div>

<div id="mp-action-toast" class="mp-action-toast" data-mp-toast role="status" aria-live="polite"></div>

<script>
    (function () {
        var toastEl = document.getElementById('mp-action-toast');
        var toastTimer = null;
        function toast(msg) {
            if (! toastEl) return;
            toastEl.textContent = msg;
            toastEl.classList.add('visible');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () {
                toastEl.classList.remove('visible');
            }, 2400);
        }

        // ── Like ──────────────────────────────────────────────
        // Client-side only for the marketplace MVP; when guest
        // accounts land we'll swap the localStorage key for a
        // POST /stays/liked call + still keep this cache as the
        // immediate optimistic-flip.
        var STORAGE_KEY = 'keverna:stays:liked';
        function readLiked() {
            try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); }
            catch (_) { return []; }
        }
        function writeLiked(list) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(list)); } catch (_) {}
        }
        document.querySelectorAll('[data-mp-like]').forEach(function (btn) {
            var slug = btn.dataset.mpSlug || '';
            var liked = readLiked();
            if (slug !== '' && liked.indexOf(slug) !== -1) {
                btn.classList.add('mp-action-liked');
                btn.setAttribute('aria-pressed', 'true');
            }
            btn.addEventListener('click', function () {
                var list = readLiked();
                var idx = list.indexOf(slug);
                if (idx === -1) {
                    list.push(slug);
                    btn.classList.add('mp-action-liked');
                    btn.setAttribute('aria-pressed', 'true');
                    toast('Saved to your list');
                } else {
                    list.splice(idx, 1);
                    btn.classList.remove('mp-action-liked');
                    btn.setAttribute('aria-pressed', 'false');
                    toast('Removed from your list');
                }
                writeLiked(list);
            });
        });

        // ── Share ─────────────────────────────────────────────
        document.querySelectorAll('[data-mp-share]').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var url = window.location.href;
                var title = btn.dataset.mpShareTitle || document.title;
                if (navigator.share) {
                    try {
                        await navigator.share({ title: title, url: url });
                    } catch (_) { /* user cancelled */ }
                    return;
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    try {
                        await navigator.clipboard.writeText(url);
                        toast('Link copied');
                    } catch (_) { toast('Copy failed'); }
                    return;
                }
                // Last-resort fallback for old browsers.
                window.prompt('Copy this link:', url);
            });
        });

        // ── Report ────────────────────────────────────────────
        var reportBackdrop = document.getElementById('mp-report-backdrop');
        var reportForm = reportBackdrop?.querySelector('[data-mp-report-form]');
        var reportError = reportBackdrop?.querySelector('[data-mp-report-error]');

        function openReport() {
            if (! reportBackdrop) return;
            reportError.hidden = true;
            reportBackdrop.hidden = false;
            reportBackdrop.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }
        function closeReport() {
            if (! reportBackdrop) return;
            reportBackdrop.hidden = true;
            reportBackdrop.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
        document.querySelectorAll('[data-mp-report-open]').forEach(function (el) {
            el.addEventListener('click', openReport);
        });
        reportBackdrop?.querySelectorAll('[data-mp-report-close]').forEach(function (el) {
            el.addEventListener('click', closeReport);
        });
        reportBackdrop?.addEventListener('click', function (e) {
            if (e.target === reportBackdrop) closeReport();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && reportBackdrop && ! reportBackdrop.hidden) closeReport();
        });

        reportForm?.addEventListener('submit', async function (e) {
            e.preventDefault();
            reportError.hidden = true;
            var submitBtn = reportForm.querySelector('button[type=submit]');
            var slug = @json((string) $property->public_slug);
            var token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            var body = new FormData(reportForm);
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting…';
            try {
                var res = await fetch('/stays/' + encodeURIComponent(slug) + '/report', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: body,
                });
                var payload = await res.json().catch(function () { return {}; });
                if (! res.ok) {
                    var msg = payload && payload.message
                        ? payload.message
                        : "We couldn't send that report. Please try again.";
                    reportError.textContent = msg;
                    reportError.hidden = false;
                    return;
                }
                closeReport();
                reportForm.reset();
                toast('Thanks - report submitted');
            } catch (_) {
                reportError.textContent = "We couldn't send that report. Please try again.";
                reportError.hidden = false;
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit report';
            }
        });
    })();
</script>

{{-- Auto-advance checkout when the guest picks a check-in. Applied
     to the sticky book card (the availability bar is Livewire-owned
     and auto-advances server-side in updatedFrom). If checkout is
     empty OR earlier than the new check-in +1 day, we fill it with
     check-in +1. If the guest has already typed a later checkout
     we leave it alone. --}}
<script>
    (function () {
        // Local YYYY-MM-DD formatter. Using `.toISOString()` would
        // return UTC — in Nigeria (WAT, UTC+1) a "+1 day" then gets
        // shifted back into the same calendar day when the ISO string
        // is truncated to the date, so checkout appears identical to
        // check-in. Formatting the local components avoids the shift.
        function localIso(date) {
            var y = date.getFullYear();
            var m = String(date.getMonth() + 1).padStart(2, '0');
            var d = String(date.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + d;
        }
        function pairUp(container) {
            var ci = container.querySelector('input[name="from"]');
            var co = container.querySelector('input[name="to"]');
            if (! ci || ! co) return;

            function advance() {
                if (! ci.value) return;
                var ciDate = new Date(ci.value + 'T00:00:00');
                if (isNaN(ciDate)) return;
                var nextDay = new Date(ciDate);
                nextDay.setDate(nextDay.getDate() + 1);
                var nextIso = localIso(nextDay);

                // Native picker floor: never offer the same day or earlier.
                co.min = nextIso;

                if (! co.value) {
                    co.value = nextIso;
                    return;
                }
                var coDate = new Date(co.value + 'T00:00:00');
                if (isNaN(coDate) || coDate <= ciDate) {
                    co.value = nextIso;
                }
            }
            ci.addEventListener('change', advance);
            ci.addEventListener('input', advance);
        }
        document.querySelectorAll('form[data-testid="stays-show-book-card"]')
            .forEach(pairUp);
    })();
</script>

<script>
    /* Show/hide overlay modal helper — shared by the amenities
       and reviews "Show all N" affordances so the DOM behaviour
       (backdrop click / X button / Esc / body scroll-lock) stays
       consistent across both. Accepts a modal id + a trigger/closer
       selector pair. */
    function attachStaysModal(modalId, openSelector, closeSelector) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        const triggers = document.querySelectorAll(openSelector);
        const closers = modal.querySelectorAll(closeSelector);
        const card = modal.querySelector('.mp-amenities-modal-card');
        const open = () => {
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        };
        const close = () => {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        };
        triggers.forEach(t => t.addEventListener('click', open));
        closers.forEach(c => c.addEventListener('click', close));
        modal.addEventListener('click', (e) => {
            if (card && !card.contains(e.target)) close();
        });
        document.addEventListener('keydown', (e) => {
            if (!modal.hidden && e.key === 'Escape') close();
        });
    }
    attachStaysModal('mp-amenities-modal', '[data-mp-amenities-open]', '[data-mp-amenities-close]');
    attachStaysModal('mp-reviews-modal', '[data-mp-reviews-open]', '[data-mp-reviews-close]');

    /* Per-review detail modal. Reuses the amenities-modal chrome
       (backdrop, card, X, Esc). Populated on click from the
       window.mpReviewsData payload emitted alongside the section
       so we don't render a hidden shell per review. */
    (function () {
        const modal = document.getElementById('mp-review-detail-modal');
        const data = window.mpReviewsData;
        if (!modal || !data) return;

        const $ = (sel) => modal.querySelector(sel);
        const card = modal.querySelector('.mp-amenities-modal-card');

        const setField = (name, text) => {
            const el = $('[data-field="' + name + '"]');
            if (el) el.textContent = text ?? '';
        };
        const setHidden = (name, hidden) => {
            const el = $('[data-field="' + name + '"]');
            if (el) el.hidden = !!hidden;
        };

        function populate(id) {
            const r = data[id];
            if (!r) return;
            setField('name', r.name);
            setField('country', r.country || '');
            setField('flag', r.flag || '');
            setHidden('country_wrap', !r.country);

            const rating = $('[data-field="rating"]');
            if (rating) rating.textContent = '★'.repeat(r.rating) + '☆'.repeat(5 - r.rating);

            const chip = $('[data-field="channel"]');
            if (chip) {
                chip.textContent = r.channel_label || '';
                chip.style.background = r.channel_bg || '#E5E7EB';
                chip.style.color = r.channel_ink || '#374151';
            }

            const date = $('[data-field="date"]');
            if (date) {
                date.textContent = r.date || '';
                if (r.date_iso) date.setAttribute('datetime', r.date_iso);
                else date.removeAttribute('datetime');
                date.hidden = !r.date;
            }

            setHidden('verified_badge', !r.verified);

            const photo = $('[data-field="photo"]');
            const initial = $('[data-field="initial"]');
            if (r.photo_url) {
                photo.src = r.photo_url;
                photo.alt = r.name || '';
                photo.hidden = false;
                initial.hidden = true;
            } else {
                photo.hidden = true;
                photo.removeAttribute('src');
                initial.hidden = false;
                initial.textContent = r.initial || '?';
            }

            setField('body', r.body || '');

            const respWrap = $('[data-field="response_wrap"]');
            if (r.response_body) {
                respWrap.hidden = false;
                setField('response_by', r.response_by || '');
                setField('response_body', r.response_body);
                const rt = $('[data-field="response_at"]');
                if (rt) {
                    rt.textContent = r.response_at || '';
                    if (r.response_at_iso) rt.setAttribute('datetime', r.response_at_iso);
                    else rt.removeAttribute('datetime');
                }
            } else {
                respWrap.hidden = true;
            }
        }

        function open(id) {
            populate(id);
            // Close the "all reviews" modal first so the two don't stack.
            const all = document.getElementById('mp-reviews-modal');
            if (all && !all.hidden) {
                all.hidden = true;
                all.setAttribute('aria-hidden', 'true');
            }
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        document.addEventListener('click', function (e) {
            const trigger = e.target.closest('[data-mp-review-open]');
            if (trigger) {
                if (e.target.closest('a, button')) return;
                const id = trigger.getAttribute('data-mp-review-open');
                open(id);
                return;
            }
            const closer = e.target.closest('[data-mp-review-detail-close]');
            if (closer) { close(); return; }
        });
        modal.addEventListener('click', (e) => {
            if (card && !card.contains(e.target)) close();
        });
        document.addEventListener('keydown', (e) => {
            if (!modal.hidden && e.key === 'Escape') close();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const trigger = e.target.closest('[data-mp-review-open]');
            if (!trigger) return;
            e.preventDefault();
            open(trigger.getAttribute('data-mp-review-open'));
        });
    })();

    /* Mobile gallery counter — updates the "1 / N" pill as the guest
       swipes through the horizontal carousel. IntersectionObserver
       against each tile with the carousel as the root so we don't
       have to hand-compute scroll offsets or debounce a scroll listener.
       Skipped when the browser lacks IO or on desktop where the grid
       mosaic hides the counter via CSS. */
    (function () {
        const track = document.querySelector('[data-mp-gallery-track]');
        const indexEl = document.querySelector('[data-mp-gallery-index]');
        if (!track || !indexEl || !('IntersectionObserver' in window)) return;
        const tiles = track.querySelectorAll('.mp-gallery-tile');
        if (tiles.length <= 1) return;
        const io = new IntersectionObserver((entries) => {
            entries.forEach((e) => {
                if (e.isIntersecting && e.intersectionRatio > 0.6) {
                    const i = Array.from(tiles).indexOf(e.target);
                    if (i >= 0) indexEl.textContent = String(i + 1);
                }
            });
        }, { root: track, threshold: [0.6, 0.9] });
        tiles.forEach((t) => io.observe(t));
    })();

    /* Sticky jump-nav visibility.
       Shows once the guest scrolls past the hero (300px), gains a
       Reserve CTA once past the desktop booking card (aria-anchor).
       Two scroll thresholds so the nav doesn't jump on every pixel
       and the CTA only appears when it actually adds value (the
       original right-rail booking card is off-screen). */
    (function () {
        const nav = document.querySelector('.mp-jumpnav');
        const book = document.getElementById('stays-show-book-card');
        if (!nav) return;
        const heroThreshold = 300;
        let ctaThreshold = 900;
        const measure = () => {
            if (!book) return;
            const rect = book.getBoundingClientRect();
            ctaThreshold = window.scrollY + rect.bottom;
        };
        measure();
        window.addEventListener('resize', measure, { passive: true });
        const update = () => {
            const y = window.scrollY;
            nav.classList.toggle('is-visible', y > heroThreshold);
            nav.classList.toggle('is-cta', y > ctaThreshold);
        };
        update();
        window.addEventListener('scroll', update, { passive: true });
    })();
</script>

{{-- Livewire runtime (bundles Alpine) - must render after all our
     own scripts so page-level observers are set up first. Once
     shipped it hydrates the availability browser embedded above. --}}
@livewireScripts
</body>
</html>
