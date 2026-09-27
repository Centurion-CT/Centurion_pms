{{--
    Keverna landing - LUNA-inspired editorial rebuild.

    Full-page redesign moving away from the card-stack cream/ink slabs
    to an editorial rhythm: dark hero → big numbers → module showcase →
    mixed photo/label facilities grid → mission → full-bleed photo
    callout → testimonials → pricing → dark developer/demo section →
    newsletter → giant wordmark → footer.

    Scoped under `.lp-page` + a v3 marker class so the operator dashboard
    keeps its own `.lp-*` styles unchanged.

    Route: `/` → `Route::view('/', 'welcome')` in routes/web.php.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F1215">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    {{-- Progressive-enhancement gate. Runs before any stylesheet
         parses so the .js class is on <html> before CSS matches, so
         [data-scroll-fade] can be scoped to `.js` and NOT hide
         itself from bots, LinkedIn/OG-image scrapers, Google's
         rendering budget, or corporate networks with JS disabled.
         Without this the marketing page ships as a cream void to
         any scanner that doesn't wait for IntersectionObserver. --}}
    <script>document.documentElement.classList.add('js');</script>

    <title>Keverna · Elevated hospitality, one property at a time.</title>
    <meta name="description" content="Elevated hospitality, one property at a time. Keverna is a smart property management system for African hospitality: direct booking pages with zero commission, front desk, POS, housekeeping, guest self-service portal, credit lines and Paystack payouts - one login for hotels, shortlets and workspaces. Tiered pricing from ₦180,000/year.">
    <meta name="keywords" content="PMS Nigeria, hotel management software Africa, zero commission booking, Paystack hotel payments, hospitality software Nigeria, hotel booking system Africa, shortlet management, WhatsApp hotel support">
    <meta name="author" content="FregateLab Limited">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <link rel="canonical" href="{{ url('/') }}">

    {{-- Open Graph / Facebook --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Keverna">
    <meta property="og:locale" content="en_NG">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:title" content="Keverna · Elevated hospitality, one property at a time.">
    <meta property="og:description" content="Elevated hospitality, one property at a time. Direct booking pages, front desk, POS, housekeeping, guest portal - one login. Zero commission.">
    <meta property="og:image" content="https://keverna-file.dokwe.com/marketing/keverna-og-cover.jpg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Keverna dashboard preview">

    {{-- Twitter Card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@keverna_com">
    <meta name="twitter:title" content="Keverna · Elevated hospitality, one property at a time.">
    <meta name="twitter:description" content="Elevated hospitality, one property at a time. Direct booking pages, front desk, POS, housekeeping, guest portal - one login. Zero commission.">
    <meta name="twitter:image" content="https://keverna-file.dokwe.com/marketing/keverna-og-cover.jpg">

    {{-- Search engine hints for AI crawlers (ChatGPT / Claude / Perplexity
         all honour the standard robots + og tags above; llms.txt at the
         root gives them a curated cheat-sheet). --}}
    <link rel="alternate" type="text/plain" title="LLM crawl guide" href="{{ url('/llms.txt') }}">

    @include('partials.favicons')

    {{-- Structured data - Organization + SoftwareApplication + FAQPage.
         Built via json_encode so Blade doesn't mis-interpret the
         schema.org "@type" / "@id" / "@context" keys as directives. --}}
    @php
        $siteUrl  = url('/');
        $logoUrl  = url('/favicon.svg');
        // Platform-wide kobo counter snapshot. Single primary-key
        // lookup so this stays O(1) regardless of how the payments
        // table grows. Padded server-side by PlatformStats::PUBLIC_PAD_FACTOR.
        $koboSnapshot = \App\Models\PlatformStats::snapshot();

        $orgLd = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            '@id'      => $siteUrl.'#organization',
            'name'     => 'Keverna',
            'url'      => $siteUrl,
            'logo'     => $logoUrl,
            'sameAs'   => ['https://twitter.com/keverna_com'],
            'parentOrganization' => [
                '@type' => 'Organization',
                'name'  => 'FregateLab Limited',
                'address' => [
                    '@type'          => 'PostalAddress',
                    'addressCountry' => 'NG',
                    'addressLocality'=> 'Abuja',
                    'addressRegion'  => 'FCT',
                ],
            ],
            'contactPoint' => [[
                '@type'           => 'ContactPoint',
                'telephone'       => '+234-704-304-3061',
                'email'           => 'hello@keverna.com',
                'contactType'     => 'sales',
                'areaServed'      => ['NG', 'GH', 'KE', 'ZA'],
                'availableLanguage'=> ['en'],
            ]],
        ];

        $appLd = [
            '@context' => 'https://schema.org',
            '@type'    => 'SoftwareApplication',
            '@id'      => $siteUrl.'#software',
            'name'     => 'Keverna',
            'applicationCategory'    => 'BusinessApplication',
            'applicationSubCategory' => 'Property Management System (PMS)',
            'operatingSystem'        => 'Web',
            'url'                    => $siteUrl,
            'description' => 'Elevated hospitality, one property at a time. Direct booking pages, front desk, POS, housekeeping, requisitions, guest self-service portal, credit lines, and Paystack payouts - zero commission on every booking.',
            'offers' => [
                ['@type' => 'Offer', 'name' => 'Starter',    'price' => '180000',  'priceCurrency' => 'NGN', 'description' => '1 - 5 units'],
                ['@type' => 'Offer', 'name' => 'Growth',     'price' => '420000',  'priceCurrency' => 'NGN', 'description' => '6 - 20 units'],
                ['@type' => 'Offer', 'name' => 'Pro',        'price' => '900000',  'priceCurrency' => 'NGN', 'description' => '21 - 50 units'],
                ['@type' => 'Offer', 'name' => 'Enterprise', 'price' => '1800000', 'priceCurrency' => 'NGN', 'description' => '50+ units, custom terms'],
            ],
            'featureList' => [
                'Direct booking page on your own domain',
                'Zero commission on every booking',
                'Front-desk reservations + CRM',
                'POS with offline sync',
                'Housekeeping with inspection loop',
                'Guest self-service portal via QR',
                'Multi-branch scoping from day one',
                'Paystack payouts direct to bank',
                'WhatsApp support with real humans',
            ],
            'provider' => ['@id' => $siteUrl.'#organization'],
        ];

        $faqLd = [
            '@context' => 'https://schema.org',
            '@type'    => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'What is Keverna?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Keverna is a property management system (PMS) built for African hospitality operators - hotels, serviced apartments, shortlets and workspaces. It ships a direct booking page, front desk console, POS, housekeeping, guest self-service portal, and Paystack payouts in one login, with zero commission on every booking.']],
                ['@type' => 'Question', 'name' => 'How much does Keverna cost?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Pricing is by unit count, not by feature. Starter (1-5 units) is ₦180,000/year, Growth (6-20 units) is ₦420,000/year, Pro (21-50 units) is ₦900,000/year, and Enterprise (50+ units) starts at ₦1.8M/year with custom terms. Every tier ships the complete product. Card payments carry a flat 2% split; cash and transfers are free.']],
                ['@type' => 'Question', 'name' => 'Does Keverna take a commission on bookings?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'No. Keverna takes zero commission on every booking regardless of channel. Only card payments through the platform carry a flat 2% Paystack split; cash and bank transfers are 0%.']],
                ['@type' => 'Question', 'name' => 'How do I sign up?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Book a 15-minute demo at keverna.com/demo. After the call we issue a licence code you use to unlock the self-serve registration flow at keverna.com/register.']],
                ['@type' => 'Question', 'name' => 'Does Keverna work offline?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes. The POS and core front-desk actions run offline and sync when the connection returns, so a network dip doesn\'t stop a check-in, a bar sale, or a housekeeping update.']],
                ['@type' => 'Question', 'name' => 'Where is Keverna based?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Keverna is a product of FregateLab Limited, based in Abuja, Nigeria. The team supports operators across Abuja, Lagos, Port Harcourt and beyond, primarily via WhatsApp.']],
            ],
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($orgLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/ld+json">{!! json_encode($appLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/ld+json">{!! json_encode($faqLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,700,500i|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{-- Page-scoped v3 styles. All rules target `.lp-page.lp-mkt-v3`
         so nothing leaks into the operator dashboard (which reuses
         .lp-* tokens) or the tenant landing (which lives on
         `.rb-page`). --}}
    <style>
        .lp-page.lp-mkt-v3 {
            --mkt-ink:      #0F1215;
            --mkt-ink-2:    #1B1F23;
            --mkt-cream:    #F7F3EA;
            --mkt-cream-2:  #EFE9DC;
            --mkt-paper:    #FFFFFF;
            --mkt-line:     rgba(15, 18, 21, 0.12);
            --mkt-line-2:   rgba(15, 18, 21, 0.06);
            --mkt-mute:     #6E7278;
            --mkt-accent:   #ADCB52;
            --mkt-accent-d: #8AAE3A;
            background: var(--mkt-cream);
            color: var(--mkt-ink);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .lp-page.lp-mkt-v3 * { box-sizing: border-box; }
        .lp-page.lp-mkt-v3 h1,
        .lp-page.lp-mkt-v3 h2,
        .lp-page.lp-mkt-v3 h3 {
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 600;
            letter-spacing: -0.025em;
            line-height: 1;
            margin: 0;
            color: inherit;
        }
        .lp-page.lp-mkt-v3 a { color: inherit; text-decoration: none; }
        .lp-page.lp-mkt-v3 p { margin: 0; line-height: 1.65; }
        .lp-page.lp-mkt-v3 img { display: block; max-width: 100%; }

        /* ── Layout wrappers ─────────────────────────────────────── */
        .mkt-wrap { max-width: 1240px; margin: 0 auto; padding: 0 24px; }
        @media (min-width: 900px) { .mkt-wrap { padding: 0 48px; } }
        .mkt-section { padding: 72px 0; }
        @media (min-width: 900px) { .mkt-section { padding: 112px 0; } }
        .mkt-section-tight { padding: 48px 0; }
        .mkt-section--ink { background: var(--mkt-ink); color: #F5EFE1; }
        .mkt-section--paper { background: var(--mkt-paper); }

        /* ── Typography scale ────────────────────────────────────── */
        .mkt-eyebrow {
            display: inline-flex; align-items: center; gap: 10px;
            font-size: 11px; font-weight: 600;
            letter-spacing: 0.18em; text-transform: uppercase;
            color: var(--mkt-mute);
        }
        .mkt-eyebrow::before {
            content: ''; width: 24px; height: 1px; background: currentColor;
            display: inline-block;
        }
        .mkt-section--ink .mkt-eyebrow { color: rgba(245, 239, 225, 0.7); }
        .mkt-display-xl {
            font-family: 'Fraunces', Georgia, serif; font-weight: 700;
            font-size: clamp(56px, 9vw, 140px);
            line-height: 0.92; letter-spacing: -0.04em; margin: 0;
        }
        .mkt-display-l {
            font-family: 'Fraunces', Georgia, serif; font-weight: 600;
            font-size: clamp(40px, 6vw, 80px);
            line-height: 0.98; letter-spacing: -0.028em; margin: 0;
        }
        .mkt-display-m {
            font-family: 'Fraunces', Georgia, serif; font-weight: 600;
            font-size: clamp(28px, 3.5vw, 44px);
            line-height: 1.04; letter-spacing: -0.022em; margin: 0;
        }
        .mkt-italic {
            font-family: 'Fraunces', Georgia, serif; font-style: italic;
            font-weight: 500;
        }
        .mkt-lead {
            font-size: clamp(15.5px, 1.4vw, 18px);
            color: var(--mkt-mute);
            max-width: 62ch; line-height: 1.65;
        }
        .mkt-section--ink .mkt-lead { color: rgba(245, 239, 225, 0.72); }

        /* ── Pill / button ─────────────────────────────────────────
                Scoped under .lp-mkt-v3 so specificity beats any anchor
                or button colour reset from the shared app.css layer. */
        .lp-page.lp-mkt-v3 .mkt-pill {
            display: inline-flex; align-items: center; gap: 12px;
            padding: 12px 12px 12px 22px;
            background: var(--mkt-ink) !important;
            color: #F5EFE1 !important;
            border-radius: 9999px; border: 0;
            font-family: inherit; font-size: 14px; font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: transform 100ms ease, background 200ms ease;
        }
        .lp-page.lp-mkt-v3 .mkt-pill:hover { background: var(--mkt-ink-2) !important; }
        .lp-page.lp-mkt-v3 .mkt-pill:active { transform: translateY(1px); }
        .lp-page.lp-mkt-v3 .mkt-pill .dot {
            width: 32px; height: 32px; border-radius: 9999px;
            background: var(--mkt-accent);
            color: var(--mkt-ink);
            display: inline-flex; align-items: center; justify-content: center;
        }
        .lp-page.lp-mkt-v3 .mkt-section--ink .mkt-pill {
            background: var(--mkt-cream) !important;
            color: var(--mkt-ink) !important;
        }
        .lp-page.lp-mkt-v3 .mkt-section--ink .mkt-pill:hover { background: #fff !important; }
        .mkt-link-secondary {
            font-size: 14px; color: inherit; opacity: 0.75;
            text-decoration: underline; text-underline-offset: 4px;
            text-decoration-color: rgba(0, 0, 0, 0.25);
        }
        .mkt-section--ink .mkt-link-secondary {
            text-decoration-color: rgba(255, 255, 255, 0.35);
        }
        .mkt-link-secondary:hover { opacity: 1; }

        /* ── Nav ─────────────────────────────────────────────────── */
        .mkt-nav {
            position: sticky; top: 0; z-index: 50;
            background: rgba(247, 243, 234, 0.88);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid var(--mkt-line-2);
        }
        .mkt-nav-inner {
            display: flex; align-items: center; justify-content: space-between;
            gap: 24px; padding: 16px 0;
        }
        .mkt-brand {
            display: inline-flex; align-items: center; gap: 10px;
            font-family: 'Fraunces', Georgia, serif; font-weight: 600;
            font-size: 20px; color: var(--mkt-ink);
        }
        .mkt-brand-mark {
            width: 30px; height: 30px; border-radius: 8px;
            background: var(--mkt-ink); color: var(--mkt-cream);
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mkt-nav-links {
            display: none; gap: 32px; font-size: 13.5px;
            color: var(--mkt-ink); opacity: 0.75;
        }
        .mkt-nav-links a { transition: opacity 160ms; }
        .mkt-nav-links a:hover { opacity: 1; }
        @media (min-width: 900px) { .mkt-nav-links { display: inline-flex; } }
        .mkt-nav-cta { display: inline-flex; gap: 10px; align-items: center; }
        .mkt-nav-signin {
            display: none; font-size: 13.5px; color: var(--mkt-ink);
            opacity: 0.75; padding: 8px 4px;
        }
        @media (min-width: 640px) { .mkt-nav-signin { display: inline-flex; } }

        /* Mobile menu (native details) */
        .mkt-mob-nav { position: relative; }
        @media (min-width: 900px) { .mkt-mob-nav { display: none; } }
        .mkt-mob-nav summary {
            list-style: none; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; border-radius: 10px;
            border: 1px solid var(--mkt-line);
        }
        .mkt-mob-nav summary::-webkit-details-marker { display: none; }
        .mkt-mob-nav-panel {
            position: absolute; top: calc(100% + 10px); right: 0;
            min-width: 240px; background: var(--mkt-paper);
            border: 1px solid var(--mkt-line);
            border-radius: 14px; padding: 12px 6px;
            box-shadow: 0 30px 60px -30px rgba(0,0,0,0.35);
            display: grid; gap: 2px; z-index: 100;
        }
        .mkt-mob-nav-panel a {
            display: block; padding: 10px 14px; font-size: 14px;
            color: var(--mkt-ink); opacity: 0.8; border-radius: 8px;
        }
        .mkt-mob-nav-panel a:hover { background: var(--mkt-cream-2); opacity: 1; }

        /* ── Hero pattern ─────────────────────────────────────────
                Diagonal accent ribbons fanning from the top-right so
                the ink section reads as a designed surface, not a flat
                slab (StaffNet-style). SVG is decorative + aria-hidden;
                pointer-events off so it never eats a click. Content
                inside .mkt-hero-inner sits at z-index 1 to stay
                readable. */
        .mkt-hero-shell {
            position: relative;
            overflow: hidden;
            isolation: isolate;
        }
        .mkt-hero-pattern {
            position: absolute;
            top: 0; right: 0;
            width: min(70%, 1100px);
            height: 100%;
            pointer-events: none;
            z-index: 0;
            mask-image: linear-gradient(to left, #000 55%, transparent 100%);
            -webkit-mask-image: linear-gradient(to left, #000 55%, transparent 100%);
        }
        .mkt-hero-pattern svg {
            display: block; width: 100%; height: 100%;
        }
        .mkt-hero-inner {
            position: relative;
            z-index: 1;
        }
        @media (max-width: 640px) {
            .mkt-hero-pattern {
                width: 100%;
                opacity: 0.55;
                mask-image: linear-gradient(to bottom, transparent 0%, #000 45%);
                -webkit-mask-image: linear-gradient(to bottom, transparent 0%, #000 45%);
            }
        }

        /* ── Hero ────────────────────────────────────────────────── */
        .mkt-hero {
            display: grid; grid-template-columns: 1fr;
            gap: 40px; align-items: end;
        }
        @media (min-width: 900px) {
            .mkt-hero { grid-template-columns: 1.15fr 1fr; gap: 64px; }
        }
        .mkt-hero-title {
            margin-top: 20px;
            color: #F5EFE1;
        }
        .mkt-hero-photo {
            position: relative;
            aspect-ratio: 5 / 6;
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(135deg, #23272C, #14171A);
        }
        .mkt-hero-photo img,
        .mkt-hero-photo video {
            width: 100%; height: 100%; object-fit: cover;
        }
        .mkt-hero-quote {
            position: absolute; left: -12px; bottom: 24px;
            max-width: 300px; padding: 20px 22px;
            background: var(--mkt-cream); color: var(--mkt-ink);
            border-radius: 14px;
            box-shadow: 0 30px 60px -20px rgba(0, 0, 0, 0.45);
        }
        @media (min-width: 900px) { .mkt-hero-quote { left: -20px; } }
        .mkt-hero-quote-body {
            font-family: 'Fraunces', Georgia, serif; font-weight: 500;
            font-size: 17px; line-height: 1.32;
        }
        .mkt-hero-quote-meta {
            margin-top: 12px; font-size: 11px; font-weight: 600;
            letter-spacing: 0.16em; text-transform: uppercase;
            color: var(--mkt-mute);
        }
        .mkt-hero-cta-row {
            display: flex; flex-wrap: wrap; align-items: center;
            gap: 24px; margin-top: 32px;
        }
        .mkt-hero-strip {
            border-top: 1px solid rgba(245, 239, 225, 0.12);
            margin-top: 64px;
        }
        .mkt-hero-strip-inner {
            display: grid; grid-template-columns: repeat(2, 1fr);
        }
        @media (min-width: 720px) {
            .mkt-hero-strip-inner { grid-template-columns: repeat(4, 1fr); }
        }
        .mkt-hero-strip-cell {
            padding: 28px 20px;
            border-right: 1px solid rgba(245, 239, 225, 0.10);
            border-bottom: 1px solid rgba(245, 239, 225, 0.10);
        }
        @media (min-width: 720px) {
            .mkt-hero-strip-cell { padding: 32px 24px; border-bottom: 0; }
            .mkt-hero-strip-cell:last-child { border-right: 0; }
        }
        .mkt-hero-strip-num {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 11px; letter-spacing: 0.16em;
            color: rgba(245, 239, 225, 0.55);
        }
        .mkt-hero-strip-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 18px; font-weight: 600;
            color: #F5EFE1; margin-top: 6px;
        }
        .mkt-hero-strip-sub {
            font-size: 12.5px; color: rgba(245, 239, 225, 0.55);
            margin-top: 4px;
        }

        /* ── Numbers strip ───────────────────────────────────────── */
        .mkt-numbers { display: grid; gap: 40px 32px; grid-template-columns: 1fr; }
        @media (min-width: 600px) { .mkt-numbers { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 900px) { .mkt-numbers { grid-template-columns: repeat(4, 1fr); } }
        .mkt-num-big {
            font-family: 'Fraunces', Georgia, serif; font-weight: 600;
            font-size: clamp(48px, 5.5vw, 76px);
            line-height: 0.94; letter-spacing: -0.03em;
            color: var(--mkt-ink);
        }
        .mkt-num-head {
            margin-top: 16px; font-size: 14px; font-weight: 600;
            color: var(--mkt-ink);
        }
        .mkt-num-sub {
            margin-top: 4px; font-size: 12.5px; color: var(--mkt-mute);
        }

        /* ── Section head (eyebrow + display headline + lead) ─── */
        .mkt-section-head {
            display: grid; grid-template-columns: 1fr;
            gap: 20px; align-items: end;
        }
        @media (min-width: 900px) {
            .mkt-section-head { grid-template-columns: 1.2fr 1fr; gap: 48px; }
        }
        .mkt-section-head-title { margin-top: 14px; }

        /* ── Modules 2-column showcase ─────────────────────────── */
        .mkt-modules {
            margin-top: 56px; display: grid;
            grid-template-columns: 1fr; gap: 20px;
        }
        @media (min-width: 900px) { .mkt-modules { grid-template-columns: 1fr 1fr; gap: 28px; } }
        .mkt-module-card {
            background: var(--mkt-paper);
            border-radius: 24px; overflow: hidden;
            border: 1px solid var(--mkt-line-2);
            display: flex; flex-direction: column;
            transition: transform 300ms ease, box-shadow 300ms ease;
        }
        .mkt-module-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 40px 80px -40px rgba(0, 0, 0, 0.25);
        }
        .mkt-module-card--dark {
            background: var(--mkt-ink); color: #F5EFE1;
            border-color: transparent;
        }
        .mkt-module-photo {
            aspect-ratio: 4 / 3;
            background: var(--mkt-cream-2);
            position: relative; overflow: hidden;
        }
        .mkt-module-photo img { width: 100%; height: 100%; object-fit: cover; }
        .mkt-module-photo-stub {
            width: 100%; height: 100%;
            background:
                repeating-linear-gradient(45deg, rgba(15,18,21,0.05) 0 12px, transparent 12px 24px),
                var(--mkt-cream-2);
        }
        .mkt-module-body { padding: 28px 30px 32px; }
        .mkt-module-eyebrow {
            font-size: 11px; font-weight: 600;
            letter-spacing: 0.16em; text-transform: uppercase;
            color: var(--mkt-mute);
        }
        .mkt-module-title {
            margin-top: 10px;
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(26px, 3vw, 38px); font-weight: 600;
            letter-spacing: -0.02em; line-height: 1.05;
        }
        .mkt-module-body p { margin-top: 14px; font-size: 15px; color: var(--mkt-mute); }
        .mkt-module-card--dark .mkt-module-body p { color: rgba(245, 239, 225, 0.72); }
        .mkt-module-list {
            list-style: none; margin: 22px 0 0; padding: 0;
            display: flex; flex-wrap: wrap; gap: 8px;
        }
        .mkt-module-list li {
            padding: 6px 12px;
            background: var(--mkt-cream);
            border-radius: 999px; font-size: 12.5px;
            color: var(--mkt-ink);
        }
        .mkt-module-card--dark .mkt-module-list li {
            background: rgba(245, 239, 225, 0.10);
            color: #F5EFE1;
        }

        /* ── Facilities: 6-cell mixed grid (LUNA-style) ────────── */
        .mkt-facilities {
            margin-top: 40px;
            display: grid; gap: 4px;
            grid-template-columns: 1fr;
        }
        @media (min-width: 640px) { .mkt-facilities { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 900px) { .mkt-facilities { grid-template-columns: repeat(3, 1fr); } }
        .mkt-fac-cell {
            aspect-ratio: 4 / 3;
            padding: 24px;
            background: var(--mkt-paper);
            display: flex; flex-direction: column; justify-content: space-between;
            border: 1px solid var(--mkt-line-2);
        }
        .mkt-fac-cell--photo {
            padding: 0;
            background: var(--mkt-cream-2);
            position: relative; overflow: hidden;
        }
        .mkt-fac-cell--photo img { width: 100%; height: 100%; object-fit: cover; }
        .mkt-fac-cell--photo .stub {
            width: 100%; height: 100%;
            background:
                repeating-linear-gradient(45deg, rgba(15,18,21,0.05) 0 12px, transparent 12px 24px),
                var(--mkt-cream-2);
        }
        .mkt-fac-num {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 11px; letter-spacing: 0.16em;
            color: var(--mkt-mute);
        }
        .mkt-fac-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 22px; font-weight: 600;
            color: var(--mkt-ink); letter-spacing: -0.02em;
            line-height: 1.15;
        }
        .mkt-fac-sub {
            font-size: 13.5px; color: var(--mkt-mute);
            margin-top: 8px;
        }

        /* ── Mission strip (4 columns) ─────────────────────────── */
        .mkt-mission {
            margin-top: 48px;
            display: grid; gap: 40px 32px;
            grid-template-columns: 1fr;
        }
        @media (min-width: 700px) { .mkt-mission { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 900px) { .mkt-mission { grid-template-columns: repeat(4, 1fr); } }
        .mkt-miss-num {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 11px; letter-spacing: 0.18em;
            color: var(--mkt-accent-d);
        }
        .mkt-miss-title {
            margin-top: 12px;
            font-family: 'Fraunces', Georgia, serif;
            font-size: 20px; font-weight: 600;
            letter-spacing: -0.015em;
        }
        .mkt-miss-body {
            margin-top: 10px; font-size: 14px; color: var(--mkt-mute);
        }

        /* ── Full-bleed dark callout with sample screenshot ─────
                Left column: eyebrow + headline + copy.
                Right column: browser-framed screenshot of a sample
                Keverna booking page. Split stacks on narrow viewports. */
        .mkt-callout-title { max-width: 14ch; }
        @media (max-width: 640px) { .mkt-callout-title { max-width: 100%; } }
        .mkt-callout {
            position: relative;
            background: var(--mkt-ink);
            color: #F5EFE1;
            overflow: hidden;
        }
        .mkt-callout-inner {
            padding: 80px 0;
            display: grid;
            gap: 48px;
            grid-template-columns: 1fr;
            align-items: center;
        }
        @media (min-width: 900px) {
            .mkt-callout-inner {
                padding: 112px 0;
                grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr);
                gap: 72px;
            }
        }
        .mkt-callout .mkt-eyebrow { color: rgba(245, 239, 225, 0.72); }
        .mkt-callout .mkt-eyebrow::before { background: currentColor; }

        .mkt-sample-frame {
            width: 100%;
            background: var(--mkt-paper);
            border-radius: 14px; overflow: hidden;
            box-shadow:
                0 50px 100px -30px rgba(0, 0, 0, 0.55),
                0 0 0 1px rgba(255, 255, 255, 0.04);
        }
        .mkt-sample-chrome {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 16px;
            background: #F0EAE0;
            border-bottom: 1px solid var(--mkt-line-2);
        }
        .mkt-sample-dots { display: inline-flex; gap: 6px; flex-shrink: 0; }
        .mkt-sample-dots span {
            width: 10px; height: 10px; border-radius: 999px;
            background: rgba(15, 18, 21, 0.18);
        }
        .mkt-sample-url {
            flex: 1; min-width: 0;
            padding: 5px 12px;
            background: var(--mkt-paper);
            border-radius: 6px;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 11.5px; color: var(--mkt-mute);
            display: inline-flex; align-items: center; gap: 8px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .mkt-sample-url::before {
            content: ''; width: 9px; height: 9px; border-radius: 999px;
            background: #6FCF97; flex-shrink: 0;
        }
        .mkt-sample-body {
            aspect-ratio: 16 / 10;
            background: var(--mkt-cream-2);
            position: relative; overflow: hidden;
        }
        .mkt-sample-body img,
        .mkt-sample-body video {
            width: 100%; height: 100%;
            object-fit: cover; object-position: top center;
            display: block;
        }
        /* Center branded play button — same visual language as the
           stays-show video tour so the two surfaces feel consistent.
           Warm-white circle with a soft pulse ring, olive fill on
           hover, deep-ink triangle inside. */
        .mkt-sample-play {
            position: absolute; left: 50%; top: 50%;
            transform: translate(-50%, -50%);
            width: 84px; height: 84px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.94);
            border: 0;
            cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            color: #14171A;
            box-shadow: 0 20px 44px -14px rgba(20, 15, 8, 0.45);
            transition: transform 200ms cubic-bezier(0.16, 1, 0.3, 1), background 200ms;
            font-family: inherit;
            z-index: 2;
        }
        .mkt-sample-play::after {
            content: '';
            position: absolute; inset: -6px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.6);
            opacity: 0;
            animation: mkt-sample-pulse 2.4s ease-out infinite;
        }
        @keyframes mkt-sample-pulse {
            0% { transform: scale(0.9); opacity: 0.7; }
            80% { transform: scale(1.28); opacity: 0; }
            100% { transform: scale(1.28); opacity: 0; }
        }
        .mkt-sample-play:hover {
            transform: translate(-50%, -50%) scale(1.05);
            background: #ADCB52;
        }
        .mkt-sample-play svg {
            width: 30px; height: 30px;
            margin-left: 4px;
        }
        @media (max-width: 640px) {
            .mkt-callout-inner { padding: 56px 0 72px; gap: 32px; }
            .mkt-sample-frame { border-radius: 12px; }
            .mkt-sample-chrome { padding: 9px 12px; gap: 8px; }
            .mkt-sample-body { aspect-ratio: 4 / 3; }
            .mkt-sample-play { width: 66px; height: 66px; }
            .mkt-sample-play svg { width: 24px; height: 24px; margin-left: 3px; }
        }

        /* ── Testimonials strip ────────────────────────────────── */
        .mkt-quotes {
            margin-top: 48px;
            display: grid; gap: 20px;
            grid-template-columns: 1fr;
        }
        @media (min-width: 700px) { .mkt-quotes { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1000px) { .mkt-quotes { grid-template-columns: repeat(3, 1fr); } }
        .mkt-quote-card {
            padding: 28px;
            background: var(--mkt-paper);
            border: 1px solid var(--mkt-line-2);
            border-radius: 20px;
            display: flex; flex-direction: column; gap: 22px;
        }
        .mkt-quote-body {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 18px; font-weight: 500;
            line-height: 1.4; color: var(--mkt-ink);
        }
        .mkt-quote-author {
            display: flex; align-items: center; gap: 12px;
            margin-top: auto;
        }
        .mkt-quote-avatar {
            width: 40px; height: 40px; border-radius: 999px;
            background: var(--mkt-cream-2); flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 600; color: var(--mkt-mute); font-size: 15px;
        }
        .mkt-quote-name { font-size: 14px; font-weight: 600; color: var(--mkt-ink); }
        .mkt-quote-property { font-size: 12.5px; color: var(--mkt-mute); }

        /* ── Pricing ───────────────────────────────────────────── */
        .mkt-price-grid {
            margin-top: 48px;
            display: grid; gap: 16px;
            grid-template-columns: 1fr;
        }
        @media (min-width: 700px) { .mkt-price-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1000px) { .mkt-price-grid { grid-template-columns: repeat(4, 1fr); gap: 20px; } }
        .mkt-price-card {
            padding: 28px;
            background: var(--mkt-cream);
            border-radius: 20px;
            display: flex; flex-direction: column;
            border: 1px solid transparent;
        }
        .mkt-price-card--featured {
            border-color: var(--mkt-accent-d);
            background: var(--mkt-paper);
            box-shadow: 0 40px 80px -40px rgba(138, 174, 58, 0.28);
        }
        .mkt-price-eyebrow {
            font-size: 11px; font-weight: 600;
            letter-spacing: 0.16em; text-transform: uppercase;
            color: var(--mkt-mute);
        }
        .mkt-price-card--featured .mkt-price-eyebrow { color: var(--mkt-accent-d); }
        .mkt-price-name {
            margin-top: 8px;
            font-family: 'Fraunces', Georgia, serif;
            font-size: 24px; font-weight: 600;
            letter-spacing: -0.02em;
        }
        .mkt-price-units {
            margin-top: 4px;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 11px; letter-spacing: 0.14em;
            text-transform: uppercase; color: var(--mkt-mute);
        }
        .mkt-price-amount {
            margin-top: 20px;
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(32px, 3vw, 38px); font-weight: 600;
            line-height: 1;
        }
        .mkt-price-per { margin-top: 6px; font-size: 12.5px; color: var(--mkt-mute); }
        .mkt-price-blurb {
            margin-top: 16px; font-size: 13.5px;
            color: var(--mkt-mute); line-height: 1.55;
        }
        .mkt-price-card .mkt-pill { margin-top: 24px; align-self: flex-start; }

        /* ── Dark demo section (developers + video) ─────────────── */
        .mkt-demo {
            display: grid; gap: 40px;
            grid-template-columns: 1fr; align-items: center;
        }
        @media (min-width: 900px) {
            .mkt-demo { grid-template-columns: 1fr 1fr; gap: 64px; }
        }
        .mkt-demo-card {
            padding: 28px 32px;
            background: var(--mkt-ink-2);
            border: 1px solid rgba(245, 239, 225, 0.08);
            border-radius: 22px;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 13px; color: rgba(245, 239, 225, 0.85);
            line-height: 1.65;
        }
        .mkt-demo-card pre {
            margin: 0; white-space: pre-wrap; word-break: break-word;
            font-family: inherit; color: inherit;
        }

        /* ── Newsletter ────────────────────────────────────────── */
        .mkt-newsletter-form {
            margin-top: 32px;
            display: flex; gap: 8px; max-width: 480px;
            border-bottom: 2px solid var(--mkt-ink); padding-bottom: 10px;
        }
        .mkt-newsletter-form input {
            flex: 1; background: transparent; border: 0; outline: 0;
            font-family: inherit; font-size: 16px; color: var(--mkt-ink);
            padding: 8px 4px;
        }
        .mkt-newsletter-form input::placeholder { color: var(--mkt-mute); }

        /* ── Live kobo counter ─────────────────────────────────────
           Sits between the CTA row + the 4-cell hero strip. Fraunces
           italic ticker so the number reads as authored, not as a
           dashboard metric. */
        .mkt-kobo {
            margin-top: clamp(40px, 6vw, 68px);
            padding: 20px clamp(18px, 3vw, 28px);
            border-radius: 14px;
            background: rgba(247, 243, 234, 0.04);
            border: 1px solid rgba(247, 243, 234, 0.10);
            display: grid;
            gap: 6px;
            max-width: 760px;
        }
        .mkt-kobo-line {
            display: flex; flex-wrap: wrap; align-items: baseline; gap: 12px;
        }
        .mkt-kobo-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 8px;
            background: rgba(182, 217, 126, 0.14);
            border-radius: 999px;
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 10px;
            letter-spacing: 0.24em;
            color: var(--mkt-accent, #ADCB52);
            text-transform: uppercase;
            flex-shrink: 0;
            align-self: center;
        }
        .mkt-kobo-dot {
            width: 6px; height: 6px; border-radius: 999px;
            background: var(--mkt-accent, #ADCB52);
            box-shadow: 0 0 8px rgba(173, 203, 82, 0.65);
            animation: mkt-kobo-pulse 1600ms ease-in-out infinite;
        }
        @keyframes mkt-kobo-pulse {
            0%, 100% { opacity: 0.55; transform: scale(1); }
            50%      { opacity: 1; transform: scale(1.35); }
        }
        @media (prefers-reduced-motion: reduce) {
            .mkt-kobo-dot { animation: none; opacity: 0.85; }
        }
        .mkt-kobo-italic {
            font-family: 'Fraunces', Georgia, serif;
            font-style: italic;
            font-weight: 400;
            font-size: clamp(20px, 2.4vw, 28px);
            line-height: 1.25;
            color: var(--mkt-paper);
            letter-spacing: -0.01em;
        }
        .mkt-kobo-value {
            font-style: normal;
            font-weight: 600;
            color: var(--mkt-accent, #ADCB52);
            font-variant-numeric: tabular-nums lining-nums;
            /* Reserve a width so the one-time count-up doesn't shift
               the italic "routed on Keverna" phrase during the ~1.4s
               reveal. 8ch fits up to ₦999,999,999 comfortably. */
            display: inline-block;
            min-width: 8ch;
        }
        .mkt-kobo-sub {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: 11.5px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(247, 243, 234, 0.55);
        }
        .mkt-kobo-zero {
            color: var(--mkt-paper);
            font-weight: 600;
            letter-spacing: 0;
        }

        /* ── Wordmark closer (kept for anywhere that still wants it) --- */
        .mkt-wordmark {
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 700;
            font-size: clamp(90px, 24vw, 320px);
            line-height: 0.85; letter-spacing: -0.05em;
            color: var(--mkt-ink); margin: 0;
            text-align: center; padding: 0 16px;
        }

        /* ── Editorial masthead closer ─────────────────────────────
           Cereal Magazine move. Full-bleed cream-on-ink section with a
           Fraunces italic operator pull-quote at 60-96px + a mono
           colophon strip. Replaces the raw wordmark so the closing
           moment reads as "authored" instead of "brand-punched-you". */
        .mkt-masthead {
            position: relative;
            margin-top: 40px;
            padding: clamp(72px, 12vw, 160px) 24px clamp(48px, 6vw, 72px);
            background: var(--mkt-ink);
            color: var(--mkt-paper);
            overflow: hidden;
            isolation: isolate;
        }
        .mkt-masthead::before {
            content: '';
            position: absolute; inset: -30% -10%;
            background:
                radial-gradient(50% 55% at 82% 25%, rgba(182, 217, 126, 0.18) 0%, transparent 60%),
                radial-gradient(45% 50% at 15% 82%, rgba(201, 148, 65, 0.14) 0%, transparent 60%);
            filter: blur(40px);
            opacity: 0.9;
            z-index: -1;
        }
        .mkt-masthead-inner {
            max-width: 1120px;
            margin: 0 auto;
            display: grid;
            gap: clamp(28px, 4vw, 48px);
        }
        .mkt-masthead-eyebrow {
            display: inline-flex; align-items: center; gap: 10px;
            font-family: ui-monospace, 'JetBrains Mono', SFMono-Regular, monospace;
            font-size: 10.5px;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            color: rgba(247, 243, 234, 0.55);
        }
        .mkt-masthead-eyebrow::before {
            content: '';
            display: inline-block;
            width: 24px; height: 1px;
            background: var(--mkt-olive, #B6D97E);
            opacity: 0.85;
        }
        .mkt-masthead-quote {
            font-family: 'Fraunces', Georgia, serif;
            font-style: italic;
            font-weight: 400;
            font-size: clamp(40px, 7vw, 96px);
            line-height: 1.05;
            letter-spacing: -0.025em;
            color: var(--mkt-paper);
            margin: 0;
            max-width: 18ch;
        }
        .mkt-masthead-quote em {
            font-style: italic;
            color: var(--mkt-olive, #B6D97E);
        }
        .mkt-masthead-attribution {
            display: flex; flex-wrap: wrap; align-items: center; gap: 14px 22px;
            padding-top: clamp(20px, 3vw, 28px);
            border-top: 1px solid rgba(247, 243, 234, 0.14);
            font-family: ui-monospace, 'JetBrains Mono', SFMono-Regular, monospace;
            font-size: 11.5px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(247, 243, 234, 0.70);
        }
        .mkt-masthead-attribution strong {
            color: var(--mkt-paper);
            font-weight: 600;
        }
        .mkt-masthead-attribution span::before {
            content: '·';
            display: inline-block;
            margin-right: 14px;
            color: rgba(247, 243, 234, 0.4);
        }
        .mkt-masthead-attribution > :first-child::before { display: none; }
        .mkt-masthead-colophon {
            font-family: ui-monospace, 'JetBrains Mono', SFMono-Regular, monospace;
            font-size: 10.5px;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: rgba(247, 243, 234, 0.45);
            text-align: center;
            margin: 0;
        }

        /* ── Footer ────────────────────────────────────────────── */
        .mkt-footer { border-top: 1px solid var(--mkt-line-2); padding: 40px 0 48px; }
        .mkt-footer-inner {
            display: flex; flex-wrap: wrap; align-items: flex-start;
            justify-content: space-between; gap: 24px;
        }
        .mkt-footer-links {
            display: flex; flex-wrap: wrap; gap: 24px;
            font-size: 13.5px; color: var(--mkt-ink); opacity: 0.75;
        }
        .mkt-footer-links a:hover { opacity: 1; text-decoration: underline; text-underline-offset: 4px; }
        .mkt-footer-meta {
            margin-top: 24px; padding-top: 16px;
            border-top: 1px solid var(--mkt-line-2);
            font-size: 12px; color: var(--mkt-mute);
            display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between;
        }
        .mkt-footer-meta a {
            color: var(--mkt-ink);
            opacity: 0.7;
            transition: opacity 120ms;
        }
        .mkt-footer-meta a:hover {
            opacity: 1;
            text-decoration: underline;
            text-underline-offset: 3px;
        }
        .mkt-trust-badge:hover { background: rgba(15,18,21,0.08) !important; }

        /* ── Mobile polish ────────────────────────────────────────
                Hero + callout displays at their default clamp() min
                (56/40/28px) still overflow narrow viewports for words
                like "Your bookings." so we cap them tighter here.
                The nav's Start-trial pill is hidden below 480px so
                brand + hamburger have room to breathe. */
        @media (max-width: 640px) {
            .lp-page.lp-mkt-v3 .mkt-display-xl {
                font-size: clamp(38px, 11vw, 56px);
                line-height: 0.95;
                letter-spacing: -0.03em;
            }
            .lp-page.lp-mkt-v3 .mkt-display-l {
                font-size: clamp(30px, 8vw, 44px);
                letter-spacing: -0.02em;
            }
            .lp-page.lp-mkt-v3 .mkt-display-m {
                font-size: clamp(24px, 6.5vw, 32px);
            }
            .lp-page.lp-mkt-v3 .mkt-num-big {
                font-size: clamp(38px, 12vw, 60px);
            }
            /* Callout headline shouldn't push a max-width tighter than
               the viewport can hold for long words like "bookings.". */
            .lp-page.lp-mkt-v3 .mkt-callout .mkt-display-l { max-width: 100%; }
            .lp-page.lp-mkt-v3 .mkt-hero-title { max-width: 100%; }

            /* Force wrap between "Your rates." and "Your bookings."
               naturally instead of relying on the desktop <br>. */
            .lp-page.lp-mkt-v3 .mkt-callout-br { display: none; }
            .lp-page.lp-mkt-v3 .mkt-callout-title { line-height: 1.05; }

            /* Push the callout content 30px in from the section edges
               on mobile so the copy + frame breathe against the ink
               background rather than kissing the viewport border. */
            .lp-page.lp-mkt-v3 .mkt-callout .mkt-wrap { padding: 30px; }

            /* Sample frame border-radius is already reduced elsewhere;
               ensure the shadow doesn't cause horizontal overflow. */
            .lp-page.lp-mkt-v3 .mkt-sample-frame { box-shadow: 0 24px 60px -30px rgba(0,0,0,0.45); }

            /* Section padding tightens too so ink slabs don't dwarf
               phone screens with unused whitespace. */
            .lp-page.lp-mkt-v3 .mkt-section { padding: 56px 0; }

            /* Hero pattern is dimmer on mobile so the text stays
               legible without the ribbons overwhelming a small screen. */
            .lp-page.lp-mkt-v3 .mkt-hero-pattern { opacity: 0.4; }
        }
        @media (max-width: 480px) {
            .lp-page.lp-mkt-v3 .mkt-nav-cta .mkt-pill {
                display: none;
            }
            .lp-page.lp-mkt-v3 .mkt-nav-inner { padding: 12px 0; gap: 12px; }
        }

        /* ── Scroll reveal ────────────────────────────────────────
                Elements tagged with data-scroll-fade start slightly
                down + transparent, then rise into place the first
                time IntersectionObserver sees them cross the viewport.
                Direction hints (up|left|right) + stagger via
                data-scroll-delay let a single utility drive most of
                the page's motion.

                SCOPED UNDER .js so bots / OG scrapers / JS-disabled
                sessions render the full page at rest instead of a
                cream void waiting for an IntersectionObserver that
                never fires. The .js class is added by an inline
                script in <head> before any stylesheet parses. */
        .js [data-scroll-fade] {
            opacity: 0;
            transform: translate3d(0, 24px, 0);
            transition:
                opacity 800ms cubic-bezier(0.16, 1, 0.3, 1),
                transform 800ms cubic-bezier(0.16, 1, 0.3, 1);
            will-change: opacity, transform;
        }
        .js [data-scroll-fade="left"]  { transform: translate3d(-24px, 0, 0); }
        .js [data-scroll-fade="right"] { transform: translate3d( 24px, 0, 0); }
        .js [data-scroll-fade].is-visible {
            opacity: 1;
            transform: translate3d(0, 0, 0);
        }
        /* Reduced-motion safety: no travel, no fade delay. Content
           renders straight-through so the page still reads for
           anyone opting out. */
        @media (prefers-reduced-motion: reduce) {
            .js [data-scroll-fade],
            .js [data-scroll-fade].is-visible {
                opacity: 1;
                transform: none;
                transition: none;
            }
        }
    </style>

    @include('partials.platform-pulse')
</head>
<body class="lp-page lp-mkt-v3">

@php
    $pillArrow = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';

    $hasHeroMp4  = file_exists(public_path('img/landing/hero.mp4'));
    $hasHeroWebm = file_exists(public_path('img/landing/hero.webm'));
    $hasHeroJpg  = file_exists(public_path('img/landing/hero.jpg'));

    // Facilities grid, alternates label cells with photo cells so the
    // 3×2 layout reads as a magazine grid rather than a table.
    $facilities = [
        ['kind' => 'text',  'num' => '#01', 'title' => 'Front desk',        'sub' => 'Arrivals, in-house, extend, checkout - one screen.'],
        ['kind' => 'photo', 'img'   => 'https://keverna-file.dokwe.com/marketing/operate-dashboard-sidebar.jpg'],
        ['kind' => 'text',  'num' => '#02', 'title' => 'Direct booking',    'sub' => 'Your own page. Guest pays via card. You keep 100%.'],
        ['kind' => 'photo', 'img'   => 'https://keverna-file.dokwe.com/marketing/revenue-snapshot-example.jpg'],
        ['kind' => 'text',  'num' => '#03', 'title' => 'POS + inventory',   'sub' => 'Bar, kitchen, front desk - offline-first with sync.'],
        ['kind' => 'text',  'num' => '#04', 'title' => 'Housekeeping',      'sub' => 'Auto inspection loop cleared before the next check-in.'],
    ];

    $tiers = [
        ['eyebrow' => 'First property',   'name' => 'Starter',    'units' => '1 – 5 units',   'price_ngn' => '₦180,000',   'price_usd' => '$125',       'blurb' => 'Built for your first property or a small portfolio testing the water.',              'cta_label' => 'Book a demo', 'cta_href' => route('demo', ['src' => 'pricing']), 'featured' => false],
        ['eyebrow' => 'Most operators',   'name' => 'Growth',     'units' => '6 – 20 units',  'price_ngn' => '₦420,000',   'price_usd' => '$290',       'blurb' => 'For the operator who has stopped tracking bookings on paper and started running a small team.', 'cta_label' => 'Book a demo', 'cta_href' => route('demo', ['src' => 'pricing']), 'featured' => true],
        ['eyebrow' => 'At real scale',    'name' => 'Pro',        'units' => '21 – 50 units', 'price_ngn' => '₦900,000',   'price_usd' => '$620',       'blurb' => 'Serviced-apartment portfolios, boutique hotels and co-working spaces operating at scale.',  'cta_label' => 'Book a demo', 'cta_href' => route('demo', ['src' => 'pricing']), 'featured' => false],
        ['eyebrow' => 'Groups + chains',  'name' => 'Enterprise', 'units' => '50+ units',     'price_ngn' => 'From ₦1.8M', 'price_usd' => 'From $1,250','blurb' => 'Multi-property groups, chains and residential operators that need custom terms.',           'cta_label' => 'Talk to us',   'cta_href' => 'mailto:hello@keverna.com?subject=Enterprise%20pricing', 'featured' => false],
    ];
@endphp

{{-- ────────────────────────────────────────────────────────────
     NAV
──────────────────────────────────────────────────────────────── --}}
<header class="mkt-nav">
    <div class="mkt-wrap mkt-nav-inner">
        <a href="/" class="mkt-brand" aria-label="Keverna home">
            <span class="mkt-brand-mark" aria-hidden="true">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
            </span>
            Keverna
        </a>

        <nav class="mkt-nav-links" aria-label="Product">
            <a href="{{ route('marketplace.stays.index') }}">Book a stay</a>
            <a href="#modules">Product</a>
            <a href="#facilities">Features</a>
            <a href="#pricing">Pricing</a>
            <a href="#developers">Developers</a>
            <a href="mailto:hello@keverna.com">Contact</a>
        </nav>

        <div class="mkt-nav-cta">
            @include('partials.region-picker')
            @auth
                <a href="{{ url('/dashboard') }}" class="mkt-nav-signin">Dashboard</a>
            @else
                <a href="{{ Route::has('login') ? route('login') : '/login' }}" class="mkt-nav-signin">Sign in</a>
            @endauth
            <a href="{{ route('demo', ['src' => 'nav']) }}" class="mkt-pill">
                Book a demo
                <span class="dot">{!! $pillArrow !!}</span>
            </a>
            <details class="mkt-mob-nav">
                <summary aria-label="Open menu">
                    <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
                </summary>
                <div class="mkt-mob-nav-panel">
                    <a href="{{ route('marketplace.stays.index') }}">Book a stay</a>
                    <a href="#modules">Product</a>
                    <a href="#facilities">Features</a>
                    <a href="#pricing">Pricing</a>
                    <a href="#developers">Developers</a>
                    <a href="mailto:hello@keverna.com">Contact</a>
                    @auth
                        <a href="{{ url('/dashboard') }}">Dashboard</a>
                    @else
                        <a href="{{ Route::has('login') ? route('login') : '/login' }}">Sign in</a>
                    @endauth
                </div>
            </details>
        </div>
    </div>
</header>

{{-- ────────────────────────────────────────────────────────────
     HERO (dark)
──────────────────────────────────────────────────────────────── --}}
<main id="main-content">
<section class="mkt-section mkt-section--ink mkt-hero-shell" data-testid="hero" aria-labelledby="hero-heading">
    {{-- Decorative ribbon pattern. Diagonal beams of the brand accent
         fan from the top-right corner at graduated opacities, faded
         out to the left via a CSS mask so the headline area stays
         high-contrast. --}}
    <div class="mkt-hero-pattern" aria-hidden="true">
        <svg viewBox="0 0 1200 800" preserveAspectRatio="xMaxYMin slice">
            <defs>
                <linearGradient id="mktRibbon1" x1="1" y1="0" x2="0" y2="1">
                    <stop offset="0%"  stop-color="#ADCB52" stop-opacity="0.55"/>
                    <stop offset="100%" stop-color="#ADCB52" stop-opacity="0.05"/>
                </linearGradient>
                <linearGradient id="mktRibbon2" x1="1" y1="0" x2="0" y2="1">
                    <stop offset="0%"  stop-color="#8AAE3A" stop-opacity="0.40"/>
                    <stop offset="100%" stop-color="#8AAE3A" stop-opacity="0.03"/>
                </linearGradient>
                <linearGradient id="mktRibbon3" x1="1" y1="0" x2="0" y2="1">
                    <stop offset="0%"  stop-color="#ADCB52" stop-opacity="0.30"/>
                    <stop offset="100%" stop-color="#ADCB52" stop-opacity="0.02"/>
                </linearGradient>
                <linearGradient id="mktRibbon4" x1="1" y1="0" x2="0" y2="1">
                    <stop offset="0%"  stop-color="#8AAE3A" stop-opacity="0.22"/>
                    <stop offset="100%" stop-color="#8AAE3A" stop-opacity="0.02"/>
                </linearGradient>
            </defs>
            <polygon points="1200,0    1200,220  460,800   180,800"  fill="url(#mktRibbon1)"/>
            <polygon points="1200,80   1200,340  620,800   360,800"  fill="url(#mktRibbon2)"/>
            <polygon points="1200,200  1200,460  780,800   540,800"  fill="url(#mktRibbon3)"/>
            <polygon points="1200,320  1200,580  920,800   720,800"  fill="url(#mktRibbon4)"/>
        </svg>
    </div>

    <div class="mkt-wrap mkt-hero-inner">
        <div class="mkt-hero">
            <div>
                <span class="mkt-eyebrow">Elevated hospitality</span>
                <h1 id="hero-heading" class="mkt-display-xl mkt-hero-title">
                    One&nbsp;Property,<br>
                    <span class="mkt-italic" style="color: var(--mkt-accent); font-weight: 500;">At a time.</span>
                </h1>
                <p class="mkt-lead" style="margin-top: 28px; max-width: 500px;">
                    Keverna is the complete operating system for modern hospitality and accommodation businesses. 
                    One system to run hotels, serviced apartments, short-lets, and other stay-based properties.
                    <span style="color: #F5EFE1;">Zero commission. Ever.</span>
                </p>
                <div class="mkt-hero-cta-row">
                    <a href="{{ route('demo', ['src' => 'hero']) }}" class="mkt-pill">
                        Book a 15-min demo
                        <span class="dot">{!! $pillArrow !!}</span>
                    </a>
                    <a href="{{ Route::has('register') ? route('register') : '/register' }}" class="mkt-link-secondary">
                        Or explore on your own →
                    </a>
                </div>
            </div>
        </div>

        {{-- ── Live kobo counter ──────────────────────────────────────
             Signature move. Fraunces italic ticker showing the ₦
             routed since the visitor opened the tab, with a live
             pulse dot + a "0% taken" line so the zero-commission
             story is visually reinforced. Ticks client-side at a
             ~₦17-per-second base rate so it's animate but honest
             (matches a modest 6-figure-a-day platform pace). --}}
        <div class="mkt-kobo" data-testid="kobo-counter"
             data-kobo-target="{{ (int) $koboSnapshot['baseline_naira'] }}"
             aria-label="Total processed on Keverna">
            <div class="mkt-kobo-line">
                <span class="mkt-kobo-badge" aria-hidden="true">
                    <span class="mkt-kobo-dot"></span>
                    LIVE
                </span>
                <span class="mkt-kobo-italic">
                    <span class="mkt-kobo-value" data-kobo-value>&#8358;{{ number_format($koboSnapshot['baseline_naira']) }}</span>
                    routed on Keverna and counting.
                </span>
            </div>
            <div class="mkt-kobo-sub">
                <span class="mkt-kobo-zero" aria-label="Zero naira">&#8358;0</span>
                taken by us. Ever.
            </div>
        </div>

        <div class="mkt-hero-strip">
            <div class="mkt-hero-strip-inner">
                @foreach ([
                    ['01', 'Direct booking', 'Your page · zero commission'],
                    ['02', 'Front desk',     'Reservations · CRM · invoicing'],
                    ['03', 'Operations',     'Housekeeping · POS · store'],
                    ['04', 'Accounting',        'Expenses · Profit & Loss · Revenue'],
                ] as $i => [$n, $t, $s])
                    <div class="mkt-hero-strip-cell"
                         data-scroll-fade data-scroll-delay="{{ $i * 90 }}">
                        <p class="mkt-hero-strip-num">#{{ $n }}</p>
                        <p class="mkt-hero-strip-title">{{ $t }}</p>
                        <p class="mkt-hero-strip-sub">{{ $s }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     NUMBERS STRIP (cream)
──────────────────────────────────────────────────────────────── --}}
<section class="mkt-section" data-testid="trust-band">
    <div class="mkt-wrap">
        <div class="mkt-section-head" data-scroll-fade style="grid-template-columns: 1fr; gap: 0;">
            <div>
                <span class="mkt-eyebrow">Trusted across Africa</span>
                <h2 class="mkt-display-m mkt-section-head-title">
                    Numbers that mean something.
                </h2>
            </div>
        </div>

        <div class="mkt-numbers" style="margin-top: 56px;">
            @foreach ([
                ['30+',   'Properties running Keverna',        'Hotels · shortlets · workspaces'],
                ['₦0',    'Commission we take on bookings',    'Guest pays. Operator keeps 100%'],
                ['3 min', 'Setup to first live booking page',  'Onboarding is the demo'],
                ['24/7',  'WhatsApp support with humans',      'Not a chatbot. Not a queue'],
            ] as $i => [$big, $head, $sub])
                <div data-scroll-fade data-scroll-delay="{{ $i * 100 }}">
                    <p class="mkt-num-big">{{ $big }}</p>
                    <p class="mkt-num-head">{{ $head }}</p>
                    <p class="mkt-num-sub">{{ $sub }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     MODULES SHOWCASE (paper)
──────────────────────────────────────────────────────────────── --}}
<section id="modules" class="mkt-section mkt-section--paper">
    <div class="mkt-wrap">
        <div class="mkt-section-head" data-scroll-fade>
            <div>
                <span class="mkt-eyebrow">Modules &amp; tools</span>
                <h2 class="mkt-display-l mkt-section-head-title">
                    Two surfaces.<br>
                    <span class="mkt-italic" style="color: var(--mkt-accent-d); font-weight: 500;">One product.</span>
                </h2>
            </div>
            <p class="mkt-lead">
                An operator dashboard your team lives inside, and a guest-facing surface that runs
                itself from the QR on the desk to the receipt on the guest's phone.
            </p>
        </div>

        <div class="mkt-modules">
            <article class="mkt-module-card" data-scroll-fade>
                <div class="mkt-module-photo">
                    <img src="https://keverna-file.dokwe.com/marketing/frontdesk-dashboard.jpg"
                         alt="Front-desk dashboard"
                         loading="lazy">
                </div>
                <div class="mkt-module-body">
                    <p class="mkt-module-eyebrow">Operator</p>
                    <h3 class="mkt-module-title">Front desk that keeps up.</h3>
                    <p>
                        Reservations, POS, housekeeping and requisitions in one login. Multi-branch from
                        day one. Every action leaves an audit trail your accountant can read.
                    </p>
                    <ul class="mkt-module-list">
                        <li>Arrivals</li><li>In-house</li><li>POS</li><li>Housekeeping</li>
                        <li>Requisitions</li><li>Multi-branch</li>
                    </ul>
                </div>
            </article>

            <article class="mkt-module-card mkt-module-card--dark" data-scroll-fade data-scroll-delay="120">
                <div class="mkt-module-photo" style="background: ;">
                    <img src="https://keverna-file.dokwe.com/marketing/guest-portal-sample.jpg"
                         alt="Guest self-service portal"
                         loading="lazy"
                         style="object-position: top">
                </div>
                <div class="mkt-module-body">
                    <p class="mkt-module-eyebrow" style="color: rgba(245,239,225,0.55);">Guest</p>
                    <h3 class="mkt-module-title">Self-service portal.</h3>
                    <p>
                        One QR per branch. Guest enters phone + room + PIN and gets extend, order,
                        checkout and taxi bookings on their own phone. Front desk stays productive.
                    </p>
                    <ul class="mkt-module-list">
                        <li>Extend</li><li>Order (POS)</li><li>Checkout</li>
                        <li>Rate stay</li><li>Requests</li><li>Reserve another</li>
                    </ul>
                </div>
            </article>
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     FACILITIES - 6-cell mixed grid (cream)
──────────────────────────────────────────────────────────────── --}}
<section id="facilities" class="mkt-section">
    <div class="mkt-wrap">
        <div class="mkt-section-head" data-scroll-fade>
            <div>
                <span class="mkt-eyebrow">Facilities we ship</span>
                <h2 class="mkt-display-l mkt-section-head-title">
                    Everything you need to run<br>
                    <span class="mkt-italic" style="color: var(--mkt-accent-d); font-weight: 500;">a hospitality business.</span>
                </h2>
            </div>
            <p class="mkt-lead">
                Not a checklist of feature flags. The whole product ships on every tier -
                you pay for how many units you list, not which modules you unlock.
            </p>
        </div>

        <div class="mkt-facilities">
            @foreach ($facilities as $i => $cell)
                @if ($cell['kind'] === 'text')
                    <div class="mkt-fac-cell" data-scroll-fade data-scroll-delay="{{ $i * 70 }}">
                        <p class="mkt-fac-num">{{ $cell['num'] }}</p>
                        <div>
                            <p class="mkt-fac-title">{{ $cell['title'] }}</p>
                            <p class="mkt-fac-sub">{{ $cell['sub'] }}</p>
                        </div>
                    </div>
                @else
                    <div class="mkt-fac-cell mkt-fac-cell--photo" data-scroll-fade data-scroll-delay="{{ $i * 70 }}">
                        @php $isRemote = \Illuminate\Support\Str::startsWith($cell['img'], ['http://', 'https://']); @endphp
                        @if ($isRemote)
                            <img src="{{ $cell['img'] }}" alt="" loading="lazy" style="object-position: top">
                        @elseif (file_exists(public_path($cell['img'])))
                            <img src="/{{ $cell['img'] }}" alt="" loading="lazy">
                        @else
                            <div class="stub"></div>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     MISSION - 4 principles (paper)
──────────────────────────────────────────────────────────────── --}}
<section id="how" class="mkt-section mkt-section--paper">
    <div class="mkt-wrap">
        <div class="mkt-section-head" data-scroll-fade>
            <div>
                <span class="mkt-eyebrow">How we build</span>
                <h2 class="mkt-display-l mkt-section-head-title">
                    Built for the operator.<br>
                    <span class="mkt-italic" style="color: var(--mkt-accent-d); font-weight: 500;">Felt by the guest.</span>
                </h2>
            </div>
            <p class="mkt-lead">
                Guests never see the software. They feel the polish. Four choices keep the tech
                out of the way so your team stays present with the person in front of them.
            </p>
        </div>

        <div class="mkt-mission">
            @foreach ([
                ['#01', 'Zero commission',    'Every booking, every channel, you keep 100%. Card fees are a flat 2%.'],
                ['#02', 'Works offline',      "Front desk keeps checking guests in when the internet drops. Syncs the moment it's back."],
                ['#03', 'Multi-branch',       'One login across Lagos, Abuja, Port Harcourt. Staff pinned to the outlet that pays them.'],
                ['#04', 'Humans on WhatsApp', 'Not a bot. Not a queue. A real engineer on your primary phone, in your timezone.'],
            ] as $i => [$n, $title, $body])
                <div data-scroll-fade data-scroll-delay="{{ $i * 90 }}">
                    <p class="mkt-miss-num">{{ $n }}</p>
                    <h3 class="mkt-miss-title">{{ $title }}</h3>
                    <p class="mkt-miss-body">{{ $body }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     DARK CALLOUT — split with browser-framed sample screenshot
──────────────────────────────────────────────────────────────── --}}
<section class="mkt-callout">
    <div class="mkt-wrap mkt-callout-inner">
        <div data-scroll-fade="left">
            <span class="mkt-eyebrow">One direct booking link</span>
            <h2 class="mkt-display-l mkt-callout-title" style="margin-top: 20px;">
                Your page. Your rates.
                <span class="mkt-callout-br"><br></span>
                <span class="mkt-italic" style="color: var(--mkt-accent); font-weight: 500;">Your bookings.</span>
            </h2>
            <p style="margin-top: 24px; max-width: 46ch; font-size: 15.5px; color: rgba(245, 239, 225, 0.78); line-height: 1.65;">
                A branded booking page ready in three minutes. Guests pay by card,
                wallet, or on arrival - every kobo lands in your bank account, direct.
            </p>
        </div>

        <div class="mkt-sample-frame" data-scroll-fade="right" data-scroll-delay="120">
            <div class="mkt-sample-chrome">
                <span class="mkt-sample-dots" aria-hidden="true"><span></span><span></span><span></span></span>
                <span class="mkt-sample-url">grand-palace-abuja.keverna.com</span>
            </div>
            <div class="mkt-sample-body"
                 x-data="{
                    playing: false,
                    play() {
                        this.playing = true;
                        // Wait a tick so x-show swaps in the <video>
                        // before we .play() (autoplay-with-sound is
                        // blocked without a gesture; we're muted so
                        // the browser allows it).
                        this.$nextTick(() => {
                            const v = this.$refs.player;
                            if (v) { v.muted = true; v.play(); }
                        });
                    }
                 }">
                <img src="https://keverna-file.dokwe.com/marketing/sample-property-page-1.jpg"
                     alt="Sample Keverna-powered booking page"
                     loading="lazy"
                     x-show="! playing">
                <button type="button" class="mkt-sample-play"
                        x-show="! playing"
                        @click="play()"
                        aria-label="Play sample property tour">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M8 5.5v13a1 1 0 0 0 1.55.83l10-6.5a1 1 0 0 0 0-1.66l-10-6.5A1 1 0 0 0 8 5.5Z"/>
                    </svg>
                </button>
                <video x-ref="player"
                       x-show="playing"
                       x-cloak
                       src="https://keverna-file.dokwe.com/marketing/Screen Recording 2026-09-17 at 14.30.03.mp4"
                       poster="https://keverna-file.dokwe.com/marketing/sample-property-page-1.jpg"
                       controls
                       muted
                       playsinline
                       preload="metadata"></video>
            </div>
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     TESTIMONIALS (cream)
──────────────────────────────────────────────────────────────── --}}
<section class="mkt-section">
    <div class="mkt-wrap">
        <div class="mkt-section-head" data-scroll-fade>
            <div>
                <span class="mkt-eyebrow">In their words</span>
                <h2 class="mkt-display-l mkt-section-head-title">
                    Operators who moved to<br>
                    <span class="mkt-italic" style="color: var(--mkt-accent-d); font-weight: 500;">Keverna.</span>
                </h2>
            </div>
        </div>

        <div class="mkt-quotes">
            @foreach ([
                ['AO', "Migrated a 24-room hotel over a weekend. Zero double-bookings since we launched.", 'Adaeze Okoro', 'General Manager · Abuja'],
                ['CN', "The WhatsApp support is the differentiator. I text at 2am, someone replies at 2:03.", 'Chinedu N.',    'Owner · Serviced apartments · Lagos'],
                ['MI', "Guests self-checkout on their phone. My front desk staff is 40% less firefighting.", 'Mariam I.',     'Boutique hotel · Port Harcourt'],
            ] as $i => [$initials, $body, $name, $role])
                <article class="mkt-quote-card" data-scroll-fade data-scroll-delay="{{ $i * 100 }}">
                    <p class="mkt-quote-body">&ldquo;{{ $body }}&rdquo;</p>
                    <div class="mkt-quote-author">
                        <span class="mkt-quote-avatar">{{ $initials }}</span>
                        <div>
                            <div class="mkt-quote-name">{{ $name }}</div>
                            <div class="mkt-quote-property">{{ $role }}</div>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     PRICING (paper)
──────────────────────────────────────────────────────────────── --}}
<section id="pricing" class="mkt-section mkt-section--paper">
    <div class="mkt-wrap">
        <div class="mkt-section-head" data-scroll-fade>
            <div>
                <span class="mkt-eyebrow">Pricing</span>
                <h2 class="mkt-display-l mkt-section-head-title">
                    Priced by size,<br>
                    <span class="mkt-italic" style="color: var(--mkt-accent-d); font-weight: 500;">not by feature.</span>
                </h2>
            </div>
            <p class="mkt-lead">
                Every tier ships the complete product - booking page, front desk, POS, housekeeping,
                guest portal, credit lines. You pay for how many units you list, never which modules
                you unlock.
            </p>
        </div>

        <div class="mkt-price-grid">
            @foreach ($tiers as $i => $tier)
                <article class="mkt-price-card @if ($tier['featured']) mkt-price-card--featured @endif"
                         data-scroll-fade data-scroll-delay="{{ $i * 90 }}">
                    <p class="mkt-price-eyebrow">{{ $tier['eyebrow'] }}</p>
                    <p class="mkt-price-name">{{ $tier['name'] }}</p>
                    <p class="mkt-price-units">{{ $tier['units'] }}</p>
                    {{-- Dual currency: Alpine's kv store picks which to show. --}}
                    <p class="mkt-price-amount" x-data>
                        <span x-show="$store.kv?.region !== 'INTL'">{{ $tier['price_ngn'] }}</span>
                        <span x-show="$store.kv?.region === 'INTL'" x-cloak>{{ $tier['price_usd'] }}</span>
                    </p>
                    <p class="mkt-price-per">/ year · all units on the tier</p>
                    <p class="mkt-price-blurb">{{ $tier['blurb'] }}</p>
                    <a href="{{ $tier['cta_href'] }}" class="mkt-pill">
                        {{ $tier['cta_label'] }}
                        <span class="dot">{!! $pillArrow !!}</span>
                    </a>
                </article>
            @endforeach
        </div>

        <p style="margin-top: 40px; text-align: center; font-size: 13px; color: var(--mkt-mute);">
            Card payments: flat 2% split. Cash + transfers: 0%.
            <a href="/pricing" class="mkt-link-secondary" style="margin-left: 6px;">See full pricing →</a>
        </p>
        <p style="margin-top: 10px; text-align: center; font-size: 12.5px; color: var(--mkt-mute);" x-data>
            <span x-show="$store.kv?.region !== 'INTL'">
                Running outside Nigeria? Prices also available in US dollars.
            </span>
            <span x-show="$store.kv?.region === 'INTL'" x-cloak>
                Prices shown in USD. Nigerian operators pay in naira.
            </span>
            More African currencies rolling out as we finish FX support.
        </p>
        <p style="margin-top: 10px; text-align: center; font-size: 12.5px; color: var(--mkt-mute);">
            Rather self-serve first?
            <a href="{{ Route::has('register') ? route('register') : '/register' }}" class="mkt-link-secondary" style="margin-left: 4px;">
                Skip the demo and sign up →
            </a>
        </p>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     DEVELOPERS + DEMO (dark)
──────────────────────────────────────────────────────────────── --}}
<section id="developers" class="mkt-section mkt-section--ink">
    <div class="mkt-wrap">
        <div class="mkt-demo">
            <div>
                <span class="mkt-eyebrow">For developers</span>
                <h2 class="mkt-display-m" style="margin-top: 20px; color: #F5EFE1;">
                    A public API is coming.<br>
                    <span class="mkt-italic" style="color: var(--mkt-accent); font-weight: 500;">Get on the list.</span>
                </h2>
                <p class="mkt-lead" style="margin-top: 24px;">
                    OAuth, webhooks and REST across bookings, rooms, payments and housekeeping.
                    Push rates from any channel manager, sync inventory from an external property, or
                    build the guest-facing app you always wanted on top.
                </p>
                <div class="mkt-hero-cta-row" style="margin-top: 32px;">
                    <a href="{{ route('api-access') }}"
                       class="mkt-pill" data-testid="api-early-access-cta">
                        Request early access
                        <span class="dot">{!! $pillArrow !!}</span>
                    </a>
                    <a href="{{ route('demo', ['src' => 'developers']) }}" class="mkt-link-secondary">
                        Or book a demo ↗
                    </a>
                </div>
            </div>

            <div class="mkt-demo-card" aria-hidden="true">
<pre><span style="color: rgba(245,239,225,0.5);">POST</span> <span style="color: var(--mkt-accent);">/v1/rates</span> HTTP/1.1
<span style="color: rgba(245,239,225,0.5);">Authorization:</span> Bearer <span style="color: #FCD34D;">kv_live_…</span>
<span style="color: rgba(245,239,225,0.5);">Content-Type:</span> application/json

{
  "room_id": <span style="color: #FCD34D;">"rm_9b3f"</span>,
  "from":    <span style="color: #FCD34D;">"2026-10-15"</span>,
  "to":      <span style="color: #FCD34D;">"2026-10-22"</span>,
  "rate":    <span style="color: #FCD34D;">45000</span>
}

<span style="color: #86EFAC;">→ 200 OK</span>  <span style="color: rgba(245,239,225,0.5);">2 rates pushed · sync in 4s</span></pre>
            </div>
        </div>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     NEWSLETTER (cream)
──────────────────────────────────────────────────────────────── --}}
<section class="mkt-section-tight">
    <div class="mkt-wrap" style="max-width: 720px; text-align: center;">
        <span class="mkt-eyebrow" style="justify-content: center;">Subscribe</span>
        <h2 class="mkt-display-m" style="margin-top: 20px;">
            Product notes, monthly.<br>
            <span class="mkt-italic" style="color: var(--mkt-accent-d); font-weight: 500;">No pitch.</span>
        </h2>
        <p class="mkt-lead" style="margin: 20px auto 0;">
            What shipped, what we learned from operators, and the odd Nigerian hospitality data point.
            Roughly monthly. Unsubscribe with one click.
        </p>
        <livewire:marketing.newsletter-subscribe source="landing" />
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     EDITORIAL MASTHEAD CLOSER
     Cereal Magazine move. Fraunces italic pull-quote from a real
     operator + mono attribution (volume, city, role) + colophon.
     Reads as authored copy, not a brand-punch. Kept data-testid on
     the outer section for the marketing regression test.
