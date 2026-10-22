{{-- Rendered by the NoCurrentTenant handler in bootstrap/app.php when a
     visitor hits a hostname that DomainTenantFinder can't resolve to a
     tenant. Kept self-contained (no layout include), the tenant-scoped
     layouts assume Tenant::current() is set, which it isn't here. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.favicons')
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nothing here · {{ config('brand.name') }}</title>
    <meta name="robots" content="noindex, nofollow">

    {{-- Same font pair as the landing (welcome.blade.php) so this page
         reads as part of the brand, not an error dump. --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,500i|inter:400,500,600" rel="stylesheet" />

    {{-- Error views must render even when Vite hasn't built (local dev
         first-run, or a broken build). Emit the tag only when the hot
         file or manifest is present, and always ship a minimal inline
         fallback so the page stays readable either way. --}}
    @if (file_exists(public_path('hot')) || file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css'])
    @endif
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; background: #fbf7ee; color: #1a1a1a; margin: 0; }
        .lp-shell { max-width: 960px; margin: 14px auto; padding: 0 16px; display: grid; gap: 14px; }
        .lp-card { border-radius: 18px; background: #fff; overflow: hidden; }
        .lp-card-cream { background: #f6efdd; }
        .lp-card-ink { background: #1a1a1a; color: #fbf7ee; }
        .lp-card-body { padding: 28px; }
        .lp-display { font-family: 'Fraunces', Georgia, serif; letter-spacing: -0.02em; }
        .lp-script { font-family: 'Fraunces', Georgia, serif; font-style: italic; }
        .lp-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .lp-pill { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 999px; background: #fbf7ee; color: #1a1a1a; text-decoration: none; font-weight: 500; }
        .lp-pill-ghost { background: transparent; border: 1px solid rgba(255,252,245,0.24); color: #fbf7ee; }
        .lp-pill-dot { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 999px; background: rgba(0,0,0,0.08); }
        .lp-eyebrow-strip { font-size: 12px; letter-spacing: 0.14em; text-transform: uppercase; font-weight: 500; }
        .lp-eyebrow-on-dark { color: rgba(255,252,245,0.55); }
    </style>
</head>
<body class="lp-page" style="min-height: 100dvh;">
    <div class="lp-shell" style="min-height: calc(100dvh - 28px); grid-template-rows: auto 1fr;">
        {{-- Nav card, small wordmark, quiet link back to the homepage. --}}
        <header class="lp-card lp-card-cream">
            <div class="lp-card-body" style="padding: 18px 22px; display: flex; align-items: center; justify-content: space-between;">
                <a href="https://{{ $centralDomain }}"
                   class="lp-display"
                   style="font-size: 20px; font-weight: 600; color: var(--color-ink); text-decoration: none; letter-spacing: -0.02em;">
                    Keverna
                </a>
                <a href="https://{{ $centralDomain }}"
                   class="lp-pill lp-pill-ghost"
                   style="text-decoration: none;">
                    Homepage
                    <span class="lp-pill-dot" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none">
                            <path d="M4 10h11m0 0-4-4m4 4-4 4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                </a>
            </div>
        </header>

        {{-- Hero card, dark ink so the message carries weight. Editorial
             layout: eyebrow strip, big Fraunces headline, body copy, two
             CTAs. Attempted host lives quietly at the bottom for debug. --}}
        <main class="lp-card lp-card-ink" style="display: flex; align-items: center;">
            <div class="lp-card-body" style="width: 100%; max-width: 720px;">
                <span class="lp-eyebrow-strip lp-eyebrow-on-dark">Nothing here yet</span>

                <h1 class="lp-display" style="margin: 20px 0 0; font-size: clamp(40px, 7vw, 76px);">
                    This address isn&rsquo;t<br>
                    a Keverna <span class="lp-script" style="color: var(--color-accent);">property</span>.
                </h1>

                <p style="margin: 24px 0 0; max-width: 52ch; font-size: 17px; line-height: 1.55; color: color-mix(in srgb, var(--color-card) 82%, transparent);">
                    Every space that lists with Keverna lives on its own subdomain: <span class="lp-mono" style="font-size: 15px;">yourplace.{{ $centralDomain }}</span>. If you followed a booking link, double-check the URL. If you&rsquo;re here to list a space, start at the homepage.
                </p>

                <div style="margin-top: 36px; display: flex; flex-wrap: wrap; gap: 12px;">
                    <a href="https://{{ $centralDomain }}"
                       class="lp-pill"
                       style="text-decoration: none;">
                        Go to {{ $centralDomain }}
                        <span class="lp-pill-dot" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none">
                                <path d="M4 10h11m0 0-4-4m4 4-4 4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                    </a>
                    <a href="https://{{ $centralDomain }}/#how-it-works"
                       class="lp-pill lp-pill-ghost"
                       style="text-decoration: none; color: var(--color-card); border-color: rgba(255, 252, 245, 0.24);">
                        What is Keverna?
                    </a>
                </div>

                @if (! empty($attemptedHost))
                    <p style="margin: 48px 0 0; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: color-mix(in srgb, var(--color-card) 45%, transparent);">
                        You landed on <span class="lp-mono" style="text-transform: none; letter-spacing: 0; font-size: 13px; color: color-mix(in srgb, var(--color-card) 75%, transparent);">{{ $attemptedHost }}</span>
                    </p>
                @endif
            </div>
        </main>
    </div>
</body>
</html>
