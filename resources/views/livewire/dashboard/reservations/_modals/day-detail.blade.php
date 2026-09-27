{{-- Day-detail modal. Pure Alpine visibility so cell clicks open
     instantly; the Livewire component loads the summary data in the
     background (wire:call from the calendar handler). Until it lands,
     a lightweight loading state occupies the panel.

     Two internal modes:
       - summary: KPI strip + every active room's status for the day
                  (Arriving / In-house / Departing / Blocked / Free).
       - reserve: shares the same fields partial as reserve-sheet,
                  submits via the same submitReserve() action.
                  "Back" flips to summary; "Cancel" closes the modal.
--}}
<template x-teleport="body">
<div class="lp-page" style="display: contents;">
    <div x-data="{ open: false }"
         x-on:reservations-modal-open.window="if ($event.detail?.name === 'day-detail') { open = true; }"
         x-on:reservations-modal-close.window="if ($event.detail?.name === 'day-detail') { open = false; }"
         x-show="open"
         x-cloak
         @click.self="open = false"
         @keydown.escape.window="open = false"
         data-testid="day-detail"
         class="lp-sheet-backdrop">
        <div class="lp-sheet-panel" style="max-width: 720px;">
            @if ($dayDetailData !== null)
                <div class="lp-sheet-head">
                    <div>
                        <h2 class="lp-sheet-title">
                            {{ $dayDetailData['date']->format('D, j M Y') }}
                        </h2>
                        <p class="text-[12px]" style="color: var(--color-ink-mute); margin: 2px 0 0;">
                            @if ($dayDetailMode === 'summary')
                                Every active room's status for this day.
                            @else
                                Reserve a room for
                                {{ \Illuminate\Support\Carbon::parse($reserveCheckin)->format('j M') }} &rarr;
                                {{ \Illuminate\Support\Carbon::parse($reserveCheckout)->format('j M') }}.
                            @endif
                        </p>
                    </div>
                    <button type="button" @click="open = false"
                            aria-label="Close" class="lp-sheet-close">×</button>
                </div>

                @if ($dayDetailMode === 'summary')
                    {{-- ── Summary mode ─────────────────────────── --}}
                    <div class="grid gap-2 grid-cols-3 lg:grid-cols-5"
                         style="margin-bottom: 14px;" data-testid="day-detail-kpi">
                        <div class="lp-kpi"><span class="label">Arrivals</span><span class="value">{{ $dayDetailData['kpi']['arrivals'] }}</span></div>
                        <div class="lp-kpi"><span class="label">Departures</span><span class="value">{{ $dayDetailData['kpi']['departures'] }}</span></div>
                        <div class="lp-kpi"><span class="label">In-house</span><span class="value">{{ $dayDetailData['kpi']['in_house'] }}</span></div>
                        <div class="lp-kpi"><span class="label">Blocked</span><span class="value">{{ $dayDetailData['kpi']['blocked'] }}</span></div>
                        <div class="lp-kpi">
                            <span class="label">{{ ($dayDetailData['is_past'] ?? false) ? 'Unbooked' : 'Free' }}</span>
                            <span class="value" style="color: {{ ($dayDetailData['is_past'] ?? false) ? 'var(--color-ink-mute)' : '#059669' }};">
                                {{ ($dayDetailData['is_past'] ?? false) ? '-' : $dayDetailData['kpi']['free'] }}
                            </span>
                        </div>
                    </div>

                    <div style="max-height: 45vh; overflow-y: auto; margin-bottom: 14px;">
                        <table class="lp-table" data-testid="day-detail-rooms">
                            <thead>
                                <tr>
                                    <th>Room</th>
                                    <th>Status</th>
                                    <th>Guest / note</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($dayDetailData['rooms'] as $row)
                                    @php
                                        // Date-aware palette. Past days read as history
                                        // ("Occupied", "No-show", "—") not live state, so a
                                        // Sep 4 view on Sep 5 doesn't mislead the front desk
                                        // into thinking Sep 4 rooms are still bookable.
                                        $status = $row['status'];
                                        $badgeStyle = match ($status) {
                                            'arriving'  => 'background: #1F2937; color: #FFFFFF;',
                                            'in_house'  => 'background: #2563EB; color: #FFFFFF;',
                                            'occupied'  => 'background: #2563EB; color: #FFFFFF;',
                                            'departing' => 'background: #F59E0B; color: #1F2937;',
                                            'no_show'   => 'background: #FEE2E2; color: #9F1F1F;',
                                            'past_free' => 'background: transparent; color: var(--color-ink-mute); border: 1px dashed var(--color-line);',
                                            'blocked'   => 'background: #DC2626; color: #FFFFFF;',
                                            default     => 'background: #DCFCE7; color: #166534;',
                                        };
                                        $label = match ($status) {
                                            'arriving'  => 'Arriving',
                                            'in_house'  => 'In-house',
                                            'occupied'  => 'Occupied',
                                            'departing' => 'Departing',
                                            'no_show'   => 'No-show',
                                            'past_free' => '-',
                                            'blocked'   => 'Closed',
                                            default     => 'Available',
                                        };
                                    @endphp
                                    <tr data-testid="day-detail-room-{{ $row['room']->id }}">
                                        <td>
                                            <div style="color: var(--color-ink); font-weight: 500;">
                                                Rm {{ $row['room']->room_number }}
                                            </div>
                                            @if ($row['room']->category)
                                                <div class="text-[11px]" style="color: var(--color-ink-mute);">
                                                    {{ $row['room']->category->name }}
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="lp-mono text-[11px]"
                                                  style="padding: 2px 8px; border-radius: 999px;
                                                         letter-spacing: 0.05em; text-transform: uppercase;
                                                         {{ $badgeStyle }}">
                                                {{ $label }}
                                            </span>
                                        </td>
                                        <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                            @if ($row['booking'])
                                                <div style="font-weight: 500; color: var(--color-ink);">
                                                    {{ $row['booking']->guest_name }}
                                                </div>
                                                <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                                    {{ $row['booking']->booking_ref }} ·
                                                    {{ $row['booking']->checkin_date?->format('j M') }} → {{ $row['booking']->checkout_date?->format('j M') }}
                                                </div>
                                            @elseif ($row['block'])
                                                <span style="color: #B85C42;">{{ $row['block']->reason ?? 'Blocked' }}</span>
                                            @else
                                                <span style="color: var(--color-ink-mute);">-</span>
                                            @endif
                                        </td>
                                        <td class="lp-mono text-[11px]" style="text-align: right;">
                                            @if ($row['booking'])
                                                <a href="{{ route('tenant.reservations.show', $row['booking']->booking_ref) }}" wire:navigate
                                                   style="color: var(--color-accent-deep); text-decoration: underline;"
                                                   data-testid="day-detail-open-{{ $row['booking']->booking_ref }}">Open</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Category-only (unassigned) bookings that overlap
                         this day. They have no room row so they never
                         show up in the table above; without this section
                         the day looked amber on the year cell but read
                         as "no info" once the operator clicked into it. --}}
                    @if (! empty($dayDetailData['unassigned']) && $dayDetailData['unassigned']->isNotEmpty())
                        <div style="margin-bottom: 14px;" data-testid="day-detail-unassigned">
                            <p class="lp-form-label" style="margin: 0 0 6px;">
                                Unassigned
                                <span style="color: var(--color-ink-mute); font-weight: 400;">
                                    · {{ $dayDetailData['unassigned']->count() }} category-only {{ Str::plural('booking', $dayDetailData['unassigned']->count()) }} holding a slot
                                </span>
                            </p>
                            <table class="lp-table">
                                <thead>
                                    <tr>
                                        <th>Guest</th>
                                        <th>Category</th>
                                        <th>Range</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($dayDetailData['unassigned'] as $ub)
                                        @php
                                            $ubStatus = $ub->booking_status?->value ?? '';
                                            [$ubBg, $ubFg] = match ($ubStatus) {
                                                'confirmed'  => ['#DCFCE7', '#166534'],
                                                'on_hold'    => ['#FEF3C7', '#92400E'],
                                                'pending'    => ['rgba(20,23,26,0.06)', 'var(--color-ink-soft)'],
                                                default      => ['rgba(20,23,26,0.06)', 'var(--color-ink-soft)'],
                                            };
                                        @endphp
                                        <tr data-testid="day-detail-unassigned-{{ $ub->booking_ref }}">
                                            <td>
                                                <div style="font-weight: 500; color: var(--color-ink);">
                                                    {{ $ub->guest_name }}
                                                </div>
                                                <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                                    {{ $ub->booking_ref }}
                                                </div>
                                            </td>
                                            <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                                {{ $ub->roomCategory?->name ?? 'Category' }}
                                                <span class="lp-tag" style="font-size: 10px; padding: 1px 6px; margin-left: 4px;
                                                                            background: var(--color-surface-2);
                                                                            color: var(--color-ink-mute);
                                                                            text-transform: uppercase; letter-spacing: 0.05em;">
                                                    Unassigned
                                                </span>
                                            </td>
                                            <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                                {{ $ub->checkin_date?->format('j M') }} → {{ $ub->checkout_date?->format('j M') }}
                                            </td>
                                            <td>
                                                <span class="lp-mono text-[10.5px]"
                                                      style="padding: 2px 8px; border-radius: 999px;
                                                             letter-spacing: 0.06em; text-transform: uppercase;
                                                             font-weight: 600;
                                                             background: {{ $ubBg }}; color: {{ $ubFg }};">
                                                    {{ $ub->booking_status?->label() ?? '-' }}
                                                </span>
                                            </td>
                                            <td class="lp-mono text-[11px]" style="text-align: right;">
                                                <a href="{{ route('tenant.reservations.show', $ub->booking_ref) }}" wire:navigate
                                                   style="color: var(--color-accent-deep); text-decoration: underline;"
                                                   data-testid="day-detail-unassigned-open-{{ $ub->booking_ref }}">Open</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="flex justify-between items-center gap-3 pt-3"
                         style="border-top: 1px solid var(--color-line-soft);">
                        @if ($dayDetailData['is_past'] ?? false)
                            <p class="text-[12px]" style="color: var(--color-ink-mute); margin: 0;"
                               data-testid="day-detail-past-note">
                                Past day. Reservations can only be created for today or later.
                            </p>
                        @else
                            <span></span>
                        @endif
                        <div class="flex gap-3">
                            <button type="button" @click="open = false" class="lp-btn">Close</button>
                            @unless ($dayDetailData['is_past'] ?? false)
                                <button type="button" wire:click="switchToReserveFromDayDetail"
                                        class="lp-btn lp-btn--primary" data-testid="day-detail-make-reservation">
                                    + Make reservation
                                </button>
                            @endunless
                        </div>
                    </div>
                @else
                    {{-- ── Reserve mode ─────────────────────────── --}}
                    <form wire:submit="submitReserve" style="display: grid; gap: 12px;"
                          data-testid="day-detail-reserve-form">
                        @include('livewire.dashboard.reservations._modals._reserve-form-fields')

                        <div class="flex justify-between items-center gap-3 pt-2"
                             style="border-top: 1px solid var(--color-line-soft);">
                            <button type="button" wire:click="backToDaySummary"
                                    class="lp-mono text-[11.5px]"
                                    style="color: var(--color-accent-deep); text-decoration: underline;
                                           background: none; border: 0; cursor: pointer; padding: 0;"
                                    data-testid="day-detail-back-to-summary">
                                &larr; Back to summary
                            </button>
                            <div class="flex gap-3">
                                <button type="button" @click="open = false" class="lp-btn">Cancel</button>
                                <button type="submit" class="lp-btn lp-btn--primary" data-testid="day-detail-reserve-submit">
                                    Reserve
                                </button>
                            </div>
                        </div>
                    </form>
                @endif
            @else
                {{-- Loading state, modal shell already visible via Alpine
                     while Livewire fetches the day summary in the background. --}}
                <div class="lp-sheet-head">
                    <h2 class="lp-sheet-title" style="color: var(--color-ink-mute);">Loading day summary…</h2>
                    <button type="button" @click="open = false"
                            aria-label="Close" class="lp-sheet-close">×</button>
                </div>
                <div style="padding: 40px 0; text-align: center; color: var(--color-ink-mute); font-size: 13px;">
                    Fetching room statuses…
                </div>
            @endif
        </div>
    </div>
</div>
</template>
