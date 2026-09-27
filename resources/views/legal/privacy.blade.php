<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F1215">
    <title>Privacy notice - Keverna</title>
    <meta name="description" content="How Keverna handles personal data collected on the marketing site and dashboard.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url()->current() }}">

    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,700,500i|inter:400,500,600" rel="stylesheet">

    @vite(['resources/css/app.css'])

    <style>
        body {
            margin: 0; background: #F7F3EA; color: #14171A;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 720px; margin: 0 auto; padding: 0 24px; }
        @media (min-width: 900px) { .wrap { padding: 0 48px; } }
        .topbar {
            padding: 20px 0; border-bottom: 1px solid rgba(15,18,21,0.08);
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
        .back { font-size: 13px; color: #6E7278; text-decoration: none; }
        .back:hover { color: #14171A; }

        main { padding: 56px 0 96px; }
        h1 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(34px, 5vw, 56px); font-weight: 600;
            letter-spacing: -0.025em; line-height: 1.05; margin: 0 0 24px;
        }
        h2 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 22px; font-weight: 600;
            letter-spacing: -0.015em; margin: 32px 0 12px;
        }
        .eyebrow {
            font-size: 12px; letter-spacing: 0.18em;
            text-transform: uppercase; color: #6E7278; margin: 0 0 8px;
        }
        .updated { font-size: 13px; color: #6E7278; margin: 0 0 32px; }
        .content { font-size: 15px; line-height: 1.7; color: #14171A; }
        .content p { margin: 0 0 16px; }
        .content ul { margin: 12px 0 20px; padding-left: 24px; }
        .content li { margin-bottom: 6px; }
        .content a {
            color: #8AAE3A; text-decoration: underline; text-underline-offset: 3px;
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
            <h1>Privacy notice</h1>
            <p class="updated">Last updated: {{ date('F Y') }}</p>

            <div class="content">
                <p>
                    Keverna is a product of <strong>FregateLab Limited</strong>,
                    Abuja, Nigeria. This notice explains how we handle
                    personal information collected through the marketing
                    site (keverna.com) and the operator dashboard hosted
                    on per-tenant subdomains.
                </p>

                <h2>What we collect</h2>
                <p><strong>On the marketing site:</strong> if you fill in the
                demo form, the API waitlist, or subscribe to our newsletter,
                we store what you gave us (name, property, email, WhatsApp
                phone, use-case notes) plus your IP address and user-agent
                string for spam prevention.</p>
                <p><strong>On the operator dashboard:</strong> the operator's
                own booking / guest / finance data lives inside their own
                tenant. Keverna never shares one tenant's data with another
                tenant, and the tenant remains the sole controller of the
                guest data they collect through their booking page.</p>

                <h2>Why we hold it</h2>
                <ul>
                    <li>Contact you about the demo you booked.</li>
                    <li>Issue you a licence code + welcome email.</li>
                    <li>Send you the newsletter you subscribed to.</li>
                    <li>Answer support questions on WhatsApp / email.</li>
                    <li>Keep the sales pipeline organised inside our admin console.</li>
                </ul>

                <h2>Who sees it</h2>
                <p>Only the Keverna / FregateLab team, and any subprocessors
                strictly needed to deliver the service (Paystack for card
                payments, Sendpipe for WhatsApp templates, a transactional
                mail provider for confirmations). We never sell your data.</p>

                <h2>Your rights</h2>
                <p>Under the Nigeria Data Protection Regulation (NDPR) you
                can ask us to show you what personal data we hold about
                you, correct anything wrong, or delete it entirely. Email
                <a href="mailto:hello@keverna.com">hello@keverna.com</a>
                with the request and we'll action it within 30 days.</p>

                <h2>Cookies</h2>
                <p>See our <a href="/cookies">cookie policy</a> for the
                specific cookies we use and how to manage them.</p>

                <h2>Contact</h2>
                <p>
                    <strong>FregateLab Limited</strong><br>
                    Abuja, Nigeria<br>
                    <a href="mailto:hello@keverna.com">hello@keverna.com</a>
                </p>
            </div>
        </div>
    </main>

    @include('partials.cookie-consent')
</body>
</html>