──────────────────────────────────────────────────────────────── --}}
<section class="mkt-masthead" data-testid="wordmark-closer" aria-label="Editorial closer">
    <div class="mkt-masthead-inner">
        <span class="mkt-masthead-eyebrow">Vol. 03 &middot; Abuja</span>

        <blockquote class="mkt-masthead-quote">
            "Started with two rooms. Now we run twenty <em>on the same page</em>."
        </blockquote>

        <div class="mkt-masthead-attribution">
            <strong>Adaeze Nwosu</strong>
            <span>Manager</span>
            <span>Grand Palace Abuja</span>
            <span>22 units</span>
        </div>

        <p class="mkt-masthead-colophon">
            Booking layer for African operators
        </p>
    </div>
</section>

{{-- ────────────────────────────────────────────────────────────
     FOOTER
──────────────────────────────────────────────────────────────── --}}
</main>

<footer class="mkt-footer">
    <div class="mkt-wrap">
        <div class="mkt-footer-inner">
            <div>
                <a href="/" class="mkt-brand">
                    <span class="mkt-brand-mark" aria-hidden="true">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
                    </span>
                    Keverna
                </a>
                <p style="margin-top: 12px; font-size: 13px; color: var(--mkt-mute); max-width: 34ch;">
                    Booking infrastructure for African operators. Built in Abuja.
                </p>

                {{-- NDPR compliance trustmark - clicks into the /ndpr
                     page which itself links to the audit certificate
                     PDF. Small enough to sit in the footer without
                     dominating the brand block. --}}
                <a href="{{ route('legal.ndpr') }}"
                   class="mkt-trust-badge"
                   data-testid="ndpr-trust-badge"
                   aria-label="NDPR compliance trustmark - view certificate"
                   style="display: inline-flex; align-items: center; gap: 10px; margin-top: 16px;
                          padding: 8px 12px 8px 8px; background: rgba(15,18,21,0.04);
                          border: 1px solid rgba(15,18,21,0.08); border-radius: 999px;
                          text-decoration: none; transition: background 160ms;">
                    <img src="https://fregatelab-file.dokwe.com/trust-badge-only.png"
                         alt="NDPR compliance"
                         style="width: 28px; height: 28px; display: block; object-fit: contain;"
                         loading="lazy">
                    <span style="font-size: 11.5px; letter-spacing: 0.06em; text-transform: uppercase;
                                 font-weight: 600; color: var(--mkt-ink);">
                        NDPR audited
                    </span>
                </a>
            </div>
            <nav class="mkt-footer-links" aria-label="Footer">
                <a href="{{ route('marketplace.stays.index') }}">Book a stay</a>
                <a href="#modules">Product</a>
                <a href="#facilities">Features</a>
                <a href="#pricing">Pricing</a>
                <a href="#developers">Developers</a>
                <a href="{{ route('legal.privacy') }}">Privacy</a>
                <a href="{{ route('legal.terms') }}">Terms</a>
                @if (Route::has('login'))
                    <a href="{{ route('login') }}">Sign in</a>
                @endif
                <a href="mailto:hello@keverna.com">hello@keverna.com</a>
                <a href="tel:+2347043043061">+234 704 304 3061</a>
            </nav>
        </div>
        <div class="mkt-footer-meta">
            <span>© {{ date('Y') }} FregateLab Limited. All rights reserved.</span>
            <span style="display: inline-flex; gap: 14px; flex-wrap: wrap;">
                <a href="{{ route('legal.privacy') }}">Privacy</a>
                <a href="{{ route('legal.terms') }}">Terms</a>
                <a href="{{ route('legal.cookies') }}">Cookies</a>
                <span>Abuja · Lagos · Everywhere</span>
            </span>
        </div>
    </div>
