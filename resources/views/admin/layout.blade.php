{{--
    Admin layout, same lp-shell-dash chrome as the operator dashboard,
    but with an amber accent so platform staff always know they're on
    admin.keverna.com (PRD §18). Sidebar is the dark floating card;
    main content sits on the cream `.lp-dash` surface.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Keverna Admin' }}</title>
    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,500i|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{-- Admin accent override, repaints the active-link stripe + key
         accents amber inside the admin shell only. Scoped via the
         data-context attribute on <body> so the operator dashboard
         stays olive-lime when an admin impersonates a tenant. --}}
    <style>
        body[data-context="admin"] {
            --color-accent:       #FBBF24;
            --color-accent-deep:  #D97706;
        }
        body[data-context="admin"] .admin-context-dot {
            display: inline-block; width: 6px; height: 6px;
            background: #FBBF24; border-radius: 9999px;
        }
    </style>
</head>
<body data-context="admin" class="antialiased">
    @include('partials.maintenance-banner')

    @auth('admin')
        @php
            $currentAdmin = auth('admin')->user();
            $isSuper = $currentAdmin?->role === 'super';

            $nav = [
                'Platform' => [
                    ['label' => 'Dashboard', 'route' => 'admin.dashboard',     'prefix' => 'admin.dashboard',
                     'icon'  => 'M3 11.5L10 5l7 6.5M5 10v8h10v-8'],
                    ['label' => 'Tenants',   'route' => 'admin.tenants.index', 'prefix' => 'admin.tenants.',
                     'icon'  => 'M7 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM2 17c0-2.5 2-4.5 5-4.5s5 2 5 4.5M14 11a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM12 17c0-2 1.5-3.5 4-3.5'],
                    ['label' => 'Subscriptions', 'route' => 'admin.subscriptions.index', 'prefix' => 'admin.subscriptions.',
                     'icon'  => 'M4 5h12v10H4zM4 9h12M7 12h4'],
                    ['label' => 'Bookings',  'route' => 'admin.bookings.index', 'prefix' => 'admin.bookings.',
                     'icon'  => 'M4 5h12v12H4zM4 9h12M8 3v4M12 3v4'],
                    ['label' => 'Rents',     'route' => 'admin.rents.index',    'prefix' => 'admin.rents.',
                     'icon'  => 'M4 17V8l6-4 6 4v9M4 17h12M8 17v-5h4v5'],
                    ['label' => 'Revenue',   'route' => 'admin.revenue',       'prefix' => 'admin.revenue',
                     'icon'  => 'M4 17V8M9 17V4M14 17v-6M3 17h14'],
                    ['label' => 'Paystack fallback', 'route' => 'admin.paystack-fallback.index', 'prefix' => 'admin.paystack-fallback.',
                     'icon'  => 'M2 7h16v8H2zM2 10h16M5 13h3M14 6l-2 2 2 2M18 6l2 2-2 2'],
                ],
                'B2B' => [
                    ['label' => 'Corporates', 'route' => 'admin.corporates.index', 'prefix' => 'admin.corporates.',
                     'icon'  => 'M4 17V7l4-3 4 3v10M12 17V11l4-2v8M4 17h12M7 11h2M7 14h2'],
                ],
                'Sales' => [
                    ['label' => 'Demo requests', 'route' => 'admin.demo-requests.index', 'prefix' => 'admin.demo-requests.',
                     'icon'  => 'M4 6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H8l-4 4V6z'],
                    ['label' => 'API waitlist', 'route' => 'admin.api-access-requests.index', 'prefix' => 'admin.api-access-requests.',
                     'icon'  => 'M6 4l-3 3 3 3M14 4l3 3-3 3M11 3l-2 14'],
                    ['label' => 'Newsletter', 'route' => 'admin.newsletter.index', 'prefix' => 'admin.newsletter.',
                     'icon'  => 'M3 5h14v10H3zM3 5l7 5 7-5'],
                ],
                'Governance' => array_values(array_filter([
                    ['label' => 'Activity log', 'route' => 'admin.activity.index', 'prefix' => 'admin.activity.',
                     'icon'  => 'M3 4h14v3H3zM3 7v9h14V7M6 10h8M6 13h6'],
                    ['label' => 'Feedback', 'route' => 'admin.feedback.index', 'prefix' => 'admin.feedback.',
                     'icon'  => 'M4 4h12v8H8l-4 4V4zM8 8h4M8 10h6'],
                    // Super-only entries. Non-supers won't see them
                    // in the sidebar, and the routes 403 as belt-
                    // and-braces.
                    $isSuper
                        ? ['label' => 'Admins', 'route' => 'admin.admins.index', 'prefix' => 'admin.admins.',
                           'icon'  => 'M7 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM2 17c0-2.5 2-4.5 5-4.5s5 2 5 4.5M14 4l1.5 1.5L18 3M14 11l1.5 1.5L18 10']
                        : null,
                    $isSuper
                        ? ['label' => 'AI spend', 'route' => 'admin.ai-spend.index', 'prefix' => 'admin.ai-spend.',
                           'icon'  => 'M10 3v14M7 6h5.5a2 2 0 0 1 0 4H7a2 2 0 0 0 0 4h6']
                        : null,
                ])),
            ];

            $isActive = function (array $item): bool {
                if (request()->routeIs($item['route'])) return true;
                if (str_ends_with($item['prefix'] ?? '', '.')) {
                    return str_starts_with(request()->route()?->getName() ?? '', $item['prefix']);
                }
                return false;
            };
        @endphp

        <div class="lp-page lp-shell-dash" data-dash-shell>

            {{-- Mobile drawer backdrop, shown only when
                 .is-drawer-open is toggled on the shell. Tapping it
                 closes the drawer. Hidden on desktop via CSS. --}}
            <div class="lp-drawer-backdrop" data-drawer-backdrop aria-hidden="true"></div>

            {{-- ── Sidebar ─────────────────────────────────────────
                 @persist keeps the DOM across wire:navigate so the
                 admin's scrollTop survives. Active state refreshed
                 client-side after each nav (see script at body end). --}}
            @persist('admin-sidebar')
            <aside class="lp-sidebar" data-testid="admin-sidebar"
                   data-admin-sidebar>

                <div class="lp-sidebar-brand">
                    <span class="lp-sidebar-brand-mark" aria-hidden>
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                            <path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/>
                        </svg>
                    </span>
                    <span class="lp-sidebar-brand-text">
                        <span class="name">Keverna</span>
                        <span class="sub" style="display: inline-flex; align-items: center; gap: 6px;">
                            <span class="admin-context-dot" aria-hidden></span>
                            Platform admin
                        </span>
                    </span>
                </div>

                @foreach ($nav as $section => $items)
                    <div class="lp-sidebar-section">
                        <span class="lp-sidebar-label">{{ $section }}</span>
                        @foreach ($items as $item)
                            <a href="{{ route($item['route']) }}" wire:navigate
                               class="lp-sidebar-link {{ $isActive($item) ? 'is-active' : '' }}"
                               data-testid="admin-nav-{{ \Illuminate\Support\Str::slug($item['label']) }}">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="{{ $item['icon'] }}"/>
                                </svg>
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endforeach

                <div class="lp-sidebar-foot">
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" data-testid="admin-logout"
                                class="lp-sidebar-link w-full text-left"
                                style="cursor: pointer; background: transparent; border: 0; font-family: inherit; font-size: inherit;">
                            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 3H5v14h6M9 10h9M15 7l3 3-3 3"/>
                            </svg>
                            Log out
                        </button>
                    </form>
                </div>
            </aside>
            @endpersist

            {{-- ── Main content ───────────────────────────────────── --}}
            <div class="lp-main">
                <header class="lp-topbar" data-testid="admin-topbar">
                    <div class="flex items-center gap-3 min-w-0">
                        {{-- Mobile-only hamburger. Hidden ≥901px via CSS
                             ([.lp-drawer-trigger] rules in app.css). --}}
                        <button type="button" class="lp-drawer-trigger"
                                data-drawer-trigger aria-label="Open navigation"
                                data-testid="admin-drawer-trigger">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                                <path d="M2 4h12M2 8h12M2 12h12"/>
                            </svg>
                        </button>
                        <span class="lp-topbar-title lp-display text-[15px] font-medium truncate"
                              style="color: var(--color-ink); max-width: 360px;">
                            Platform admin
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="lp-mono text-[10.5px] tracking-[0.16em] uppercase flex items-center gap-1.5"
                              style="color: var(--color-ink-mute);">
                            <span class="admin-context-dot" aria-hidden></span>
                            Admin
                        </span>
                        <span class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                              style="color: var(--color-ink-mute);">
                            {{ auth('admin')->user()?->email }}
                        </span>
                    </div>
                </header>

                <main>
                    {{ $slot }}
                </main>
            </div>
        </div>

        {{-- Sidebar persistence companion script, same pattern as
             the tenant layout. Re-applies `is-active` after each
             wire:navigate since the persisted DOM wouldn't otherwise
             refresh the highlighted entry. --}}
        <script>
            (function () {
                function refresh() {
                    var sb = document.querySelector('[data-admin-sidebar]');
                    if (!sb) return;
                    var current = (location.pathname.replace(/\/$/, '') || '/').toLowerCase();
                    sb.querySelectorAll('a.lp-sidebar-link').forEach(function (a) {
                        var url; try { url = new URL(a.href, location.origin); } catch (e) { return; }
                        if (url.host !== location.host) return;
                        var path = (url.pathname.replace(/\/$/, '') || '/').toLowerCase();
                        var active = false;
                        if (path === '/admin') {
                            active = current === '/admin';
                        } else if (path !== '/') {
                            active = current === path || current.indexOf(path + '/') === 0;
                        }
                        a.classList.toggle('is-active', active);
                    });
                }
                document.addEventListener('livewire:navigated', refresh);
                document.addEventListener('DOMContentLoaded', refresh);

                // Mobile sidebar drawer, same wiring as the tenant
                // layout. CSS gates visibility behind ≤900px so this
                // code runs on desktop too but has no visual effect.
                function setDrawer(open) {
                    var shell = document.querySelector('[data-dash-shell]');
                    if (!shell) return;
                    shell.classList.toggle('is-drawer-open', open);
                    document.body.style.overflow = open ? 'hidden' : '';
                }
                document.addEventListener('click', function (e) {
                    if (e.target.closest('[data-drawer-trigger]')) {
                        setDrawer(true);
                        return;
                    }
                    if (e.target.closest('[data-drawer-backdrop]')) {
                        setDrawer(false);
                        return;
                    }
                    // Any sidebar link tap should close the drawer so
                    // the newly navigated page is visible.
                    var link = e.target.closest('[data-admin-sidebar] a.lp-sidebar-link');
                    if (link && link.target !== '_blank') {
                        setDrawer(false);
                    }
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') setDrawer(false);
                });
                window.addEventListener('resize', function () {
                    if (window.innerWidth > 900) setDrawer(false);
                });
            })();
        </script>
    @endauth

    @guest('admin')
        <main>{{ $slot }}</main>
    @endguest

    {{-- Global success / error modal, shared with the tenant
         dashboard. Fire via $this->dispatch('notify', title, message, kind). --}}
    @include('partials.notify-modal')
    @include('partials._confirm-modal')

    @livewireScripts
</body>
</html>
