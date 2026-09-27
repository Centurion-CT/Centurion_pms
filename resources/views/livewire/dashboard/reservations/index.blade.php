<div class="lp-dash">

    {{-- ── Hero: title + primary actions + condensed pulse ────
         Consolidates the old header + button group + 4 KPI tiles
         into one editorial surface. Left side owns identity + the
         primary CTAs; right side is a compact pulse row that reads
         at a glance without stealing vertical space. --}}
    <section class="lp-res-idx-hero" data-testid="reservations-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Operate</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Reservations
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 480px;">
                    Arriving today, arriving tomorrow, and the longest upcoming stays. In-house guests live on their own page.
                </p>

                {{-- Action toolbar — mirrors the detail page's
                     .lp-res-action vocabulary so buttons share one
                     shape across the module. --}}
                <div class="lp-res-idx-hero-actions">
                    <button type="button"
                            x-on:click="$dispatch('reservations-modal-open', { name: 'reserve' })"
                            class="lp-res-action lp-res-action--primary"
                            data-testid="reservations-reserve-btn">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        Reserve room
                    </button>
                    <button type="button"
                            x-on:click="$dispatch('reservations-modal-open', { name: 'in-house' })"
                            class="lp-res-action"
                            data-testid="reservations-in-house-btn">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 13V6l5-3 5 3v7M6 13V9h4v4"/></svg>
                        In-house · {{ $counts['in_house'] }}
                    </button>
                    <button type="button"
                            x-on:click="$dispatch('reservations-modal-open', { name: 'maintenance' })"
                            class="lp-res-action"
                            data-testid="reservations-close-room-btn">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 8l3-3 4 4-3 3zM10 5l3-3 1 1-3 3M6 12l-2 2"/></svg>
                        Close room
                    </button>
                </div>
            </div>

            {{-- Pulse — the four numbers a front-desk operator needs
                 at load. Stack vertically as a compact strip so the
                 hero's Fraunces title still owns the eye. --}}
            @php
                $pulseTiles = [
                    ['label' => 'Today',      'value' => (int) $counts['arrivals_today'],    'dot' => 'var(--color-accent-deep)', 'sub' => $today->format('D, j M')],
                    ['label' => 'Tomorrow',   'value' => (int) $counts['arrivals_tomorrow'], 'dot' => '#4B7BB0',                  'sub' => $tomorrow->format('D, j M')],
                    ['label' => 'This week',  'value' => (int) $counts['arrivals_week'],     'dot' => '#7A6EA7',                  'sub' => 'next 7d'],
                    ['label' => 'Occupancy',  'value' => $counts['occupancy_today'],         'dot' => '#a36318',                  'sub' => $counts['in_house'].' in-house', 'suffix' => '%'],
                ];
            @endphp
            <div class="lp-res-idx-hero-pulse" data-testid="reservations-kpis">
                @foreach ($pulseTiles as $tile)
                    <div class="lp-res-idx-pulse-tile">
                        <span aria-hidden class="lp-res-idx-pulse-dot" style="background: {{ $tile['dot'] }}; box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;"></span>
                        <div style="min-width: 0; flex: 1;">
                            <p class="lp-res-idx-pulse-value">
                                @if ($tile['value'] === null)
                                    <span style="color: var(--color-ink-mute);">-</span>
                                @else
                                    {{ number_format((int) $tile['value']) }}@if (! empty($tile['suffix']))<span class="lp-res-idx-pulse-suffix">{{ $tile['suffix'] }}</span>@endif
                                @endif
                            </p>
                            <p class="lp-res-idx-pulse-label">
                                {{ $tile['label'] }}
                                <span class="lp-res-idx-pulse-sub">{{ $tile['sub'] }}</span>
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Arrivals today ──────────────────────────────────── --}}
    <section id="arrivals-today" class="lp-dash-card" data-testid="reservations-arrivals-today"
             style="scroll-margin-top: 80px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Arriving today</span>
            <span class="lp-dash-card-meta">{{ $today->format('D, j M Y') }} · {{ $arrivalsToday->count() }} {{ Str::plural('booking', $arrivalsToday->count()) }}</span>
        </div>
        @include('livewire.dashboard.reservations._arrivals-table', ['bookings' => $arrivalsToday, 'empty' => 'No arrivals scheduled for today.'])
    </section>

    {{-- ── Arrivals tomorrow ──────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="reservations-arrivals-tomorrow">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Arriving tomorrow</span>
            <span class="lp-dash-card-meta">{{ $tomorrow->format('D, j M Y') }} · {{ $arrivalsTomorrow->count() }} {{ Str::plural('booking', $arrivalsTomorrow->count()) }}</span>
        </div>
        @include('livewire.dashboard.reservations._arrivals-table', ['bookings' => $arrivalsTomorrow, 'empty' => 'Nothing on the books for tomorrow yet.'])
    </section>

    {{-- ── Unassigned bookings ──────────────────────────────
         Category-only reservations sit here until the front desk
         pins a room to them. They're pending revenue + a work item
         for the operator, so calling them out on their own card
         keeps them from disappearing into the general timeline. --}}
    @if ($unassignedBookings->isNotEmpty())
        <section id="unassigned" class="lp-dash-card" data-testid="reservations-unassigned"
                 style="scroll-margin-top: 80px;">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Unassigned</span>
                <span class="lp-dash-card-meta">
                    {{ $unassignedBookings->count() }} category-only reservation{{ $unassignedBookings->count() === 1 ? '' : 's' }} awaiting a room
                </span>
            </div>
            <ul class="lp-arrivals" data-testid="reservations-unassigned-list">
                @foreach ($unassignedBookings as $b)
                    @php
                        $nights = $b->checkin_date && $b->checkout_date
                            ? max(0, (int) $b->checkin_date->diffInDays($b->checkout_date))
                            : 0;
                        $statusVal = $b->booking_status?->value ?? '';
                        [$chipBg, $chipFg] = match ($statusVal) {
                            'confirmed'  => ['#DCFCE7', '#166534'],
                            'on_hold'    => ['#FEF3C7', '#92400E'],
                            'pending'    => ['rgba(20,23,26,0.06)', 'var(--color-ink-soft)'],
                            default      => ['rgba(20,23,26,0.06)', 'var(--color-ink-soft)'],
                        };
                        // Total collected = sum of Succeeded Payment rows.
                        // Refunded rows subtract; Failed / Pending don't count.
                        $paid = (int) $b->payments
                            ->where('status', \App\Enums\PaymentStatus::Succeeded)
                            ->sum('amount');
                        // Discount issued = manual discount (baked in on
                        // the booking row) + any auto-apply discounts
                        // resolved on create.
                        $discountTotal = (int) ($b->manual_discount_amount ?? 0)
                            + (int) $b->appliedDiscounts->sum('amount_off');
                    @endphp
                    <li class="lp-arrivals-item lp-arrivals-item--warn"
                        data-testid="unassigned-row-{{ $b->booking_ref }}"
                        data-row-href="{{ route('tenant.reservations.show', $b->booking_ref) }}"
                        tabindex="0"
                        aria-label="Open {{ $b->booking_ref }} detail">
                        <span class="lp-ledger-marker lp-ledger-marker--warn" aria-hidden></span>
                        <div class="lp-arrivals-body">
                            <div class="lp-arrivals-primary">
                                <span class="lp-arrivals-name">{{ $b->guest_name }}</span>
                                <span class="lp-mono lp-arrivals-ref">{{ $b->booking_ref }}</span>
                            </div>
                            <p class="lp-arrivals-meta">
                                {{ $b->roomCategory?->name ?? 'Category' }}
                                <span class="lp-tag" style="font-size: 9.5px; padding: 1px 6px; margin-left: 4px;
                                                            background: var(--color-surface-2);
                                                            color: var(--color-ink-mute);
                                                            text-transform: uppercase; letter-spacing: 0.05em;">
                                    Unassigned
                                </span>
                                <span style="color: var(--color-ink-mute);">·</span>
                                {{ $b->checkin_date?->format('D, j M') ?? '-' }}
                                <span style="color: var(--color-ink-mute);">→</span>
                                {{ $b->checkout_date?->format('D, j M') ?? '-' }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                {{ $nights }}n
                                @if ($paid > 0)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span data-testid="unassigned-paid-{{ $b->booking_ref }}"
                                          style="color: var(--color-ink);">paid ₦{{ number_format($paid) }}</span>
                                @else
                                    <span data-testid="unassigned-paid-{{ $b->booking_ref }}" style="display: none;"></span>
                                @endif
                                @if ($discountTotal > 0)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span data-testid="unassigned-discount-{{ $b->booking_ref }}"
                                          style="color: var(--color-accent-deep);">−₦{{ number_format($discountTotal) }} off</span>
                                @else
                                    <span data-testid="unassigned-discount-{{ $b->booking_ref }}" style="display: none;"></span>
                                @endif
                            </p>
                        </div>
                        <div class="lp-arrivals-right">
                            <span class="lp-mono lp-arrivals-status"
                                  style="background: {{ $chipBg }}; color: {{ $chipFg }};">
                                {{ $b->booking_status?->label() ?? '-' }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ── Year-view heatmap ───────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="reservations-heatmap">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Year at a glance</span>
            <span class="lp-dash-card-meta">
                {{ $totalActiveRooms }} active rooms · today &rarr; {{ $today->copy()->addDays(365)->format('j M Y') }}
            </span>
        </div>

        @if ($totalActiveRooms === 0)
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                No active rooms yet, nothing to plot.
            </p>
        @else
            <style>
                .lp-heatmap-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
                @media (max-width: 900px) { .lp-heatmap-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
                @media (max-width: 520px) { .lp-heatmap-grid { grid-template-columns: 1fr; } }
                .lp-heatmap-month { background: var(--color-card-deep); border-radius: 10px; padding: 10px; }
                .lp-heatmap-month-title { font-size: 11.5px; font-weight: 600; color: var(--color-ink);
                    letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 6px; }
                .lp-heatmap-week { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
                /* Cell = square tile with a small day number centred
                   inside. Number is white on the coloured tiers, ink
                   on the past/pad neutrals. */
                .lp-heatmap-cell {
                    aspect-ratio: 1; border-radius: 3px;
                    display: flex; align-items: center; justify-content: center;
                    cursor: help; user-select: none;
                    font-size: 10px; font-weight: 600;
                    letter-spacing: -0.01em;
                    color: rgba(255, 255, 255, 0.92);
                    line-height: 1;
                }
                .lp-heatmap-cell--pickable { cursor: crosshair; }
                .lp-heatmap-cell--pickable:hover { outline: 1px solid var(--color-ink); outline-offset: 1px; }
                .lp-heatmap-cell--in-selection { outline: 2px solid var(--color-ink); outline-offset: 1px; }
                .lp-heatmap-cell--pad { background: transparent; cursor: default; color: transparent; }
                /* Solid mid-saturation palette, clear signal at a glance,
                   still comfortable to look at for a whole year of cells. */
                .lp-heatmap-cell--free { background: #10B981; }       /* emerald-500 */
                .lp-heatmap-cell--partial { background: #F59E0B; }    /* amber-500 */
                .lp-heatmap-cell--full { background: #EF4444; }       /* red-500 */
                .lp-heatmap-cell--today { outline: 2px solid var(--color-ink); outline-offset: 1px; }
                /* Past days with no activity: visible for context but
                   inert. Number renders in muted ink so it still reads
                   against the pale wash. */
                .lp-heatmap-cell--past {
                    background: rgba(20, 23, 26, 0.06);
                    cursor: not-allowed;
                    color: rgba(20, 23, 26, 0.35);
                }
                /* Past days that had a reservation (booked / blocked /
                   checked out / cancelled). Clickable → opens the
                   read-only day-detail modal. Slightly warmer wash +
                   pointer cursor to distinguish from inert past days. */
                .lp-heatmap-cell--past-active {
                    background: rgba(20, 23, 26, 0.14);
                    cursor: pointer;
                    color: rgba(20, 23, 26, 0.75);
                }
                .lp-heatmap-cell--past-active:hover { outline: 1px solid var(--color-ink); outline-offset: 1px; }
                .lp-heatmap-dow { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px;
                    font-size: 9.5px; color: var(--color-ink-mute); text-align: center;
                    letter-spacing: 0.04em; text-transform: uppercase; margin-bottom: 3px; }
                .lp-heatmap-legend { display: flex; gap: 12px; align-items: center; margin-top: 10px;
                    font-size: 11.5px; color: var(--color-ink-mute); flex-wrap: wrap; }
                .lp-heatmap-swatch { display: inline-block; width: 12px; height: 12px; border-radius: 2px; vertical-align: middle; margin-right: 4px; }
            </style>

            {{-- Alpine handles the drag-selection state; only cells
                 tagged `pickable` (free / partial tiers) participate.
                 On pointerup we call the Livewire action with the
                 sorted [from, to] pair. --}}
            <div class="lp-heatmap-grid"
                 x-data="{
                     dragging: false, start: null, end: null,
                     onDown(iso) { this.dragging = true; this.start = iso; this.end = iso; },
                     onEnter(iso) { if (this.dragging) this.end = iso; },
                     inRange(iso) {
                         if (!this.dragging || !this.start || !this.end) return false;
                         const lo = this.start < this.end ? this.start : this.end;
                         const hi = this.start > this.end ? this.start : this.end;
                         return iso >= lo && iso <= hi;
                     },
                     commit() {
                         if (!this.dragging || !this.start) { this.reset(); return; }
                         const lo = (this.end && this.end < this.start) ? this.end : this.start;
                         const hi = (this.end && this.end > this.start) ? this.end : this.start;
                         // Alpine opens the modal instantly. Livewire
                         // loads the data (day-detail) or seeds the
                         // form (reserve) in the background, no wait.
                         if (lo === hi) {
                             $dispatch('reservations-modal-open', { name: 'day-detail', date: lo });
                             $wire.loadDayDetail(lo);
                         } else {
                             $dispatch('reservations-modal-open', { name: 'reserve' });
                             $wire.openReserveFromCalendar(lo, hi);
                         }
                         this.reset();
                     },
                     reset() { this.dragging = false; this.start = null; this.end = null; },
                 }"
                 @pointerup.window="commit()"
                 @pointercancel.window="reset()"
                 data-testid="heatmap-grid">
                @foreach ($daysByMonth as $monthKey => $days)
                    @php
                        $firstDay = collect($days)->first()['date'];
                        // Leading blanks so day-of-week columns align (Mon=0).
                        $leadPad = ($firstDay->dayOfWeekIso - 1);
                    @endphp
                    <div class="lp-heatmap-month" data-month="{{ $monthKey }}">
                        <div class="lp-heatmap-month-title">{{ $firstDay->format('F Y') }}</div>
                        <div class="lp-heatmap-dow">
                            <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                        </div>
                        <div class="lp-heatmap-week">
                            @for ($i = 0; $i < $leadPad; $i++)
                                <span class="lp-heatmap-cell lp-heatmap-cell--pad"></span>
                            @endfor
                            @foreach ($days as $iso => $d)
                                @php
                                    $isPast = $d['date']->lt($today);
                                    // Past days had activity if any room was
                                    // booked or blocked on that date. Those are
                                    // clickable → open the day-detail modal in
                                    // read-only mode so operators can review
                                    // check-ins, check-outs, cancellations.
                                    $pastActive = $isPast && $d['booked'] > 0;
                                    // Only future/today free/partial cells
                                    // participate in the drag-to-reserve flow.
                                    $pickable = ! $isPast && $d['tier'] !== 'full';
                                    // For past days the tier class is dropped in
                                    // favour of --past / --past-active so the
                                    // muted styling wins over the tier colour.
                                    $tierClass = match (true) {
                                        $pastActive => 'lp-heatmap-cell--past-active',
                                        $isPast     => 'lp-heatmap-cell--past',
                                        default     => 'lp-heatmap-cell--'.$d['tier'],
                                    };
                                    $tooltipSuffix = match (true) {
                                        $pastActive => ' · click to view',
                                        $isPast     => ' · past, view only',
                                        $pickable   => ' · click / drag to reserve',
                                        default     => '',
                                    };
                                @endphp
                                <span class="lp-heatmap-cell {{ $tierClass }} {{ $iso === $today->toDateString() ? 'lp-heatmap-cell--today' : '' }} {{ $pickable ? 'lp-heatmap-cell--pickable' : '' }}"
                                      :class="inRange('{{ $iso }}') ? 'lp-heatmap-cell--in-selection' : ''"
                                      title="{{ $d['date']->format('D j M Y') }} · {{ $d['booked'] }} / {{ $d['total'] }} rooms · {{ $d['pct'] }}%{{ $tooltipSuffix }}"
                                      data-testid="heatmap-cell-{{ $iso }}"
                                      @if ($pickable)
                                          @pointerdown.prevent="onDown('{{ $iso }}')"
                                          @pointerenter="onEnter('{{ $iso }}')"
                                      @elseif ($pastActive)
                                          @click.prevent="$dispatch('reservations-modal-open', { name: 'day-detail', date: '{{ $iso }}' }); $wire.loadDayDetail('{{ $iso }}')"
                                      @endif
                                      >{{ $d['date']->day }}</span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="lp-heatmap-legend">
                <span><span class="lp-heatmap-swatch" style="background:#10B981;"></span> Free</span>
                <span><span class="lp-heatmap-swatch" style="background:#F59E0B;"></span> Partial (1–79%)</span>
                <span><span class="lp-heatmap-swatch" style="background:#EF4444;"></span> Full / closed (80%+)</span>
                <span style="margin-left: auto; color: var(--color-ink-mute);">Click or drag across free / partial cells to open the reserve sheet with dates pre-filled.</span>
            </div>
        @endif
    </section>

    {{-- ── Arrival detail panel (client-side, shared by both arrivals tables) ── --}}
    @include('livewire.dashboard.reservations._arrival-panel')

    {{-- ── Close for maintenance modal ─────────────────────── --}}
    @include('livewire.dashboard.reservations._modals.maintenance')

    {{-- ── In-house action sheet ───────────────────────────── --}}
    @include('livewire.dashboard.reservations._modals.in-house-sheet')

    {{-- ── In-house guest detail panel (opens ON TOP of the sheet,
             doesn't touch the sheet's own open state) ────────── --}}
    @include('livewire.dashboard.reservations._in-house-sheet-panel')

    {{-- ── Reserve room action sheet ───────────────────────── --}}
    @include('livewire.dashboard.reservations._modals.reserve-sheet')

    {{-- ── "Check in now?" prompt (shown after a same-day reserve) ── --}}
    @include('livewire.dashboard._partials.checkin-prompt')

    {{-- ── Day-detail modal (calendar single-click) ────────── --}}
    @include('livewire.dashboard.reservations._modals.day-detail')

    {{-- Modal shell + sheet CSS. Bottom-sheet on mobile, centered on
         desktop. Same pattern the POS booking-lookup uses. --}}
    <style>
        .lp-sheet-backdrop {
            position: fixed; inset: 0;
            background: rgba(20, 20, 30, 0.55);
            display: flex; align-items: flex-end; justify-content: center;
            z-index: 900;
        }
        .lp-sheet-panel {
            background: var(--color-card); width: 100%; max-width: 560px;
            border-radius: 16px 16px 0 0; padding: 20px 20px 24px;
            max-height: 88vh; overflow-y: auto;
            box-shadow: 0 -20px 60px rgba(0, 0, 0, 0.20);
        }
        .lp-sheet-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px; margin-bottom: 12px;
        }
        .lp-sheet-title {
            font-family: 'Fraunces', ui-serif, Georgia, serif;
            font-size: 18px; font-weight: 600; color: var(--color-ink);
            margin: 0;
        }
        .lp-sheet-close {
            background: none; border: 0; font-size: 24px; line-height: 1;
            color: var(--color-ink-mute); cursor: pointer; padding: 2px 6px;
        }
        @media (min-width: 640px) {
            .lp-sheet-backdrop { align-items: center; padding: 24px; }
            .lp-sheet-panel { border-radius: 16px; }
        }
    </style>

    {{-- ── Longest upcoming ────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="reservations-longest">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Longest upcoming stays</span>
            <span class="lp-dash-card-meta">next 90 days · top {{ $longest->count() }}</span>
        </div>
        @if ($longest->isEmpty())
            @include('livewire.dashboard._partials._empty', [
                'icon'    => 'clipboard',
                'title'   => 'No upcoming reservations to plan for yet.',
                'sub'     => 'Multi-night bookings from the next 90 days show up here.',
                'ctaLabel'=> 'Reserve room',
                'ctaEvent'=> ['reservations-modal-open', ['name' => 'reserve']],
            ])
        @else
            <ul class="lp-arrivals" data-testid="reservations-longest-list">
                @foreach ($longest as $b)
                    @php
                        $nights = $b->checkin_date && $b->checkout_date
                            ? max(0, (int) $b->checkin_date->diffInDays($b->checkout_date))
                            : 0;
                        $value = (int) ($b->total_amount ?? 0);
                    @endphp
                    <a href="{{ route('tenant.reservations.show', $b->booking_ref) }}" wire:navigate
                       data-testid="longest-row-{{ $b->booking_ref }}"
                       class="lp-arrivals-item"
                       style="text-decoration: none;">
                        <span class="lp-ledger-marker lp-ledger-marker--info" aria-hidden></span>
                        <div class="lp-arrivals-body">
                            <div class="lp-arrivals-primary">
                                <span class="lp-arrivals-name">{{ $b->guest_name }}</span>
                                <span class="lp-mono lp-arrivals-ref">{{ $b->booking_ref }}</span>
                            </div>
                            <p class="lp-arrivals-meta">
                                {{ $b->room ? 'Rm '.$b->room->room_number : 'No room' }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                {{ $b->checkin_date?->format('D, j M') ?? '-' }}
                                <span style="color: var(--color-ink-mute);">→</span>
                                {{ $b->checkout_date?->format('D, j M') ?? '-' }}
                                @if ($b->corporate)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ $b->corporate->name }}
                                @endif
                            </p>
                        </div>
                        <div class="lp-arrivals-right" style="flex-direction: column; align-items: flex-end; gap: 2px;">
                            <span class="lp-mono" style="font-size: 13.5px; font-weight: 600; color: var(--color-ink);">
                                {{ $nights }}n
                            </span>
                            @if ($value > 0)
                                <span class="lp-mono" style="font-size: 11px; color: var(--color-ink-soft);">
                                    ₦{{ number_format($value) }}
                                </span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </ul>
        @endif
    </section>
</div>
