{{--
    Per-tenant property landing, booking-first.

    Rewritten to mirror the /rooms browse page: minimal header, prominent
    filter bar, category grid, done. We're not trying to replace the
    property's marketing website, we're the "quick + recurring booking"
    surface guests visit when they already know they want to stay here.

    Preserved from the previous design:
      • Every SEO / OG / meta hook (canonical, twitter cards, sitemap)
      • Per-tenant CSS custom properties (--primary + display/body fonts)
      • data-testid hooks tests depend on (hero, footer, no-rooms,
        reviews-section, whatsapp-button)
      • Section visibility toggles the operator has set (About / Reviews
        / Contact still render if enabled, but they trail the booking flow
        rather than dominating it)
      • WhatsApp FAB behaviour
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="{{ $property->primary_color }}">

    @php
        // Composed title puts the property name + location + type so
        // "Grand Palace Abuja - Boutique hotel in Abuja" is what shows
        // in a search result rather than just the property name alone.
        $kindLabel = match ($kind) {
            'workspace'   => 'Workspace',
            'residential' => 'Homes for rent',
            default       => match ($property->type?->value) {
                'hotel'       => 'Hotel',
                'apartment'   => 'Serviced apartments',
                'shortlet'    => 'Short-let',
                'guesthouse'  => 'Guest house',
                default       => 'Stays',
            },
        };
        $locationBits = array_filter([$property->city, $property->state]);
        $locationStr  = $locationBits ? implode(', ', $locationBits) : null;

        $composedTitle = $property->name
            .($locationStr ? ' - '.$kindLabel.' in '.$locationStr : ' - '.$kindLabel)
            .' - Book direct';

        // Description: prefer tagline; else description; else compose
        // a sensible one from what we know.
        $composedDesc = $property->tagline
            ?: ($property->description
                ? \Illuminate\Support\Str::limit(strip_tags($property->description), 200)
                : 'Book '.$property->name.' direct'
                    .($locationStr ? ' in '.$locationStr : '')
                    .'. Instant confirmation, no commission, secure card + cash payments.');
    @endphp

    <title>{{ $composedTitle }}</title>
    <meta name="description" content="{{ $composedDesc }}">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <link rel="canonical" href="{{ $canonicalUrl }}">

    {{-- Open Graph (PRD §10) --}}
    <meta property="og:title" content="{{ $composedTitle }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:locale" content="en_NG">
    <meta property="og:description" content="{{ $composedDesc }}">
    @if ($ogImageUrl)
        <meta property="og:image" content="{{ $ogImageUrl }}">
        <meta property="og:image:alt" content="{{ $property->name }}">
    @endif

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $composedTitle }}">
    <meta name="twitter:description" content="{{ $composedDesc }}">
    @if ($ogImageUrl)
        <meta name="twitter:image" content="{{ $ogImageUrl }}">
    @endif

    {{-- Structured data - LodgingBusiness for stays, LocalBusiness for
         workspaces, RealEstateListing for residential. Same @php-then-
         json_encode pattern we use on welcome.blade.php so schema.org
         keys ("@type", "@id", "@context") don't get eaten by Blade. --}}
    @php
        $priceFromNaira = $priceFrom ? '₦'.number_format((int) $priceFrom) : null;
        $priceRange = $priceFrom && $priceTo && $priceFrom !== $priceTo
            ? '₦'.number_format((int) $priceFrom).'-₦'.number_format((int) $priceTo)
            : ($priceFromNaira ?: null);

        // Try LodgingBusiness for stay/apartment/hotel/guesthouse;
        // fall back to LocalBusiness for workspaces and Residence
        // for residential so the schema type actually matches the
        // real-world entity Google is indexing.
        $ldType = match ($kind) {
            'workspace'   => 'LocalBusiness',
            'residential' => 'Residence',
            default       => 'LodgingBusiness',
        };

        $businessLd = array_filter([
            '@context'    => 'https://schema.org',
            '@type'       => $ldType,
            '@id'         => rtrim($canonicalUrl, '/').'#business',
            'name'        => $property->name,
            'url'         => $canonicalUrl,
            'description' => $composedDesc,
            'image'       => $ogImageUrl ?: null,
            'telephone'   => $tenant?->phone ?: null,
            'email'       => $tenant?->email ?: null,
            'priceRange'  => $priceRange,
            'address'     => array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $property->address ?: null,
                'addressLocality' => $property->city ?: null,
                'addressRegion'   => $property->state ?: null,
                'addressCountry'  => 'NG',
            ]),
            'geo' => $property->latitude && $property->longitude ? [
                '@type'    => 'GeoCoordinates',
                'latitude' => (float) $property->latitude,
                'longitude'=> (float) $property->longitude,
            ] : null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');

        if (isset($rating['count']) && (int) $rating['count'] > 0) {
            $businessLd['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (float) ($rating['average'] ?? 0),
                'reviewCount' => (int) $rating['count'],
                'bestRating'  => 5,
                'worstRating' => 1,
            ];
        }

        // Amenities feed to schema.org amenityFeature. Only relevant
        // for lodging types where the term makes sense.
        $amenities = is_array($property->amenities) ? array_values(array_filter($property->amenities)) : [];
        if ($amenities !== [] && $ldType === 'LodgingBusiness') {
            $businessLd['amenityFeature'] = array_map(fn ($a) => [
                '@type' => 'LocationFeatureSpecification',
                'name'  => is_string($a) ? $a : (string) ($a['name'] ?? $a),
                'value' => true,
            ], $amenities);
        }

        // Category-level offerings so a category card appears as a
        // real offer in Google's rich results. Per-room detail pages
        // will carry their own Product/Offer schema separately.
        $catLd = [];
        if ($kind === 'stay' && ! empty($categories)) {
            foreach ($categories as $cat) {
                $catLd[] = array_filter([
                    '@context'    => 'https://schema.org',
                    '@type'       => 'Product',
                    'name'        => $property->name.' - '.$cat->name,
                    'description' => $cat->description ?? null,
                    'brand'       => ['@type' => 'Brand', 'name' => $property->name],
                    'offers'      => [
                        '@type'        => 'Offer',
                        'priceCurrency'=> 'NGN',
                        'price'        => (int) ($cat->base_rate ?? 0),
                        'availability' => 'https://schema.org/InStock',
                        'url'          => $canonicalUrl,
                    ],
                ], fn ($v) => $v !== null && $v !== [] && $v !== '');
            }
        }

        // Breadcrumb - simple two-hop: Home > Rooms/Homes/Workspaces.
        $crumbLd = [
            '@context'   => 'https://schema.org',
            '@type'      => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $canonicalUrl],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $navInventoryLabel ?? 'Rooms', 'item' => rtrim($canonicalUrl, '/').'/'.$inventoryNoun],
            ],
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($businessLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @foreach ($catLd as $ld)
        <script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endforeach
    <script type="application/ld+json">{!! json_encode($crumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

    {{-- Per-tenant font theme (PRD §16). --}}
    @php
        $fontMap = [
            'inter_playfair'          => ['display' => 'Playfair Display', 'body' => 'Inter'],
            'inter_inter'             => ['display' => 'Inter',            'body' => 'Inter'],
            'lora_lora'               => ['display' => 'Lora',             'body' => 'Lora'],
            'poppins_poppins'         => ['display' => 'Poppins',          'body' => 'Poppins'],
            'merriweather_montserrat' => ['display' => 'Merriweather',     'body' => 'Montserrat'],
        ];
        $fonts = $fontMap[$property->font_theme] ?? $fontMap['inter_playfair'];
        $today = \Illuminate\Support\Carbon::today()->toDateString();
        $tomorrow = \Illuminate\Support\Carbon::today()->addDay()->toDateString();
        $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
    @endphp
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $fonts['display']) }}:wght@500;600;700&family={{ str_replace(' ', '+', $fonts['body']) }}:wght@400;500;600&display=swap">

    <style>
        /* ── Tokens: per-tenant primary + neutral palette ─────────── */
        :root {
            --primary: {{ $property->primary_color }};
            --primary-dark: color-mix(in srgb, {{ $property->primary_color }} 78%, black);
            --primary-soft: color-mix(in srgb, {{ $property->primary_color }} 12%, transparent);
            --display-font: '{{ $fonts['display'] }}', Georgia, serif;
            --body-font: '{{ $fonts['body'] }}', system-ui, -apple-system, 'Segoe UI', sans-serif;
        }

        /* ── Base: mirrors resources/views/livewire/booking/rooms-browse.blade.php
                so the property landing and the /rooms browse feel like the
                same product, not two pages. ────────────────────────────────── */
        .rb-page {
            --rb-paper:    #FAF7F2;
            --rb-paper-2:  #F2EEE6;
            --rb-card:     #FFFFFF;
            --rb-ink:      #14171A;
            --rb-ink-soft: #3D434A;
            --rb-ink-mute: #7A7F87;
            --rb-line:     rgba(20, 23, 26, 0.10);
            --rb-line-soft:rgba(20, 23, 26, 0.06);

            background: var(--rb-paper);
            color: var(--rb-ink);
            font-family: var(--body-font);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-bottom: 5rem;
            min-height: 100vh;
        }
        .rb-page * { box-sizing: border-box; }
        .rb-page h1, .rb-page h2, .rb-page h3 {
            font-family: var(--display-font);
            color: var(--rb-ink);
            letter-spacing: -0.018em;
            line-height: 1.06;
            margin: 0;
            font-weight: 600;
        }
        .rb-page a { color: inherit; text-decoration: none; }
        .rb-page p { color: var(--rb-ink-soft); line-height: 1.65; margin: 0; }
        .rb-page img { display: block; max-width: 100%; }

        .rb-wrap { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
        @media (min-width: 768px) { .rb-wrap { padding: 0 2.5rem; } }

        /* ── Top bar ───────────────────────────────────────────────── */
        .rb-topbar {
            border-bottom: 1px solid var(--rb-line-soft);
            background: color-mix(in srgb, var(--rb-paper) 92%, transparent);
            backdrop-filter: saturate(180%) blur(12px);
            -webkit-backdrop-filter: saturate(180%) blur(12px);
            position: sticky; top: 0; z-index: 40;
        }
        .rb-topbar-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 0.9rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        }
        @media (min-width: 768px) { .rb-topbar-inner { padding: 1rem 2.5rem; } }
        .rb-brand {
            display: inline-flex; align-items: center; gap: 0.7rem;
            font-family: var(--display-font);
            font-size: 16.5px; font-weight: 600;
            color: var(--rb-ink); letter-spacing: -0.01em;
        }
        .rb-brand img { height: 26px; width: auto; }
        .rb-topbar-links { display: inline-flex; align-items: center; gap: 1.25rem; }
        .rb-topbar-links a {
            font-size: 13px; color: var(--rb-ink-soft);
        }
        .rb-topbar-links a:hover { color: var(--rb-ink); }

        /* ── Docked search pill ─────────────────────────────────────
                Appears in the topbar centre once the main filter card
                has scrolled out of view (see the IntersectionObserver
                at the bottom of the page). Shows the current picked
                values as a summary and takes the guest back to the
                full filter with one click. Smooth height + opacity
                transition so it feels like the search "flew up" into
                the header, not like a new element popped in. */
        .rb-topbar-search {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 6px 6px 6px 16px;
            background: var(--rb-card);
            border: 1px solid var(--rb-line);
            border-radius: 9999px;
            box-shadow: 0 8px 20px -14px rgba(20,17,12,0.22);
            font-size: 13px; color: var(--rb-ink);
            transition:
                opacity 240ms ease,
                transform 240ms ease,
                visibility 0s linear 240ms;
            opacity: 0;
            transform: translateY(-8px);
            visibility: hidden;
            pointer-events: none;
            /* Cap it so long summaries don't blow out the topbar. */
            max-width: min(520px, 60vw);
        }
        .rb-page.is-search-docked .rb-topbar-search {
            opacity: 1;
            transform: translateY(0);
            visibility: visible;
            transition-delay: 0s;
            pointer-events: auto;
        }
        .rb-topbar-search .rb-tsearch-summary {
            display: inline-flex; align-items: center; gap: 8px;
            min-width: 0;
            font-weight: 500;
            color: var(--rb-ink);
            white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .rb-topbar-search .rb-tsearch-summary .dot {
            color: var(--rb-ink-mute); font-weight: 400;
        }
        .rb-topbar-search .rb-tsearch-summary .muted {
            color: var(--rb-ink-soft); font-weight: 400;
        }
        .rb-topbar-search .rb-tsearch-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px;
            background: var(--rb-ink); color: var(--rb-card);
            border-radius: 9999px;
            flex-shrink: 0;
            transition: background 200ms;
        }
        .rb-topbar-search .rb-tsearch-btn:hover { background: var(--primary-dark); }
        @media (max-width: 640px) {
            .rb-topbar-search { padding: 5px 5px 5px 12px; font-size: 12.5px; }
            .rb-topbar-search .rb-tsearch-btn { width: 28px; height: 28px; }
        }

        /* ── Search modal (opens from the docked pill) ─────────────
                Same 4-field layout as the main filter, wrapped in a
                sheet that slides down from the top on desktop and up
                from the bottom on mobile. Seeded from the main filter
                values so the guest sees their prior picks. --}}
        */
        .rb-modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(20, 17, 12, 0.45);
            display: flex; align-items: flex-start; justify-content: center;
            padding: 4.5rem 1rem 1rem;
            z-index: 60;
            opacity: 0;
            pointer-events: none;
            transition: opacity 220ms ease;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
        }
        .rb-modal-backdrop.is-open {
            opacity: 1;
            pointer-events: auto;
        }
        .rb-modal-panel {
            width: 100%; max-width: 560px;
            background: var(--rb-card);
            border-radius: 18px;
            box-shadow: 0 40px 80px -30px rgba(20,17,12,0.5);
            transform: translateY(-14px) scale(0.985);
            opacity: 0;
            transition: transform 240ms cubic-bezier(0.16, 1, 0.3, 1),
                        opacity 240ms ease;
        }
        .rb-modal-backdrop.is-open .rb-modal-panel {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
        .rb-modal-head {
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 1rem;
            padding: 1.25rem 1.5rem 0.5rem;
        }
        .rb-modal-head h2 {
            font-family: var(--display-font);
            font-size: 20px; font-weight: 600;
            color: var(--rb-ink); letter-spacing: -0.015em;
        }
        .rb-modal-close {
            background: transparent; border: 0;
            width: 32px; height: 32px;
            border-radius: 9999px;
            color: var(--rb-ink-mute);
            cursor: pointer;
            font-size: 22px; line-height: 1;
            display: inline-flex; align-items: center; justify-content: center;
            transition: background 200ms, color 200ms;
        }
        .rb-modal-close:hover {
            background: var(--rb-paper-2);
            color: var(--rb-ink);
        }
        .rb-modal-body { padding: 0.5rem 1.5rem 1.5rem; }
        .rb-modal-body .rb-filters {
            margin: 0;
            box-shadow: none;
            border-color: var(--rb-line);
        }
        /* The main filter's 5-column grid is too tight inside a
           560px modal, the submit gets clipped or wraps ugly. Force
           a 2×2 field layout with the submit as a full-width row below. */
        .rb-modal-body .rb-filters-grid {
            grid-template-columns: 1fr 1fr;
        }
        .rb-modal-body .rb-filter-submit {
            grid-column: 1 / -1;
            padding: 12px;
            justify-content: stretch;
        }
        .rb-modal-body .rb-filter-submit .rb-pill {
            width: 100%;
            justify-content: space-between;
        }

        @media (max-width: 640px) {
            .rb-modal-backdrop {
                align-items: flex-end;
                padding: 0;
            }
            .rb-modal-panel {
                max-width: 100%;
                border-radius: 18px 18px 0 0;
                transform: translateY(30%);
            }
            .rb-modal-head { padding: 1rem 1.15rem 0.5rem; }
            .rb-modal-body { padding: 0.5rem 1.15rem 1.5rem; }
        }

        /* ── Filter animation on entry + when the sentinel is out ───
                Adds a subtle fade / lift so the docked pill trades
                places with the main filter instead of just appearing. */
        .rb-filters {
            transition: opacity 220ms ease, transform 220ms ease;
        }
        .rb-page.is-search-docked .rb-filters {
            /* Keep the main filter reachable but softly de-emphasize
               it while the docked pill is the primary affordance. */
            opacity: 0.55;
        }
        .rb-page.is-search-docked .rb-filters:hover,
        .rb-page.is-search-docked .rb-filters:focus-within {
            opacity: 1;
        }

        /* ── Eyebrow (italic display-serif with leading rule) ──── */
        .rb-eyebrow {
            display: inline-flex; align-items: center; gap: 10px;
            font-family: var(--display-font);
            font-style: italic;
            font-weight: 500;
            font-size: 14px;
            color: var(--primary);
        }
        .rb-eyebrow::before {
            content: '';
            display: inline-block;
            width: 14px;
            height: 1px;
            background: var(--primary);
        }

        /* ── Header (fallback when no hero photo) ───────────────── */
        .rb-header { padding: 2.5rem 0 1.5rem; }
        @media (min-width: 768px) { .rb-header { padding: 3.5rem 0 2rem; } }
        .rb-title {
            margin-top: 0.85rem;
            font-size: clamp(32px, 5vw, 52px);
            font-weight: 600;
            line-height: 1.04;
            letter-spacing: -0.025em;
        }
        .rb-subtitle {
            margin-top: 0.9rem;
            font-size: 15.5px;
            color: var(--rb-ink-soft);
            max-width: 620px;
            line-height: 1.6;
        }
        .rb-header-chips {
            margin-top: 1rem;
            display: flex; flex-wrap: wrap; gap: 8px;
        }
        .rb-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px;
            background: var(--rb-card);
            border: 1px solid var(--rb-line-soft);
            border-radius: 9999px;
            font-size: 12.5px; color: var(--rb-ink-soft);
        }
        .rb-chip.rb-chip-accent {
            background: var(--primary-soft);
            border-color: transparent;
            color: var(--primary-dark);
            font-weight: 500;
        }
        .rb-chip .star { color: var(--primary); }

        /* ── Photo-first hero (Travelio-inspired) ────────────────
                Renders when the property has a hero image. Full-bleed
                photograph with a soft-to-strong bottom gradient so the
                title + chips read cleanly regardless of image tone.
                Content anchors bottom-left with generous padding; the
                filter card that follows floats on top via negative
                margin so the two overlap for a layered feel. --}}
        */
        .rb-hero-photo {
            position: relative;
            width: 100%;
            min-height: 380px;
            display: flex;
            align-items: flex-end;
            background-color: #14171A;
            background-size: cover;
            background-position: center;
            color: #fff;
            overflow: hidden;
            border-radius: 0;
        }
        @media (min-width: 768px) {
            .rb-hero-photo { min-height: 520px; }
        }
        .rb-hero-photo::before {
            content: '';
            position: absolute; inset: 0;
            background:
                linear-gradient(180deg,
                    rgba(20, 23, 26, 0.35) 0%,
                    rgba(20, 23, 26, 0.20) 30%,
                    rgba(20, 23, 26, 0.55) 75%,
                    rgba(20, 23, 26, 0.85) 100%);
            z-index: 1;
        }
        .rb-hero-photo-inner {
            position: relative; z-index: 2;
            width: 100%;
            max-width: 1200px; margin: 0 auto;
            padding: 3rem 1.5rem 6rem;
        }
        @media (min-width: 768px) {
            .rb-hero-photo-inner { padding: 4rem 2.5rem 7.5rem; }
        }
        .rb-hero-photo .rb-eyebrow {
            color: rgba(255, 255, 255, 0.92);
        }
        .rb-hero-photo .rb-eyebrow::before {
            background: rgba(255, 255, 255, 0.75);
        }
        .rb-hero-photo .rb-title {
            color: #fff;
            font-size: clamp(36px, 6vw, 68px);
            text-shadow: 0 2px 24px rgba(0, 0, 0, 0.25);
            max-width: 18ch;
        }
        .rb-hero-photo .rb-subtitle {
            color: rgba(255, 252, 245, 0.85);
            max-width: 560px;
        }
        .rb-hero-photo .rb-chip {
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.22);
            color: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .rb-hero-photo .rb-chip.rb-chip-accent {
            background: rgba(255, 255, 255, 0.90);
            border-color: transparent;
            color: var(--primary-dark, #14171A);
        }
        .rb-hero-photo .rb-chip .star { color: var(--primary); }

        /* Filter card floats over the bottom edge of the hero photo.
           Landing-page-scoped so the /rooms browse's filter is untouched. */
        .rb-page.has-photo-hero .rb-filters {
            margin-top: -3.5rem;
            position: relative;
            z-index: 3;
            box-shadow: 0 40px 80px -32px rgba(20, 17, 12, 0.35);
        }
        @media (min-width: 768px) {
            .rb-page.has-photo-hero .rb-filters { margin-top: -4.5rem; }
        }
        .rb-page.has-photo-hero .rb-wrap.rb-wrap--filter { margin-top: 0; }

        /* ── Filter bar ─────────────────────────────────────────── */
        .rb-filters {
            margin-top: 1rem;
            background: var(--rb-card);
            border: 1px solid var(--rb-line);
            border-radius: 18px;
            padding: 1rem;
            box-shadow: 0 22px 50px -32px rgba(20,17,12,0.22);
        }
        .rb-filters-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2px;
            background: var(--rb-line-soft);
            border-radius: 12px;
            overflow: hidden;
        }
        @media (min-width: 720px) {
            .rb-filters-grid {
                grid-template-columns: 1.2fr 1.2fr 1fr 1fr auto;
                align-items: stretch;
            }
        }
        .rb-filter {
            background: var(--rb-card);
            padding: 12px 16px;
            display: flex; flex-direction: column; gap: 4px;
            position: relative;
        }
        .rb-filter-label {
            font-size: 10px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--rb-ink-mute);
            font-weight: 500;
        }
        .rb-filter input,
        .rb-filter select {
            font-family: inherit;
            font-size: 15px;
            color: var(--rb-ink);
            border: 0; outline: 0; padding: 0; margin: 0;
            background: transparent;
            width: 100%;
            line-height: 1.3;
            appearance: none; -webkit-appearance: none;
        }
        .rb-filter select {
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' fill='none' stroke='%237A7F87' stroke-width='1.5' stroke-linecap='round'><path d='M1 1l4 4 4-4'/></svg>");
            background-repeat: no-repeat;
            background-position: right 0 center;
            padding-right: 18px;
        }
        .rb-filter input[type="date"] { font-size: 14.5px; }
        .rb-filter:focus-within { background: var(--rb-paper); }

        .rb-filter-submit {
            background: var(--rb-card);
            padding: 8px;
            display: flex; align-items: center; justify-content: center;
        }
        .rb-pill {
            display: inline-flex; align-items: center; justify-content: space-between;
            gap: 12px;
            padding: 10px 10px 10px 22px;
            background: var(--rb-ink);
            color: var(--rb-card);
            border: 0;
            border-radius: 9999px;
            font-family: var(--body-font);
            font-size: 14px; font-weight: 500;
            cursor: pointer;
            transition: background 200ms, transform 80ms;
            white-space: nowrap;
        }
        .rb-pill:hover { background: var(--primary-dark); }
        .rb-pill:active { transform: translateY(1px); }
        .rb-pill .dot {
            width: 30px; height: 30px;
            background: var(--primary);
            color: #fff;
            border-radius: 9999px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        /* ── Results head + grid ─────────────────────────────────── */
        .rb-results-head {
            display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between;
            gap: 1rem;
            margin: 2.5rem 0 1.25rem;
        }
        .rb-results-count {
            font-family: var(--display-font);
            font-size: clamp(20px, 2.2vw, 26px);
            font-weight: 500;
            color: var(--rb-ink);
            letter-spacing: -0.015em;
        }
        .rb-results-count em {
            font-style: italic;
            color: var(--primary);
            font-weight: 500;
        }
        .rb-see-all {
            font-size: 13px; color: var(--rb-ink-soft);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .rb-see-all::after { content: '→'; color: var(--primary); }
        .rb-see-all:hover { color: var(--rb-ink); }

        .rb-grid {
            display: grid;
            gap: 1.5rem;
            grid-template-columns: 1fr;
        }
        @media (min-width: 720px)  { .rb-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1100px) { .rb-grid { grid-template-columns: repeat(3, 1fr); } }

        .rb-card {
            background: var(--rb-card);
            border: 1px solid var(--rb-line);
            border-radius: 18px;
            overflow: hidden;
            display: flex; flex-direction: column;
            transition: transform 240ms ease, box-shadow 240ms ease, border-color 200ms;
        }
        .rb-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 24px 48px -22px rgba(20,17,12,0.22);
            border-color: var(--rb-ink);
        }
        .rb-card-photo {
            aspect-ratio: 16 / 11;
            background:
                repeating-linear-gradient(45deg, rgba(20,23,26,0.05), rgba(20,23,26,0.05) 12px, transparent 12px, transparent 24px),
                var(--rb-paper-2);
            overflow: hidden;
            position: relative;
        }
        .rb-card-photo img {
            width: 100%; height: 100%; object-fit: cover;
            transition: transform 600ms cubic-bezier(0.16, 1, 0.3, 1);
        }
        .rb-card:hover .rb-card-photo img { transform: scale(1.04); }
        .rb-card-badge {
            position: absolute; top: 14px; left: 14px;
            display: inline-flex; align-items: center;
            padding: 4px 10px;
            background: var(--rb-card);
            color: var(--rb-ink);
            border-radius: 9999px;
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(20,17,12,0.12);
        }
        .rb-card-badge.rb-card-badge-full {
            background: rgba(140, 30, 30, 0.90); color: #fff;
        }
        .rb-card-body {
            padding: 1.25rem 1.4rem 1.5rem;
            display: flex; flex-direction: column; flex: 1;
            gap: 0.75rem;
        }
        .rb-card-eyebrow {
            font-family: var(--display-font);
            font-style: italic;
            font-weight: 500;
            font-size: 12.5px;
            color: var(--primary);
        }
        .rb-card-name {
            font-family: var(--display-font);
            font-weight: 600;
            font-size: 22px;
            line-height: 1.15;
            letter-spacing: -0.015em;
            color: var(--rb-ink);
        }
        .rb-card-meta {
            display: flex; flex-wrap: wrap; align-items: center;
            gap: 0.5rem 0.85rem;
            font-size: 13px;
            color: var(--rb-ink-soft);
        }
        .rb-card-meta .dot { color: var(--rb-ink-mute); }
        .rb-card-foot {
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid var(--rb-line-soft);
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 0.5rem;
        }
        .rb-card-price {
            font-family: var(--display-font);
            font-weight: 600;
            font-size: 22px;
            color: var(--rb-ink);
            letter-spacing: -0.015em;
        }
        .rb-card-price .from {
            font-family: var(--body-font);
            font-size: 11px;
            color: var(--rb-ink-mute);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-right: 4px;
        }
        .rb-card-price .per {
            font-family: var(--body-font);
            font-size: 12.5px;
            color: var(--rb-ink-mute);
            font-weight: 400;
        }
        .rb-card-cta {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--rb-ink);
            letter-spacing: 0.04em;
        }
        .rb-card-cta::after {
            content: '→';
            color: var(--primary);
            font-size: 14px; line-height: 1;
        }

        /* ── Empty state ────────────────────────────────────────── */
        .rb-empty {
            margin-top: 1rem;
            padding: 3.5rem 2rem;
            text-align: center;
            background: var(--rb-card);
            border: 1px solid var(--rb-line);
            border-radius: 18px;
        }
        .rb-empty h2 { font-size: clamp(20px, 2.2vw, 26px); margin-bottom: 0.75rem; }
        .rb-empty p { font-size: 14.5px; margin: 0 auto; max-width: 460px; }

        /* ── Trailing sections (Reviews / Contact / About) ─────────
                These render below the booking flow only if the operator
                has enabled them. Kept small so booking stays the hero. --}}
        */
        .rb-band {
            margin-top: 3rem;
            padding: 2.25rem 2rem;
            background: var(--rb-card);
            border: 1px solid var(--rb-line-soft);
            border-radius: 20px;
            box-shadow: 0 1px 2px rgba(20, 17, 12, 0.03);
        }
        @media (min-width: 768px) {
            .rb-band { padding: 2.5rem 2.25rem; }
        }
        .rb-band-head {
            display: flex; align-items: baseline; justify-content: space-between; gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .rb-band-head h2 {
            font-size: clamp(22px, 2.2vw, 28px);
            font-weight: 600;
            letter-spacing: -0.02em;
        }
        .rb-band p { font-size: 14.5px; line-height: 1.65; }
        .rb-band-meta { font-size: 12.5px; color: var(--rb-ink-mute); }

        .rb-review-list { display: grid; gap: 1rem; margin-top: 0.5rem; }
        @media (min-width: 720px) { .rb-review-list { grid-template-columns: 1fr 1fr; } }
        .rb-review {
            padding: 1rem 1.15rem;
            background: var(--rb-paper);
            border-radius: 12px;
            font-size: 13.5px; color: var(--rb-ink-soft);
        }
        .rb-review-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 0.5rem; margin-bottom: 0.5rem;
        }
        .rb-review-author { font-weight: 500; color: var(--rb-ink); font-size: 13px; }
        .rb-review-rating { color: var(--primary); font-size: 12.5px; }
        .rb-review-response {
            margin-top: 12px;
            padding: 10px 12px;
            background: color-mix(in srgb, var(--rb-ink, #14171A) 4%, transparent);
            border-left: 2px solid color-mix(in srgb, var(--primary, #14171A) 45%, transparent);
            border-radius: 0 6px 6px 0;
            font-size: 12.5px;
        }
        .rb-review-response-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; margin-bottom: 4px;
        }
        .rb-review-response-label {
            font-weight: 600; color: var(--rb-ink);
            letter-spacing: -0.005em;
        }
        .rb-review-response-head time {
            font-size: 11.5px; color: var(--rb-ink-mute, #6B7280);
        }
        .rb-review-response-body {
            margin: 0; color: color-mix(in srgb, var(--rb-ink, #14171A) 85%, transparent);
            line-height: 1.55;
        }

        .rb-contact-grid { display: grid; gap: 1rem; margin-top: 0.5rem; }
        @media (min-width: 720px) { .rb-contact-grid { grid-template-columns: repeat(3, 1fr); } }
        .rb-contact-tile {
            padding: 1rem 1.15rem;
            background: var(--rb-paper);
            border-radius: 12px;
        }
        .rb-contact-label {
            font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase;
            color: var(--rb-ink-mute); font-weight: 500;
        }
        .rb-contact-value {
            display: block; margin-top: 6px; font-size: 15px;
            color: var(--rb-ink); font-weight: 500;
        }
        .rb-contact-value a { text-decoration: underline; text-underline-offset: 3px; }

        /* ── "Good to know" tiles (check-in / out + late fee) ────── */
        .rb-gtk-grid { display: grid; gap: 1rem; margin-top: 0.5rem; }
        @media (min-width: 720px) { .rb-gtk-grid { grid-template-columns: repeat(3, 1fr); } }
        .rb-gtk-tile {
            padding: 1.1rem 1.25rem;
            background: var(--rb-paper);
            border-radius: 14px;
            display: flex; flex-direction: column; gap: 8px;
            border: 1px solid transparent;
            transition: border-color 160ms, background 160ms;
        }
        .rb-gtk-tile:hover {
            background: color-mix(in srgb, var(--rb-paper) 92%, var(--primary) 8%);
            border-color: var(--rb-line-soft);
        }
        .rb-gtk-tile--wide { grid-column: 1 / -1; }
        @media (min-width: 720px) {
            .rb-gtk-tile--wide { grid-column: span 3; }
        }
        .rb-gtk-label {
            font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase;
            color: var(--rb-ink-mute); font-weight: 500;
        }
        .rb-gtk-value {
            font-size: 14.5px; color: var(--rb-ink); line-height: 1.55;
        }

        /* ── WhatsApp FAB ────────────────────────────────────────── */
        .rb-whatsapp {
            position: fixed; right: 1.25rem; bottom: 1.25rem;
            z-index: 30;
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 16px 10px 12px;
            background: #25D366; color: #fff;
            border-radius: 9999px;
            font-size: 14px; font-weight: 500;
            box-shadow: 0 12px 30px rgba(37, 211, 102, 0.35);
        }
        .rb-whatsapp svg { flex-shrink: 0; }

        /* ── Footer ──────────────────────────────────────────────── */
        .rb-foot {
            margin-top: 4rem;
            padding: 1.75rem 0;
            border-top: 1px solid var(--rb-line-soft);
            font-size: 12.5px; color: var(--rb-ink-mute);
        }
        .rb-foot-inner {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 0.75rem;
        }
        .rb-foot a { text-decoration: underline; text-underline-offset: 3px; color: var(--rb-ink-soft); }

        /* ── Mobile polish ───────────────────────────────────────── */
        @media (max-width: 640px) {
            .rb-wrap { padding: 0 1.1rem; }
            .rb-header { padding: 1.75rem 0 1rem; }
            .rb-filters { padding: 0.5rem; border-radius: 14px; }
            .rb-filter { padding: 10px 13px; }
            .rb-filter-submit { padding: 8px 0 4px; }
            .rb-pill { width: 100%; justify-content: center; }
            .rb-grid { gap: 1.1rem; }
            .rb-card-photo { aspect-ratio: 4 / 3; }
            .rb-card-body { padding: 1rem 1.15rem 1.15rem; gap: 0.55rem; }
            .rb-card-name { font-size: 19px; }
            .rb-card-price { font-size: 19px; }
            .rb-band { padding: 1.5rem; }
        }
    </style>
</head>
<body>
    <div class="rb-page @if ($heroUrl) has-photo-hero @endif">

        {{-- ── Top bar ─────────────────────────────────────────────
             Docked search pill (data-search-docked) sits in the middle
             when the main filter has scrolled out of view. It's just
             a summary + a "jump back" button, click scrolls smoothly
             to the full filter so the guest can re-pick dates. Only
             renders for stay-type properties since workspace / resi
             don't use the date+guest widget. --}}
        <nav class="rb-topbar" data-testid="topbar">
            <div class="rb-topbar-inner">
                <a href="#top" class="rb-brand" aria-label="{{ $property->name }}">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $property->name }}" data-testid="logo">
                    @endif
                    <span>{{ $property->name }}</span>
                </a>
                @if ($kind === 'stay')
                    <button type="button" class="rb-topbar-search"
                            data-search-docked
                            data-modal-open
                            aria-haspopup="dialog"
                            aria-controls="search-modal"
                            data-testid="topbar-search-docked"
                            aria-label="Change your search">
                        <span class="rb-tsearch-summary">
                            <span data-summary-dates>Any dates</span>
                            <span class="dot">·</span>
                            <span class="muted" data-summary-guests>2 guests</span>
                        </span>
                        <span class="rb-tsearch-btn" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"
                                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="9" cy="9" r="6"/>
                                <path d="M17 17l-3.5-3.5"/>
                            </svg>
                        </span>
                    </button>
                @endif
                <div class="rb-topbar-links">
                    @include('tenant.partials.branch-switcher', [
                        'branches' => $branches ?? collect(),
                        'current' => $property,
                    ])
                    <a href="{{ route($browseRoute) }}">{{ $navInventoryLabel }} &rarr;</a>
                </div>
            </div>
        </nav>

        <span id="top"></span>

        {{-- ── Photo hero (Travelio-inspired) ────────────────────────
             Renders when the property has a hero image. Same content
             as the compact header below, just typography inverted for
             legibility on the darkened photo. When there's no image,
             the fallback compact header inside .rb-wrap runs instead
             so we don't ship an empty grey rectangle. --}}
        @php
            $heroContent = function () use ($property, $rating, $priceFrom, $pricePer, $kind) {
                ob_start();
                ?>
                <span class="rb-eyebrow">
                    <?php switch ($kind) {
                        case 'workspace':   echo 'Book a workspace'; break;
                        case 'residential': echo 'Available homes'; break;
                        default:            echo 'Stay with us';
                    } ?>
                </span>
                <h1 class="rb-title" data-testid="property-name"><?= e($property->name) ?></h1>
                <?php if ($property->tagline): ?>
                    <p class="rb-subtitle" data-testid="tagline"><?= e($property->tagline) ?></p>
                <?php endif; ?>
                <div class="rb-header-chips">
                    <?php if ($rating['count'] > 0): ?>
                        <span class="rb-chip rb-chip-accent" data-testid="rating-badge">
                            <span class="star">★</span>
                            <?= e($rating['average']) ?>
                            <span style="opacity: 0.7; font-weight: 400;">
                                &middot; <?= $rating['count'] ?> review<?= $rating['count'] === 1 ? '' : 's' ?>
                            </span>
                        </span>
                    <?php endif; ?>
                    <?php if ($property->city): ?>
                        <span class="rb-chip">
                            <?= e($property->city) ?><?= $property->state ? ', '.e($property->state) : '' ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($priceFrom):
                        $priceSuffix = match ($pricePer) {
                            'hour'  => '/ hour',
                            'day'   => '/ day',
                            'week'  => '/ week',
                            'month' => '/ month',
                            'year'  => '/ year',
                            default => '/ night',
                        }; ?>
                        <span class="rb-chip" data-testid="price-from-badge">
                            From ₦<?= number_format($priceFrom) ?> <?= $priceSuffix ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php
                return ob_get_clean();
            };
        @endphp

        @if ($heroUrl)
            <section class="rb-hero-photo" data-testid="hero"
                     style="background-image: url('{{ $heroUrl }}');">
                <div class="rb-hero-photo-inner">
                    {!! $heroContent() !!}
                </div>
            </section>
        @endif

        <div class="rb-wrap">

            @unless ($heroUrl)
                {{-- Compact fallback header when the operator hasn't
                     uploaded a hero yet. Same test hook so nothing
                     downstream cares which mode ran. --}}
                <header class="rb-header" data-testid="hero">
                    {!! $heroContent() !!}
                </header>
            @endunless

            {{-- ── Booking / filter bar ──────────────────────────────
                 Stay-type properties get date+guest fields that GET-post
                 straight into the /rooms browse (which honours these as
                 URL params via #[Url] on RoomsBrowse). Workspace + resi
                 get a single-button jump since dates play differently. --}}
            @if ($kind === 'stay')
                {{-- Named anchor + IntersectionObserver sentinel.
                     When #book-sentinel scrolls out of view, the topbar
                     shows its docked search pill; when it re-enters,
                     the pill hides again. The anchor lets the pill's
                     href="#book" scroll the guest smoothly back here. --}}
                <span id="book"></span>
                <form method="get" action="{{ route('tenant.rooms.browse') }}"
                      class="rb-filters" data-testid="landing-filter-form"
                      data-main-filter>
                    <div class="rb-filters-grid">
                        <label class="rb-filter">
                            <span class="rb-filter-label">Check-in</span>
                            <input type="date" name="in"
                                   value="{{ $today }}" min="{{ $today }}"
                                   data-testid="filter-checkin">
                        </label>
                        <label class="rb-filter">
                            <span class="rb-filter-label">Check-out</span>
                            <input type="date" name="out"
                                   value="{{ $tomorrow }}" min="{{ $tomorrow }}"
                                   data-testid="filter-checkout">
                        </label>
                        <label class="rb-filter">
                            <span class="rb-filter-label">Adults</span>
                            <select name="adults" data-testid="filter-adults">
                                @for ($i = 1; $i <= 8; $i++)
                                    <option value="{{ $i }}" @selected($i === 2)>{{ $i }} adult{{ $i === 1 ? '' : 's' }}</option>
                                @endfor
                            </select>
                        </label>
                        <label class="rb-filter">
                            <span class="rb-filter-label">Children</span>
                            <select name="children" data-testid="filter-children">
                                @for ($i = 0; $i <= 6; $i++)
                                    <option value="{{ $i }}">{{ $i }} child{{ $i === 1 ? '' : 'ren' }}</option>
                                @endfor
                            </select>
                        </label>
                        <div class="rb-filter-submit">
                            <button type="submit" class="rb-pill" data-testid="book-cta">
                                <span>Check availability</span>
                                <span class="dot">{!! $pillArrow !!}</span>
                            </button>
                        </div>
                    </div>
                </form>
            @else
                <div style="margin-top: 1rem; display: flex; justify-content: flex-start;">
                    <a href="{{ route($browseRoute) }}" class="rb-pill" data-testid="book-cta">
                        <span>{{ $browseLabel }}</span>
                        <span class="dot">{!! $pillArrow !!}</span>
                    </a>
                </div>
            @endif

            {{-- ── Results head + grid ───────────────────────────────
                 Per-kind: stay renders category cards, workspace renders
                 plan/space cards, residential renders unit cards. Same
                 .rb-card shell for each so the visual language holds. --}}
            @if ($kind === 'stay')
                @php $totalRoomCount = $categories->sum('total'); @endphp
                <div class="rb-results-head">
                    <p class="rb-results-count">
                        @if ($categories->isEmpty())
                            No rooms <em>yet.</em>
                        @else
                            {{ $categories->count() }} {{ $categories->count() === 1 ? 'category' : 'categories' }}
                            <em>&middot; {{ $totalRoomCount }} unit{{ $totalRoomCount === 1 ? '' : 's' }}</em>
                        @endif
                    </p>
                    @if ($categories->isNotEmpty())
                        <a href="{{ route('tenant.rooms.browse') }}" class="rb-see-all">
                            See all &amp; filter by dates
                        </a>
                    @endif
                </div>

                @if ($categories->isEmpty())
                    <section class="rb-empty" data-testid="no-rooms">
                        <h2>Rooms coming soon.</h2>
                        <p>{{ $property->name }} hasn&rsquo;t published any bookable rooms yet.
                            Check back shortly.</p>
                    </section>
                @else
                    <div class="rb-grid" data-testid="rooms-grid">
                        @foreach ($categories as $entry)
                            @php
                                $category = $entry->model;
                                $hero = $entry->hero;
                                $total = $entry->total;
                                $available = $entry->available;
                                $isSoldOut = $available === 0;
                                $availLabel = $isSoldOut
                                    ? 'Fully booked today'
                                    : ($available === 1 ? '1 room left today' : $available.' of '.$total.' left today');
                            @endphp
                            <a href="{{ route('tenant.categories.show', $category) }}"
                               class="rb-card" data-testid="category-card-{{ $category->id }}">
                                <div class="rb-card-photo">
                                    @if ($hero)
                                        <img src="{{ $hero->getUrl('medium') }}"
                                             alt="{{ $hero->getCustomProperty('alt') ?: $category->name }}"
                                             loading="lazy">
                                    @endif
                                    <span class="rb-card-badge {{ $isSoldOut ? 'rb-card-badge-full' : '' }}"
                                          data-testid="availability-badge-{{ $category->id }}">
                                        {{ $availLabel }}
                                    </span>
                                </div>
                                <div class="rb-card-body">
                                    <span class="rb-card-eyebrow">{{ $category->type }}</span>
                                    <h3 class="rb-card-name">{{ $category->name }}</h3>
                                    <div class="rb-card-meta">
                                        <span>Sleeps {{ $category->maxOccupancy() }}</span>
                                        <span class="dot">·</span>
                                        <span>Min {{ $category->min_nights }} night{{ $category->min_nights === 1 ? '' : 's' }}</span>
                                    </div>
                                    <div class="rb-card-foot">
                                        <span class="rb-card-price">
                                            <span class="from">From</span>
                                            ₦{{ number_format($category->base_rate) }}<span class="per"> / night</span>
                                        </span>
                                        <span class="rb-card-cta">{{ $isSoldOut ? 'Check dates' : 'View' }}</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif

            @elseif ($kind === 'workspace')
                <div class="rb-results-head">
                    <p class="rb-results-count">
                        @if ($plans->isEmpty() && $workspaces->isEmpty())
                            Plans <em>coming soon.</em>
                        @else
                            {{ $plans->count() }} plan{{ $plans->count() === 1 ? '' : 's' }}
                            <em>&middot; {{ $workspaces->count() }} space{{ $workspaces->count() === 1 ? '' : 's' }}</em>
                        @endif
                    </p>
                </div>

                @if ($plans->isEmpty() && $workspaces->isEmpty())
                    <section class="rb-empty" data-testid="no-rooms">
                        <h2>Plans coming soon.</h2>
                        <p>Get in touch below and we&rsquo;ll come back to you with pricing.</p>
                    </section>
                @else
                    <div class="rb-grid">
                        @foreach ($plans as $plan)
                            <a href="{{ route('tenant.workspaces.browse') }}" class="rb-card"
                               data-testid="plan-card-{{ $plan->id }}">
                                <div class="rb-card-body" style="padding-top: 1.5rem;">
                                    <span class="rb-card-eyebrow">{{ $plan->billing_interval->label() }}</span>
                                    <h3 class="rb-card-name">{{ $plan->name }}</h3>
                                    @if ($plan->description)
                                        <div class="rb-card-meta">
                                            <span>{{ \Illuminate\Support\Str::limit($plan->description, 120) }}</span>
                                        </div>
                                    @endif
                                    <div class="rb-card-foot">
                                        <span class="rb-card-price">
                                            ₦{{ number_format($plan->price_naira) }}<span class="per">{{ $plan->billing_interval->suffix() }}</span>
                                        </span>
                                        <span class="rb-card-cta">Enquire</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                        @foreach ($workspaces as $ws)
                            @php
                                $avail = $ws->availabilitySummary();
                                $isFull = $avail['status'] === 'full';
                            @endphp
                            <a href="{{ route('tenant.workspaces.browse') }}" class="rb-card"
                               data-testid="ws-card-{{ $ws->id }}">
                                <div class="rb-card-photo">
                                    <span class="rb-card-badge {{ $isFull ? 'rb-card-badge-full' : '' }}">
                                        {{ $isFull ? 'Fully subscribed' : $avail['available'].' free' }}
                                    </span>
                                </div>
                                <div class="rb-card-body">
                                    <span class="rb-card-eyebrow">{{ $ws->type->label() }}</span>
                                    <h3 class="rb-card-name">{{ $ws->name }}</h3>
                                    <div class="rb-card-meta">
                                        <span>Capacity {{ $ws->capacity }}</span>
                                    </div>
                                    <div class="rb-card-foot">
                                        <span class="rb-card-price">
                                            @if ($ws->hourly_rate_naira)
                                                ₦{{ number_format($ws->hourly_rate_naira) }}<span class="per"> / hour</span>
                                            @elseif ($ws->daily_rate_naira)
                                                ₦{{ number_format($ws->daily_rate_naira) }}<span class="per"> / day</span>
                                            @endif
                                        </span>
                                        <span class="rb-card-cta">{{ $isFull ? 'Waitlist' : 'View' }}</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif

            @elseif ($kind === 'residential')
                <div class="rb-results-head">
                    <p class="rb-results-count">
                        @if ($units->isEmpty())
                            No units <em>available right now.</em>
                        @else
                            {{ $units->count() }} home{{ $units->count() === 1 ? '' : 's' }}
                            <em>&middot; from ₦{{ number_format($priceFrom ?? 0) }} / year</em>
                        @endif
                    </p>
                </div>

                @if ($units->isEmpty())
                    <section class="rb-empty" data-testid="no-rooms">
                        <h2>No units currently available.</h2>
                        <p>Register your interest via the form below and we&rsquo;ll notify you.</p>
                    </section>
                @else
                    <div class="rb-grid">
                        @foreach ($units as $unit)
                            @php
                                $avail = $unit->availabilitySummary();
                                $isLeased = $avail['status'] !== 'available';
                            @endphp
                            <a href="{{ route('tenant.units.browse') }}" class="rb-card"
                               data-testid="unit-card-{{ $unit->id }}">
                                <div class="rb-card-photo">
                                    <span class="rb-card-badge {{ $isLeased ? 'rb-card-badge-full' : '' }}">
                                        {{ $avail['label'] }}
                                    </span>
                                </div>
                                <div class="rb-card-body">
                                    <span class="rb-card-eyebrow">{{ $unit->bedrooms }} bed / {{ $unit->bathrooms }} bath</span>
                                    <h3 class="rb-card-name">{{ $unit->name }}</h3>
                                    <div class="rb-card-meta">
                                        <span>{{ $unit->address }}{{ $unit->city ? ', '.$unit->city : '' }}</span>
                                    </div>
                                    <div class="rb-card-foot">
                                        <span class="rb-card-price">
                                            ₦{{ number_format($unit->annual_rent_naira) }}<span class="per"> / year</span>
                                        </span>
                                        <span class="rb-card-cta">{{ $isLeased ? 'Waitlist' : 'Enquire' }}</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif

            {{-- ── Reviews (optional, small) ────────────────────────
                 The landing is booking-first, so reviews render as a
                 compact trailing band only when the operator has both
                 enabled the section AND has reviews to show. --}}
            @if ($property->isSectionVisible('reviews') && $reviews->isNotEmpty())
                <section class="rb-band" id="reviews" data-testid="reviews-section">
                    <div class="rb-band-head">
                        <h2>Guests say</h2>
                        <span class="rb-band-meta">
                            {{ $reviews->count() }} review{{ $reviews->count() === 1 ? '' : 's' }}
                            @if ($rating['count'] > 0)
                                &middot; <span style="color: var(--primary);">★</span> {{ $rating['average'] }}
                            @endif
                        </span>
                    </div>
                    <div class="rb-review-list">
                        @foreach ($reviews->take(4) as $review)
                            @php
                                $chColors = $review->channel?->badgeColors() ?? ['bg' => '#E5E7EB', 'ink' => '#374151'];
                            @endphp
                            <div class="rb-review" data-testid="review-{{ $review->id }}">
                                <div class="rb-review-head" style="display: flex; align-items: flex-start; gap: 12px;">
                                    @if ($review->photo_path)
                                        <img src="{{ $review->photoUrl() }}"
                                             alt="{{ $review->author_name ?? 'Reviewer' }}"
                                             loading="lazy"
                                             data-testid="review-photo-{{ $review->id }}"
                                             style="width: 40px; height: 40px; border-radius: 999px; object-fit: cover; flex-shrink: 0;">
                                    @else
                                        <span aria-hidden="true"
                                              style="width: 40px; height: 40px; border-radius: 999px; background: var(--rb-card-deep, #F1EBE0); color: var(--rb-ink-mute); display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; flex-shrink: 0;">
                                            {{ \Illuminate\Support\Str::of($review->author_name ?? 'A')->substr(0, 1)->upper() }}
                                        </span>
                                    @endif
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                            <div>
                                                <span class="rb-review-author">{{ $review->author_name ?? 'Anonymous' }}</span>
                                                @if (! empty($review->guest_country))
                                                    @php $reviewFlag = \App\Services\Locale\Countries::flagFor($review->guest_country); @endphp
                                                    <span style="font-size: 11px; color: var(--rb-ink-mute); margin-left: 6px;">
                                                        · @if ($reviewFlag)<span style="font-size: 13px; line-height: 1;">{{ $reviewFlag }}</span> @endif{{ $review->guest_country }}
                                                    </span>
                                                @endif
                                            </div>
                                            @if ($review->rating)
                                                <span class="rb-review-rating" style="white-space: nowrap;">
                                                    ★ {{ number_format($review->rating, 1) }}
                                                </span>
                                            @endif
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap;">
                                            <span style="font-size: 10px; padding: 2px 8px; border-radius: 999px; letter-spacing: 0.06em; text-transform: uppercase;
                                                         background: {{ $chColors['bg'] }}; color: {{ $chColors['ink'] }}; font-weight: 600;"
                                                  data-testid="review-channel-{{ $review->id }}">
                                                {{ ($review->channel ?? \App\Enums\ReviewChannel::Direct)->label() }}
                                            </span>
                                            @if ($review->published_at)
                                                <time datetime="{{ $review->published_at->toIso8601String() }}"
                                                      style="font-size: 11px; color: var(--rb-ink-mute);">
                                                    {{ $review->published_at->format('M Y') }}
                                                </time>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <p style="margin-top: 12px;">{{ \Illuminate\Support\Str::limit($review->body, 220) }}</p>
                                @if ($review->hasResponse())
                                    <div class="rb-review-response"
                                         data-testid="review-response-{{ $review->id }}">
                                        <div class="rb-review-response-head">
                                            <span class="rb-review-response-label">
                                                Response from {{ $property->name }}
                                            </span>
                                            @if ($review->responded_at)
                                                <time datetime="{{ $review->responded_at->toIso8601String() }}">
                                                    {{ $review->responded_at->diffForHumans() }}
                                                </time>
                                            @endif
                                        </div>
                                        <p class="rb-review-response-body">{{ \Illuminate\Support\Str::limit($review->response_body, 260) }}</p>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ── Contact (optional, compact) ────────────────────── --}}
            @if ($property->isSectionVisible('contact_block'))
                <section class="rb-band" id="contact" data-testid="contact-section">
                    <div class="rb-band-head">
                        <h2>Contact the property</h2>
                        <span class="rb-band-meta">We reply within an hour during opening times.</span>
                    </div>
                    <div class="rb-contact-grid">
                        @if ($tenant->phone)
                            <div class="rb-contact-tile">
                                <span class="rb-contact-label">Call</span>
                                <span class="rb-contact-value">
                                    <a href="tel:{{ $tenant->phone }}">{{ $tenant->phone }}</a>
                                </span>
                            </div>
                        @endif
                        @if ($tenant->email)
                            <div class="rb-contact-tile">
                                <span class="rb-contact-label">Email</span>
                                <span class="rb-contact-value">
                                    <a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a>
                                </span>
                            </div>
                        @endif
                        @if ($property->city)
                            <div class="rb-contact-tile">
                                <span class="rb-contact-label">Address</span>
                                <span class="rb-contact-value" style="font-weight: 400;">
                                    {{ $property->city }}{{ $property->state ? ', '.$property->state : '' }}
                                </span>
                            </div>
                        @endif
                    </div>
                </section>
            @endif

            {{-- ── About (optional, compact) ────────────────────────
                 Only renders when the operator has both enabled the
                 About section and written a description. Everything
                 else about-adjacent (amenities, nearby, gallery) is
                 dropped from the landing, the goal is booking, not
                 a mini-website. Operators who want the marketing content
                 can surface it via the mini-CMS Pages feature. --}}
            @if ($property->isSectionVisible('about') && $property->description)
                <section class="rb-band" id="about" data-testid="about">
                    <div class="rb-band-head">
                        <h2>About {{ $property->name }}</h2>
                    </div>
                    {{-- Rendered unescaped because the description is
                         saved through HtmlSanitizer, which allow-lists
                         a tiny set of tags (b/i/u/a/p/br/ul/ol/li) and
                         forces safe rel + target on anchors. --}}
                    <div class="rb-body-html">{!! $property->description !!}</div>
                </section>
            @endif

            {{-- ── Good to know (check-in / out + late-checkout fee) ─
                 Compact "know before you book" tile band. Shows the
                 arrival window, departure window, and - when the
                 operator has enabled it - the late-checkout policy in
                 plain English. Renders only when at least one field
                 is populated so a bare property doesn't show an empty
                 band. The rest of the old house-rules copy (pets,
                 smoking, cancellation prose) intentionally isn't here;
                 those belong on the Pages CMS. --}}
            @php
                $rules = $property->houseRules();
                $checkoutPolicy = $property->checkoutPolicy();
                $formatTime = static function (string $t): string {
                    if ($t === '' || ! preg_match('/^(\d{1,2}):(\d{2})$/', $t)) {
                        return '';
                    }
                    try {
                        return \Carbon\Carbon::createFromFormat('H:i', $t)->format('g:i A');
                    } catch (\Throwable) {
                        return '';
                    }
                };
                $checkInFrom = $formatTime($rules['check_in_from']);
                $checkInTo = $formatTime($rules['check_in_to']);
                $checkOutFrom = $formatTime($rules['check_out_from']);
                $checkOutTo = $formatTime($rules['check_out_to']);
                $checkInText = match (true) {
                    $checkInFrom !== '' && $checkInTo !== '' => "{$checkInFrom} - {$checkInTo}",
                    $checkInFrom !== '' => "from {$checkInFrom}",
                    $checkInTo !== '' => "until {$checkInTo}",
                    default => '',
                };
                $checkOutText = match (true) {
                    $checkOutFrom !== '' && $checkOutTo !== '' => "{$checkOutFrom} - {$checkOutTo}",
                    $checkOutFrom !== '' => "from {$checkOutFrom}",
                    $checkOutTo !== '' => "until {$checkOutTo}",
                    default => '',
                };
                $lateFeeEnabled = $checkoutPolicy['late_checkout_enabled']
                    && ($checkoutPolicy['late_checkout_hourly_fee'] > 0
                        || $checkoutPolicy['late_checkout_full_day_after_hours'] > 0);
                $lateFeeLines = [];
                if ($lateFeeEnabled) {
                    $deadline = $checkOutTo !== '' ? $checkOutTo : ($checkOutFrom !== '' ? $checkOutFrom : 'the end of check-out');
                    if ($checkoutPolicy['late_checkout_hourly_fee'] > 0) {
                        $lateFeeLines[] = '₦'.number_format($checkoutPolicy['late_checkout_hourly_fee']).'/hour past '.$deadline
                            .($checkoutPolicy['late_checkout_grace_minutes'] > 0
                                ? ' ('.$checkoutPolicy['late_checkout_grace_minutes'].'-min grace)'
                                : '')
                            .'.';
                    }
                    if ($checkoutPolicy['late_checkout_full_day_after_hours'] > 0) {
                        $lateFeeLines[] = 'Stay '.$checkoutPolicy['late_checkout_full_day_after_hours'].'+ hours late and the full room rate is charged.';
                    }
                }
                $hasGoodToKnow = $checkInText !== '' || $checkOutText !== '' || $lateFeeEnabled;
            @endphp
            @if ($hasGoodToKnow)
                <section class="rb-band" id="good-to-know" data-testid="good-to-know">
                    <div class="rb-band-head">
                        <h2>Good to know</h2>
                        <span class="rb-band-meta">Worth a look before you book.</span>
                    </div>
                    <div class="rb-gtk-grid">
                        @if ($checkInText !== '')
                            <div class="rb-gtk-tile" data-testid="gtk-checkin">
                                <span class="rb-gtk-label">Check-in</span>
                                <span class="rb-gtk-value">{{ $checkInText }}</span>
                            </div>
                        @endif
                        @if ($checkOutText !== '')
                            <div class="rb-gtk-tile" data-testid="gtk-checkout">
                                <span class="rb-gtk-label">Check-out</span>
                                <span class="rb-gtk-value">{{ $checkOutText }}</span>
                            </div>
                        @endif
                        @if ($lateFeeEnabled)
                            <div class="rb-gtk-tile rb-gtk-tile--wide" data-testid="gtk-late-fee">
                                <span class="rb-gtk-label">Late check-out fee</span>
                                <span class="rb-gtk-value">{{ implode(' ', $lateFeeLines) }}</span>
                            </div>
                        @endif
                    </div>
                </section>
            @endif

            {{-- ── Footer ───────────────────────────────────────────── --}}
            <footer class="rb-foot" data-testid="footer">
                <div class="rb-foot-inner">
                    <span>© {{ date('Y') }} {{ $property->name }}.</span>
                    <span style="display: inline-flex; gap: 14px; flex-wrap: wrap;">
                        <a href="https://keverna.com/privacy" target="_blank" rel="noopener">Privacy</a>
                        <a href="https://keverna.com/terms" target="_blank" rel="noopener">Terms</a>
                        <a href="https://keverna.com/cookies" target="_blank" rel="noopener">Cookies</a>
                    </span>
                    <span style="display: inline-flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        {{-- NDPR trustmark inherited from Keverna's parent
                             (FregateLab). Small pill so it fits the
                             tenant footer without dominating. --}}
                        <a href="https://keverna.com/ndpr" target="_blank" rel="noopener"
                           aria-label="NDPR compliance trustmark - view certificate"
                           style="display: inline-flex; align-items: center; gap: 8px;
                                  padding: 5px 10px 5px 6px;
                                  background: rgba(20, 23, 26, 0.04);
                                  border: 1px solid rgba(20, 23, 26, 0.08);
                                  border-radius: 999px; text-decoration: none;">
                            <img src="https://fregatelab-file.dokwe.com/trust-badge-only.png"
                                 alt="NDPR compliance"
                                 style="width: 22px; height: 22px; display: block; object-fit: contain;"
                                 loading="lazy">
                            <span style="font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase;
                                         font-weight: 600; color: var(--rb-ink); text-decoration: none;">
                                NDPR audited
                            </span>
                        </a>
                        <span>Powered by <a href="https://keverna.com" target="_blank" rel="noopener">Keverna</a>.</span>
                    </span>
                </div>
            </footer>
        </div>

        {{-- ── WhatsApp FAB ─────────────────────────────────────────
             Gated on BOTH the property's whatsapp_button_enabled toggle
             AND the tenant having a phone number, same contract as the
             previous design. --}}
        @if ($property->whatsapp_button_enabled && $tenant->phone)
            @php
                $waNumber = preg_replace('/\D+/', '', $tenant->phone);
                $waPrefill = $property->whatsapp_button_prefill ?? null;
                $waLink = 'https://wa.me/'.$waNumber
                    . ($waPrefill ? '?text='.rawurlencode($waPrefill) : '');
            @endphp
            <a href="{{ $waLink }}"
               class="rb-whatsapp" data-testid="whatsapp-button"
               target="_blank" rel="noopener">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M20.5 3.5A11.9 11.9 0 0 0 12 0C5.4 0 0 5.4 0 12c0 2.1.6 4.1 1.6 5.9L0 24l6.3-1.6A11.9 11.9 0 0 0 12 24c6.6 0 12-5.4 12-12 0-3.2-1.3-6.2-3.5-8.5zM12 22a10 10 0 0 1-5.1-1.4l-.4-.2-3.8 1 1-3.7-.2-.4A10 10 0 1 1 22 12c0 5.5-4.5 10-10 10zm5.5-7.5c-.3-.2-1.8-.9-2.1-1s-.5-.2-.7.2c-.2.3-.8 1-1 1.2-.2.2-.4.2-.7 0-.3-.2-1.3-.5-2.5-1.5-.9-.8-1.5-1.9-1.7-2.2-.2-.3 0-.5.1-.7l.5-.6c.2-.2.2-.3.3-.5.1-.2 0-.4 0-.6l-.7-1.7c-.2-.4-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.4-.3.3-1 1-1 2.5s1 2.9 1.2 3.1c.1.2 2 3.1 5 4.3.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4.2-.7.2-1.3.2-1.4-.1-.1-.3-.2-.6-.4z"/>
                </svg>
                <span>Chat on WhatsApp</span>
            </a>
        @endif

        {{-- ── Search modal ────────────────────────────────────────
             Opens when the docked pill in the topbar is clicked. Same
             4-field layout as the main filter, seeded from the current
             values so the guest sees their prior picks. Submits as a
             plain GET form to /rooms, RoomsBrowse picks up the query
             params via its #[Url] props. Stay-type only, since only
             stay uses the date+guest widget. --}}
        @if ($kind === 'stay')
            <div class="rb-modal-backdrop"
                 id="search-modal"
                 data-modal-backdrop
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="search-modal-title"
                 aria-hidden="true"
                 data-testid="search-modal">
                <div class="rb-modal-panel" role="document">
                    <div class="rb-modal-head">
                        <h2 id="search-modal-title">Check availability</h2>
                        <button type="button"
                                class="rb-modal-close"
                                data-modal-close
                                aria-label="Close">
                            &times;
                        </button>
                    </div>
                    <div class="rb-modal-body">
                        <form method="get" action="{{ route('tenant.rooms.browse') }}"
                              class="rb-filters"
                              data-modal-filter
                              data-testid="modal-filter-form">
                            <div class="rb-filters-grid">
                                <label class="rb-filter">
                                    <span class="rb-filter-label">Check-in</span>
                                    <input type="date" name="in"
                                           value="{{ $today }}" min="{{ $today }}">
                                </label>
                                <label class="rb-filter">
                                    <span class="rb-filter-label">Check-out</span>
                                    <input type="date" name="out"
                                           value="{{ $tomorrow }}" min="{{ $tomorrow }}">
                                </label>
                                <label class="rb-filter">
                                    <span class="rb-filter-label">Adults</span>
                                    <select name="adults">
                                        @for ($i = 1; $i <= 8; $i++)
                                            <option value="{{ $i }}" @selected($i === 2)>{{ $i }} adult{{ $i === 1 ? '' : 's' }}</option>
                                        @endfor
                                    </select>
                                </label>
                                <label class="rb-filter">
                                    <span class="rb-filter-label">Children</span>
                                    <select name="children">
                                        @for ($i = 0; $i <= 6; $i++)
                                            <option value="{{ $i }}">{{ $i }} child{{ $i === 1 ? '' : 'ren' }}</option>
                                        @endfor
                                    </select>
                                </label>
                                <div class="rb-filter-submit">
                                    <button type="submit" class="rb-pill" data-testid="modal-book-cta">
                                        <span>Search</span>
                                        <span class="dot">{!! $pillArrow !!}</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- ── Docked-search behaviour ─────────────────────────────────
         Vanilla JS (no Alpine dependency on this page). Two jobs:

         1. Toggle .is-search-docked on .rb-page based on whether the
            main filter card is still on-screen. Uses IntersectionObserver
            with rootMargin: -60px 0 0 so the docked pill appears when
            the filter has scrolled about a nav-bar's worth past the top,
            not the instant its bottom edge crosses the viewport line.

         2. Mirror the current filter values into the docked pill so
            the summary shows the picked dates + guest count. Updates
            live as the guest edits the main filter, so scrolling down
            and up shows the current state either way. --}}
    <script>
        (function () {
            var page   = document.querySelector('.rb-page');
            var filter = document.querySelector('[data-main-filter]');
            var docked = document.querySelector('[data-search-docked]');
            if (!page || !filter || !docked) return;

            // ── Docking observer ────────────────────────────────────
            // rootMargin's top offset roughly matches the sticky topbar
            // so the pill appears as the filter reaches the header,
            // not before or after.
            if ('IntersectionObserver' in window) {
                var io = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        page.classList.toggle('is-search-docked', ! entry.isIntersecting);
                    });
                }, { rootMargin: '-70px 0px 0px 0px', threshold: 0 });
                io.observe(filter);
            }

            // ── Summary mirror ──────────────────────────────────────
            var inEl       = filter.querySelector('[name="in"]');
            var outEl      = filter.querySelector('[name="out"]');
            var adultsEl   = filter.querySelector('[name="adults"]');
            var childrenEl = filter.querySelector('[name="children"]');
            var datesOut   = docked.querySelector('[data-summary-dates]');
            var guestsOut  = docked.querySelector('[data-summary-guests]');

            var monthShort = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var fmt = function (iso) {
                if (! iso) return null;
                var d = new Date(iso + 'T00:00:00');
                if (isNaN(d.getTime())) return null;
                return d.getDate() + ' ' + monthShort[d.getMonth()];
            };

            var refresh = function () {
                if (datesOut) {
                    var a = fmt(inEl && inEl.value);
                    var b = fmt(outEl && outEl.value);
                    datesOut.textContent = (a && b) ? (a + ' → ' + b) : 'Any dates';
                }
                if (guestsOut) {
                    var g = (parseInt(adultsEl && adultsEl.value, 10) || 0)
                          + (parseInt(childrenEl && childrenEl.value, 10) || 0);
                    guestsOut.textContent = g === 1 ? '1 guest' : g + ' guests';
                }
            };

            [inEl, outEl, adultsEl, childrenEl].forEach(function (el) {
                if (el) el.addEventListener('change', refresh);
                if (el) el.addEventListener('input',  refresh);
            });
            refresh();

            // ── Search modal open/close ─────────────────────────────
            // Every [data-modal-open] toggles the backdrop's is-open
            // class + syncs the modal form's field values from the
            // main filter each time. Close on: X button, backdrop
            // click, Escape key. Body scroll is locked while open so
            // the sheet feels like a real dialog on mobile.
            var backdrop     = document.querySelector('[data-modal-backdrop]');
            var modalFilter  = document.querySelector('[data-modal-filter]');
            var lastFocused  = null;

            var seedModalFromMain = function () {
                if (! modalFilter) return;
                ['in', 'out', 'adults', 'children'].forEach(function (name) {
                    var src = filter.querySelector('[name="' + name + '"]');
                    var dst = modalFilter.querySelector('[name="' + name + '"]');
                    if (src && dst) dst.value = src.value;
                });
            };

            var openModal = function () {
                if (! backdrop) return;
                seedModalFromMain();
                lastFocused = document.activeElement;
                backdrop.classList.add('is-open');
                backdrop.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                // Focus the first input for keyboard-first flow.
                var firstInput = modalFilter && modalFilter.querySelector('input, select, button');
                if (firstInput) setTimeout(function () { firstInput.focus(); }, 60);
            };

            var closeModal = function () {
                if (! backdrop || ! backdrop.classList.contains('is-open')) return;
                backdrop.classList.remove('is-open');
                backdrop.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                if (lastFocused && typeof lastFocused.focus === 'function') {
                    lastFocused.focus();
                }
            };

            document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    openModal();
                });
            });
            document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
                btn.addEventListener('click', closeModal);
            });
            if (backdrop) {
                // Backdrop click (but not panel click) closes.
                backdrop.addEventListener('click', function (e) {
                    if (e.target === backdrop) closeModal();
                });
            }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeModal();
            });
        })();
    </script>

    @include('partials.cookie-consent')
</body>
</html>
