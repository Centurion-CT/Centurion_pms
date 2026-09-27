<div class="lp-dash"
     x-data="{
        bucketIds: {{ \Illuminate\Support\Js::from($bucketIds) }},
        panels: {{ \Illuminate\Support\Js::from($panels) }},
        bucket: null,
        search: '',
        init() {
            // Deep-link support - /dashboard/in-house?openBucket=outstanding
            // lands the operator directly on that panel. Also honours a
            // #hash on first paint. Guard against empty buckets so a
            // stale link doesn't open an empty modal.
            const initBucket = {{ \Illuminate\Support\Js::from($openBucketInit) }};
            if (initBucket) this.openBucket(initBucket);
        },
        bucketTitle() {
            return ({
                in_house:    'All in-house guests',
                today:       'Checking out today',
                tomorrow:    'Leaving tomorrow',
                overdue:     'Overdue check-outs',
                outstanding: 'In-house guests with an outstanding balance',
            })[this.bucket] || '';
        },
        openBucket(name) {
            if (!this.bucketIds[name] || this.bucketIds[name].length === 0) return;
            this.bucket = name;
            document.documentElement.style.overflow = 'hidden';
        },
        closeBucket() {
            this.bucket = null;
            document.documentElement.style.overflow = '';
        },
        bucketPanels() {
            if (!this.bucket) return [];
            const ids = this.bucketIds[this.bucket] || [];
            return ids.map(id => this.panels[id]).filter(Boolean);
        },
        applySearch() {
            // Rows are <li data-name=…> on this page, not <tr>. The
            // old `tr[data-name]` selector matched zero elements so
            // typing did nothing at all.
            const q = this.search.trim().toLowerCase();
            const rows = this.$root.querySelectorAll('[data-name]');
            let shown = 0;
            rows.forEach(r => {
                const on = !q || (
                    (r.dataset.name  || '').toLowerCase().includes(q)
                    || (r.dataset.ref   || '').toLowerCase().includes(q)
                    || (r.dataset.phone || '').toLowerCase().includes(q)
                    || (r.dataset.email || '').toLowerCase().includes(q)
                    || (r.dataset.room  || '').toLowerCase().includes(q)
                );
                r.style.display = on ? '' : 'none';
                if (on) shown++;
            });
            const empty = this.$root.querySelector('[data-search-empty]');
            if (empty) empty.style.display = shown === 0 && q ? '' : 'none';
        },
     }">

    <style>
        /* Clickable rows on the in-house table, subtle hover to hint
           the whole row is interactive, since the standalone "Open"
           link was removed in favour of a row-wide click target. */
        .lp-in-house-row:hover { background: var(--color-card-deep) !important; }
        .lp-in-house-row:focus-visible {
            outline: 2px solid var(--color-accent-deep);
            outline-offset: -2px;
        }
        /* Overdue rows in the in-house table carry a soft amber tint
           so they read as attention-worthy at a glance. Matches the
           old lp-ih-item--overdue treatment on the ledger card-stack. */
        .lp-ih-row--overdue > td { background: rgba(168, 101, 10, 0.06); }
        .lp-ih-row--overdue:hover > td { background: rgba(168, 101, 10, 0.11); }
        /* KPI tiles become clickable buttons on this page. Adds a
           faint press cue so the operator learns "these are actions,
           not just labels". */
        .lp-ih-kpi-btn {
            width: 100%; text-align: left; padding: 0;
            background: transparent; border: 0; cursor: pointer;
            font: inherit; color: inherit;
        }
        .lp-ih-kpi-btn > .lp-dash-card { transition: transform 120ms ease, box-shadow 120ms ease; }
        .lp-ih-kpi-btn:hover > .lp-dash-card {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(20, 17, 12, 0.08);
        }
        .lp-ih-kpi-btn:focus-visible > .lp-dash-card {
            outline: 2px solid var(--color-accent-deep);
            outline-offset: 2px;
        }

        /* Timeline strip - horizontal axis for today's departures,
           each guest as a pin. Overdue guests share position 0 with
           a red dot; on-time guests sit at the property's advertised
           check-out time. */
        .lp-ih-timeline {
            position: relative;
            height: 76px;
            margin: 14px 0 4px;
            padding: 20px 12px 12px;
            background: var(--color-paper, #F2EEE6);
            border-radius: 12px;
        }
        .lp-ih-timeline-axis {
            position: absolute;
            left: 12px; right: 12px; bottom: 22px;
            height: 2px;
            background: rgba(20, 17, 12, 0.14);
            border-radius: 999px;
        }
        .lp-ih-timeline-tick {
            position: absolute; bottom: -18px; transform: translateX(-50%);
            font-size: 9.5px; letter-spacing: 0.12em; text-transform: uppercase;
            color: var(--color-ink-mute);
            font-family: 'SF Mono', 'JetBrains Mono', monospace;
        }
        .lp-ih-timeline-checkout {
            position: absolute; top: 0; bottom: 22px;
            width: 1px; background: var(--color-accent-deep);
            opacity: 0.4;
        }
        .lp-ih-timeline-checkout-label {
            position: absolute; top: -2px; transform: translateX(-50%);
            font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase;
            color: var(--color-accent-deep); font-weight: 600;
            white-space: nowrap;
        }
        .lp-ih-timeline-pin {
            position: absolute; bottom: 8px; transform: translateX(-50%);
            width: 34px; height: 34px;
            border-radius: 50%;
            background: var(--color-card);
            border: 2px solid var(--color-accent-deep);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 10.5px; font-weight: 600;
            color: var(--color-ink);
            cursor: pointer;
            transition: transform 120ms ease, z-index 0s;
            z-index: 2;
        }
        .lp-ih-timeline-pin:hover { transform: translateX(-50%) scale(1.15); z-index: 3; }
        .lp-ih-timeline-pin.is-overdue {
            background: #FEE2E2;
            border-color: #B85C42;
            color: #B85C42;
        }
        .lp-ih-timeline-pin-tip {
            position: absolute; bottom: 46px; left: 50%; transform: translateX(-50%);
            padding: 4px 8px; border-radius: 6px;
            background: var(--color-ink); color: #fff;
            font-size: 11px; white-space: nowrap;
            opacity: 0; pointer-events: none;
            transition: opacity 120ms;
            font-weight: 500;
        }
        .lp-ih-timeline-pin:hover .lp-ih-timeline-pin-tip { opacity: 1; }

        /* Slide-in right panel for KPI drill-downs. */
        .lp-ih-drawer-back {
            position: fixed; inset: 0; z-index: 60;
            background: rgba(20, 17, 12, 0.34);
        }
        .lp-ih-drawer {
            position: fixed; top: 0; right: 0; bottom: 0;
            width: min(440px, 92vw);
            background: var(--color-card);
            border-left: 1px solid var(--color-line);
            box-shadow: -20px 0 60px rgba(20, 17, 12, 0.14);
            z-index: 61;
            display: flex; flex-direction: column;
            overflow: hidden;
        }
        .lp-ih-drawer-head {
            padding: 18px 20px; border-bottom: 1px solid var(--color-line-soft);
            display: flex; align-items: baseline; justify-content: space-between; gap: 12px;
        }
        .lp-ih-drawer-body { flex: 1; overflow-y: auto; padding: 8px 0; }
        .lp-ih-drawer-close {
            background: transparent; border: 0; font-size: 20px; line-height: 1;
            color: var(--color-ink-mute); cursor: pointer; padding: 4px 8px;
        }
    </style>

    {{-- All Alpine state lives inline on the root x-data above so
         Livewire re-renders (wire:navigate) don't miss the factory -
         alpine:init only fires once. --}}

    {{-- ── Hero: title + accrued balance ─────────────────── --}}
    <section class="lp-res-idx-hero" data-testid="in-house-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Operate</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    In-house
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                    Every guest currently checked in, sorted by who's leaving next. Overstayed guests bubble to the top.
                </p>
            </div>

            {{-- Total accrued balance sits in the hero's right column
                 so managers see the number the moment they land — it's
                 the highest-signal thing on this page. --}}
            <div class="lp-ih-hero-accrued" data-testid="in-house-accrued">
                <span class="lp-mono" style="font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">
                    Total accrued balance
                </span>
                <p class="lp-ih-hero-accrued-value"
                   style="color: {{ $totalAccruedNaira > 0 ? '#8c1e1e' : 'var(--color-ink)' }};">
                    ₦{{ number_format((int) $totalAccruedNaira) }}
                </p>
                <p class="lp-ih-hero-accrued-sub">
                    Unpaid folio + unsettled extras across all in-house bookings.
                </p>
            </div>
        </div>
    </section>

    {{-- ── Pulse strip ──────────────────────────────────────────
         Same dot-tile pattern as the dashboard / reservations pages
         so the "here's what to know at a glance" surface is uniform
         across the app. Overdue tile flips to red when there are
         any overstay guests. --}}
    @php
        $overdueCount = (int) $counts['overdue'];
        $ihPulseTiles = [
            ['label' => 'In-house',          'value' => (int) $counts['in_house'],           'dot' => 'var(--color-accent-deep)', 'sub' => 'currently checked in'],
            ['label' => 'Checkouts',         'value' => (int) $counts['checkouts_today'],    'dot' => '#4B7BB0',                  'sub' => $today->format('D, j M')],
            ['label' => 'Tomorrow',          'value' => (int) $counts['checkouts_tomorrow'], 'dot' => '#7A6EA7',                  'sub' => 'plan handovers'],
            ['label' => 'Overdue',           'value' => $overdueCount,                        'dot' => $overdueCount > 0 ? '#B85C42' : 'var(--color-accent-deep)', 'sub' => 'past booked checkout', 'color' => $overdueCount > 0 ? '#B85C42' : 'var(--color-ink)'],
        ];
    @endphp
    @php
        $ihBucketKeys = ['in_house', 'today', 'tomorrow', 'overdue'];
    @endphp
    <div class="grid gap-2 grid-cols-2 lg:grid-cols-4" data-testid="in-house-kpis">
        @foreach ($ihPulseTiles as $i => $tile)
            @php $bucketKey = $ihBucketKeys[$i] ?? null; @endphp
            <button type="button" class="lp-ih-kpi-btn"
                    x-on:click="openBucket('{{ $bucketKey }}')"
                    data-testid="in-house-kpi-{{ $bucketKey }}"
                    aria-label="Open {{ $tile['label'] }} guests">
                <div class="lp-dash-card"
                     style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                    <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                              background: {{ $tile['dot'] }};
                                              box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                              flex-shrink: 0;"></span>
                    <div style="min-width: 0; flex: 1;">
                        <p class="lp-num-lg text-[22px]" style="color: {{ $tile['color'] ?? 'var(--color-ink)' }};">
                            {{ number_format($tile['value']) }}
                        </p>
                        <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                           style="color: var(--color-ink-mute); margin-top: 2px;">
                            {{ $tile['label'] }}
                            <span style="text-transform: none; letter-spacing: 0;
                                         color: var(--color-ink-mute);
                                         font-size: 11px; margin-left: 4px;">
                                {{ $tile['sub'] }}
                            </span>
                        </p>
                    </div>
                </div>
            </button>
        @endforeach
    </div>

    {{-- ── Timeline strip - today's check-outs as pins ──────────
         Horizontal axis 06:00 → 22:00. Overdue guests bunch to the
         left of the property's advertised check-out time; today's
         scheduled check-outs pin to that line. Hover a pin for a
         guest label + room number. --}}
    @php
        $tlStartMin = 6 * 60;    // 06:00
        $tlEndMin = 22 * 60;     // 22:00
        $tlSpanMin = $tlEndMin - $tlStartMin;
        [$coHour, $coMin] = array_map('intval', explode(':', $checkoutTimeStr));
        $checkoutMinuteOfDay = $coHour * 60 + $coMin;
        $checkoutPct = max(0, min(100, (($checkoutMinuteOfDay - $tlStartMin) / $tlSpanMin) * 100));
        // Cluster pins that share the same minute by nudging them
        // vertically so they don't overlap. Simple x-position stack.
        $tlPositioned = [];
        foreach ($timelinePins as $pin) {
            $mins = max($tlStartMin, min($tlEndMin, (int) $pin['minute']));
            $pct = round((($mins - $tlStartMin) / $tlSpanMin) * 100, 2);
            $tlPositioned[] = $pin + ['pct' => $pct];
        }
    @endphp
    @if (! empty($timelinePins))
        <section class="lp-dash-card" data-testid="in-house-timeline" style="padding: 14px 18px;">
            <div class="flex items-baseline justify-between mb-1" style="gap: 10px;">
                <div>
                    <span class="lp-dash-card-title">Today's departures</span>
                    <p class="text-[11.5px] mt-0.5" style="color: var(--color-ink-mute);">
                        Pinned at your advertised check-out time ({{ $checkoutTimeStr }}).
                        Overdue guests sit at "now" so they don't get missed.
                    </p>
                </div>
                <span class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                      style="color: var(--color-ink-mute);">
                    {{ count($timelinePins) }} on the clock
                </span>
            </div>
            <div class="lp-ih-timeline">
                <div class="lp-ih-timeline-axis"></div>
                {{-- Ticks: 6am, noon, 6pm, 10pm --}}
                @foreach ([[6,'6a'], [12,'noon'], [18,'6p'], [22,'10p']] as [$h, $label])
                    @php $tickPct = round((($h * 60 - $tlStartMin) / $tlSpanMin) * 100, 2); @endphp
                    <span class="lp-ih-timeline-tick" style="left: calc(12px + {{ $tickPct }}%);">
                        {{ $label }}
                    </span>
                @endforeach
                {{-- Scheduled check-out line --}}
                <span class="lp-ih-timeline-checkout" style="left: calc(12px + {{ $checkoutPct }}%);"></span>
                <span class="lp-ih-timeline-checkout-label" style="left: calc(12px + {{ $checkoutPct }}%);">
                    check-out
                </span>
                {{-- Pins --}}
                @foreach ($tlPositioned as $pin)
                    <button type="button"
                            class="lp-ih-timeline-pin @if ($pin['is_overdue']) is-overdue @endif"
                            data-testid="tl-pin-{{ $pin['ref'] }}"
                            style="left: calc(12px + {{ $pin['pct'] }}%);"
                            x-on:click="bucket = '{{ $pin['is_overdue'] ? 'overdue' : 'today' }}'; document.documentElement.style.overflow = 'hidden';"
                            aria-label="{{ $pin['guest'] }} · {{ $pin['room'] }}{{ $pin['is_overdue'] ? ' (overdue)' : '' }}">
                        {{ mb_substr($pin['guest'], 0, 2) }}
                        <span class="lp-ih-timeline-pin-tip">
                            {{ $pin['guest'] }} · {{ $pin['room'] }}
                            @if ($pin['outstanding'] > 0)
                                · owes ₦{{ number_format($pin['outstanding']) }}
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Total accrued balance moved to the hero's right column. --}}

    {{-- ── In-house list ─────────────────────────────────── --}}
    <section class="lp-dash-card" style="padding: 14px 16px;">
        <div class="lp-dash-card-head" style="flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
            <span class="lp-dash-card-title">Guests in-house</span>
            <div class="flex items-center gap-3 flex-1" style="justify-content: flex-end;">
                <div style="position: relative; max-width: 320px; width: 100%;">
                    <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
                                width: 14px; height: 14px; color: var(--color-ink-mute);"
                         viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="7" cy="7" r="4.5"/><path d="M13.5 13.5L10.5 10.5"/>
                    </svg>
                    <input type="search"
                           x-model="search"
                           x-on:input="applySearch()"
                           x-init="applySearch()"
                           placeholder="Search name, ref, phone, email, room #"
                           class="lp-input"
                           style="padding-left: 32px;"
                           data-testid="in-house-search"
                           aria-label="Search in-house guests">
                </div>
                <span class="lp-dash-card-meta" data-testid="in-house-count">
                    {{ $inHouse->count() }} {{ Str::plural('guest', $inHouse->count()) }}
                </span>
            </div>
        </div>

        @if ($inHouse->isEmpty())
            <div data-testid="in-house-empty">
                @include('livewire.dashboard._partials._empty', [
                    'icon'  => 'sparkle',
                    'title' => 'No one is checked in right now.',
                    'sub'   => "Guests appear here the moment front desk marks them as checked in.",
                ])
            </div>
        @else
            <p class="text-[13px]" data-search-empty
               style="display: none; padding: 14px 0; color: var(--color-ink-mute);"
               data-testid="in-house-search-empty">
                Nothing matches that search.
            </p>
            {{-- Dense table replaces the old ledger card-stack — much
                 easier to scan at a busy front desk with 40+ in-house
                 guests. Row-click still dispatches the panel-open event
                 so the detail slide-out fires exactly as before. --}}
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="in-house-table">
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Room</th>
                            <th>Stay</th>
                            <th style="text-align: right;">Nights</th>
                            <th style="text-align: right;">Rate/n</th>
                            <th style="text-align: right;">Accrued</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($inHouse as $b)
                        @php
                            $today = \Illuminate\Support\Carbon::today();
                            $nightsSoFar = $b->checkin_date
                                ? max(0, (int) $b->checkin_date->diffInDays($today, false))
                                : 0;
                            $nightsLeft = $b->checkout_date
                                ? (int) $today->diffInDays($b->checkout_date, false)
                                : null;
                            $isOverdue = $b->checkout_date && $b->checkout_date->lt($today);
                            $accrued = $b->outstandingTotal();

                            $paidDailyRate = $b->room?->category
                                ? $b->room->category->effectiveNightlyRate((bool) $b->breakfast_included)
                                : (int) ($b->room?->base_rate ?? 0);
                            $stickerRate = (int) ($b->room?->base_rate ?? 0);
                            $isDiscounted = $stickerRate > 0 && $paidDailyRate < $stickerRate;
                        @endphp
                        <tr class="lp-ih-row @if ($isOverdue) lp-ih-row--overdue @endif"
                            data-testid="in-house-row-{{ $b->booking_ref }}"
                            data-row-href
                            role="button"
                            tabindex="0"
                            aria-label="Open {{ $b->booking_ref }} detail"
                            data-name="{{ $b->guest_name }}"
                            data-ref="{{ $b->booking_ref }}"
                            data-phone="{{ $b->guest_phone }}"
                            data-email="{{ $b->guest_email }}"
                            data-room="{{ $b->room?->room_number }}"
                            x-data
                            x-on:click="$event.target.closest('a,button') || $dispatch('in-house-panel-open', { id: {{ $b->id }} })"
                            x-on:keydown.enter.prevent="$dispatch('in-house-panel-open', { id: {{ $b->id }} })"
                            x-on:keydown.space.prevent="$dispatch('in-house-panel-open', { id: {{ $b->id }} })">
                            <td>
                                <div style="min-width: 0;">
                                    <p style="font-weight: 500; color: var(--color-ink); margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        {{ $b->guest_name }}
                                    </p>
                                    <p class="lp-mono" style="margin: 2px 0 0; font-size: 10.5px; color: var(--color-ink-mute); letter-spacing: 0.02em;">
                                        {{ $b->booking_ref }}
                                        @if ($b->corporate)
                                            <span style="color: var(--color-accent-deep); text-transform: uppercase; letter-spacing: 0.06em;">
                                                · CORP · {{ $b->corporate->name }}
                                            </span>
                                        @endif
                                    </p>
                                </div>
                            </td>
                            <td>
                                <div style="min-width: 0;">
                                    <p style="font-weight: 500; color: var(--color-ink); margin: 0;">
                                        @if ($b->room?->room_number)
                                            Rm {{ $b->room->room_number }}
                                        @else
                                            <span style="color: var(--color-ink-mute);">-</span>
                                        @endif
                                    </p>
                                    @if ($b->room?->category)
                                        <p style="margin: 2px 0 0; font-size: 11px; color: var(--color-ink-soft);">
                                            {{ $b->room->category->name }}
                                        </p>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div style="min-width: 0;">
                                    <p style="margin: 0; font-size: 12.5px; color: var(--color-ink);">
                                        {{ $b->checkin_date?->format('j M') ?? '-' }}
                                        <span style="color: var(--color-ink-mute);">→</span>
                                        <span style="color: {{ $isOverdue ? '#8c1e1e' : 'var(--color-ink)' }}; font-weight: {{ $isOverdue ? '600' : '400' }};">
                                            {{ $b->checkout_date?->format('j M') ?? '-' }}
                                        </span>
                                    </p>
                                    @if ($isOverdue)
                                        <span class="lp-tag lp-tag--cancelled" style="font-size: 9.5px; padding: 1px 6px; margin-top: 3px; display: inline-block;">Overdue</span>
                                    @endif
                                </div>
                            </td>
                            <td class="lp-num" style="text-align: right;">
                                <p style="margin: 0; font-size: 12.5px;">
                                    {{ $nightsSoFar }}n so far
                                </p>
                                @if ($nightsLeft !== null)
                                    <p style="margin: 2px 0 0; font-size: 11px; color: {{ $nightsLeft < 0 ? '#8c1e1e' : 'var(--color-ink-soft)' }};">
                                        {{ $nightsLeft }}n left
                                    </p>
                                @endif
                            </td>
                            <td class="lp-num" style="text-align: right;">
                                <p style="margin: 0; font-size: 12.5px;" data-testid="in-house-daily-rate">
                                    ₦{{ number_format($paidDailyRate) }}
                                </p>
                                @if ($isDiscounted)
                                    <p class="lp-mono" title="Sticker ₦{{ number_format($stickerRate) }}/night"
                                       style="margin: 2px 0 0; color: var(--color-accent-deep); font-size: 10.5px;">
                                        was ₦{{ number_format($stickerRate) }}
                                    </p>
                                @endif
                            </td>
                            <td class="lp-num" style="text-align: right; font-weight: 600;">
                                @if ($accrued > 0)
                                    <span style="color: #8c1e1e;">
                                        ₦{{ number_format((int) $accrued) }}
                                    </span>
                                @else
                                    <span style="color: var(--color-ink-mute); font-weight: 400;">clean</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Client-side detail panel - payload pre-hydrated in
         InHouse\Index::render so opening is instant, no wire trip. --}}
    @include('livewire.dashboard.in-house._panel', ['panels' => $panels])

    {{-- ── KPI drawer: opens when a stat card is clicked. Reads the
         pre-hydrated `panels` map keyed by booking id to render a
         list of the guests in that bucket. Rows link out to the full
         reservation detail - the row-panel above is for the whole
         table's rich detail, this drawer is a lean scannable view. --}}
    <template x-teleport="body">
        <div x-show="bucket !== null" x-cloak
             x-transition.opacity.duration.150ms>
            <div class="lp-ih-drawer-back"
                 x-on:click="closeBucket()"
                 data-testid="in-house-drawer-back"></div>
            <div class="lp-ih-drawer"
                 x-on:keydown.escape.window="closeBucket()"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="translate-x-full opacity-0"
                 x-transition:enter-end="translate-x-0 opacity-100"
                 role="dialog" aria-modal="true"
                 data-testid="in-house-drawer">
                <div class="lp-ih-drawer-head">
                    <div>
                        <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                           style="color: var(--color-ink-mute);">
                            Bucket
                        </p>
                        <h2 style="font-size: 18px; font-weight: 600; margin-top: 2px;"
                            x-text="bucketTitle()"></h2>
                    </div>
                    <button type="button" class="lp-ih-drawer-close"
                            x-on:click="closeBucket()"
                            data-testid="in-house-drawer-close"
                            aria-label="Close">&times;</button>
                </div>
                <div class="lp-ih-drawer-body">
                    <template x-for="p in bucketPanels()" :key="p.id">
                        <a :href="p.url"
                           style="display: block; padding: 12px 20px;
                                  border-bottom: 1px solid var(--color-line-soft);
                                  text-decoration: none; color: inherit;">
                            <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 12px;">
                                <div style="min-width: 0; flex: 1;">
                                    <p style="font-size: 14px; font-weight: 500; color: var(--color-ink);"
                                       x-text="p.guest_name"></p>
                                    <p class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-top: 2px;">
                                        <span x-text="p.ref"></span>
                                        <span> · </span>
                                        <span x-text="p.room"></span>
                                        <span> · leaves </span>
                                        <span x-text="p.checkout"></span>
                                    </p>
                                </div>
                                <div style="text-align: right; white-space: nowrap;">
                                    <template x-if="p.outstanding > 0">
                                        <span class="lp-mono text-[12px]"
                                              style="color: #B85C42; font-weight: 600;">
                                            ₦<span x-text="p.outstanding.toLocaleString()"></span>
                                        </span>
                                    </template>
                                    <template x-if="p.is_overdue">
                                        <span class="lp-mono text-[10px]"
                                              style="display: block; margin-top: 2px; padding: 1px 6px;
                                                     border-radius: 999px; letter-spacing: 0.06em;
                                                     text-transform: uppercase; font-weight: 600;
                                                     background: #FEE2E2; color: #B85C42;">
                                            overdue
                                        </span>
                                    </template>
                                </div>
                            </div>
                        </a>
                    </template>
                    <template x-if="bucketPanels().length === 0">
                        <p style="padding: 20px; color: var(--color-ink-mute); font-size: 13.5px; text-align: center;"
                           data-testid="in-house-drawer-empty">
                            Nobody in this bucket right now.
                        </p>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- Shared extend-stay modal (HasExtendStayModal trait). Same
         rate preview + discount + inline-payment as the reservation
         detail + dashboard home + calendar sheet. --}}
    @include('livewire.dashboard._partials.extend-stay-modal')

    {{-- ── Change-room inline modal ────────────────────────────
         Opens when the operator clicks Change room on the in-house
         panel. Same cross-category + CheckedIn candidate rules as
         the reservation-show + calendar sheet flow. --}}
    @if ($changeRoomOpen && $changeRoomBooking !== null)
        <div wire:click="closeChangeRoom"
             data-testid="change-room-backdrop"
             class="lp-modal-backdrop"
             style="position: fixed; inset: 0; z-index: 200;
                    background: rgba(20, 22, 24, 0.42);
                    backdrop-filter: blur(2px);
                    display: flex; align-items: center; justify-content: center;
                    padding: 16px;">
            <div @click.stop
                 data-testid="change-room-card"
                 style="width: 100%; max-width: 480px;
                        background: var(--color-card);
                        border-radius: 16px;
                        border: 1px solid var(--color-line-soft);
                        box-shadow: 0 24px 60px rgba(0,0,0,0.22);
                        padding: 22px 24px 20px;">
                <p class="lp-mono text-[10.5px]"
                   style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;">
                    Change room
                </p>
                <h2 class="lp-display" style="font-size: 19px; font-weight: 600; margin: 4px 0 12px;">
                    Move {{ $changeRoomBooking->guest_name }}
                </h2>
                <p class="text-[12.5px]" style="color: var(--color-ink-soft); margin-bottom: 14px;">
                    Currently in Rm {{ $changeRoomBooking->room?->room_number ?? '-' }}
                    ({{ $changeRoomBooking->room?->category?->name ?? 'Uncategorised' }}).
                    Pro-rata: nights already spent stay at the current rate; remaining nights swap to the new rate.
                </p>

                @if ($changeRoomCandidates->isEmpty())
                    <p class="text-[12.5px]" data-testid="change-room-empty"
                       style="padding: 14px; background: var(--color-surface-2); border-radius: 8px;
                              color: var(--color-ink-mute); text-align: center;">
                        No other rooms available for the remaining nights.
                    </p>
                @else
                    <label class="lp-form-label" for="in-house-change-room-select">Move to</label>
                    {{-- wire:model.live so the picker's value syncs
                         to the server on every change - otherwise
                         the Move-guest button's server-side disabled
                         check reads a stale empty value and stays
                         greyed out even though a room is picked.
                         wire:loading.attr disables the picker while
                         the rate-delta preview is fetching so the
                         operator sees an obvious "we're working" cue
                         instead of a dead-feeling dropdown. --}}
                    <div style="position: relative;">
                        <select id="in-house-change-room-select"
                                wire:model.live="changeRoomTargetRoomId"
                                wire:loading.attr="disabled"
                                wire:target="changeRoomTargetRoomId"
                                data-testid="change-room-target"
                                class="lp-input" style="font-size: 13px;">
                            <option value="">- pick a room -</option>
                            @foreach ($changeRoomCandidates->groupBy(fn ($r) => $r->category?->name ?? 'Uncategorised') as $categoryName => $rooms)
                                <optgroup label="{{ $categoryName }} · ₦{{ number_format((int) $rooms->first()->base_rate) }}/night">
                                    @foreach ($rooms as $r)
                                        <option value="{{ $r->id }}">Room {{ $r->room_number ?? $r->id }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <span wire:loading wire:target="changeRoomTargetRoomId"
                              aria-hidden="true"
                              style="position: absolute; right: 30px; top: 50%; transform: translateY(-50%);
                                     width: 12px; height: 12px;
                                     border: 2px solid rgba(20, 22, 24, 0.18);
                                     border-top-color: var(--color-ink);
                                     border-radius: 999px;
                                     animation: kv-spin 720ms linear infinite;
                                     pointer-events: none;"></span>
                    </div>

                    {{-- Rate delta preview - shows the operator what
                         the guest will be charged (or credited) BEFORE
                         they hit Move guest. Pro-rata math matches
                         ReassignRoom on submit: nights already spent
                         stay at the current rate, only the remaining
                         nights swap. --}}
                    @if ($changeRoomPreview !== null)
                        @php
                            $delta = (int) $changeRoomPreview['delta'];
                            $isUp = $delta > 0;
                            $isDown = $delta < 0;
                            $bg = $isUp ? '#FEF3C7' : ($isDown ? '#DCFCE7' : 'var(--color-surface-2)');
                            $ink = $isUp ? '#92400E' : ($isDown ? '#166534' : 'var(--color-ink-soft)');
                        @endphp
                        <div data-testid="change-room-preview"
                             style="margin-top: 10px; padding: 10px 12px;
                                    background: {{ $bg }}; border-radius: 8px;
                                    font-size: 12.5px; line-height: 1.5; color: {{ $ink }};">
                            <div style="font-weight: 600;">
                                Room {{ $changeRoomPreview['target_room_number'] }}
                                @if ($changeRoomPreview['target_category'])
                                    <span style="font-weight: 400; opacity: 0.7;">
                                        · {{ $changeRoomPreview['target_category'] }}
                                    </span>
                                @endif
                            </div>
                            <div style="margin-top: 4px;">
                                ₦{{ number_format($changeRoomPreview['old_rate']) }}/night
                                <span style="opacity: 0.6;">→</span>
                                ₦{{ number_format($changeRoomPreview['new_rate']) }}/night
                                <span style="opacity: 0.6;">×</span>
                                {{ $changeRoomPreview['remaining_nights'] }} remaining night{{ $changeRoomPreview['remaining_nights'] === 1 ? '' : 's' }}
                            </div>
                            <div style="margin-top: 6px; font-weight: 600;">
                                @if ($isUp)
                                    +₦{{ number_format($delta) }} to collect from the guest
                                @elseif ($isDown)
                                    -₦{{ number_format(abs($delta)) }} owed back to the guest
                                @else
                                    No rate change.
                                @endif
                            </div>
                        </div>
                    @endif
                @endif

                <div class="flex justify-end gap-2 mt-4 pt-3"
                     style="border-top: 1px solid var(--color-line-soft);">
                    <button type="button" wire:click="closeChangeRoom"
                            data-testid="change-room-cancel"
                            class="lp-btn" style="font-size: 12px;">Cancel</button>
                    <button type="button" wire:click="submitChangeRoom"
                            data-testid="change-room-submit"
                            wire:confirm="Move {{ $changeRoomBooking->guest_name }} to the selected room?"
                            @disabled($changeRoomTargetRoomId === null || $changeRoomTargetRoomId === '')
                            class="lp-btn lp-btn--primary" style="font-size: 12px;">
                        <span wire:loading.remove wire:target="submitChangeRoom">Move guest</span>
                        <span wire:loading wire:target="submitChangeRoom">Moving...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Add-charge inline modal ─────────────────────────────
         Opens when the operator clicks Add charge on the in-house
         panel. Mirrors the dashboard-home header AddCharge form. --}}
    @if ($addChargeOpen && $chargeBooking !== null)
        <div wire:click="closeAddCharge"
             data-testid="add-charge-backdrop"
             class="lp-modal-backdrop"
             style="position: fixed; inset: 0; z-index: 200;
                    background: rgba(20, 22, 24, 0.42);
                    backdrop-filter: blur(2px);
                    display: flex; align-items: center; justify-content: center;
                    padding: 16px;">
            <div @click.stop
                 data-testid="add-charge-card"
                 style="width: 100%; max-width: 460px;
                        background: var(--color-card);
                        border-radius: 16px;
                        border: 1px solid var(--color-line-soft);
                        box-shadow: 0 24px 60px rgba(0,0,0,0.22);
                        padding: 22px 24px 20px;">
                <p class="lp-mono text-[10.5px]"
                   style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;">
                    Add charge
                </p>
                <h2 class="lp-display" style="font-size: 19px; font-weight: 600; margin: 4px 0 4px;">
                    Charge {{ $chargeBooking->guest_name }}
                </h2>
                <p class="lp-mono text-[11.5px]" style="color: var(--color-ink-mute); margin-bottom: 14px;">
                    Rm {{ $chargeBooking->room?->room_number ?? '-' }}
                    <span style="margin: 0 4px;">·</span>
                    {{ $chargeBooking->booking_ref }}
                </p>

                <div style="display: grid; gap: 10px;">
                    <div>
                        <label class="lp-form-label" for="in-house-charge-type">Type</label>
                        <select id="in-house-charge-type" wire:model="addChargeType"
                                data-testid="add-charge-type"
                                class="lp-input" style="font-size: 13px;">
                            @foreach ($chargeTypeOptions as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="lp-form-label" for="in-house-charge-amount">Amount</label>
                        <div style="position: relative;">
                            <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
                                         font-size: 12px; color: var(--color-ink-mute); pointer-events: none;">&#8358;</span>
                            <input id="in-house-charge-amount" type="number" min="1" step="1"
                                   wire:model="addChargeAmount"
                                   data-testid="add-charge-amount"
                                   class="lp-input" style="padding-left: 22px; font-size: 13px;">
                        </div>
                    </div>
                    <div>
                        <label class="lp-form-label" for="in-house-charge-desc">
                            Description
                            <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                        </label>
                        <input id="in-house-charge-desc" type="text" maxlength="240"
                               wire:model="addChargeDescription"
                               data-testid="add-charge-description"
                               placeholder="e.g. 2x dinner + drinks"
                               class="lp-input" style="font-size: 13px;">
                    </div>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" wire:model="addChargeWaive"
                               data-testid="add-charge-waive"
                               style="accent-color: var(--color-accent-deep);">
                        <span style="font-size: 12.5px; color: var(--color-ink);">
                            Waive this charge
                            <span style="color: var(--color-ink-mute); font-weight: 400;">
                                · records on the folio, flags as waived
                            </span>
                        </span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 mt-4 pt-3"
                     style="border-top: 1px solid var(--color-line-soft);">
                    <button type="button" wire:click="closeAddCharge"
                            data-testid="add-charge-cancel"
                            class="lp-btn" style="font-size: 12px;">Cancel</button>
                    <button type="button" wire:click="submitAddCharge"
                            data-testid="add-charge-submit"
                            @disabled(($addChargeAmount ?? 0) <= 0)
                            class="lp-btn lp-btn--primary" style="font-size: 12px;">
                        <span wire:loading.remove wire:target="submitAddCharge">
                            {{ $addChargeWaive ? 'Add & waive' : 'Add charge' }}
                        </span>
                        <span wire:loading wire:target="submitAddCharge">Recording...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
