{{--
    Cookie policy - self-contained full-page view so it doesn't have
    to fight the component-slot pattern the marketing layout uses.
    Same LUNA-v3 tokens as welcome/demo/api-access so it reads as
    one site.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F1215">
    <title>Cookie policy - Keverna</title>
    <meta name="description" content="How Keverna uses cookies and how you can control them.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url()->current() }}">

    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,700,500i|inter:400,500,600" rel="stylesheet">

    @vite(['resources/css/app.css'])

    <style>
        body {
            margin: 0;
            background: #F7F3EA;
            color: #14171A;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 720px; margin: 0 auto; padding: 0 24px; }
        @media (min-width: 900px) { .wrap { padding: 0 48px; } }

        .topbar {
            padding: 20px 0;
            border-bottom: 1px solid rgba(15,18,21,0.08);
            background: rgba(247, 243, 234, 0.92);
            backdrop-filter: saturate(180%) blur(10px);
        }
        .topbar-inner { display: flex; align-items: center; justify-content: space-between; }
        .brand {
            display: inline-flex; align-items: center; gap: 10px;
            font-family: 'Fraunces', Georgia, serif; font-weight: 600;
            font-size: 20px; color: #14171A; text-decoration: none;
        }
        .brand-mark {
            width: 30px; height: 30px; border-radius: 8px;
            background: #0F1215; color: #F7F3EA;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .back {
            font-size: 13px; color: #6E7278;
            text-decoration: none;
        }
        .back:hover { color: #14171A; }

        main { padding: 56px 0 96px; }
        h1 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(34px, 5vw, 56px); font-weight: 600;
            letter-spacing: -0.025em; line-height: 1.05;
            margin: 0 0 24px;
        }
        h2 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 22px; font-weight: 600;
            letter-spacing: -0.015em;
            margin: 32px 0 12px;
        }
        .eyebrow {
            font-size: 12px; letter-spacing: 0.18em;
            text-transform: uppercase; color: #6E7278;
            margin: 0 0 8px;
        }
        .updated { font-size: 13px; color: #6E7278; margin: 0 0 32px; }
        .content { font-size: 15px; line-height: 1.7; color: #14171A; }
        .content p { margin: 0 0 16px; }
        .content ul { margin: 12px 0 20px; padding-left: 24px; }
        .content li { margin-bottom: 6px; }
        .content code {
            font-family: ui-monospace, 'JetBrains Mono', monospace;
            font-size: 13px;
            background: #EFE9DC;
            padding: 1px 6px;
            border-radius: 4px;
        }
        .content a {
            color: #8AAE3A;
            text-decoration: underline;
            text-underline-offset: 3px;
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="wrap topbar-inner">
            <a href="/" class="brand" aria-label="Keverna home">
                <span class="brand-mark" aria-hidden="true">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
                </span>
                Keverna
            </a>
            <a href="/" class="back">Back to landing</a>
        </div>
    </header>

    <main>
        <div class="wrap">
            <p class="eyebrow">Legal</p>
            <h1>Cookie policy</h1>
            <p class="updated">Last updated: {{ date('F Y') }}</p>

            <div class="content">
                <h2>What cookies are</h2>
                <p>
                    A cookie is a small text file a site puts on your device
                    to remember something between visits. keverna.com uses a
                    handful of them, all first-party.
                </p>

                <h2>What we set</h2>
                <p><strong>Strictly necessary.</strong> These keep the site working:</p>
                <ul>
                    <li><code>keverna_session</code> - keeps you signed in.</li>
                    <li><code>XSRF-TOKEN</code> - prevents cross-site form forgery.</li>
                    <li><code>kv_cookie_consent</code> - remembers your cookie choice so we don't ask again.</li>
                </ul>
                <p>
                    <strong>Optional (only if you tap "Accept all").</strong>
                    If we add analytics or marketing tags in the future they
                    will only load after you've explicitly opted in on the
                    consent banner. Right now nothing else is set.
                </p>

                <h2>Changing your mind</h2>
                <p>
                    Clear cookies for keverna.com in your browser to be
                    re-prompted on your next visit. You can also block cookies
                    entirely in your browser settings - the site will still
                    work but you may need to sign in more often.
                </p>

                <h2>Contact</h2>
                <p>
                    Questions? Email
                    <a href="mailto:hello@keverna.com">hello@keverna.com</a>.
                    Keverna is a product of FregateLab Limited, Abuja, Nigeria.
                </p>
            </div>
        </div>
    </main>

    @include('partials.cookie-consent')
</body>
</html>
