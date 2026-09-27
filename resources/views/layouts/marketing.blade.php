{{--
    Shared layout for marketing sub-pages (/demo, future
    /case-studies, etc). Same fonts + LUNA-v3 tokens as the main
    landing (welcome.blade.php) but slimmed to just what these
    single-column forms need.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F1215">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Keverna · Run your entire property from one platform' }}</title>
    <meta name="description" content="{{ $metaDescription ?? 'Booking infrastructure for African hospitality operators. Direct booking pages, front desk, POS, housekeeping, guest portal. Zero commission on every booking.' }}">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large">
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Keverna">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="{{ $title ?? 'Keverna · Run your entire property from one platform' }}">
    <meta property="og:description" content="{{ $metaDescription ?? 'Booking infrastructure for African hospitality operators. Zero commission on every booking.' }}">
    <meta property="og:image" content="https://keverna-file.dokwe.com/marketing/keverna-og-cover.jpg">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@keverna_com">
    <meta name="twitter:image" content="https://keverna-file.dokwe.com/marketing/keverna-og-cover.jpg">

    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,700,500i|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

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
        .lp-page.lp-mkt-v3 h2 {
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 600; letter-spacing: -0.025em; line-height: 1; margin: 0; color: inherit;
        }
        .lp-page.lp-mkt-v3 a { color: inherit; text-decoration: none; }
        .lp-page.lp-mkt-v3 p { margin: 0; line-height: 1.65; }

        .mkt-wrap { max-width: 960px; margin: 0 auto; padding: 0 24px; }
        @media (min-width: 900px) { .mkt-wrap { padding: 0 48px; } }

        .mkt-topbar {
            padding: 20px 0;
            border-bottom: 1px solid var(--mkt-line-2);
            background: rgba(247, 243, 234, 0.92);
            backdrop-filter: saturate(180%) blur(10px);
            -webkit-backdrop-filter: saturate(180%) blur(10px);
        }
        .mkt-topbar-inner {
            display: flex; align-items: center; justify-content: space-between;
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
        .mkt-back {
            font-size: 13px; color: var(--mkt-mute);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .mkt-back:hover { color: var(--mkt-ink); }
    </style>
</head>
<body class="lp-page lp-mkt-v3">

<header class="mkt-topbar">
    <div class="mkt-wrap mkt-topbar-inner">
        <a href="/" class="mkt-brand" aria-label="Keverna home">
            <span class="mkt-brand-mark" aria-hidden="true">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
            </span>
            Keverna
        </a>
        <div style="display: inline-flex; align-items: center; gap: 14px;">
            @include('partials.region-picker')
            <a href="/" class="mkt-back">← Back to landing</a>
        </div>
    </div>
</header>

{{ $slot }}

@include('partials.whatsapp-float')
@include('partials.cookie-consent')

@livewireScripts
</body>
</html>
