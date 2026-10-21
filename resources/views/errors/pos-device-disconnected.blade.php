{{-- Landing page after a paired phone self-disconnects via the kiosk
     header. Distinct from pos-device-revoked (which fires when an
     admin revokes the phone) because the copy needs to reassure -
     the operator did this on purpose. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signed out · {{ config('brand.name') }} POS</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css'])
</head>
<body class="lp-page" style="min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px;">
    <main class="lp-card lp-card-cream" style="max-width: 420px; width: 100%;" data-testid="device-disconnected">
        <div class="lp-card-body" style="padding: 28px 26px;">
            <span class="lp-eyebrow-strip">Keverna POS</span>
            <h1 class="lp-display" style="margin: 14px 0 0; font-size: clamp(24px, 6vw, 32px); font-weight: 600; line-height: 1.1;">
                Signed out.
            </h1>
            <p style="margin: 14px 0 0; color: var(--color-ink-soft); font-size: 15px; line-height: 1.55;">
                This phone is no longer linked to the POS. To sign back in,
                open any terminal &rsaquo; <em>Link phone</em> and scan the
                fresh QR code.
            </p>
        </div>
    </main>
</body>
</html>
