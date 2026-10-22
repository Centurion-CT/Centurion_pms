{{-- Rendered when a phone hits /pos/pair/{code} with a code that
     doesn't resolve to a valid, unclaimed, unexpired row. `$reason` is
     one of: invalid, expired, already_used. Self-contained page, the
     phone isn't logged in yet so tenant-auth layouts don't apply. --}}
@php
    $copy = match ($reason ?? 'invalid') {
        'expired'      => [
            'title' => 'This code has expired.',
            'body'  => 'Pairing codes are short-lived for security. Ask the terminal to show a fresh QR and try again.',
        ],
        'already_used' => [
            'title' => 'That code has already been used.',
            'body'  => 'Each pairing code links exactly one phone. Have the terminal generate a fresh code and scan that one.',
        ],
        default        => [
            'title' => "We don't recognise that code.",
            'body'  => "The link you scanned isn't a valid Keverna POS pairing code. Try scanning it again, if it keeps failing, ask the terminal to regenerate the QR.",
        ],
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.favicons')
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pairing code · {{ config('brand.name') }} POS</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css'])
</head>
<body class="lp-page" style="min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px;">
    <main class="lp-card lp-card-cream" style="max-width: 420px; width: 100%;" data-testid="pair-invalid">
        <div class="lp-card-body" style="padding: 28px 26px;">
            <span class="lp-eyebrow-strip">Keverna POS · Pairing</span>
            <h1 class="lp-display" style="margin: 14px 0 0; font-size: clamp(24px, 6vw, 32px); font-weight: 600; line-height: 1.1;">
                {{ $copy['title'] }}
            </h1>
            <p style="margin: 14px 0 0; color: var(--color-ink-soft); font-size: 15px; line-height: 1.55;">
                {{ $copy['body'] }}
            </p>
            <p style="margin: 28px 0 0; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-ink-mute);">
                {{ $reason ?? 'invalid' }}
            </p>
        </div>
    </main>
</body>
</html>
