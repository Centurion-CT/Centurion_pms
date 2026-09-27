@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
    $today = \Illuminate\Support\Carbon::today()->toDateString();
@endphp

{{-- Per-tenant "browse all rooms" page. Inherits --primary,
     --display-font, --body-font from layouts.tenant-public so accents
     follow the operator's brand. Visual language matches the property
     landing + room detail pages. --}}
<div class="rb-page" wire:poll.10s="refresh">
    <style>
        .rb-page {
            --rb-paper:    #FAF7F2;
            --rb-paper-2:  #F2EEE6;
            --rb-card:     #FFFFFF;
            --rb-ink:      #14171A;
            --rb-ink-soft: #3D434A;
            --rb-ink-mute: #7A7F87;
            --rb-line:     rgba(20, 23, 26, 0.10);
            --rb-line-soft:rgba(20, 23, 26, 0.06);
            --rb-primary-dark: color-mix(in srgb, var(--primary, #14171A) 78%, black);
            --rb-primary-soft: color-mix(in srgb, var(--primary, #14171A) 12%, transparent);

            background: var(--rb-paper);
            color: var(--rb-ink);
            font-family: var(--body-font);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-bottom: 5rem;
        }
        .rb-page h1, .rb-page h2, .rb-page h3 {
            font-family: var(--display-font);
            color: var(--rb-ink);
            letter-spacing: -0.018em;
            line-height: 1.06;
            margin: 0;
            font-weight: 600;
        }
        .rb-page a { color: inherit; text-decoration: none; }
        .rb-page p { color: var(--rb-ink-soft); line-height: 1.65; }

        .rb-wrap { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
        @media (min-width: 768px) { .rb-wrap { padding: 0 2.5rem; } }

        /* ── Eyebrow (italic display-serif with leading rule) ──── */
        .rb-eyebrow {
            display: inline-flex; align-items: center; gap: 10px;
            font-family: var(--display-font);
            font-style: italic;
            font-weight: 500;
            font-size: 14px;
            color: var(--primary, var(--rb-ink-soft));
        }
        .rb-eyebrow::before {
            content: '';
            display: inline-block;
            width: 14px;
            height: 1px;
            background: var(--primary, var(--rb-ink-soft));
        }

        /* ── Header ─────────────────────────────────────────────── */
        .rb-header { padding: 2.5rem 0 1.5rem; }
        @media (min-width: 768px) { .rb-header { padding: 3.5rem 0 2rem; } }
        .rb-title {
            margin-top: 0.85rem;
            font-size: clamp(34px, 5vw, 56px);
            font-weight: 600;
            line-height: 1.04;
            letter-spacing: -0.025em;
        }
        .rb-title .accent { color: var(--primary, var(--rb-ink)); font-style: italic; font-weight: 500; }
        .rb-subtitle {
            margin-top: 1rem;
            font-size: 16px;
            color: var(--rb-ink-soft);
            max-width: 580px;
        }

        /* ── Filter bar ─────────────────────────────────────────── */
        .rb-filters {
            margin-top: 1rem;
            background: var(--rb-card);
            border: 1px solid var(--rb-line);
            border-radius: 18px;
            padding: 1rem;
            box-shadow: 0 22px 50px -32px rgba(20,17,12,0.22);
            /* Stick just below the tenant-public sticky nav so guests
               can keep tweaking dates + guests as they scroll through
               the room grid. Wire:model.live on every field means
               results update behind the still-visible filter. */
            position: sticky;
            top: 62px;
            z-index: 30;
            transition:
                padding 220ms ease,
                border-radius 220ms ease,
                box-shadow 220ms ease,
                margin-top 220ms ease;
        }
        /* Compact treatment applied via IntersectionObserver when the
           filter reaches the top of the viewport. Shrinks the padding
           and rounds the corners flush so it reads as a docked toolbar
           without losing any of its inputs. */
        .rb-filters.is-stuck {
            margin-top: 0;
            padding: 0.4rem;
            border-radius: 0 0 14px 14px;
            border-top-color: transparent;
            box-shadow: 0 10px 24px -14px rgba(20,17,12,0.22);
        }
        .rb-filters.is-stuck .rb-filter { padding: 8px 14px; }
        .rb-filters.is-stuck .rb-filter-label { font-size: 9.5px; }
        .rb-filters.is-stuck .rb-filter input,
        .rb-filters.is-stuck .rb-filter select { font-size: 13.5px; }
        .rb-filters.is-stuck .rb-filter-foot {
            /* Collapse the filter footer (summary + clear) once
               docked, the summary now duplicates what the results
               head shows. */
            display: none;
        }
        .rb-filters.is-stuck + .rb-sticky-shadow {
            /* Faint fade under the docked filter so the room grid
               doesn't visually clip against it. */
            opacity: 1;
        }
        .rb-sticky-shadow {
            height: 12px;
            margin-top: -12px;
            background: linear-gradient(to bottom, rgba(20,17,12,0.06), transparent);
            pointer-events: none;
            opacity: 0;
            transition: opacity 220ms ease;
            position: relative;
            z-index: 29;
        }
        /* Sentinel that IntersectionObserver watches to know when the
           filter is stuck. Height 1px, invisible; positioned right
           above the filter so its out-of-view = filter stuck. */
        .rb-sticky-sentinel {
            display: block;
            height: 1px;
            margin-top: -1px;
        }
        .rb-filters-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2px;
            background: var(--rb-line-soft);
            border-radius: 12px;
            overflow: hidden;
        }
        @media (min-width: 720px) {
            .rb-filters-grid {
                grid-template-columns: 1.2fr 1.2fr 1fr 1fr auto;
                align-items: stretch;
            }
        }
        .rb-filter {
            background: var(--rb-card);
            padding: 12px 16px;
            display: flex; flex-direction: column;
            gap: 4px;
            position: relative;
        }
        .rb-filter-label {
            font-size: 10px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--rb-ink-mute);
            font-weight: 500;
        }
        .rb-filter input,
        .rb-filter select {
            font-family: inherit;
            font-size: 15px;
            color: var(--rb-ink);
            border: 0; outline: 0; padding: 0; margin: 0;
            background: transparent;
            width: 100%;
            line-height: 1.3;
            appearance: none;
            -webkit-appearance: none;
        }
        .rb-filter select {
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' fill='none' stroke='%237A7F87' stroke-width='1.5' stroke-linecap='round'><path d='M1 1l4 4 4-4'/></svg>");
            background-repeat: no-repeat;
            background-position: right 0 center;
            padding-right: 18px;
        }
        .rb-filter input[type="date"] { font-size: 14.5px; }
        .rb-filter:focus-within { background: var(--rb-paper); }

        .rb-filter-submit {
            background: var(--rb-card);
            padding: 8px 8px 8px 12px;
            display: flex; align-items: center; justify-content: center;
            gap: 10px;
        }
        @media (min-width: 720px) {
            .rb-filter-submit {
                padding: 8px;
            }
        }
        .rb-pill {
            display: inline-flex; align-items: center; justify-content: space-between;
            gap: 12px;
            padding: 10px 10px 10px 22px;
            background: var(--rb-ink);
            color: var(--rb-card);
            border: 0;
            border-radius: 9999px;
            font-family: var(--body-font);
            font-size: 14px; font-weight: 500;
            cursor: pointer;
            transition: background 200ms, transform 80ms;
            text-decoration: none;
            white-space: nowrap;
        }
        .rb-pill:hover { background: var(--rb-primary-dark); }
        .rb-pill:active { transform: translateY(1px); }
        .rb-pill .dot {
            width: 30px; height: 30px;
            background: var(--primary, var(--rb-ink-mute));
            color: #fff;
            border-radius: 9999px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .rb-filter-foot {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 1rem;
            padding: 1rem 0.5rem 0.25rem;
            border-top: 1px solid var(--rb-line-soft);
            margin-top: 1rem;
        }
        .rb-filter-summary {
            font-size: 12.5px;
            color: var(--rb-ink-soft);
            letter-spacing: 0.01em;
        }
        .rb-filter-summary strong {
            color: var(--rb-ink);
            font-weight: 600;
        }
        .rb-clear {
            font-size: 12px;
            color: var(--rb-ink-mute);
            background: transparent; border: 0; cursor: pointer;
            font-family: inherit;
            text-decoration: underline;
            text-underline-offset: 4px;
            letter-spacing: 0.04em;
            padding: 4px 6px;
        }
        .rb-clear:hover { color: var(--rb-ink); }

        /* ── Results meta row ───────────────────────────────────── */
        .rb-results-head {
            display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between;
            gap: 1rem;
            margin: 3rem 0 1.5rem;
        }
        .rb-results-count {
            font-family: var(--display-font);
            font-size: clamp(20px, 2.2vw, 26px);
            font-weight: 500;
            color: var(--rb-ink);
            letter-spacing: -0.015em;
        }
        .rb-results-count em {
            font-style: italic;
            color: var(--primary, var(--rb-ink-mute));
            font-weight: 500;
        }
        .rb-sort {
            display: inline-flex; align-items: center; gap: 10px;
            font-size: 12.5px;
            color: var(--rb-ink-mute);
        }
        .rb-sort select {
            font-family: inherit;
            font-size: 13px;
            color: var(--rb-ink);
            background: var(--rb-card);
            border: 1px solid var(--rb-line);
            border-radius: 9999px;
            padding: 7px 30px 7px 14px;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' fill='none' stroke='%2314171A' stroke-width='1.5' stroke-linecap='round'><path d='M1 1l4 4 4-4'/></svg>");
            background-repeat: no-repeat;
            background-position: right 12px center;
        }
        .rb-sort select:hover { border-color: var(--rb-ink); }

        /* ── Room grid ──────────────────────────────────────────── */
        .rb-grid {
            display: grid;
            gap: 1.75rem;
            grid-template-columns: 1fr;
        }
        @media (min-width: 720px)  { .rb-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1100px) { .rb-grid { grid-template-columns: repeat(3, 1fr); } }

        .rb-card {
            background: var(--rb-card);
            border: 1px solid var(--rb-line);
            border-radius: 18px;
            overflow: hidden;
            display: flex; flex-direction: column;
            transition: transform 240ms ease, box-shadow 240ms ease, border-color 200ms;
        }
        .rb-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 24px 48px -22px rgba(20,17,12,0.22);
            border-color: var(--rb-ink);
        }
        .rb-card-photo {
            aspect-ratio: 16 / 11;
            background:
                repeating-linear-gradient(45deg, rgba(20,23,26,0.05), rgba(20,23,26,0.05) 12px, transparent 12px, transparent 24px),
                var(--rb-paper-2);
            overflow: hidden;
            position: relative;
        }
        .rb-card-photo img {
            width: 100%; height: 100%; object-fit: cover;
            transition: transform 600ms cubic-bezier(0.16, 1, 0.3, 1);
        }
        .rb-card:hover .rb-card-photo img { transform: scale(1.04); }
        .rb-card-badge {
            position: absolute; top: 14px; left: 14px;
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px;
            background: var(--rb-card);
            color: var(--rb-ink);
            border-radius: 9999px;
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(20,17,12,0.12);
        }
        .rb-card-badge .star { color: var(--primary, var(--rb-ink-soft)); }

        .rb-card-body {
            padding: 1.25rem 1.4rem 1.5rem;
            display: flex; flex-direction: column; flex: 1;
            gap: 0.75rem;
        }
        .rb-card-eyebrow {
            font-family: var(--display-font);
            font-style: italic;
            font-weight: 500;
            font-size: 12.5px;
            color: var(--primary, var(--rb-ink-mute));
        }
        .rb-card-name {
            font-family: var(--display-font);
            font-weight: 600;
            font-size: 22px;
            line-height: 1.15;
            letter-spacing: -0.015em;
            color: var(--rb-ink);
        }
        .rb-card-meta {
            display: flex; flex-wrap: wrap; align-items: center;
            gap: 0.5rem 0.85rem;
            font-size: 13px;
            color: var(--rb-ink-soft);
        }
        .rb-card-meta .dot { color: var(--rb-ink-mute); }
        .rb-card-foot {
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid var(--rb-line-soft);
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 0.5rem;
        }
        .rb-card-price {
            font-family: var(--display-font);
            font-weight: 600;
            font-size: 22px;
            color: var(--rb-ink);
            letter-spacing: -0.015em;
        }
        .rb-card-price .from {
            font-family: var(--body-font);
            font-size: 11px;
            color: var(--rb-ink-mute);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-right: 4px;
        }
        .rb-card-price .per {
            font-family: var(--body-font);
            font-size: 12.5px;
            color: var(--rb-ink-mute);
            font-weight: 400;
        }
        .rb-card-price .nights {
            display: block;
            font-family: var(--body-font);
            font-size: 11.5px;
            color: var(--rb-ink-mute);
            font-weight: 400;
            margin-top: 2px;
            letter-spacing: 0.02em;
        }
        .rb-card-cta {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--rb-ink);
            letter-spacing: 0.04em;
        }
        .rb-card-cta::after {
            content: '→';
            color: var(--primary, var(--rb-ink));
            font-size: 14px;
            line-height: 1;
        }

        /* ── Empty state ────────────────────────────────────────── */
        .rb-empty {
            margin-top: 2rem;
            padding: 4rem 2rem;
            text-align: center;
            background: var(--rb-card);
            border: 1px solid var(--rb-line);
            border-radius: 18px;
        }
        .rb-empty h2 {
            font-size: clamp(22px, 2.4vw, 28px);
            margin-bottom: 0.75rem;
        }
        .rb-empty p {
            font-size: 14.5px;
            color: var(--rb-ink-soft);
            margin: 0 auto;
            max-width: 480px;
        }
        .rb-empty-hints {
            display: flex; flex-wrap: wrap; justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }
        .rb-empty-hint {
            display: inline-flex; align-items: center;
            padding: 5px 12px;
            background: var(--rb-paper);
            border: 1px solid var(--rb-line-soft);
            border-radius: 9999px;
            font-size: 12px;
            color: var(--rb-ink-soft);
        }

        /* ── Live-availability indicator (poll-driven) ───────────── */
        .rb-live {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 11.5px;
            color: var(--rb-ink-mute);
            font-family: var(--body-font);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .rb-live-dot {
            width: 7px; height: 7px;
            background: var(--primary, var(--rb-ink-soft));
            border-radius: 50%;
            animation: rb-live-pulse 1.6s ease-in-out infinite;
        }
        @keyframes rb-live-pulse {
            0%, 100% { opacity: 1; transform: scale(1);    box-shadow: 0 0 0 0   color-mix(in srgb, var(--primary, #14171A) 35%, transparent); }
            50%      { opacity: 0.55; transform: scale(0.9); box-shadow: 0 0 0 6px transparent; }
        }

        /* ── Loading indicator for live filters ─────────────────── */
        .rb-loading {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px;
            color: var(--rb-ink-mute);
            font-style: italic;
            font-family: var(--display-font);
        }
        .rb-loading::before {
            content: '';
            width: 7px; height: 7px;
            background: var(--primary, var(--rb-ink-mute));
            border-radius: 50%;
            animation: rb-pulse 1.2s ease-in-out infinite;
        }
        @keyframes rb-pulse {
            0%, 100% { opacity: 0.3; transform: scale(0.85); }
            50%      { opacity: 1;   transform: scale(1); }
        }

        /* ── Mobile polish ──────────────────────────────────────────
           Targeted overrides for ≤640px. Avoids breaking the
           tablet-and-up layout while making the stacked phone view
           feel intentional instead of just "narrowed". */
        @media (max-width: 640px) {
            .rb-wrap { padding: 0 1.1rem; }
            .rb-header { padding: 1.75rem 0 1rem; }

            /* Filter card, drop the heavy shadow + inner padding so
               the form doesn't dominate the viewport. The five rows
               already stack to one column; just need them less
               airy. */
            .rb-filters {
                padding: 0.5rem;
                border-radius: 14px;
                box-shadow: 0 10px 24px -16px rgba(20,17,12,0.18);
            }
            .rb-filter { padding: 10px 13px; }
            .rb-filter-submit { padding: 8px 0 4px; }
            .rb-pill { width: 100%; justify-content: center; }
            .rb-filter-foot {
                padding: 0.75rem 0.25rem 0.25rem;
                gap: 0.6rem;
            }
            .rb-filter-summary { font-size: 12px; line-height: 1.5; }

            /* Results header, let the sort drop neatly under the
               count instead of fighting for the same row. */
            .rb-results-head {
                margin: 2rem 0 1rem;
                gap: 0.75rem;
            }
            .rb-sort { width: 100%; justify-content: space-between; }

            /* Cards, tighter padding + smaller photo + slightly
               smaller display name so a phone shows ~1.5 cards
               above the fold instead of ~1. */
            .rb-grid { gap: 1.1rem; }
            .rb-card-photo { aspect-ratio: 4 / 3; }
            .rb-card-body { padding: 1rem 1.15rem 1.15rem; gap: 0.55rem; }
            .rb-card-name { font-size: 19px; }
            .rb-card-meta { font-size: 12.5px; gap: 0.4rem 0.7rem; }
            .rb-card-foot { padding-top: 0.75rem; }
            .rb-card-price { font-size: 19px; }
            .rb-card-price .nights { font-size: 11px; }

            /* Empty state, 4rem vertical was too much on a phone. */
            .rb-empty { padding: 2.5rem 1.25rem; }
        }
    </style>

    <div class="rb-wrap">

        {{-- ── Header ───────────────────────────────────────────── --}}
        <header class="rb-header">
            <span class="rb-eyebrow">Stay with us</span>
            <h1 class="rb-title">
                Find a room <span class="accent">made for you.</span>
            </h1>
            <p class="rb-subtitle">
                Pick your dates, tell us how many guests, and we&rsquo;ll show you everything
                available at {{ $property?->name ?? 'this property' }}.
            </p>
        </header>

        {{-- IntersectionObserver sentinel: when this 1px marker
             scrolls out of view, the filter has hit the top and the
             .is-stuck compact class fires (see the small script at
             the bottom of this file). --}}
        <span class="rb-sticky-sentinel" data-sticky-sentinel></span>

        {{-- ── Filter bar (sticky-compact) ─────────────────────── --}}
        <form wire:submit.prevent class="rb-filters" data-testid="rooms-filters"
              data-sticky-filter>
            <div class="rb-filters-grid">
                <div class="rb-filter">
                    <span class="rb-filter-label">Check-in</span>
                    <input type="date"
                           wire:model.live.debounce.400ms="checkin"
                           min="{{ $today }}"
                           data-testid="filter-checkin">
                </div>
                <div class="rb-filter">
                    <span class="rb-filter-label">Check-out</span>
                    <input type="date"
                           wire:model.live.debounce.400ms="checkout"
                           min="{{ $today }}"
                           data-testid="filter-checkout">
                </div>
                <div class="rb-filter">
                    <span class="rb-filter-label">Adults</span>
                    <select wire:model.live="adults" data-testid="filter-adults">
                        @for ($i = 1; $i <= 10; $i++)
                            <option value="{{ $i }}">{{ $i }} adult{{ $i === 1 ? '' : 's' }}</option>
                        @endfor
                    </select>
                </div>
                <div class="rb-filter">
                    <span class="rb-filter-label">Children</span>
                    <select wire:model.live="children" data-testid="filter-children">
                        @for ($i = 0; $i <= 8; $i++)
                            <option value="{{ $i }}">{{ $i }} child{{ $i === 1 ? '' : 'ren' }}</option>
                        @endfor
                    </select>
                </div>
                <div class="rb-filter-submit">
                    <button type="submit" class="rb-pill" data-testid="filter-search">
                        <span>Search</span>
                        <span class="dot">{!! $pillArrow !!}</span>
                    </button>
                </div>
            </div>

            <div class="rb-filter-foot">
                <span class="rb-filter-summary">
                    @if ($datesValid)
                        Showing rooms available for
                        <strong>{{ \Illuminate\Support\Carbon::parse($checkin)->toFormattedDateString() }}</strong>
                        →
                        <strong>{{ \Illuminate\Support\Carbon::parse($checkout)->toFormattedDateString() }}</strong>,
                        {{ $guestCount }} guest{{ $guestCount === 1 ? '' : 's' }}.
                    @elseif ($checkin !== '' || $checkout !== '')
                        Pick a valid date range to check availability.
                    @else
                        Showing every active room. Add dates to filter by availability.
                    @endif
                    <span wire:loading wire:target="checkin,checkout,adults,children,sort"
                          class="rb-loading" style="margin-left: 10px;">
                        Updating…
                    </span>
                </span>
                <button type="button" wire:click="clearFilters" class="rb-clear" data-testid="filter-clear">
                    Clear all
                </button>
            </div>
        </form>

        {{-- Faint shadow that fades in under the docked filter so the
             room grid doesn't visually clip against it. --}}
        <div class="rb-sticky-shadow" aria-hidden="true"></div>

        {{-- ── Results meta ─────────────────────────────────────── --}}
        <div class="rb-results-head">
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <p class="rb-results-count">
                    @if ($categories->count() === 0 && $totalCategoryCount === 0)
                        No rooms <em>yet.</em>
                    @elseif ($categories->count() === 0)
                        No matches <em>for these filters.</em>
                    @else
                        {{ $categories->count() }}
                        {{ $categories->count() === 1 ? 'category' : 'categories' }}
                        @if ($categories->count() < $totalCategoryCount)
                            <em>of {{ $totalCategoryCount }}</em>
                        @endif
                    @endif
                </p>
                <span class="rb-live" data-testid="rb-live-indicator" aria-live="polite">
                    <span class="rb-live-dot" aria-hidden></span>
                    Live availability &middot; synced just now
                </span>
            </div>

            @if ($categories->count() > 1)
                <label class="rb-sort">
                    <span>Sort</span>
                    <select wire:model.live="sort" data-testid="filter-sort">
                        <option value="price_asc">Price · low to high</option>
                        <option value="price_desc">Price · high to low</option>
                        <option value="capacity_desc">Capacity · largest first</option>
                    </select>
                </label>
            @endif
        </div>

        {{-- ── Results grid ─────────────────────────────────────── --}}
        @if ($categories->isEmpty())
            <section class="rb-empty" data-testid="no-results">
                <h2>
                    @if ($totalCategoryCount === 0)
                        No room categories are live yet.
                    @else
                        Nothing matches just yet.
                    @endif
                </h2>
                <p>
                    @if ($totalCategoryCount === 0)
                        {{ $property?->name ?? 'This property' }} hasn&rsquo;t published any rooms.
                        Check back soon or reach out directly.
                    @elseif ($unavailableCount > 0 && $tooSmallCount > 0)
                        {{ $unavailableCount }} {{ $unavailableCount === 1 ? 'category is' : 'categories are' }} fully booked for those dates,
                        and {{ $tooSmallCount }} {{ $tooSmallCount === 1 ? "doesn't fit" : "don't fit" }} {{ $guestCount }} guest{{ $guestCount === 1 ? '' : 's' }}.
                        Try a different date range or fewer guests.
                    @elseif ($unavailableCount > 0)
                        Every category that fits is fully booked for those dates. Try another date range.
                    @elseif ($tooSmallCount > 0)
                        No categories fit {{ $guestCount }} guest{{ $guestCount === 1 ? '' : 's' }}.
                        Try fewer guests or contact the property for larger arrangements.
                    @else
                        We couldn&rsquo;t find a match for your filters.
                    @endif
                </p>
                @if ($totalCategoryCount > 0)
                    <div class="rb-empty-hints">
                        <span class="rb-empty-hint">Try shifting your dates a day or two</span>
                        <span class="rb-empty-hint">Adjust the guest count</span>
                        <span class="rb-empty-hint">Clear filters to see every category</span>
                    </div>
                @endif
            </section>
        @else
            <div class="rb-grid" data-testid="rooms-grid">
                @foreach ($categories as $entry)
                    @php
                        $category = $entry->model;
                        $hero = $entry->hero;
                        $available = $entry->available;
                        $totalActive = $entry->totalActive;
                        $preview = $entry->preview;
                    @endphp
                    <a href="{{ route('tenant.categories.show', $category) }}"
                       class="rb-card"
                       data-testid="category-card-{{ $category->id }}">

                        <div class="rb-card-photo">
                            @if ($hero)
                                <img src="{{ $hero->getUrl('medium') }}"
                                     alt="{{ $hero->getCustomProperty('alt') ?: $category->name }}"
                                     loading="lazy">
                            @endif
                            @if ($available !== null)
                                <span class="rb-card-badge" data-testid="availability-badge-{{ $category->id }}">
                                    {{ $available }} of {{ $totalActive }} available
                                </span>
                            @elseif ($totalActive > 1)
                                <span class="rb-card-badge">
                                    {{ $totalActive }} units
                                </span>
                            @endif
                        </div>

                        <div class="rb-card-body">
                            <span class="rb-card-eyebrow">{{ $category->type }}</span>
                            <h3 class="rb-card-name">{{ $category->name }}</h3>

                            <div class="rb-card-meta">
                                <span>Sleeps {{ $category->maxOccupancy() }}</span>
                                <span class="dot">·</span>
                                <span>{{ $category->capacity_adults }} adult{{ $category->capacity_adults === 1 ? '' : 's' }}</span>
                                @if ($category->capacity_children > 0)
                                    <span class="dot">·</span>
                                    <span>{{ $category->capacity_children }} child{{ $category->capacity_children === 1 ? '' : 'ren' }}</span>
                                @endif
                                <span class="dot">·</span>
                                <span>Min {{ $category->min_nights }} night{{ $category->min_nights === 1 ? '' : 's' }}</span>
                            </div>

                            <div class="rb-card-foot">
                                <span class="rb-card-price">
                                    @if ($preview)
                                        ₦{{ number_format($preview->totalDue) }}
                                        <span class="nights">
                                            for {{ $preview->nights }} night{{ $preview->nights === 1 ? '' : 's' }} &middot; all fees included
                                        </span>
                                    @else
                                        <span class="from">From</span>
                                        ₦{{ number_format($category->base_rate) }}
                                        <span class="per">/ night</span>
                                    @endif
                                </span>
                                <span class="rb-card-cta">View</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ── Sticky-filter behaviour ─────────────────────────────
         Adds .is-stuck to the filter when it reaches the top of
         the viewport (i.e. when the 1px sentinel scrolls out).
         Livewire replaces DOM on updates but our filter node
         survives (has wire:submit but no wire:key remount), so
         one observer attached on load is enough. --}}
    <script>
        (function () {
            var filter   = document.querySelector('[data-sticky-filter]');
            var sentinel = document.querySelector('[data-sticky-sentinel]');
            if (! filter || ! sentinel || ! ('IntersectionObserver' in window)) return;

            new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    filter.classList.toggle('is-stuck', ! entry.isIntersecting);
                });
            }, { threshold: 0 }).observe(sentinel);
        })();
    </script>
</div>
