<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F1215">
    <title>NDPR compliance - Keverna</title>
    <meta name="description" content="FregateLab Limited, the company behind Keverna, holds an NDPR compliance trustmark from the Nigeria Data Protection Bureau. See the certificate.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:type" content="article">
    <meta property="og:title" content="NDPR compliance - Keverna">
    <meta property="og:description" content="FregateLab Limited holds an NDPR compliance trustmark from the Nigeria Data Protection Bureau.">
    <meta property="og:image" content="https://fregatelab-file.dokwe.com/trust-badge-fgl.png">

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
        .wrap { max-width: 880px; margin: 0 auto; padding: 0 24px; }
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
        .content { font-size: 15px; line-height: 1.7; color: #14171A; }
        .content p { margin: 0 0 16px; }
        .content ul { margin: 12px 0 20px; padding-left: 24px; }
        .content li { margin-bottom: 6px; }
        .content a { color: #8AAE3A; text-decoration: underline; text-underline-offset: 3px; }

        .ndpr-hero {
            display: grid; grid-template-columns: 1fr; gap: 32px;
            align-items: center;
            margin: 24px 0 40px;
        }
        @media (min-width: 760px) {
            .ndpr-hero { grid-template-columns: 1.05fr 0.95fr; gap: 48px; }
        }
        .ndpr-badge-card {
            display: flex; flex-direction: column; align-items: center;
            gap: 20px;
            padding: 28px;
            background: #fff;
            border-radius: 18px;
            border: 1px solid rgba(15, 18, 21, 0.10);
            box-shadow: 0 30px 60px -40px rgba(15, 18, 21, 0.25);
        }
        .ndpr-badge-card img {
            width: 100%; max-width: 260px;
            height: auto; display: block;
        }
        .ndpr-badge-caption {
            font-size: 12px; color: #6E7278;
            letter-spacing: 0.14em; text-transform: uppercase;
            font-weight: 600; text-align: center;
        }
        .ndpr-cert-btn {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 12px 12px 12px 20px;
            background: #0F1215; color: #F5EFE1;
            border-radius: 9999px;
            text-decoration: none;
            font-family: inherit; font-size: 13.5px; font-weight: 500;
            transition: background 200ms;
        }
        .ndpr-cert-btn:hover { background: #1B1F23; text-decoration: none; }
        .ndpr-cert-btn .dot {
            width: 30px; height: 30px; border-radius: 9999px;
            background: #ADCB52; color: #0F1215;
            display: inline-flex; align-items: center; justify-content: center;
        }

        .ndpr-check-list {
            list-style: none; padding: 0; margin: 24px 0;
        }
        .ndpr-check-list li {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 10px 0;
            font-size: 14.5px;
        }
        .ndpr-check-list li::before {
            content: ''; flex-shrink: 0; margin-top: 3px;
            width: 20px; height: 20px; border-radius: 999px;
            background: #ADCB52
                url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12' fill='none' stroke='%230F1215' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M2.5 6l2.5 2.5L9.5 3.5'/></svg>")
                center/12px no-repeat;
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
            <p class="eyebrow">Compliance</p>
            <h1>NDPR compliance <br>trustmark</h1>

            <section class="ndpr-hero">
                <div class="content">
                    <p>
                        <strong>FregateLab Limited</strong>, the company that
                        builds and operates Keverna, holds an audited
                        compliance trustmark issued under the Nigeria Data
                        Protection Regulation (NDPR).
                    </p>
                    <p>
                        The trustmark is the deliverable of an independent
                        NDPR audit - it means our data-handling practices
                        were reviewed against the Regulation and found in
                        conformity. Your booking data, your guest data, and
                        the personal information you share with us are
                        processed under those obligations.
                    </p>

                    <a href="https://fregatelab-file.dokwe.com/FREGATELAB_LIMITED_-_AUDIT_COMPLAINCE_TRUSTMARK-2.pdf"
                       target="_blank" rel="noopener"
                       class="ndpr-cert-btn"
                       data-testid="ndpr-certificate-link">
                        View the certificate
                        <span class="dot" aria-hidden="true">
                            <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                        </span>
                    </a>
                </div>

                <a href="https://fregatelab-file.dokwe.com/FREGATELAB_LIMITED_-_AUDIT_COMPLAINCE_TRUSTMARK-2.pdf"
                   target="_blank" rel="noopener"
                   class="ndpr-badge-card"
                   aria-label="Open FregateLab Limited NDPR audit certificate (PDF)"
                   style="text-decoration: none; color: inherit;">
                    <img src="https://fregatelab-file.dokwe.com/trust-badge-fgl.png"
                         alt="FregateLab Limited NDPR compliance trustmark">
                    <span class="ndpr-badge-caption">Audited compliance trustmark</span>
                </a>
            </section>

            <div class="content">
                <h2>What this means for you</h2>
                <ul class="ndpr-check-list">
                    <li>
                        <span><strong>Data minimisation.</strong> We only collect
                        the personal data actually needed to run a booking,
                        contact you, or issue a receipt.</span>
                    </li>
                    <li>
                        <span><strong>Purpose limits.</strong> Data you or your
                        guests provide is used for the purpose it was
                        collected for - not resold, not repurposed.</span>
                    </li>
                    <li>
                        <span><strong>Subject rights honoured.</strong> You can
                        request access to what we hold, correction, or
                        deletion. We respond within 30 days.</span>
                    </li>
                    <li>
                        <span><strong>Breach transparency.</strong> If a data
                        breach ever affects your account we notify you and
                        the regulator in line with NDPR timelines.</span>
                    </li>
                    <li>
                        <span><strong>Processor / controller split.</strong> As
                        an operator on Keverna you remain the controller of
                        your guest data; we act as the processor.</span>
                    </li>
                </ul>

                <h2>The audit + certificate</h2>
                <p>
                    The certificate is issued to <strong>FregateLab
                    Limited</strong> (Keverna's parent company) and covers
                    the period noted on the document. It is verifiable
                    directly with the Nigeria Data Protection Bureau (NDPB)
                    using the reference on the certificate.
                </p>
                <p>
                    <a href="https://fregatelab-file.dokwe.com/FREGATELAB_LIMITED_-_AUDIT_COMPLAINCE_TRUSTMARK-2.pdf"
                       target="_blank" rel="noopener">Open the official
                    certificate (PDF)</a>
                </p>

                <h2>Related</h2>
                <p>
                    See our <a href="{{ route('legal.privacy') }}">privacy
                    notice</a> for the full picture of how personal data
                    flows through Keverna, or email
                    <a href="mailto:hello@keverna.com">hello@keverna.com</a>
                    for anything specific.
                </p>
            </div>
        </div>
    </main>

    @include('partials.cookie-consent')
</body>
</html>
