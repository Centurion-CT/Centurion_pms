@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Inventory</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Workspaces
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                {{ $workspaces->total() }} space{{ $workspaces->total() === 1 ? '' : 's' }} configured.
            </p>
        </div>
        <a href="{{ route('tenant.workspaces.create') }}" wire:navigate
           class="lp-pill" data-testid="create-link">
            Add workspace <span class="lp-pill-dot">{!! $pillArrow !!}</span>
        </a>
    </div>
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Filter</span>
        </div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="ws-search">Search</label>
                <input id="ws-search" type="search"
                       wire:model.live.debounce.300ms="search"
                       data-testid="search" class="lp-input"
                       placeholder="Workspace name">
            </div>
            <div>
                <label class="lp-form-label" for="ws-type">Type</label>
                <select id="ws-type" wire:model.live="typeFilter"
                        data-testid="type-filter" class="lp-input">
                    <option value="">All types</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    {{-- ── Today occupancy Gantt ────────────────────────────────────
         Horizontal 07:00–22:00 strip per workspace showing painted
         booked slots + a live status pill ("Free until 3pm", "Booked
         until 5pm"). Answers the operational question — "what's in
         use RIGHT NOW and when does it free up?" — without needing
         to open each workspace's detail page. Report 08 flagged this
         as the single move that makes co-working feel first-class. --}}
    @if ($workspaces->isNotEmpty())
        @php
            $ganttStart = $ganttWindow['start_hour'];
            $ganttEnd   = $ganttWindow['end_hour'];
            $spanHours  = $ganttEnd - $ganttStart;
            $nowHour    = (int) now()->hour + (now()->minute / 60);
            $nowPct     = ($nowHour - $ganttStart) / $spanHours * 100;
        @endphp
        <section class="lp-dash-card lp-ws-gantt" data-testid="workspaces-occupancy"
                 style="padding: 22px 24px;">
            <div class="lp-dash-card-head" style="margin-bottom: 14px;">
                <span class="lp-dash-card-title">Today &middot; live occupancy</span>
                <span class="lp-dash-card-meta lp-mono">
                    {{ sprintf('%02d:00', $ganttStart) }} → {{ sprintf('%02d:00', $ganttEnd) }}
                </span>
            </div>

            {{-- Hour axis header --}}
            <div class="lp-ws-gantt__axis">
                <span class="lp-ws-gantt__axis-label"></span>
                <div class="lp-ws-gantt__axis-hours">
                    @foreach ($ganttWindow['hours'] as $h)
                        <span class="lp-ws-gantt__hour">{{ sprintf('%02d', $h) }}</span>
                    @endforeach
                </div>
            </div>

            {{-- One row per visible workspace --}}
            @foreach ($workspaces as $w)
                @php
                    $occ = $occupancy[$w->id] ?? null;
                    $status = match (true) {
                        $occ === null                 => ['label' => 'No data',        'tone' => 'neutral'],
                        $occ['occupied_now']          => [
                            'label' => 'Booked · frees at '.$occ['next_free']?->format('g:i A'),
                            'tone'  => 'busy',
                        ],
                        $occ['next_booked'] !== null  => [
                            'label' => 'Free until '.$occ['next_booked']->format('g:i A'),
                            'tone'  => 'pending',
                        ],
                        default                       => ['label' => 'Free',           'tone' => 'free'],
                    };
                @endphp
                <div class="lp-ws-gantt__row" data-testid="ws-gantt-row-{{ $w->id }}">
                    <div class="lp-ws-gantt__label">
                        <span class="lp-ws-gantt__name">{{ $w->name }}</span>
                        <span class="lp-ws-gantt__type">{{ $w->type->label() }}</span>
                    </div>
                    <div class="lp-ws-gantt__track" data-status="{{ $status['tone'] }}">
                        {{-- Painted booked slots. Each booking gets an
                             absolutely-positioned block sized as % of the
                             window; slots outside the axis are clipped. --}}
                        @if ($occ !== null)
                            @foreach ($occ['bookings'] as $b)
                                @php
                                    $s = max($ganttStart, $b->start_at->hour + $b->start_at->minute / 60);
                                    $e = min($ganttEnd,   $b->end_at->hour   + $b->end_at->minute   / 60);
                                    if ($b->end_at->format('H') === '00' && $b->end_at->minute === 0) {
                                        $e = $ganttEnd;
                                    }
                                    if ($e <= $s) continue;
                                    $left  = (($s - $ganttStart) / $spanHours) * 100;
                                    $width = (($e - $s)          / $spanHours) * 100;
                                    $guest = $b->customer?->name
                                        ?? $b->corporateMember?->name
                                        ?? 'Booking '.$b->reference;
                                @endphp
                                <div class="lp-ws-gantt__slot"
                                     data-testid="ws-gantt-slot-{{ $b->id }}"
                                     title="{{ $guest }} · {{ $b->start_at->format('g:i A') }} → {{ $b->end_at->format('g:i A') }}"
                                     style="left: {{ number_format($left, 2) }}%; width: {{ number_format($width, 2) }}%;">
                                    <span>{{ $guest }}</span>
                                </div>
                            @endforeach
                        @endif

                        {{-- Now marker — vertical accent line at the
                             current time, drawn only during business
                             hours so we don't paint a stray marker at
                             the edge overnight. --}}
                        @if ($nowHour >= $ganttStart && $nowHour <= $ganttEnd)
                            <span class="lp-ws-gantt__now" aria-hidden="true"
                                  style="left: {{ number_format($nowPct, 2) }}%;"></span>
                        @endif
                    </div>
                    <div class="lp-ws-gantt__status" data-status="{{ $status['tone'] }}">
                        {{ $status['label'] }}
                    </div>
                </div>
            @endforeach
        </section>

        <style>
            .lp-page .lp-ws-gantt__axis,
            .lp-page .lp-ws-gantt__row {
                display: grid;
                grid-template-columns: 200px 1fr 200px;
                gap: 12px;
                align-items: center;
            }
            .lp-page .lp-ws-gantt__row {
                padding: 8px 0;
                border-top: 1px solid var(--color-line-soft);
            }
            .lp-page .lp-ws-gantt__axis {
                padding-bottom: 8px;
                border-bottom: 1px solid var(--color-line);
            }
            .lp-page .lp-ws-gantt__axis-label { min-width: 0; }
            .lp-page .lp-ws-gantt__axis-hours {
                display: grid;
                grid-template-columns: repeat({{ $spanHours }}, 1fr);
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 9.5px;
                letter-spacing: 0.10em;
                color: var(--color-ink-mute);
            }
            .lp-page .lp-ws-gantt__hour {
                text-align: left;
                padding-left: 2px;
            }
            .lp-page .lp-ws-gantt__label {
                display: flex; flex-direction: column;
                min-width: 0;
                overflow: hidden;
            }
            .lp-page .lp-ws-gantt__name {
                font-weight: 600;
                font-size: 13.5px;
                color: var(--color-ink);
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .lp-page .lp-ws-gantt__type {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 10px;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                color: var(--color-ink-mute);
                margin-top: 2px;
            }
            .lp-page .lp-ws-gantt__track {
                position: relative;
                height: 30px;
                background: color-mix(in srgb, var(--color-ink) 4%, transparent);
                border-radius: 6px;
                overflow: hidden;
            }
            .lp-page .lp-ws-gantt__slot {
                position: absolute;
                top: 3px; bottom: 3px;
                display: flex; align-items: center;
                padding: 0 8px;
                background: var(--color-ink);
                color: var(--color-paper);
                border-radius: 4px;
                font-size: 11.5px;
                font-weight: 500;
                white-space: nowrap; overflow: hidden;
                min-width: 20px;
            }
            .lp-page .lp-ws-gantt__slot span {
                overflow: hidden; text-overflow: ellipsis;
            }
            .lp-page .lp-ws-gantt__now {
                position: absolute;
                top: 0; bottom: 0;
                width: 2px;
                background: var(--color-accent-deep, #C99441);
                box-shadow: 0 0 0 2px rgba(201, 148, 65, 0.18);
                pointer-events: none;
            }
            .lp-page .lp-ws-gantt__status {
                display: inline-flex; align-items: center;
                padding: 4px 10px;
                border-radius: 999px;
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 10.5px;
                letter-spacing: 0.10em;
                text-transform: uppercase;
                white-space: nowrap;
                overflow: hidden; text-overflow: ellipsis;
            }
            .lp-page .lp-ws-gantt__status[data-status="busy"] {
                background: rgba(220, 38, 38, 0.12); color: #B91C1C;
            }
            .lp-page .lp-ws-gantt__status[data-status="pending"] {
                background: rgba(201, 148, 65, 0.16); color: #A8650A;
            }
            .lp-page .lp-ws-gantt__status[data-status="free"] {
                background: rgba(76, 149, 108, 0.14); color: #166534;
            }
            .lp-page .lp-ws-gantt__status[data-status="neutral"] {
                background: color-mix(in srgb, var(--color-ink) 5%, transparent);
                color: var(--color-ink-mute);
            }
            @media (max-width: 780px) {
                .lp-page .lp-ws-gantt__axis,
                .lp-page .lp-ws-gantt__row {
                    grid-template-columns: 1fr;
                    row-gap: 6px;
                }
                .lp-page .lp-ws-gantt__label { display: flex; align-items: baseline; gap: 10px; }
                .lp-page .lp-ws-gantt__type { margin-top: 0; }
                .lp-page .lp-ws-gantt__status { justify-self: flex-start; }
                .lp-page .lp-ws-gantt__axis-hours { font-size: 8.5px; }
            }
        </style>
    @endif

    @if ($workspaces->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="empty" class="text-[18px]"
               style="color: var(--color-ink); font-weight: 500;">
                No workspaces yet. Add your first one.
            </p>
            <p class="mt-2 text-[13px]" style="color: var(--color-ink-soft);">
                Workspaces are the bookable units members reserve.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="workspaces-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Capacity</th>
                            <th>Hourly</th>
                            <th>Daily</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($workspaces as $w)
                            <tr data-testid="ws-row-{{ $w->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">{{ $w->name }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $w->type->label() }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $w->capacity }}</td>
                                <td>
                                    @if ($w->hourly_rate_naira)
                                        <span class="lp-table-amount">₦{{ number_format($w->hourly_rate_naira) }}</span>
                                    @else
                                        <span style="color: var(--color-ink-mute);">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($w->daily_rate_naira)
                                        <span class="lp-table-amount">₦{{ number_format($w->daily_rate_naira) }}</span>
                                    @else
                                        <span style="color: var(--color-ink-mute);">-</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="lp-tag {{ $w->is_active ? 'lp-tag--confirmed' : 'lp-tag--checked-out' }}">
                                        {{ $w->is_active ? 'Active' : 'Hidden' }}
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-4 text-[12.5px]"
                                         style="color: var(--color-ink-soft);">
                                        <a href="{{ route('tenant.workspaces.edit', $w) }}" wire:navigate
                                           data-testid="edit-{{ $w->id }}"
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">Edit</a>
                                        <button type="button" wire:click="toggleActive({{ $w->id }})"
                                                style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">
                                            {{ $w->is_active ? 'Hide' : 'Activate' }}
                                        </button>
                                        <button type="button" wire:click="delete({{ $w->id }})"
                                                wire:confirm="Archive this workspace?"
                                                style="color: var(--color-danger, #b00); background: none; border: 0; cursor: pointer;">
                                            Archive
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $workspaces->links() }}</div>
        </section>
    @endif
</div>