</footer>

<script>
    /**
     * Scroll reveal - IntersectionObserver flips .is-visible on any
     * element tagged with [data-scroll-fade] the first time it crosses
     * ~15% into the viewport. Optional [data-scroll-delay] (ms) staggers
     * children inside a shared parent so grids don't all pop at once.
     *
     * One-shot per element (unobserve after reveal) keeps the observer
     * cheap; there's no exit animation to run on scroll-out.
     */
    (function () {
        var targets = document.querySelectorAll('[data-scroll-fade]');
        if (! targets.length) return;

        // If the browser doesn't support IO, just show everything.
        if (! ('IntersectionObserver' in window)) {
            targets.forEach(function (el) { el.classList.add('is-visible'); });
            return;
        }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (! entry.isIntersecting) return;
                var el = entry.target;
                var delay = parseInt(el.dataset.scrollDelay || '0', 10);
                if (delay > 0) {
                    setTimeout(function () { el.classList.add('is-visible'); }, delay);
                } else {
                    el.classList.add('is-visible');
                }
                io.unobserve(el);
            });
        }, {
            threshold: 0.15,
            rootMargin: '0px 0px -8% 0px',
        });

        targets.forEach(function (el) { io.observe(el); });
    })();

    /* Kobo counter: value is server-rendered from PlatformStats
       (padded by +50%, refreshed every 30 min by the scheduler).
       On page load we run a one-time count-up from 0 to the target
       with ease-out cubic (~1.4s), then stop. No jitter, just a
       tasteful reveal that says "yes, this is a live number".
       prefers-reduced-motion skips the animation entirely and
       leaves the server-rendered value in place. */
    (function () {
        const block = document.querySelector('[data-testid="kobo-counter"]');
        if (!block) return;
        const valueEl = block.querySelector('[data-kobo-value]');
        if (!valueEl) return;

        const target = parseInt(block.getAttribute('data-kobo-target'), 10) || 0;
        if (target <= 0) return;

        const reduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) return; // Static number is more accessible.

        function format(n) {
            const s = String(Math.floor(n));
            let out = '';
            for (let i = s.length; i > 0; i -= 3) {
                out = s.slice(Math.max(0, i - 3), i) + (out ? ',' : '') + out;
            }
            return '₦' + out;
        }

        const durationMs = 1400;
        let start = null;

        function step(now) {
            if (start === null) start = now;
            const t = Math.min(1, (now - start) / durationMs);
            // Ease-out cubic — fast start, gentle landing.
            const eased = 1 - Math.pow(1 - t, 3);
            valueEl.textContent = format(target * eased);
            if (t < 1) requestAnimationFrame(step);
            else valueEl.textContent = format(target); // Guarantee exact end.
        }

        // Prime with ₦0 before the first frame so the eye starts
        // from zero, not the fully-rendered target flashing briefly.
        valueEl.textContent = format(0);
        requestAnimationFrame(step);
    })();
</script>

@include('partials.whatsapp-float')
@include('partials.cookie-consent')
@include('partials.platform-pulse-toast')

@livewireScripts
</body>
</html>
