<div class="lp-dash portfolio-page-scope">

    {{-- ── Hero: title + primary action ─────────────────────
         Identity + Add-a-property CTA only. The rollup pulse
         moves to a full-width 4-tile grid underneath, matching
         the dashboard KPI strip. --}}
    <section class="lp-res-idx-hero" data-testid="portfolio-hero">
        <div class="lp-res-idx-hero-body" style="grid-template-columns: 1fr;">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Overview</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Portfolio
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                    Every property under {{ $tenant?->name ?? 'your account' }}, at a glance.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.branches.index') }}"
                       class="lp-res-action lp-res-action--primary"
                       data-testid="portfolio-add-property">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        Add a property
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Portfolio rollup: full-width 4-tile grid ─────────
         Same shape as the dashboard KPI strip. Each tile: dot
         marker, big mono value, uppercase label with an
         optional sub. --}}
    @php
        $rollupTiles = [
            ['label' => 'Properties', 'value' => (int) $totals['properties'], 'dot' => 'var(--color-accent-deep)', 'sub' => 'in your account'],
            ['label' => 'Live',       'value' => (int) $totals['live'],       'dot' => '#4B7BB0',                  'sub' => 'accepting bookings'],
            ['label' => 'Bookings',   'value' => (int) $totals['booking_count'], 'dot' => '#7A6EA7',               'sub' => 'month to date'],
            ['label' => 'Revenue',    'value' => (int) $totals['revenue'],    'dot' => '#a36318',                  'sub' => 'month to date', 'money' => true],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-2 lg:grid-cols-4" data-testid="portfolio-rollup">
        @foreach ($rollupTiles as $tile)
            <div class="lp-dash-card"
                 style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;
                        border: 1px solid var(--color-line); border-radius: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: var(--color-ink);">
                        @if (! empty($tile['money']))₦{{ number_format($tile['value']) }}@else{{ number_format($tile['value']) }}@endif
                    </p>
                    <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                       style="color: var(--color-ink-mute); margin-top: 2px;">
                        {{ $tile['label'] }}
                        <span style="text-transform: none; letter-spacing: 0; color: var(--color-ink-mute);
                                     font-size: 11px; margin-left: 4px;">
                            {{ $tile['sub'] }}
                        </span>
                    </p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Property cards ──────────────────────────────────── --}}
    @if ($rows->isEmpty())
        <section class="lp-dash-card" data-testid="portfolio-empty">
            @include('livewire.dashboard._partials._empty', [
                'icon'    => 'room',
                'title'   => "You don't have any properties yet.",
                'sub'     => 'Set up your first property to unlock rooms, rates, and the marketplace listing.',
                'ctaLabel'=> 'Add your first property',
                'ctaHref' => route('tenant.branches.index'),
            ])
        </section>
    @else
        <div class="portfolio-grid" data-testid="portfolio-grid">
            @foreach ($rows as $row)
                @php
                    $p = $row['property'];
                    $hero = method_exists($p, 'getFirstMediaUrl')
                        ? ($p->getFirstMediaUrl('gallery', 'medium') ?: $p->getFirstMediaUrl('hero', 'medium'))
                        : '';
                    $isCurrent = $current?->id === $p->id;
                @endphp
                <article class="portfolio-card @if ($isCurrent) portfolio-card--current @endif"
                         data-testid="portfolio-card-{{ $p->id }}">
                    <div class="portfolio-card-hero"
                         @if ($hero) style="background-image: url('{{ $hero }}');" @endif>
                        @if (! $hero)
                            <span class="portfolio-card-hero-stub">{{ $p->city ?: 'Photo coming soon' }}</span>
                        @endif
                        <div class="portfolio-card-badges">
                            @if ($row['is_live'])
                                <span class="portfolio-badge portfolio-badge--live">● Live</span>
                            @else
                                <span class="portfolio-badge portfolio-badge--draft">Draft</span>
                            @endif
                            @if ($row['is_single_unit'])
                                <span class="portfolio-badge portfolio-badge--single">Single unit</span>
                            @endif
                            @if ($isCurrent)
                                <span class="portfolio-badge portfolio-badge--current">Current</span>
                            @endif
                        </div>
                    </div>
                    <div class="portfolio-card-body">
                        <div>
                            <h3 class="portfolio-card-name">{{ $p->name }}</h3>
                            <div class="portfolio-card-city">
                                {{ $row['city_line'] !== '' ? $row['city_line'] : 'Location not set' }}
                            </div>
                        </div>
                        <div class="portfolio-card-stats">
                            <div class="portfolio-stat">
                                <span class="portfolio-stat-value">{{ number_format((int) $row['booking_count']) }}</span>
                                <span class="portfolio-stat-label">Bookings MTD</span>
                            </div>
                            <div class="portfolio-stat">
                                <span class="portfolio-stat-value">₦{{ number_format((int) $row['revenue']) }}</span>
                                <span class="portfolio-stat-label">Revenue MTD</span>
                            </div>
                            <div class="portfolio-stat">
                                <span class="portfolio-stat-value">{{ number_format((int) $row['upcoming_checkins']) }}</span>
                                <span class="portfolio-stat-label">Check-ins · 7d</span>
                            </div>
                        </div>
                        <div class="portfolio-card-actions">
                            @if ($isCurrent)
                                <span class="lp-res-action" style="cursor: default; opacity: 0.7;">
                                    Already open
                                </span>
                            @else
                                <button type="button" wire:click="switchTo({{ $p->id }})"
                                        class="lp-res-action lp-res-action--primary"
                                        data-testid="portfolio-switch-{{ $p->id }}">
                                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M9 3l4 5-4 5M3 8h10"/></svg>
                                    Open dashboard
                                </button>
                            @endif
                            @if ($row['is_listed'] && ! empty($p->public_slug))
                                <a href="{{ url('/stays/'.$p->public_slug) }}"
                                   target="_blank" rel="noopener"
                                   class="lp-res-action">
                                    Marketplace
                                </a>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    <style>
        .portfolio-page-scope .portfolio-grid {
            display: grid; gap: 18px;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        }
        .portfolio-page-scope .portfolio-card {
            background: var(--color-card);
            border: 1px solid var(--color-line);
            border-radius: 16px; overflow: hidden;
            display: flex; flex-direction: column;
            transition: border-color 120ms ease, transform 120ms ease;
        }
        .portfolio-page-scope .portfolio-card:hover {
            border-color: var(--color-ink);
        }
        .portfolio-page-scope .portfolio-card--current {
            border-color: var(--color-accent-deep);
            box-shadow: 0 0 0 1px var(--color-accent-deep) inset;
        }
        .portfolio-page-scope .portfolio-card-hero {
            aspect-ratio: 16 / 9;
            background: linear-gradient(135deg, #EDEAE3 0%, #E2DED2 100%);
            background-size: cover; background-position: center;
            position: relative; display: flex; align-items: flex-end;
        }
        .portfolio-page-scope .portfolio-card-hero-stub {
            padding: 12px 16px; font-size: 13px; color: rgba(20, 17, 12, 0.55);
        }
        .portfolio-page-scope .portfolio-card-badges {
            position: absolute; top: 10px; left: 10px;
            display: flex; gap: 6px; flex-wrap: wrap;
        }
        .portfolio-page-scope .portfolio-badge {
            padding: 3px 9px; border-radius: 999px;
            font-family: var(--font-mono);
            font-size: 10px; font-weight: 600; letter-spacing: 0.08em;
            text-transform: uppercase;
            backdrop-filter: blur(6px);
        }
        .portfolio-page-scope .portfolio-badge--live { background: rgba(226, 237, 200, 0.9); color: #2E4A0F; }
        .portfolio-page-scope .portfolio-badge--draft { background: rgba(240, 240, 240, 0.9); color: #555; }
        .portfolio-page-scope .portfolio-badge--single { background: rgba(252, 239, 212, 0.92); color: #6B4C00; }
        .portfolio-page-scope .portfolio-badge--current { background: rgba(20, 23, 26, 0.92); color: #FCFAF5; }
        .portfolio-page-scope .portfolio-card-body {
            padding: 16px 18px 18px;
            display: grid; gap: 14px; flex: 1;
        }
        .portfolio-page-scope .portfolio-card-name {
            font-family: var(--font-display);
            font-size: 18px; font-weight: 600; line-height: 1.15;
            letter-spacing: -0.005em;
            margin: 0 0 3px; color: var(--color-ink);
        }
        .portfolio-page-scope .portfolio-card-city {
            font-size: 12.5px; color: var(--color-ink-mute);
        }
        .portfolio-page-scope .portfolio-card-stats {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 8px; padding: 12px 0;
            border-top: 1px solid var(--color-line-soft);
            border-bottom: 1px solid var(--color-line-soft);
        }
        .portfolio-page-scope .portfolio-stat { min-width: 0; }
        .portfolio-page-scope .portfolio-stat-value {
            display: block;
            font-family: var(--font-mono);
            font-size: 14px; font-weight: 600; color: var(--color-ink);
        }
        .portfolio-page-scope .portfolio-stat-label {
            display: block;
            font-family: var(--font-mono);
            font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase;
            color: var(--color-ink-mute); margin-top: 3px;
        }
        .portfolio-page-scope .portfolio-card-actions {
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        .portfolio-page-scope .portfolio-card-actions .lp-res-action { padding: 8px 14px; font-size: 12.5px; }
    </style>
</div>
