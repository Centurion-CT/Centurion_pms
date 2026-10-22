{{--
    Per-tenant property landing, booking-first.

    Centurion "Harbour" design (approved concept 1B): navy hero with the
    booking form beside three rising photo windows, then room types,
    amenities, reviews, contact and policies. Earlier note: minimal header, prominent
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
        $locationBits = array_unique(array_filter([$property->city, $property->state]));
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

    {{-- Centurion "Harbour" design: brand fonts + palette instead of the
         per-property font theme. `--primary` is still declared so the
         Branding colour stays available to shared partials. --}}
    @php
        $today = \Illuminate\Support\Carbon::today()->toDateString();
        $tomorrow = \Illuminate\Support\Carbon::today()->addDay()->toDateString();

        // Three rising "windows" (the logo's bars) beside the hero: the
        // property's own gallery first, then category photos. No stock
        // imagery on the public site; empty slots render as brand bars.
        $windowUrls = collect($heroSlides)->pluck('url')
            ->merge($kind === 'stay' ? $categories->map(fn ($c) => $c->hero?->getUrl('medium'))->filter() : [])
            ->filter()->unique()->take(3)->values();

        $brandLogo = $logoUrl ?: asset(config('brand.logo'));
        // Headline with the hotel (tenant) name; the property is a branch,
        // named only when there's more than one to tell apart.
        $hotelName = $tenant?->name ?: $property->name;
        $showBranchName = ($branches ?? collect())->count() > 1 && $property->name !== $hotelName;
        $amenityList = is_array($property->amenities) ? array_values(array_filter($property->amenities, 'is_string')) : [];
        $perSuffix = fn (?string $per) => match ($per) {
            'hour' => '/ hour', 'day' => '/ day', 'week' => '/ week', 'month' => '/ month', 'year' => '/ year', default => '/ night',
        };
    @endphp
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:500,600,700|hanken-grotesk:400,500,600,700&display=swap">
    @include('partials.favicons')

    <style>
        :root {
            --primary: {{ $property->primary_color }};
            --navy: #16324B; --navy-deep: #0F2438; --navy-line: #2A4A68;
            --blue: #336799; --blue-600: #285480; --blue-50: #EAF1F8; --blue-200: #A9C5E1; --blue-300: #8DB6DE;
            --mist: #EEF3F7; --white: #FFFFFF; --gold: #E3A83A;
            --ink: #1A2430; --ink-soft: #4A5563; --ink-mute: #6B7684;
            --line: rgba(26, 36, 48, 0.10);
            --emerald: #1F7A50; --rose: #C2414B;
            --display: 'Bricolage Grotesque', system-ui, sans-serif;
            --body: 'Hanken Grotesk', system-ui, sans-serif;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
        body { margin: 0; background: var(--mist); color: var(--ink); font-family: var(--body); font-size: 16px; line-height: 1.5; -webkit-font-smoothing: antialiased; font-feature-settings: "tnum" 1; }
        a { color: inherit; }
        img { max-width: 100%; display: block; }
        .cx-wrap { max-width: 1280px; margin: 0 auto; padding: 0 20px; }
        @media (min-width: 768px) { .cx-wrap { padding: 0 40px; } }
        .cx-display { font-family: var(--display); font-weight: 600; letter-spacing: -0.01em; }
        :focus-visible { outline: 3px solid var(--blue-300); outline-offset: 2px; }

        /* Top bar (sticky, navy) */
        .cx-top { position: sticky; top: 0; z-index: 40; background: var(--navy); color: var(--white); }
        .cx-top-inner { height: 72px; display: flex; align-items: center; gap: 24px; }
        .cx-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; min-width: 0; }
        .cx-brand-mark { width: 40px; height: 40px; border-radius: 10px; background: var(--white); display: grid; place-items: center; flex-shrink: 0; overflow: hidden; }
        .cx-brand-mark img { width: 28px; height: 28px; object-fit: contain; }
        .cx-brand-name { font-family: var(--display); font-weight: 600; font-size: 19px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cx-nav { margin-left: auto; display: none; align-items: center; gap: 26px; font-size: 15px; color: var(--blue-200); }
        .cx-nav a { text-decoration: none; }
        .cx-nav a:hover { color: var(--white); }
        @media (min-width: 900px) { .cx-nav { display: flex; } }
        .cx-top-cta { margin-left: auto; height: 40px; padding: 0 16px; border-radius: 8px; background: var(--white); color: var(--navy); font-weight: 600; font-size: 15px; display: inline-flex; align-items: center; text-decoration: none; white-space: nowrap; }
        @media (min-width: 900px) { .cx-top-cta { margin-left: 0; } }

        /* Docked search pill (appears once the hero form scrolls away) */
        .cx-docked { display: none; align-items: center; gap: 10px; height: 40px; padding: 0 6px 0 14px; border-radius: 8px; border: 1px solid var(--navy-line); background: var(--navy-deep); color: var(--blue-200); font: inherit; font-size: 14px; cursor: pointer; }
        .cx-docked .cx-docked-btn { width: 30px; height: 30px; border-radius: 6px; background: var(--blue); color: var(--white); display: grid; place-items: center; }
        .is-search-docked .cx-docked { display: inline-flex; }
        .cx-docked-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
        @media (max-width: 479px) { .cx-docked-guests { display: none; } }
        @media (max-width: 899px) { .is-search-docked .cx-docked { margin-left: auto; } .is-search-docked .cx-top-cta { display: none; } }

        /* Hero */
        .cx-hero { background: var(--navy); color: var(--white); overflow: hidden; }
        .cx-hero-grid { display: grid; grid-template-columns: 1fr; gap: 40px; padding-top: 40px; }
        @media (min-width: 1024px) { .cx-hero-grid { grid-template-columns: minmax(0, 1fr) 520px; gap: 56px; align-items: end; padding-top: 48px; } }
        .cx-hero-copy { padding-bottom: 56px; }
        @media (min-width: 1024px) { .cx-hero-copy { padding-bottom: 80px; } }
        .cx-title { font-family: var(--display); font-weight: 600; font-size: clamp(40px, 6vw, 68px); line-height: 1.0; letter-spacing: -0.015em; margin: 0; max-width: 14ch; }
        .cx-tagline { font-size: 18px; color: var(--blue-200); margin: 18px 0 0; max-width: 34em; }
        .cx-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 20px; }
        .cx-chip { display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 12px; border-radius: 8px; background: rgba(255, 255, 255, 0.08); color: var(--white); font-size: 14px; }
        .cx-chip .star { color: var(--gold); }

        .cx-book { margin-top: 32px; background: var(--white); color: var(--ink); border-radius: 12px; padding: 16px; max-width: 580px; }
        .cx-book-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
        .cx-field, .cx-field input, .cx-field select { min-width: 0; }
        .cx-field { display: block; border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px; background: var(--white); cursor: pointer; }
        .cx-field:focus-within { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(51, 103, 153, 0.18); }
        .cx-field-label { display: block; font-size: 13px; color: var(--ink-mute); }
        .cx-field input, .cx-field select { width: 100%; border: 0; padding: 2px 0 0; font: inherit; font-size: 16px; font-weight: 600; color: var(--ink); background: transparent; outline: none; }
        .cx-book-submit { grid-column: 1 / -1; height: 48px; border: 0; border-radius: 8px; background: var(--blue); color: var(--white); font: inherit; font-size: 16px; font-weight: 600; cursor: pointer; }
        .cx-book-submit:hover { background: var(--blue-600); }
        .cx-book-note { margin: 12px 2px 0; font-size: 13px; color: var(--ink-mute); display: flex; gap: 6px; align-items: center; }
        .cx-hero-cta { display: inline-flex; align-items: center; height: 48px; padding: 0 22px; margin-top: 32px; border-radius: 8px; background: var(--white); color: var(--navy); font-weight: 600; text-decoration: none; }

        .cx-windows { display: none; align-items: flex-end; gap: 14px; height: 560px; }
        @media (min-width: 1024px) { .cx-windows { display: flex; } }
        .cx-window { flex: 1; border-radius: 12px 12px 4px 4px; background: var(--navy-line) center / cover no-repeat; }
        .cx-window:nth-child(1) { height: 100%; }
        .cx-window:nth-child(2) { height: 72%; }
        .cx-window:nth-child(3) { height: 46%; }

        /* Sections */
        .cx-section { padding: 72px 0; }
        .cx-h2 { font-family: var(--display); font-weight: 600; font-size: clamp(28px, 3.4vw, 40px); line-height: 1.1; margin: 0; }
        .cx-sub { color: var(--ink-soft); margin: 8px 0 0; }
        .cx-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px 24px; }
        .cx-link { color: var(--blue-600); font-weight: 600; text-decoration: none; }
        .cx-link:hover { text-decoration: underline; text-underline-offset: 4px; }

        /* Cards */
        .cx-grid { margin-top: 36px; display: grid; gap: 22px; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
        .cx-card { display: flex; flex-direction: column; background: var(--white); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; text-decoration: none; transition: border-color 150ms ease; }
        .cx-card:hover { border-color: rgba(51, 103, 153, 0.45); }
        .cx-card-photo { position: relative; aspect-ratio: 3 / 2; background: var(--blue-50); }
        .cx-card-photo img { width: 100%; height: 100%; object-fit: cover; }
        .cx-card-body { padding: 20px 22px 22px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
        .cx-card-top { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
        .cx-card-name { font-family: var(--display); font-weight: 600; font-size: 22px; line-height: 1.2; margin: 0; }
        .cx-avail { font-size: 13px; font-weight: 600; color: var(--emerald); white-space: nowrap; }
        .cx-avail.is-low { color: var(--rose); }
        .cx-avail.is-full { color: var(--ink-mute); }
        .cx-card-meta { font-size: 15px; color: var(--ink-soft); }
        .cx-card-foot { margin-top: auto; padding-top: 14px; display: flex; align-items: center; gap: 12px; }
        .cx-price { font-family: var(--display); font-weight: 600; font-size: 24px; }
        .cx-price small { font-family: var(--body); font-weight: 400; font-size: 14px; color: var(--ink-mute); }
        .cx-card-cta { margin-left: auto; height: 42px; padding: 0 18px; border-radius: 8px; background: var(--navy); color: var(--white); font-weight: 600; font-size: 15px; display: inline-flex; align-items: center; }

        .cx-empty { margin-top: 32px; background: var(--white); border: 1px dashed rgba(51, 103, 153, 0.35); border-radius: 12px; padding: 40px; }
        .cx-empty h2 { font-family: var(--display); font-weight: 600; font-size: 24px; margin: 0; }
        .cx-empty p { margin: 8px 0 0; color: var(--ink-soft); }

        /* Bands */
        .cx-panel { background: var(--white); border: 1px solid var(--line); border-radius: 12px; padding: 32px; }
        @media (min-width: 768px) { .cx-panel { padding: 40px; } }
        .cx-amenities { margin: 24px 0 0; padding: 0; list-style: none; display: grid; gap: 14px 32px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
        .cx-amenities li { display: flex; gap: 10px; align-items: flex-start; font-size: 15px; }
        .cx-amenities svg { flex-shrink: 0; color: var(--blue); margin-top: 2px; }

        .cx-reviews { margin-top: 28px; display: grid; gap: 18px; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
        .cx-review { background: var(--white); border: 1px solid var(--line); border-radius: 12px; padding: 22px; }
        .cx-review-head { display: flex; gap: 12px; align-items: flex-start; }
        .cx-avatar { width: 40px; height: 40px; border-radius: 999px; object-fit: cover; flex-shrink: 0; background: var(--blue-50); color: var(--blue-600); display: inline-flex; align-items: center; justify-content: center; font-weight: 600; }
        .cx-review-author { font-weight: 600; }
        .cx-review-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 4px; font-size: 12px; color: var(--ink-mute); }
        .cx-review-rating { margin-left: auto; font-weight: 600; white-space: nowrap; }
        .cx-review-rating .star { color: var(--gold); }
        .cx-review p { margin: 12px 0 0; color: var(--ink-soft); }
        .cx-response { margin-top: 14px; padding: 12px 14px; border-radius: 8px; background: var(--blue-50); font-size: 14px; }
        .cx-response-head { display: flex; justify-content: space-between; gap: 8px; font-weight: 600; color: var(--blue-600); }
        .cx-response-head time { font-weight: 400; color: var(--ink-mute); }
        .cx-response p { margin: 6px 0 0; color: var(--ink); }

        .cx-contact { margin-top: 24px; display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
        .cx-tile-label { display: block; font-size: 14px; color: var(--ink-mute); }
        .cx-tile-value { display: block; font-weight: 600; font-size: 18px; margin-top: 2px; word-break: break-word; }
        .cx-tile-value a { text-decoration: none; color: var(--blue-600); }
        .cx-about { margin-top: 18px; color: var(--ink-soft); max-width: 70ch; }
        .cx-about a { color: var(--blue-600); }

        .cx-gtk { background: var(--white); border-block: 1px solid var(--line); }
        .cx-gtk-grid { display: grid; gap: 28px; padding-block: 56px; grid-template-columns: 1fr; }
        @media (min-width: 900px) { .cx-gtk-grid { grid-template-columns: 260px repeat(3, minmax(0, 1fr)); align-items: start; } }
        .cx-gtk-value { display: block; font-weight: 600; font-size: 20px; margin-top: 4px; }
        .cx-gtk-wide .cx-gtk-value { font-size: 16px; font-weight: 500; line-height: 1.5; }

        /* Footer */
        .cx-foot { background: var(--navy-deep); color: var(--white); }
        .cx-foot-top { display: flex; flex-wrap: wrap; align-items: center; gap: 20px 40px; padding-block: 48px; }
        .cx-foot-where { color: var(--blue-200); font-size: 15px; }
        .cx-foot-cta { margin-left: auto; height: 44px; padding: 0 18px; border-radius: 8px; background: var(--white); color: var(--navy); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .cx-foot-bottom { border-top: 1px solid rgba(255, 255, 255, 0.10); }
        .cx-foot-bottom .cx-wrap { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px 24px; padding-block: 18px; font-size: 13px; color: var(--blue-200); }
        .cx-foot-bottom nav { display: flex; flex-wrap: wrap; gap: 18px; }
        .cx-foot-bottom a { text-decoration: none; }
        .cx-foot-bottom a:hover { color: var(--white); }

        /* WhatsApp button */
        .cx-wa { position: fixed; right: 20px; bottom: 20px; z-index: 50; display: inline-flex; align-items: center; gap: 8px; height: 48px; padding: 0 18px; border-radius: 999px; background: var(--navy); color: var(--white); font-weight: 600; text-decoration: none; box-shadow: 0 12px 28px -12px rgba(15, 36, 56, 0.6); }
        .cx-wa:hover { background: var(--blue-600); }

        /* Search modal */
        .cx-modal { position: fixed; inset: 0; z-index: 60; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(15, 36, 56, 0.55); }
        .cx-modal.is-open { display: flex; }
        .cx-modal-panel { width: 100%; max-width: 560px; background: var(--white); border-radius: 12px; padding: 20px; box-shadow: 0 24px 60px -20px rgba(15, 36, 56, 0.6); }
        .cx-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .cx-modal-head h2 { font-family: var(--display); font-weight: 600; font-size: 22px; margin: 0; }
        .cx-modal-close { width: 36px; height: 36px; border: 0; border-radius: 8px; background: var(--mist); font-size: 22px; line-height: 1; cursor: pointer; color: var(--ink); }
    </style>
</head>
<body>
@php
    $searchFields = function (bool $main) use ($today, $tomorrow) {
        $t = fn ($id) => $main ? ' data-testid="'.$id.'"' : '';
        ob_start(); ?>
        <div class="cx-book-grid">
            <label class="cx-field"><span class="cx-field-label">Check in</span>
                <input type="date" name="in" value="<?= e($today) ?>" min="<?= e($today) ?>"<?= $t('filter-checkin') ?>></label>
            <label class="cx-field"><span class="cx-field-label">Check out</span>
                <input type="date" name="out" value="<?= e($tomorrow) ?>" min="<?= e($tomorrow) ?>"<?= $t('filter-checkout') ?>></label>
            <label class="cx-field"><span class="cx-field-label">Adults</span>
                <select name="adults"<?= $t('filter-adults') ?>>
                    <?php for ($i = 1; $i <= 8; $i++): ?><option value="<?= $i ?>"<?= $i === 2 ? ' selected' : '' ?>><?= $i ?> adult<?= $i === 1 ? '' : 's' ?></option><?php endfor; ?>
                </select></label>
            <label class="cx-field"><span class="cx-field-label">Children</span>
                <select name="children"<?= $t('filter-children') ?>>
                    <?php for ($i = 0; $i <= 6; $i++): ?><option value="<?= $i ?>"><?= $i ?> child<?= $i === 1 ? '' : 'ren' ?></option><?php endfor; ?>
                </select></label>
            <button type="submit" class="cx-book-submit" data-testid="<?= $main ? 'book-cta' : 'modal-book-cta' ?>"><?= $main ? 'Check availability' : 'Search' ?></button>
        </div>
        <?php return ob_get_clean();
    };
@endphp
<div class="cx-page">

    <nav class="cx-top" data-testid="topbar">
        <div class="cx-wrap cx-top-inner">
            <a href="#top" class="cx-brand" aria-label="{{ $hotelName }}">
                <span class="cx-brand-mark"><img src="{{ $brandLogo }}" alt="{{ $hotelName }}" @if ($logoUrl) data-testid="logo" @endif></span>
                <span class="cx-brand-name">{{ $hotelName }}</span>
            </a>
            @if ($kind === 'stay')
                <button type="button" class="cx-docked" data-search-docked data-modal-open
                        aria-haspopup="dialog" aria-controls="search-modal"
                        data-testid="topbar-search-docked" aria-label="Change your search">
                    <span class="cx-docked-text"><span data-summary-dates>Any dates</span><span class="cx-docked-guests">, <span data-summary-guests>2 guests</span></span></span>
                    <span class="cx-docked-btn" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="9" cy="9" r="6"/><path d="M17 17l-3.5-3.5"/></svg>
                    </span>
                </button>
            @endif
            <div class="cx-nav">
                @include('tenant.partials.branch-switcher', ['branches' => $branches ?? collect(), 'current' => $property])
                <a href="{{ route($browseRoute) }}">{{ $navInventoryLabel }}</a>
                @if ($amenityList !== [])<a href="#included">What's included</a>@endif
                <a href="#good-to-know">Good to know</a>
                @if ($property->isSectionVisible('contact_block'))<a href="#contact">Contact</a>@endif
            </div>
            <a href="{{ $kind === 'stay' ? '#book' : route($browseRoute) }}" class="cx-top-cta">{{ $browseLabel }}</a>
        </div>
    </nav>

    <span id="top"></span>

    <header class="cx-hero" data-testid="hero">
        <div class="cx-wrap cx-hero-grid">
            <div class="cx-hero-copy">
                <h1 class="cx-title" data-testid="property-name">{{ $hotelName }}</h1>
                @if ($property->tagline)
                    <p class="cx-tagline" data-testid="tagline">{{ $property->tagline }}</p>
                @endif
                <div class="cx-chips">
                    @if ($showBranchName)
                        <span class="cx-chip">{{ $property->name }}</span>
                    @endif
                    @if ($rating['count'] > 0)
                        <span class="cx-chip" data-testid="rating-badge"><span class="star">★</span>{{ $rating['average'] }} from {{ $rating['count'] }} review{{ $rating['count'] === 1 ? '' : 's' }}</span>
                    @endif
                    @if ($locationStr)
                        <span class="cx-chip">{{ $locationStr }}</span>
                    @endif
                    @if ($priceFrom)
                        <span class="cx-chip" data-testid="price-from-badge">From ₦{{ number_format($priceFrom) }} {{ $perSuffix($pricePer) }}</span>
                    @endif
                </div>

                @if ($kind === 'stay')
                    <span id="book"></span>
                    <form method="get" action="{{ route('tenant.rooms.browse') }}" class="cx-book"
                          data-testid="landing-filter-form" data-main-filter>
                        {!! $searchFields(true) !!}
                        <p class="cx-book-note">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="4" y="9" width="12" height="8" rx="2"/><path d="M7 9V6a3 3 0 0 1 6 0v3"/></svg>
                            Book direct for instant confirmation and secure payment.
                        </p>
                    </form>
                @else
                    <a href="{{ route($browseRoute) }}" class="cx-hero-cta" data-testid="book-cta">{{ $browseLabel }}</a>
                @endif
            </div>

            <div class="cx-windows" aria-hidden="true">
                @for ($i = 0; $i < 3; $i++)
                    <div class="cx-window" @if (isset($windowUrls[$i])) style="background-image: url('{{ $windowUrls[$i] }}');" @endif></div>
                @endfor
            </div>
        </div>
    </header>

    <main>
        <section class="cx-section" id="rooms">
            <div class="cx-wrap">
                @if ($kind === 'stay')
                    @php $totalRoomCount = $categories->sum('total'); @endphp
                    <div class="cx-head">
                        <div>
                            <h2 class="cx-h2">Choose where you'll stay</h2>
                            @if ($categories->isNotEmpty())
                                <p class="cx-sub">{{ $categories->count() }} {{ $categories->count() === 1 ? 'room type' : 'room types' }}, {{ $totalRoomCount }} {{ $totalRoomCount === 1 ? 'unit' : 'units' }}. Availability shown for tonight.</p>
                            @endif
                        </div>
                        @if ($categories->isNotEmpty())
                            <a href="{{ route('tenant.rooms.browse') }}" class="cx-link">Search by your dates</a>
                        @endif
                    </div>

                    @if ($categories->isEmpty())
                        <div class="cx-empty" data-testid="no-rooms">
                            <h2>Rooms coming soon</h2>
                            <p>{{ $property->name }} hasn't published any bookable rooms yet. Check back shortly.</p>
                        </div>
                    @else
                        <div class="cx-grid" data-testid="rooms-grid">
                            @foreach ($categories as $entry)
                                @php
                                    $category = $entry->model;
                                    $available = $entry->available;
                                    $isSoldOut = $available === 0;
                                    $availLabel = $isSoldOut ? 'Full tonight' : ($available === 1 ? '1 left' : $available.' left');
                                @endphp
                                <a href="{{ route('tenant.categories.show', $category) }}" class="cx-card" data-testid="category-card-{{ $category->id }}">
                                    <div class="cx-card-photo">
                                        @if ($entry->hero)
                                            <img src="{{ $entry->hero->getUrl('medium') }}" alt="{{ $entry->hero->getCustomProperty('alt') ?: $category->name }}" loading="lazy">
                                        @endif
                                    </div>
                                    <div class="cx-card-body">
                                        <div class="cx-card-top">
                                            <h3 class="cx-card-name">{{ $category->name }}</h3>
                                            <span class="cx-avail {{ $isSoldOut ? 'is-full' : ($available === 1 ? 'is-low' : '') }}" data-testid="availability-badge-{{ $category->id }}">{{ $availLabel }}</span>
                                        </div>
                                        <div class="cx-card-meta">
                                            Sleeps {{ $category->maxOccupancy() }}@if ($category->min_nights > 1), minimum {{ $category->min_nights }} nights @endif
                                        </div>
                                        <div class="cx-card-foot">
                                            <span class="cx-price">₦{{ number_format($category->base_rate) }} <small>/ night</small></span>
                                            <span class="cx-card-cta">{{ $isSoldOut ? 'Check dates' : 'Book' }}</span>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif

                @elseif ($kind === 'workspace')
                    <div class="cx-head"><h2 class="cx-h2">Plans and spaces</h2></div>
                    @if ($plans->isEmpty() && $workspaces->isEmpty())
                        <div class="cx-empty" data-testid="no-rooms">
                            <h2>Plans coming soon</h2>
                            <p>Get in touch below and we'll come back to you with pricing.</p>
                        </div>
                    @else
                        <div class="cx-grid">
                            @foreach ($plans as $plan)
                                <a href="{{ route('tenant.workspaces.browse') }}" class="cx-card" data-testid="plan-card-{{ $plan->id }}">
                                    <div class="cx-card-body">
                                        <div class="cx-card-top"><h3 class="cx-card-name">{{ $plan->name }}</h3><span class="cx-avail is-full">{{ $plan->billing_interval->label() }}</span></div>
                                        @if ($plan->description)<div class="cx-card-meta">{{ \Illuminate\Support\Str::limit($plan->description, 120) }}</div>@endif
                                        <div class="cx-card-foot"><span class="cx-price">₦{{ number_format($plan->price_naira) }} <small>{{ $plan->billing_interval->suffix() }}</small></span><span class="cx-card-cta">Enquire</span></div>
                                    </div>
                                </a>
                            @endforeach
                            @foreach ($workspaces as $ws)
                                @php $avail = $ws->availabilitySummary(); $isFull = $avail['status'] === 'full'; @endphp
                                <a href="{{ route('tenant.workspaces.browse') }}" class="cx-card" data-testid="ws-card-{{ $ws->id }}">
                                    <div class="cx-card-body">
                                        <div class="cx-card-top"><h3 class="cx-card-name">{{ $ws->name }}</h3><span class="cx-avail {{ $isFull ? 'is-full' : '' }}">{{ $isFull ? 'Fully subscribed' : $avail['available'].' free' }}</span></div>
                                        <div class="cx-card-meta">{{ $ws->type->label() }}, capacity {{ $ws->capacity }}</div>
                                        <div class="cx-card-foot">
                                            <span class="cx-price">
                                                @if ($ws->hourly_rate_naira) ₦{{ number_format($ws->hourly_rate_naira) }} <small>/ hour</small>
                                                @elseif ($ws->daily_rate_naira) ₦{{ number_format($ws->daily_rate_naira) }} <small>/ day</small> @endif
                                            </span>
                                            <span class="cx-card-cta">{{ $isFull ? 'Join waitlist' : 'View' }}</span>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif

                @elseif ($kind === 'residential')
                    <div class="cx-head">
                        <div>
                            <h2 class="cx-h2">Available homes</h2>
                            @if ($units->isNotEmpty())<p class="cx-sub">{{ $units->count() }} {{ $units->count() === 1 ? 'home' : 'homes' }}, from ₦{{ number_format($priceFrom ?? 0) }} a year.</p>@endif
                        </div>
                    </div>
                    @if ($units->isEmpty())
                        <div class="cx-empty" data-testid="no-rooms">
                            <h2>No homes available right now</h2>
                            <p>Register your interest below and we'll let you know when one opens up.</p>
                        </div>
                    @else
                        <div class="cx-grid">
                            @foreach ($units as $unit)
                                @php $avail = $unit->availabilitySummary(); $isLeased = $avail['status'] !== 'available'; @endphp
                                <a href="{{ route('tenant.units.browse') }}" class="cx-card" data-testid="unit-card-{{ $unit->id }}">
                                    <div class="cx-card-body">
                                        <div class="cx-card-top"><h3 class="cx-card-name">{{ $unit->name }}</h3><span class="cx-avail {{ $isLeased ? 'is-full' : '' }}">{{ $avail['label'] }}</span></div>
                                        <div class="cx-card-meta">{{ $unit->bedrooms }} bed, {{ $unit->bathrooms }} bath. {{ $unit->address }}{{ $unit->city ? ', '.$unit->city : '' }}</div>
                                        <div class="cx-card-foot"><span class="cx-price">₦{{ number_format($unit->annual_rent_naira) }} <small>/ year</small></span><span class="cx-card-cta">{{ $isLeased ? 'Join waitlist' : 'Enquire' }}</span></div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        </section>

        @if ($amenityList !== [])
            <section class="cx-wrap" id="included" style="padding-bottom: 72px;">
                <div class="cx-panel">
                    <h2 class="cx-h2" style="font-size: 30px;">Included in every stay</h2>
                    <ul class="cx-amenities">
                        @foreach ($amenityList as $amenity)
                            <li><svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10.5l4 4 8-9"/></svg>{{ $amenity }}</li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        @if ($property->isSectionVisible('reviews') && $reviews->isNotEmpty())
            <section class="cx-wrap" id="reviews" data-testid="reviews-section" style="padding-bottom: 72px;">
                <div class="cx-head">
                    <h2 class="cx-h2">What guests say</h2>
                    @if ($rating['count'] > 0)
                        <span class="cx-sub" style="margin:0;"><span style="color: var(--gold);">★</span> {{ $rating['average'] }} from {{ $reviews->count() }} {{ $reviews->count() === 1 ? 'review' : 'reviews' }}</span>
                    @endif
                </div>
                <div class="cx-reviews">
                    @foreach ($reviews->take(4) as $review)
                        @php $chColors = $review->channel?->badgeColors() ?? ['bg' => '#E5E7EB', 'ink' => '#374151']; @endphp
                        <article class="cx-review" data-testid="review-{{ $review->id }}">
                            <div class="cx-review-head">
                                @if ($review->photo_path)
                                    <img class="cx-avatar" src="{{ $review->photoUrl() }}" alt="{{ $review->author_name ?? 'Reviewer' }}" loading="lazy" data-testid="review-photo-{{ $review->id }}">
                                @else
                                    <span class="cx-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::of($review->author_name ?? 'A')->substr(0, 1)->upper() }}</span>
                                @endif
                                <div style="min-width: 0;">
                                    <div class="cx-review-author">
                                        {{ $review->author_name ?? 'Anonymous' }}
                                        @if (! empty($review->guest_country))
                                            @php $reviewFlag = \App\Services\Locale\Countries::flagFor($review->guest_country); @endphp
                                            <span style="font-weight: 400; color: var(--ink-mute); font-size: 13px;">@if ($reviewFlag){{ $reviewFlag }} @endif{{ $review->guest_country }}</span>
                                        @endif
                                    </div>
                                    <div class="cx-review-meta">
                                        <span style="padding: 2px 8px; border-radius: 6px; font-weight: 600; background: {{ $chColors['bg'] }}; color: {{ $chColors['ink'] }};" data-testid="review-channel-{{ $review->id }}">{{ ($review->channel ?? \App\Enums\ReviewChannel::Direct)->label() }}</span>
                                        @if ($review->published_at)
                                            <time datetime="{{ $review->published_at->toIso8601String() }}">{{ $review->published_at->format('M Y') }}</time>
                                        @endif
                                    </div>
                                </div>
                                @if ($review->rating)
                                    <span class="cx-review-rating"><span class="star">★</span> {{ number_format($review->rating, 1) }}</span>
                                @endif
                            </div>
                            <p>{{ \Illuminate\Support\Str::limit($review->body, 220) }}</p>
                            @if ($review->hasResponse())
                                <div class="cx-response" data-testid="review-response-{{ $review->id }}">
                                    <div class="cx-response-head">
                                        <span>Reply from {{ $property->name }}</span>
                                        @if ($review->responded_at)<time datetime="{{ $review->responded_at->toIso8601String() }}">{{ $review->responded_at->diffForHumans() }}</time>@endif
                                    </div>
                                    <p>{{ \Illuminate\Support\Str::limit($review->response_body, 260) }}</p>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($property->isSectionVisible('about') && $property->description)
            <section class="cx-wrap" id="about" data-testid="about" style="padding-bottom: 72px;">
                <div class="cx-panel">
                    <h2 class="cx-h2" style="font-size: 30px;">About {{ $property->name }}</h2>
                    {{-- Saved through HtmlSanitizer (b/i/u/a/p/br/ul/ol/li only). --}}
                    <div class="cx-about">{!! $property->description !!}</div>
                </div>
            </section>
        @endif

        @if ($property->isSectionVisible('contact_block'))
            <section class="cx-wrap" id="contact" data-testid="contact-section" style="padding-bottom: 72px;">
                <div class="cx-panel">
                    <h2 class="cx-h2" style="font-size: 30px;">Contact the front desk</h2>
                    <div class="cx-contact">
                        @if ($tenant->phone)
                            <div><span class="cx-tile-label">Call</span><span class="cx-tile-value"><a href="tel:{{ $tenant->phone }}">{{ $tenant->phone }}</a></span></div>
                        @endif
                        @if ($tenant->email)
                            <div><span class="cx-tile-label">Email</span><span class="cx-tile-value"><a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a></span></div>
                        @endif
                        @if ($property->address || $locationStr)
                            <div><span class="cx-tile-label">Address</span><span class="cx-tile-value" style="font-weight: 500;">{{ collect([$property->address, $locationStr])->filter()->implode(', ') }}</span></div>
                        @endif
                    </div>
                </div>
            </section>
        @endif

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
                $checkInFrom !== '' => "From {$checkInFrom}",
                $checkInTo !== '' => "Until {$checkInTo}",
                default => '',
            };
            $checkOutText = match (true) {
                $checkOutFrom !== '' && $checkOutTo !== '' => "{$checkOutFrom} - {$checkOutTo}",
                $checkOutFrom !== '' => "From {$checkOutFrom}",
                $checkOutTo !== '' => "By {$checkOutTo}",
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
            <section class="cx-gtk" id="good-to-know" data-testid="good-to-know">
                <div class="cx-wrap cx-gtk-grid">
                    <h2 class="cx-h2" style="font-size: 30px;">Good to know</h2>
                    @if ($checkInText !== '')
                        <div data-testid="gtk-checkin"><span class="cx-tile-label">Check in</span><span class="cx-gtk-value">{{ $checkInText }}</span></div>
                    @endif
                    @if ($checkOutText !== '')
                        <div data-testid="gtk-checkout"><span class="cx-tile-label">Check out</span><span class="cx-gtk-value">{{ $checkOutText }}</span></div>
                    @endif
                    @if ($lateFeeEnabled)
                        <div class="cx-gtk-wide" data-testid="gtk-late-fee"><span class="cx-tile-label">Late check-out</span><span class="cx-gtk-value">{{ implode(' ', $lateFeeLines) }}</span></div>
                    @endif
                </div>
            </section>
        @endif
    </main>

    <footer class="cx-foot" data-testid="footer">
        <div class="cx-wrap cx-foot-top">
            <a href="#top" class="cx-brand">
                <span class="cx-brand-mark"><img src="{{ $brandLogo }}" alt=""></span>
                <span class="cx-brand-name">{{ $hotelName }}</span>
            </a>
            @if ($locationStr)
                <span class="cx-foot-where">{{ $locationStr }}</span>
            @endif
            @if ($tenant->email)
                <a href="mailto:{{ $tenant->email }}" class="cx-foot-cta">Email the front desk</a>
            @endif
        </div>
        <div class="cx-foot-bottom">
            <div class="cx-wrap">
                <span>© {{ date('Y') }} {{ $hotelName }}</span>
                <nav>
                    <a href="{{ route('legal.privacy') }}">Privacy</a>
                    <a href="{{ route('legal.terms') }}">Terms</a>
                    <a href="{{ route('legal.cookies') }}">Cookies</a>
                    <span>Powered by @if (config('brand.url'))<a href="{{ config('brand.url') }}" target="_blank" rel="noopener">{{ config('brand.name') }}</a>@else{{ config('brand.name') }}@endif</span>
                </nav>
            </div>
        </div>
    </footer>

    @if ($property->whatsapp_button_enabled && $tenant->phone)
        @php
            $waNumber = preg_replace('/\D+/', '', $tenant->phone);
            $waPrefill = $property->whatsapp_button_prefill ?? null;
            $waLink = 'https://wa.me/'.$waNumber.($waPrefill ? '?text='.rawurlencode($waPrefill) : '');
        @endphp
        <a href="{{ $waLink }}" class="cx-wa" data-testid="whatsapp-button" target="_blank" rel="noopener">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.5 3.5A11.9 11.9 0 0 0 12 0C5.4 0 0 5.4 0 12c0 2.1.6 4.1 1.6 5.9L0 24l6.3-1.6A11.9 11.9 0 0 0 12 24c6.6 0 12-5.4 12-12 0-3.2-1.3-6.2-3.5-8.5zM12 22a10 10 0 0 1-5.1-1.4l-.4-.2-3.8 1 1-3.7-.2-.4A10 10 0 1 1 22 12c0 5.5-4.5 10-10 10zm5.5-7.5c-.3-.2-1.8-.9-2.1-1s-.5-.2-.7.2c-.2.3-.8 1-1 1.2-.2.2-.4.2-.7 0-.3-.2-1.3-.5-2.5-1.5-.9-.8-1.5-1.9-1.7-2.2-.2-.3 0-.5.1-.7l.5-.6c.2-.2.2-.3.3-.5.1-.2 0-.4 0-.6l-.7-1.7c-.2-.4-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.4-.3.3-1 1-1 2.5s1 2.9 1.2 3.1c.1.2 2 3.1 5 4.3.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4.2-.7.2-1.3.2-1.4-.1-.1-.3-.2-.6-.4z"/></svg>
            <span>Chat on WhatsApp</span>
        </a>
    @endif

    @if ($kind === 'stay')
        <div class="cx-modal" id="search-modal" data-modal-backdrop role="dialog" aria-modal="true"
             aria-labelledby="search-modal-title" aria-hidden="true" data-testid="search-modal">
            <div class="cx-modal-panel" role="document">
                <div class="cx-modal-head">
                    <h2 id="search-modal-title">Check availability</h2>
                    <button type="button" class="cx-modal-close" data-modal-close aria-label="Close">&times;</button>
                </div>
                <form method="get" action="{{ route('tenant.rooms.browse') }}" data-modal-filter data-testid="modal-filter-form">
                    {!! $searchFields(false) !!}
                </form>
            </div>
        </div>
    @endif
</div>

{{-- Docked search + modal behaviour (vanilla JS): show the topbar pill
     once the hero form scrolls away, mirror the picked dates/guests into
     it, and open a pre-filled search dialog from it. --}}
<script>
    (function () {
        var page   = document.querySelector('.cx-page');
        var filter = document.querySelector('[data-main-filter]');
        var docked = document.querySelector('[data-search-docked]');
        if (!page || !filter || !docked) return;

        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    page.classList.toggle('is-search-docked', !entry.isIntersecting);
                });
            }, { rootMargin: '-72px 0px 0px 0px', threshold: 0 }).observe(filter);
        }

        var inEl = filter.querySelector('[name="in"]'), outEl = filter.querySelector('[name="out"]');
        var adultsEl = filter.querySelector('[name="adults"]'), childrenEl = filter.querySelector('[name="children"]');
        var datesOut = docked.querySelector('[data-summary-dates]'), guestsOut = docked.querySelector('[data-summary-guests]');
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var fmt = function (iso) {
            if (!iso) return null;
            var d = new Date(iso + 'T00:00:00');
            return isNaN(d.getTime()) ? null : d.getDate() + ' ' + months[d.getMonth()];
        };
        var refresh = function () {
            var a = fmt(inEl && inEl.value), b = fmt(outEl && outEl.value);
            if (datesOut) datesOut.textContent = (a && b) ? (a + ' to ' + b) : 'Any dates';
            var g = (parseInt(adultsEl && adultsEl.value, 10) || 0) + (parseInt(childrenEl && childrenEl.value, 10) || 0);
            if (guestsOut) guestsOut.textContent = g === 1 ? '1 guest' : g + ' guests';
        };
        [inEl, outEl, adultsEl, childrenEl].forEach(function (el) {
            if (el) { el.addEventListener('change', refresh); el.addEventListener('input', refresh); }
        });
        refresh();

        var backdrop = document.querySelector('[data-modal-backdrop]');
        var modalFilter = document.querySelector('[data-modal-filter]');
        var lastFocused = null;
        var openModal = function () {
            if (!backdrop) return;
            ['in', 'out', 'adults', 'children'].forEach(function (name) {
                var src = filter.querySelector('[name="' + name + '"]'), dst = modalFilter && modalFilter.querySelector('[name="' + name + '"]');
                if (src && dst) dst.value = src.value;
            });
            lastFocused = document.activeElement;
            backdrop.classList.add('is-open');
            backdrop.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            var first = modalFilter && modalFilter.querySelector('input, select, button');
            if (first) setTimeout(function () { first.focus(); }, 60);
        };
        var closeModal = function () {
            if (!backdrop || !backdrop.classList.contains('is-open')) return;
            backdrop.classList.remove('is-open');
            backdrop.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            if (lastFocused && lastFocused.focus) lastFocused.focus();
        };
        document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
            btn.addEventListener('click', function (e) { e.preventDefault(); openModal(); });
        });
        document.querySelectorAll('[data-modal-close]').forEach(function (btn) { btn.addEventListener('click', closeModal); });
        if (backdrop) backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
    })();
</script>

@include('partials.cookie-consent')
</body>
</html>
