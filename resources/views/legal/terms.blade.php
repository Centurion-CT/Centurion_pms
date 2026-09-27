<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F1215">
    <title>Terms of service - Keverna</title>
    <meta name="description" content="The terms that govern operator use of the Keverna platform.">
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
        .content a { color: #8AAE3A; text-decoration: underline; text-underline-offset: 3px; }
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
            <h1>Terms of service</h1>
            <p class="updated">Last updated: {{ date('F Y') }}</p>

            <div class="content">
                <p>
                    These terms govern your use of Keverna, the property
                    management platform provided by <strong>FregateLab
                    Limited</strong> (Abuja, Nigeria). By signing up you
                    accept them; if you disagree, don't sign up.
                </p>

                <h2>The service</h2>
                <p>
                    Keverna is a hosted PMS - direct booking pages, front
                    desk console, POS, housekeeping, guest self-service
                    portal, and Paystack payouts. We do our best to keep
                    the service running around the clock; scheduled
                    maintenance is announced in advance.
                </p>

                <h2>Your account</h2>
                <ul>
                    <li>You are responsible for keeping your operator + staff credentials safe.</li>
                    <li>You must be authorised to represent the property you sign up for.</li>
                    <li>Adding or removing staff, running bookings, and handling guest data are actions taken by you, not by us.</li>
                </ul>

                <h2>Payments</h2>
                <p>
                    Subscription fees are billed annually per unit-count
                    tier. Card payments guests make through your booking
                    page are subject to a flat 2% Paystack split; cash
                    and bank transfers are 0%. Keverna takes zero
                    commission on the booking value itself.
                </p>

                <h2>Guest data</h2>
                <p>
                    You remain the data controller for guests booking on
                    your Keverna site. Keverna acts as a processor on
                    your behalf. See our
                    <a href="/privacy">privacy notice</a> for how
                    personal data is handled.
                </p>

                <h2>Fair use</h2>
                <p>
                    Don't use Keverna to run illegal, fraudulent, or
                    abusive operations. We reserve the right to suspend
                    accounts that damage the platform (spam booking
                    forms, brute-force attempts, payment fraud).
                </p>

                <h2>Uptime + support</h2>
                <p>
                    We target high availability but don't offer a
                    contractual SLA at Starter / Growth / Pro tiers.
                    Enterprise agreements can include specific SLAs.
                    Real-human support runs on WhatsApp during business
                    hours (WAT).
                </p>

                <h2>Ending the relationship</h2>
                <p>
                    You can cancel at any time; the current billing
                    period runs to term. Your booking data stays
                    exportable for 30 days after cancellation.
                </p>

                <h2>Governing law</h2>
                <p>
                    These terms are governed by the laws of the Federal
                    Republic of Nigeria.
                </p>

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
