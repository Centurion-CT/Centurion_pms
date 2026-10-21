{{--
    Corporate self-service portal layout.

    Lives on the central marketing domain at /corporates/*. Same lp-*
    chrome as the operator dashboard, but the accent is repainted
    teal-blue so corporate admins always know they're in their own
    portal rather than the property side or admin.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ auth('corporate')->user()?->corporate?->name ?? config('brand.name').' Corporate' }}</title>
    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,500i|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{-- Portal accent override, teal-leaning so the surface reads
         distinct from operator olive and admin amber. Scoped via the
         body data attribute. --}}
    <style>
        body[data-context="corporate-portal"] {
            --color-accent:       #2A9D8F;
            --color-accent-deep:  #1F7268;
        }
        body[data-context="corporate-portal"] .corp-context-dot {
            display: inline-block; width: 6px; height: 6px;
            background: #2A9D8F; border-radius: 9999px;
        }
    </style>
</head>
<body data-context="corporate-portal" class="antialiased">
    @include('partials.maintenance-banner')

    @auth('corporate')
        @php
            $member = auth('corporate')->user();
            $corporate = $member?->corporate;
            $isAdmin = $member?->role?->value === 'admin';

            $nav = [
                'Workspace' => array_values(array_filter([
                    ['label' => 'Overview', 'route' => 'corporate.dashboard',      'prefix' => 'corporate.dashboard',
                     'icon'  => 'M3 11.5L10 5l7 6.5M5 10v8h10v-8'],
                    ['label' => 'Bookings', 'route' => 'corporate.bookings.index', 'prefix' => 'corporate.bookings.',
                     'icon'  => 'M4 5h12v12H4zM4 9h12M8 3v4M12 3v4'],
                    $isAdmin
                        ? ['label' => 'Members', 'route' => 'corporate.members.index', 'prefix' => 'corporate.members.',
                           'icon'  => 'M7 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM2 17c0-2.5 2-4.5 5-4.5s5 2 5 4.5M14 11a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM12 17c0-2 1.5-3.5 4-3.5']
                        : null,
                    $isAdmin
                        ? ['label' => 'Wallet', 'route' => 'corporate.wallet', 'prefix' => 'corporate.wallet',
                           'icon'  => 'M3 6h14v8H3zM3 9h14M14 11h2']
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

        <div class="lp-page lp-shell-dash">

            @persist('corp-sidebar')
            <aside class="lp-sidebar" data-testid="corp-sidebar"
                   data-corp-sidebar>
                <div class="lp-sidebar-brand">
                    <span class="lp-sidebar-brand-mark" aria-hidden>
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                            <path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/>
                        </svg>
                    </span>
                    <span class="lp-sidebar-brand-text">
                        <span class="name">{{ $corporate?->name ?? 'Corporate' }}</span>
                        <span class="sub" style="display: inline-flex; align-items: center; gap: 6px;">
                            <span class="corp-context-dot" aria-hidden></span>
                            Corporate portal
                        </span>
                    </span>
                </div>

                @foreach ($nav as $section => $items)
                    <div class="lp-sidebar-section">
                        <span class="lp-sidebar-label">{{ $section }}</span>
                        @foreach ($items as $item)
                            <a href="{{ route($item['route']) }}" wire:navigate
                               class="lp-sidebar-link {{ $isActive($item) ? 'is-active' : '' }}"
                               data-testid="corp-nav-{{ \Illuminate\Support\Str::slug($item['label']) }}">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="{{ $item['icon'] }}"/>
                                </svg>
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endforeach

                <div class="lp-sidebar-foot">
                    <form method="POST" action="{{ route('corporate.logout') }}">
                        @csrf
                        <button type="submit" data-testid="corp-logout"
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

            <div class="lp-main">
                <header class="lp-topbar" data-testid="corp-topbar">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="lp-display text-[15px] font-medium truncate"
                              style="color: var(--color-ink); max-width: 360px;">
                            {{ $corporate?->name }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="lp-mono text-[10.5px] tracking-[0.16em] uppercase flex items-center gap-1.5"
                              style="color: var(--color-ink-mute);">
                            <span class="corp-context-dot" aria-hidden></span>
                            {{ $isAdmin ? 'Corporate admin' : 'Member' }}
                        </span>
                        <span class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                              style="color: var(--color-ink-mute);">
                            {{ $member?->email }}
                        </span>
                    </div>
                </header>

                <main>
                    {{ $slot }}
                </main>
            </div>
        </div>

        {{-- Sidebar persistence companion, re-applies `is-active`
             on the persisted sidebar after each wire:navigate. --}}
        <script>
            (function () {
                function refresh() {
                    var sb = document.querySelector('[data-corp-sidebar]');
                    if (!sb) return;
                    var current = (location.pathname.replace(/\/$/, '') || '/').toLowerCase();
                    sb.querySelectorAll('a.lp-sidebar-link').forEach(function (a) {
                        var url; try { url = new URL(a.href, location.origin); } catch (e) { return; }
                        if (url.host !== location.host) return;
                        var path = (url.pathname.replace(/\/$/, '') || '/').toLowerCase();
                        var active = false;
                        if (path === '/corporates') {
                            active = current === '/corporates';
                        } else if (path !== '/') {
                            active = current === path || current.indexOf(path + '/') === 0;
                        }
                        a.classList.toggle('is-active', active);
                    });
                }
                document.addEventListener('livewire:navigated', refresh);
                document.addEventListener('DOMContentLoaded', refresh);
            })();
        </script>
    @endauth

    @guest('corporate')
        <main>{{ $slot }}</main>
    @endguest

    {{-- Global success / error modal, same partial as tenant + admin. --}}
    @include('partials.notify-modal')
    {{-- No WhatsApp bubble on the corporate portal, this is a B2B admin
         surface and a floating consumer-chat button reads wrong to a
         finance/HR user auditing an account. Corporate support flows
         through the utility footer email/phone link instead. --}}
    @include('partials._nav-spinner')
    @include('partials._confirm-modal')

    @livewireScripts
</body>
</html>
