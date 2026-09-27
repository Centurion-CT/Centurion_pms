{{-- Rendered by EnsureLinkedDeviceActive when the paired phone hits
     any POS route after an admin revoked its device session. The user
     has already been logged out by the time this renders. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Device unlinked · Keverna POS</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css'])
</head>
<body class="lp-page" style="min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px;">
    <main class="lp-card lp-card-cream" style="max-width: 420px; width: 100%;" data-testid="device-revoked">
        <div class="lp-card-body" style="padding: 28px 26px;">
            <span class="lp-eyebrow-strip">Keverna POS</span>
            <h1 class="lp-display" style="margin: 14px 0 0; font-size: clamp(24px, 6vw, 32px); font-weight: 600; line-height: 1.1;">
                This device was unlinked.
            </h1>
            <p style="margin: 14px 0 0; color: var(--color-ink-soft); font-size: 15px; line-height: 1.55;">
                An admin revoked this phone's access to the POS. If that
                wasn't intentional, ask them to pair it again from any
                terminal &rsaquo; <em>Link phone</em>.
            </p>
        </div>
    </main>
</body>
</html>
