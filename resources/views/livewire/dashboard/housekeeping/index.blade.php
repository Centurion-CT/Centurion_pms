<div class="lp-dash" data-testid="hk-page">

    {{-- ── Hero: title + inspections queue CTA ──────────────
         Identity block on the left; right side hosts a link
         into the inspections queue with the count when > 0. --}}
    <section class="lp-res-idx-hero" data-testid="hk-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Operate · Housekeeping</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Housekeeping ops
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Per-room accountability. Each card shows the last inspection, who signed off (cleaner, porter, supervisor), the readiness score, and any open damage. Tap a room for its full history.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.housekeeping.inspections.index') }}" wire:navigate
                       class="lp-res-action lp-res-action--primary"
                       data-testid="hk-open-inspections">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4 3h8v10H4z"/><path d="M6 6h4M6 8h4M6 10h2"/></svg>
                        Open inspections
                        @if ((int) $kpis['open_inspections'] > 0)
                            <span class="lp-tag lp-tag--on-hold" style="margin-left: 4px;">{{ (int) $kpis['open_inspections'] }}</span>
                        @endif
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Rooms overview">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Ready</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ (int) $roomStatusCounts['ready'] > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink)' }};">
                        {{ number_format((int) $roomStatusCounts['ready']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        {{ (int) $roomStatusCounts['occupied'] }} occupied ·
                        {{ (int) $roomStatusCounts['turnover_pending'] }} turnover pending
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Pulse strip ────────────────────────────────────────────
         Same dot-tile pattern as the dashboard / reservations pages.
         Dot color signals severity: green (nothing burning), amber
         (open work), red (real problems). --}}
    @php
        $hkTiles = [
            [
                'label' => 'Issues · 7d',
                'value' => (int) $kpis['issues_7d'],
                'sub'   => 'damage flags raised',
                'dot'   => $kpis['issues_7d'] > 0 ? '#B85C42' : 'var(--color-accent-deep)',
                'color' => $kpis['issues_7d'] > 0 ? '#B85C42' : 'var(--color-ink)',
            ],
            [
                'label' => 'Issues · 30d',
                'value' => (int) $kpis['issues_30d'],
                'sub'   => 'rolling trend',
                'dot'   => '#7A6EA7',
                'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Open inspections',
                'value' => (int) $kpis['open_inspections'],
                'sub'   => $kpis['open_inspections'] > 0 ? 'in queue' : 'all rooms cleared',
                'dot'   => $kpis['open_inspections'] > 0 ? '#a36318' : 'var(--color-accent-deep)',
                'color' => $kpis['open_inspections'] > 0 ? '#a36318' : 'var(--color-ink)',
                'href'  => $kpis['open_inspections'] > 0 ? route('tenant.housekeeping.inspections.index') : null,
            ],
            [
                'label' => 'Needs replacement',
                'value' => (int) $kpis['needs_replacement'],
                'sub'   => 'items flagged, unresolved',
                'dot'   => $kpis['needs_replacement'] > 0 ? '#B85C42' : 'var(--color-accent-deep)',
                'color' => $kpis['needs_replacement'] > 0 ? '#B85C42' : 'var(--color-ink)',
            ],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-2 lg:grid-cols-4" data-testid="hk-kpis">
        @foreach ($hkTiles as $tile)
            @php $Tag = $tile['href'] ?? null ? 'a' : 'div'; @endphp
            <{{ $Tag }} @if ($tile['href'] ?? null) href="{{ $tile['href'] }}" wire:navigate @endif
                class="lp-dash-card"
                style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: {{ $tile['color'] }};">
                        {{ number_format((int) $tile['value']) }}
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
            </{{ $Tag }}>
        @endforeach
    </div>

    {{-- ── Rooms grid, per-room last-inspection card ──────────── --}}
    <section class="lp-dash-card" data-testid="hk-rooms-grid">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Rooms</span>
            <span class="lp-dash-card-meta">
                {{ $roomStatusCounts['occupied'] }} occupied &middot;
                {{ $roomStatusCounts['ready'] }} ready &middot;
                {{ $roomStatusCounts['turnover_pending'] }} turnover pending
            </span>
        </div>

        @if ($roomsBoard->isEmpty())
            <p data-testid="hk-no-rooms" class="text-[13.5px]" style="color: var(--color-ink-mute);">
                No active rooms yet.
            </p>
        @else
            <style>
                .hk-room-card {
                    position: relative;
                    display: grid; gap: 8px;
                    padding: 12px 14px 12px 18px;
                    border: 1px solid var(--color-line-soft);
                    border-radius: 10px;
                    background: var(--color-card);
                    text-decoration: none;
                    color: inherit;
                    overflow: hidden;
                }
                /* Left-edge status ribbon. Painted by state via
                   [data-hk-status] on each card so a housekeeper on
                   a phone reads "green edge = go clean", "amber =
                   turnover pending", "grey = occupied, hands off"
                   without reading the copy. */
                .hk-room-card::before {
                    content: '';
                    position: absolute;
                    left: 0; top: 0; bottom: 0;
                    width: 4px;
                    background: var(--color-line);
                }
                .hk-room-card[data-hk-status="ready"]::before      { background: #4C956C; }
                .hk-room-card[data-hk-status="turnover"]::before   { background: #C99441; }
                .hk-room-card[data-hk-status="occupied"]::before   { background: #2563EB; }
                .hk-room-card[data-hk-status="damage"]::before     { background: #B85C42; }
                .hk-room-card:hover { background: var(--color-card-deep); cursor: pointer; }
                .hk-room-card:focus-visible {
                    outline: 2px solid var(--color-accent-deep);
                    outline-offset: -2px;
                }
                .hk-signer {
                    display: inline-flex; align-items: center; gap: 4px;
                    padding: 2px 6px; border-radius: 999px;
                    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                    font-size: 10.5px; letter-spacing: 0.04em;
                }
                .hk-signer--done { background: #DCFCE7; color: #166534; }
                .hk-signer--pending { background: var(--color-card-deep); color: var(--color-ink-mute); }

                /* Mobile: bigger tap targets, tighter internal padding
                   so more rooms fit on one screen, room number gets a
                   full-line treatment. Report 07 flagged the existing
                   desktop-first render as "borderline unusable" on a
                   housekeeper's Android; this addresses it. */
                @media (max-width: 640px) {
                    .hk-room-card {
                        padding: 14px 14px 14px 20px;
                        gap: 10px;
                        min-height: 72px; /* Comfortable thumb target. */
                    }
                    .hk-room-card::before { width: 5px; }
                    .hk-signer { font-size: 11px; padding: 3px 8px; }
                }
            </style>
            <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
                @foreach ($roomsBoard as $entry)
                    @php
                        $room = $entry->room;
                        $ins = $entry->inspection;
                        $href = route('tenant.housekeeping.rooms.show', $room);
                        // Left-edge status ribbon color. The colour ladder
                        // reads at a glance for a housekeeper on a phone
                        // in an apron pocket: green = go clean, amber =
                        // turnover pending inspection, blue = occupied
                        // (hands off), red = open damage flagged.
                        $hkStatus = match (true) {
                            (int) $entry->openDamage > 0        => 'damage',
                            (bool) ($entry->occupiedBy ?? false) => 'occupied',
                            $ins && $ins->pass_score === false   => 'turnover',
                            $ins && $ins->pass_score === true    => 'ready',
                            default                               => 'turnover',
                        };
                    @endphp
                    <a href="{{ $href }}" wire:navigate
                       class="hk-room-card"
                       data-hk-status="{{ $hkStatus }}"
                       data-testid="hk-room-card-{{ $room->id }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <div>
                                <div class="lp-mono" style="font-weight: 600; color: var(--color-ink); font-size: 15px;">
                                    Rm {{ $room->room_number ?? '-' }}
                                </div>
                                <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                    {{ $room->category?->name ?? 'Uncategorised' }}
                                </div>
                            </div>
                            @if ($entry->occupiedBy)
                                {{-- Occupancy trumps inspection readiness, a Passed
                                     inspection from the last turnover is stale once a
                                     new guest has checked in. --}}
                                <div style="text-align: right;">
                                    <span class="lp-mono text-[11px]"
                                          style="padding: 2px 8px; border-radius: 999px;
                                                 background: #DBEAFE; color: #1E40AF;">
                                        Occupied
                                    </span>
                                    <div class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); margin-top: 2px;">
                                        out {{ \Illuminate\Support\Carbon::parse($entry->occupiedBy->checkout_date)->format('j M') }}
                                    </div>
                                </div>
                            @elseif ($ins)
                                <div style="text-align: right;">
                                    <span class="lp-mono text-[11px]"
                                          style="padding: 2px 8px; border-radius: 999px;
                                                 {{ $ins->status->value === 'passed'
                                                    ? 'background: #DCFCE7; color: #166534;'
                                                    : ($ins->status->value === 'failed'
                                                        ? 'background: #FEE2E2; color: #B85C42;'
                                                        : 'background: #FEF3C7; color: #92400E;') }}">
                                        {{ $ins->status->label() }}
                                    </span>
                                    @if ($ins->readiness_score > 0)
                                        <div class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); margin-top: 2px;">
                                            score {{ $ins->readiness_score }}/100
                                        </div>
                                    @endif
                                </div>
                            @else
                                <span class="lp-mono text-[11px]"
                                      style="padding: 2px 8px; border-radius: 999px;
                                             background: var(--color-card-deep); color: var(--color-ink-mute);">
                                    Never inspected
                                </span>
                            @endif
                        </div>

                        @if ($entry->occupiedBy)
                            <div class="text-[11.5px]" style="color: var(--color-ink-soft);">
                                Guest
                                <strong style="color: var(--color-ink); font-weight: 500;">
                                    {{ $entry->occupiedBy->guest_name }}
                                </strong>
                                <span style="color: var(--color-ink-mute);">
                                    · {{ $entry->occupiedBy->booking_ref }}
                                </span>
                            </div>
                        @elseif ($ins)
                            <div class="text-[11.5px]" style="color: var(--color-ink-soft);">
                                Last touched
                                <strong style="color: var(--color-ink); font-weight: 500;">
                                    {{ $ins->updated_at->diffForHumans() }}
                                </strong>
                            </div>

                            {{-- Signer chips, done/pending per stage. --}}
                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                <span class="hk-signer {{ $entry->housekeeper ? 'hk-signer--done' : 'hk-signer--pending' }}">
                                    ✓ Cleaner{{ $entry->housekeeper ? ' · '.$entry->housekeeper : '' }}
                                </span>
                                <span class="hk-signer {{ $entry->porter ? 'hk-signer--done' : 'hk-signer--pending' }}">
                                    ✓ Porter{{ $entry->porter ? ' · '.$entry->porter : '' }}
                                </span>
                                <span class="hk-signer {{ $entry->supervisor ? 'hk-signer--done' : 'hk-signer--pending' }}">
                                    ✓ Supervisor{{ $entry->supervisor ? ' · '.$entry->supervisor : '' }}
                                </span>
                            </div>

                            @if ($entry->openDamage > 0)
                                <div class="text-[11.5px]" style="color: #B85C42;">
                                    ⚠ {{ $entry->openDamage }} open damage
                                    {{ $entry->openDamage === 1 ? 'flag' : 'flags' }}
                                </div>
                            @endif
                        @else
                            <p class="text-[11.5px]" style="color: var(--color-ink-mute); font-style: italic;">
                                No inspection recorded yet. A fresh one opens on the next guest checkout.
                            </p>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ── Needs replacement ────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="hk-needs-replacement">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Needs replacement</span>
            <span class="lp-dash-card-meta">{{ $needsReplacement->count() }} outstanding</span>
        </div>

        @if ($needsReplacement->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute); padding: 8px 0;">
                No open damage flags. Everything's shipshape.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="hk-needs-replacement-table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Item</th>
                            <th>Note</th>
                            <th>Flagged</th>
                            @if ($canResolveDamage)
                                <th style="text-align: right;">Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($needsReplacement as $flag)
                            <tr data-testid="hk-flag-row-{{ $flag->id }}">
                                <td class="lp-mono" style="font-weight: 500; color: var(--color-ink);">
                                    Rm {{ $flag->inspection?->room?->room_number ?? '-' }}
                                    <div class="text-[10.5px]" style="color: var(--color-ink-mute); font-weight: 400;">
                                        {{ $flag->inspection?->room?->category?->name ?? '' }}
                                    </div>
                                </td>
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $flag->label }}
                                </td>
                                <td class="text-[12.5px]" style="color: #B85C42;">
                                    {{ $flag->damage_note }}
                                </td>
                                <td class="text-[11.5px]" style="color: var(--color-ink-soft);">
                                    {{ $flag->created_at->diffForHumans() }}
                                </td>
                                @if ($canResolveDamage)
                                    <td style="text-align: right;">
                                        <button type="button"
                                                wire:click="markDamageResolved({{ $flag->id }})"
                                                wire:confirm="Mark resolved? Only tick once the item has actually been replaced / fixed."
                                                data-testid="hk-resolve-{{ $flag->id }}"
                                                class="lp-btn"
                                                style="height: 26px; padding: 0 10px; font-size: 11px;">
                                            Mark resolved
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ── Checklist bundles (reusable, collapsible) ────────────── --}}
    <section class="lp-dash-card" data-testid="hk-checklist-bundles">
        <div class="lp-dash-card-head">
            <button type="button"
                    wire:click="toggleTemplates"
                    data-testid="hk-templates-toggle"
                    style="all: unset; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                <span class="lp-dash-card-title">Checklist bundles</span>
                <span class="lp-mono text-[10.5px]"
                      style="letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-ink-mute);">
                    {{ $templatesOpen ? '▾ hide' : '▸ show' }}
                </span>
            </button>
            <span class="lp-dash-card-meta">
                Reusable checklists, attach one to many categories. Items snapshot onto every fresh inspection.
            </span>
        </div>

        @if ($templatesOpen)
            {{-- Create-bundle strip. Manager-only. --}}
            @if ($isManager)
                <form wire:submit.prevent="createBundle"
                      style="display: flex; gap: 8px; padding: 8px 0 14px;
                             border-bottom: 1px solid var(--color-line-soft); margin-bottom: 14px;">
                    <input type="text"
                           wire:model="newBundleName"
                           placeholder="New bundle name, e.g. Standard cleaning"
                           maxlength="120"
                           data-testid="hk-bundle-new-name"
                           class="lp-input"
                           style="flex: 1; height: 34px; font-size: 12.5px;">
                    <button type="submit"
                            data-testid="hk-bundle-create"
                            class="lp-btn lp-btn--primary"
                            style="height: 34px; padding: 0 14px; font-size: 12px;">
                        + Create bundle
                    </button>
                </form>
            @endif

            @if ($bundles->isEmpty())
                <p class="text-[13.5px]" style="color: var(--color-ink-mute); padding: 8px 0;">
                    No checklist bundles yet.
                    @if ($isManager)
                        Use the form above to author your first one, then attach it to the room categories that should snapshot it on turnover.
                    @else
                        A manager needs to author one before inspections carry items.
                    @endif
                </p>
            @else
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($bundles as $b)
                        @php $checklist = $b->checklist; $items = $b->items; @endphp
                        <div data-testid="hk-bundle-{{ $checklist->id }}"
                             style="border: 1px solid var(--color-line-soft); border-radius: 10px; padding: 14px;">

                            {{-- Header: bundle name (rename-inline for managers) + delete. --}}
                            <div style="display: flex; align-items: center; gap: 8px; justify-content: space-between;">
                                @if ($isManager)
                                    <input type="text"
                                           value="{{ $checklist->name }}"
                                           wire:change="renameBundle({{ $checklist->id }}, $event.target.value)"
                                           maxlength="120"
                                           data-testid="hk-bundle-{{ $checklist->id }}-name"
                                           class="lp-input"
                                           style="flex: 1; height: 28px; font-size: 13px; color: var(--color-ink); font-weight: 500;
                                                  background: transparent; border: 1px solid transparent; padding: 2px 6px;"
                                           onfocus="this.style.borderColor='var(--color-line)'"
                                           onblur="this.style.borderColor='transparent'">
                                @else
                                    <div style="flex: 1; color: var(--color-ink); font-weight: 500; padding: 4px 0;">
                                        {{ $checklist->name }}
                                    </div>
                                @endif
                                @if ($isManager)
                                    <button type="button"
                                            wire:click="deleteBundle({{ $checklist->id }})"
                                            wire:confirm="Delete '{{ $checklist->name }}'? Detaches from every category. Existing inspections aren't affected."
                                            data-testid="hk-bundle-{{ $checklist->id }}-delete"
                                            class="lp-btn"
                                            style="height: 26px; padding: 0 8px; font-size: 11px; color: #E40014;"
                                            title="Delete bundle">Delete</button>
                                @endif
                            </div>

                            {{-- Attached categories row (chips) + attach dropdown. --}}
                            <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-top: 10px;
                                        padding: 8px 10px; background: var(--color-card-deep); border-radius: 6px;">
                                <span class="lp-mono text-[10px]"
                                      style="letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-ink-mute);">
                                    Attached
                                </span>
                                @if ($b->attached->isEmpty())
                                    <span class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                        no categories yet
                                    </span>
                                @else
                                    @foreach ($b->attached as $cat)
                                        <span data-testid="hk-bundle-{{ $checklist->id }}-attached-{{ $cat->id }}"
                                              style="display: inline-flex; align-items: center; gap: 4px;
                                                     padding: 2px 6px 2px 8px; background: var(--color-card); border-radius: 999px;
                                                     border: 1px solid var(--color-line-soft); font-size: 11.5px; color: var(--color-ink);">
                                            {{ $cat->name }}
                                            @if ($isManager)
                                                <button type="button"
                                                        wire:click="detachBundleFromCategory({{ $checklist->id }}, {{ $cat->id }})"
                                                        title="Detach"
                                                        data-testid="hk-bundle-{{ $checklist->id }}-detach-{{ $cat->id }}"
                                                        style="background: transparent; border: 0; cursor: pointer;
                                                               color: var(--color-ink-mute); font-size: 12px; line-height: 1;">
                                                    ×
                                                </button>
                                            @endif
                                        </span>
                                    @endforeach
                                @endif
                                @if ($isManager && $b->available->isNotEmpty())
                                    <select wire:change="attachBundleToCategory({{ $checklist->id }}, $event.target.value)"
                                            data-testid="hk-bundle-{{ $checklist->id }}-attach"
                                            class="lp-input"
                                            style="height: 24px; padding: 0 20px 0 8px; font-size: 11.5px;
                                                   width: auto; background-color: transparent; border: 1px dashed var(--color-line);
                                                   color: var(--color-accent-deep); cursor: pointer;">
                                        <option value="">+ attach to category…</option>
                                        @foreach ($b->available as $cat)
                                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>

                            {{-- Items list --}}
                            @if ($items->isNotEmpty())
                                <ul class="mt-3" style="display: grid; gap: 4px;">
                                    @foreach ($items as $i => $item)
                                        <li wire:key="hk-bundle-{{ $checklist->id }}-item-{{ $item->id }}"
                                            data-testid="hk-bundle-{{ $checklist->id }}-item-{{ $item->id }}"
                                            style="display: flex; align-items: center; gap: 8px; padding: 5px 8px;
                                                   background: var(--color-card-deep); border-radius: 6px;">
                                            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); min-width: 22px;">
                                                {{ $i + 1 }}.
                                            </span>
                                            <span style="flex: 1; color: var(--color-ink); font-size: 12.5px;">{{ $item->label }}</span>
                                            @if ($isManager)
                                                <button type="button"
                                                        wire:click="moveChecklistItem({{ $item->id }}, -1)"
                                                        @disabled($i === 0)
                                                        class="lp-btn"
                                                        style="height: 22px; padding: 0 6px; font-size: 10px;"
                                                        title="Move up">↑</button>
                                                <button type="button"
                                                        wire:click="moveChecklistItem({{ $item->id }}, 1)"
                                                        @disabled($i === $items->count() - 1)
                                                        class="lp-btn"
                                                        style="height: 22px; padding: 0 6px; font-size: 10px;"
                                                        title="Move down">↓</button>
                                                <button type="button"
                                                        wire:click="removeChecklistItem({{ $item->id }})"
                                                        wire:confirm="Remove this item? Existing inspections aren't affected."
                                                        data-testid="hk-bundle-{{ $checklist->id }}-remove-{{ $item->id }}"
                                                        class="lp-btn"
                                                        style="height: 22px; padding: 0 6px; font-size: 10px; color: #E40014;"
                                                        title="Remove">×</button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="text-[11.5px]" style="color: var(--color-ink-mute); padding: 8px 4px 0;">
                                    No items yet, add the first one below.
                                </p>
                            @endif

                            {{-- Add-item strip. --}}
                            @if ($isManager)
                                <form wire:submit.prevent="addChecklistItem({{ $checklist->id }})"
                                      style="display: flex; gap: 6px; margin-top: 8px;">
                                    <input type="text"
                                           wire:model="newItemLabels.{{ $checklist->id }}"
                                           placeholder="Add item · Enter"
                                           maxlength="200"
                                           data-testid="hk-bundle-{{ $checklist->id }}-new"
                                           class="lp-input"
                                           style="flex: 1; height: 30px; font-size: 12px;">
                                    <button type="submit"
                                            data-testid="hk-bundle-{{ $checklist->id }}-add"
                                            class="lp-btn"
                                            style="height: 30px; padding: 0 10px; font-size: 11px;">
                                        Add
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </section>
</div>
