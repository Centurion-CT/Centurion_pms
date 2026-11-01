{{--
    Full-screen POS kiosk layout, used when the signed-in operator is
    PosStaff. Strips the operator dashboard down to just the terminal
    surface + a minimal header with tenant + operator + Switch user /
    Logout. Managers keep the full sidebar dashboard because they need
    to hop between finance, inventory, etc.

    Rendered by the POS Livewire components via `->layout('layouts.pos-kiosk')`
    when the current user is PosStaff (see the shared UsesKioskLayout trait).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ \App\Models\Tenant::current()?->name }} POS &middot; {{ config('brand.name') }}</title>
    @include('partials.favicons')

    {{-- PWA install: manifest + theme colour + iOS-specific bits. See
         public/manifest.webmanifest and public/sw.js. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    {{-- Two theme-color variants so mobile browsers repaint the status
         bar to match the active POS palette. Warm-dark kicks in for the
         "phone at 11pm behind the bar" case. --}}
    <meta name="theme-color" content="#F5EDE3" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#12100D" media="(prefers-color-scheme: dark)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('brand.name') }} POS">
    <link rel="apple-touch-icon" href="/icons/pos-192.png">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,500i|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="antialiased lp-kiosk-body">
    @include('partials.maintenance-banner')

    @auth('web')
        @php
            $operator = auth('web')->user();
            $tenant = \App\Models\Tenant::current();
        @endphp

        <div class="lp-kiosk" data-testid="pos-kiosk-shell">

            {{-- ── Kiosk header ────────────────────────────────── --}}
            <header class="lp-kiosk__header" data-testid="pos-kiosk-header">
                <div class="lp-kiosk__brand">
                    <span class="lp-kiosk__brand-mark" aria-hidden>
                        <svg width="14" height="14" viewBox="0 0 12 12" fill="none">
                            <path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/>
                        </svg>
                    </span>
                    <span class="lp-kiosk__brand-name">{{ $tenant?->name ?? config('brand.name').' POS' }}</span>
                </div>

                <div class="lp-kiosk__actions">
                    <span class="lp-kiosk__operator" data-testid="pos-kiosk-operator">
                        <span class="lp-kiosk__operator-name">{{ $operator?->name }}</span>
                        <span class="lp-kiosk__operator-role">{{ $operator?->role?->label() }}</span>
                    </span>

                    {{-- Sync-now button, shown when the offline queue or the
                         failed-sales store is non-empty. Click drains the
                         queue; the badge sums queued + failed so the
                         operator knows how much is outstanding. --}}
                    <button type="button"
                            data-pos-sync-btn
                            data-testid="pos-kiosk-sync"
                            onclick="window.KevernaPos && window.KevernaPos.drain(); window.KevernaPosDB?.getFailed().then(f => { if (f.length > 0 && window.KevernaPosFailed) window.KevernaPosFailed.openPanel(); });"
                            style="display: none;"
                            class="lp-kiosk__btn lp-kiosk__btn--ghost"
                            title="Sync queued sales">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 6a5 5 0 0 1 9-3M14 10a5 5 0 0 1-9 3M12 3v3h-3M4 13v-3h3"/>
                        </svg>
                        Sync <span data-pos-sync-count>0</span>
                    </button>

                    {{-- Two flavours of the same slot: on a paired phone
                         (session has pos_device_session_id) show a
                         Disconnect action, clicking it revokes THIS
                         device and signs the phone out. On the desktop
                         kiosk show the "Link phone" flow that opens the
                         pairing QR. --}}
                    @if (session()->has('pos_device_session_id'))
                        <form method="POST" action="{{ route('tenant.pos.link-device.disconnect') }}"
                              class="lp-kiosk__form"
                              data-testid="pos-kiosk-disconnect"
                              data-confirm="Disconnect this phone from the POS? You'll need a fresh QR to sign back in."
                              data-confirm-tone="danger">
                            @csrf
                            <button type="submit" class="lp-kiosk__btn lp-kiosk__btn--ghost"
                                    title="Unlink this phone and sign out">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6 4H4a3 3 0 0 0 0 6h2M10 4h2a3 3 0 0 1 0 6h-2M6 7h4"/>
                                    <path d="M2 2l12 12" stroke-width="1.4"/>
                                </svg>
                                Disconnect
                            </button>
                        </form>
                    @else
                        <a href="{{ route('tenant.pos.link-device') }}" wire:navigate
                           class="lp-kiosk__btn lp-kiosk__btn--ghost"
                           data-testid="pos-kiosk-link-device"
                           title="Link a phone to run POS from a mobile">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="5" y="1" width="7" height="14" rx="1.2"/>
                                <path d="M7.5 12.5h2"/>
                            </svg>
                            Link phone
                        </a>
                    @endif

                    <form method="POST" action="{{ route('tenant.logout') }}"
                          class="lp-kiosk__form"
                          data-testid="pos-kiosk-switch-user"
                          data-confirm="Switch user? The current cart will be lost.">
                        @csrf
                        <button type="submit" class="lp-kiosk__btn lp-kiosk__btn--ghost">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M6 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM1 15c0-2.5 2-4.5 5-4.5s5 2 5 4.5M11 5l2 2 3-3"/>
                            </svg>
                            Switch user
                        </button>
                    </form>

                    <form method="POST" action="{{ route('tenant.logout') }}"
                          class="lp-kiosk__form"
                          data-testid="pos-kiosk-logout">
                        @csrf
                        <button type="submit" class="lp-kiosk__btn">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10 4V2H3v12h7v-2M14 8H6M12 5l3 3-3 3"/>
                            </svg>
                            Log out
                        </button>
                    </form>
                </div>
            </header>

            {{-- ── Slot ────────────────────────────────────────── --}}
            <main class="lp-kiosk__main">
                {{ $slot }}
            </main>
        </div>
    @endauth

    @guest('web')
        {{-- Kiosk shouldn't be visible to guests, but if a session drops
             mid-shift Livewire may render this once before the redirect
             fires, bare slot keeps the transition graceful. --}}
        {{ $slot }}
    @endguest

    {{-- Update the Sync-now button visibility + badge as the offline queue
         and failed-store counts change. Vanilla so it doesn't depend on
         Alpine being available in this layout. --}}
    <script>
        (function () {
            function refresh() {
                const btn = document.querySelector('[data-pos-sync-btn]');
                if (!btn) return;
                const q = Number(document.documentElement.dataset.posQueueCount || 0);
                Promise.resolve(window.KevernaPosDB ? window.KevernaPosDB.getFailed() : []).then(function (failed) {
                    var total = q + (failed?.length ?? 0);
                    btn.querySelector('[data-pos-sync-count]').textContent = String(total);
                    btn.style.display = total > 0 ? '' : 'none';
                });
            }
            window.addEventListener('DOMContentLoaded', refresh);
            window.addEventListener('keverna-pos:queue-changed', refresh);
            window.addEventListener('keverna-pos:failed-changed', refresh);
        })();
    </script>

    {{-- Auto-request browser fullscreen on the first user gesture. Browsers
         disallow programmatic fullscreen without a gesture, so we wire it
         to the very next click anywhere on the shell. A single click on a
         product tile or the "Enter fullscreen" nudge counts. --}}
    <script>
        (function () {
            if (!document.documentElement.requestFullscreen) return;
            function enter() {
                if (document.fullscreenElement) return;
                document.documentElement.requestFullscreen().catch(() => { /* user cancelled */ });
                document.removeEventListener('click', enter, true);
            }
            document.addEventListener('click', enter, true);
        })();
    </script>

    @include('partials._nav-spinner')
    @include('partials._confirm-modal')

    @livewireScripts
</body>
</html>
