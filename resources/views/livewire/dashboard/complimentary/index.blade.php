<div class="lp-dash" data-testid="complimentary-page">

    {{-- ── Hero: title + served / today count ─────────────
         Identity block on the left; right side is a Fraunces
         "N of M served" pulse so the kitchen sees at a glance
         how much of today's breakfast is still to plate. --}}
    @php
        $todayCovers = (int) $ambientCounts['today'];
    @endphp
    <section class="lp-res-idx-hero" data-testid="complimentary-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Operate · Kitchen</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Complimentary breakfast
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Guests entitled to breakfast for
                    <strong style="color: var(--color-ink);">{{ $day->format('D, j M Y') }}</strong>.
                    Kitchen prep by 7am; front desk uses room-number search at the dining room door.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <button type="button" wire:click="goToToday"
                            data-testid="complimentary-today"
                            class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><circle cx="8" cy="8" r="5.5"/><circle cx="8" cy="8" r="1.5" fill="currentColor" stroke="none"/></svg>
                        Today
                    </button>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Served today">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Served</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ $servedCount === $covers->count() && $covers->count() > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink)' }};"
                       data-testid="complimentary-served-total">
                        {{ number_format($servedCount) }}
                        <span style="font-size: 0.55em; color: var(--color-ink-mute); font-weight: 500;">
                            / {{ number_format($covers->count()) }}
                        </span>
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        @if ($covers->count() > 0)
                            {{ $covers->count() - $servedCount }} still to plate
                        @else
                            No covers on this day
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Pulse strip, kitchen prep signals ────────────────────
         Dot-tile pattern matching the dashboard / reservations pages
         so the kitchen team scans this the same way front-desk scans
         theirs. --}}
    @php
        $compTiles = [
            ['label' => 'Today',      'value' => (int) $ambientCounts['today'],    'dot' => 'var(--color-accent-deep)', 'sub' => 'covers to serve now'],
            ['label' => 'Tomorrow',   'value' => (int) $ambientCounts['tomorrow'], 'dot' => '#4B7BB0',                  'sub' => 'plan the pull'],
            ['label' => 'Next 7 days','value' => (int) $ambientCounts['week'],     'dot' => '#7A6EA7',                  'sub' => 'total across the week'],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-1 sm:grid-cols-3" data-testid="complimentary-kpis">
        @foreach ($compTiles as $tile)
            <div class="lp-dash-card"
                 style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: var(--color-ink);">
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
        @endforeach
    </div>

    {{-- ── Filter strip ─────────────────────────────────── --}}
    <section class="lp-dash-card lp-cal-search" style="padding: 12px 14px;">
        <div class="lp-cal-search-row">
            <div class="lp-cal-search-field lp-cal-search-field--room">
                <label for="c-room" class="lp-rate-sub-label">Room #</label>
                <input id="c-room" type="text"
                       wire:model.live.debounce.300ms="roomSearch"
                       placeholder="e.g. 306"
                       data-testid="complimentary-room-search"
                       class="lp-input" style="height: 36px;">
            </div>

            <div class="lp-cal-search-field">
                <label for="c-day" class="lp-rate-sub-label">Day</label>
                <input id="c-day" type="date"
                       wire:model.live="date"
                       data-testid="complimentary-date"
                       class="lp-input" style="height: 36px;">
            </div>

            @if (trim($roomSearch) !== '')
                <button type="button"
                        wire:click="clearRoomSearch"
                        data-testid="complimentary-clear"
                        class="lp-res-action lp-res-action--ghost"
                        style="height: 36px; padding: 0 14px; font-size: 12px;">
                    Clear search
                </button>
            @endif
        </div>
    </section>

    {{-- ── Roster ───────────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">
                {{ $covers->count() }} {{ Str::plural('cover', $covers->count()) }}
                &middot; {{ $day->format('D, j M') }}
                @if ($covers->count() > 0)
                    <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); font-weight: 400; margin-left: 6px;">
                        · {{ $servedCount }} of {{ $covers->count() }} served
                    </span>
                @endif
            </span>
            <span class="lp-dash-card-meta">
                Tick the box as each guest is served. Click the row body to open the reservation.
            </span>
        </div>

        @if ($covers->isEmpty())
            <div data-testid="complimentary-empty">
                @include('livewire.dashboard._partials._empty', [
                    'icon'    => 'sparkle',
                    'title'   => trim($roomSearch) !== ''
                                    ? 'No breakfast covers match "'.$roomSearch.'" on this day.'
                                    : 'No complimentary breakfasts scheduled for this day.',
                    'sub'     => trim($roomSearch) !== ''
                                    ? 'Try clearing the room search.'
                                    : 'Covers appear here as in-house guests with breakfast on their booking.',
                    'ctaLabel'    => trim($roomSearch) !== '' ? 'Clear search' : null,
                    'ctaClickJs'  => trim($roomSearch) !== '' ? "\$wire.clearRoomSearch()" : null,
                ])
            </div>
        @else
            <style>
                .lp-comp-row { cursor: pointer; }
                .lp-comp-row:hover { background: var(--color-card-deep); }
                .lp-comp-row:focus-visible {
                    outline: 2px solid var(--color-accent-deep);
                    outline-offset: -2px;
                }
                /* Served rows dim + strike, so the kitchen's eye finds
                   the still-to-serve rows first. */
                .lp-comp-row.is-served { opacity: 0.55; }
                .lp-comp-row.is-served td:not(.lp-comp-toggle-cell) {
                    text-decoration: line-through;
                }
                .lp-comp-toggle-cell { cursor: default; }
                .lp-comp-toggle {
                    width: 20px; height: 20px; cursor: pointer;
                    accent-color: var(--color-accent-deep);
                }
            </style>
            <div class="overflow-x-auto">
                @php
                    $pageIds = $covers->pluck('id')->all();
                    $pageState = $this->selectionStateFor($pageIds);
                @endphp
                <table class="lp-table" data-testid="complimentary-table">
                    <thead>
                        <tr>
                            <th class="lp-bulk-col">
                                <input type="checkbox"
                                       class="lp-bulk-checkbox"
                                       data-testid="comp-bulk-toggle-all"
                                       aria-label="{{ $pageState['all'] ? 'Deselect all covers' : 'Select all covers' }}"
                                       @checked($pageState['all'])
                                       x-init="$el.indeterminate = @js($pageState['some'])"
                                       wire:click.stop="{{ $pageState['all']
                                            ? 'deselectPage('.json_encode($pageIds).')'
                                            : 'selectAll('.json_encode($pageIds).')' }}">
                            </th>
                            <th style="width: 40px; text-align: center;">Served</th>
                            <th>Room</th>
                            <th>Guest</th>
                            <th>Category</th>
                            <th>Stay</th>
                            <th class="lp-mono" style="text-align: right;">Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($covers as $b)
                            @php
                                $rowHref = route('tenant.reservations.show', $b->booking_ref);
                                $service = $servedByBookingId->get($b->id);
                                $isServed = $service !== null;
                            @endphp
                            <tr class="lp-comp-row @if ($isServed) is-served @endif"
                                data-testid="complimentary-row-{{ $b->booking_ref }}"
                                @if ($isServed) data-served="true" @endif
                                role="link"
                                tabindex="0"
                                aria-label="Open reservation {{ $b->booking_ref }}"
                                x-data
                                x-on:click="$event.target.closest('a,button,input,label') || Livewire.navigate(@js($rowHref))"
                                x-on:keydown.enter.prevent="Livewire.navigate(@js($rowHref))"
                                x-on:keydown.space.prevent="Livewire.navigate(@js($rowHref))">
                                <td class="lp-bulk-col" wire:click.stop>
                                    <input type="checkbox"
                                           class="lp-bulk-checkbox"
                                           data-testid="comp-bulk-toggle-{{ $b->id }}"
                                           aria-label="Select cover for room {{ $b->room?->room_number ?? $b->id }}"
                                           @checked(in_array((string) $b->id, $selectedIds, true))
                                           wire:click="toggleSelected({{ $b->id }})">
                                </td>
                                {{-- Served toggle. Click-through guarded via
                                     the x-on:click above (input skips nav).
                                     wire:click.stop stops the click from
                                     bubbling to any parent handler either. --}}
                                <td class="lp-comp-toggle-cell" style="text-align: center;">
                                    <label style="display: inline-flex; align-items: center; margin: 0;"
                                           title="{{ $isServed
                                                ? 'Served at '.$service->served_at->format('g:i A').', click to un-mark'
                                                : 'Mark as served' }}">
                                        <input type="checkbox"
                                               class="lp-comp-toggle"
                                               data-testid="complimentary-toggle-{{ $b->booking_ref }}"
                                               @if ($isServed) checked @endif
                                               wire:click.stop="toggleServed({{ $b->id }})"
                                               wire:loading.attr="disabled"
                                               wire:target="toggleServed({{ $b->id }})">
                                    </label>
                                </td>
                                <td class="lp-mono" style="font-weight: 500; color: var(--color-ink);">
                                    Rm {{ $b->room?->room_number ?? '-' }}
                                </td>
                                <td>
                                    <div style="color: var(--color-ink); font-weight: 500;">
                                        {{ $b->guest_name }}
                                    </div>
                                    @if ($b->guest_phone)
                                        <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                            {{ $b->guest_phone }}
                                        </div>
                                    @endif
                                    @if ($isServed)
                                        <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute); text-decoration: none;">
                                            Served {{ $service->served_at->format('g:i A') }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                    {{ $b->room?->category?->name ?? '-' }}
                                </td>
                                <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                    {{ $b->checkin_date?->format('j M') ?? '-' }}
                                    <span style="color: var(--color-ink-mute);">→</span>
                                    {{ $b->checkout_date?->format('j M') ?? '-' }}
                                </td>
                                <td class="lp-mono text-[11px]"
                                    style="text-align: right; color: var(--color-ink-mute);">
                                    {{ $b->booking_ref }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($this->hasSelection())
        <x-dash-bulk-bar :count="count($selectedIds)" noun="cover" testid="comp-bulk-bar">
            <x-slot:actions>
                <button type="button"
                        wire:click="markSelectedServed"
                        wire:loading.attr="disabled"
                        class="lp-dash-bulk-action lp-dash-bulk-action--primary"
                        data-testid="comp-bulk-serve">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2.5 6.5l2.5 2.5L10 3.5"/>
                    </svg>
                    Mark served
                </button>
            </x-slot:actions>
        </x-dash-bulk-bar>
    @endif
</div>
