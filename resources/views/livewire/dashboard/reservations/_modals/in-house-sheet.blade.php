{{-- In-house action sheet. Pure Alpine visibility, data is
     precomputed on every render, so the modal has nothing to fetch
     when it opens. --}}
<template x-teleport="body">
<div class="lp-page" style="display: contents;">
    <div x-data="{ open: false }"
         x-on:reservations-modal-open.window="if ($event.detail?.name === 'in-house') { open = true; }"
         x-on:reservations-modal-close.window="if ($event.detail?.name === 'in-house') { open = false; }"
         x-show="open"
         x-cloak
         @click.self="open = false"
         @keydown.escape.window="open = false"
         data-testid="in-house-sheet"
         class="lp-sheet-backdrop">
        <div class="lp-sheet-panel">
            <div class="lp-sheet-head">
                <h2 class="lp-sheet-title">In-house right now</h2>
                <button type="button" @click="open = false"
                        aria-label="Close" class="lp-sheet-close">×</button>
            </div>

            {{-- KPI mini-strip --}}
            <div class="grid gap-2 grid-cols-2" style="margin-bottom: 12px;">
                <div class="lp-kpi">
                    <span class="label">In-house</span>
                    <span class="value">{{ number_format($counts['in_house']) }}</span>
                    <span class="sub">currently checked in</span>
                </div>
                <div class="lp-kpi">
                    <span class="label">Overdue</span>
                    <span class="value" style="color: {{ $inHouseSheetData['overdue'] > 0 ? '#B85C42' : 'var(--color-ink)' }};">
                        {{ number_format($inHouseSheetData['overdue']) }}
                    </span>
                    <span class="sub">past booked checkout</span>
                </div>
                <div class="lp-kpi">
                    <span class="label">Checkouts today</span>
                    <span class="value">{{ number_format($inHouseSheetData['checkouts_today']) }}</span>
                </div>
                <div class="lp-kpi">
                    <span class="label">Checkouts tomorrow</span>
                    <span class="value">{{ number_format($inHouseSheetData['checkouts_tomorrow']) }}</span>
                </div>
            </div>

            <div style="padding: 10px 12px; background: var(--color-card-deep); border-radius: 10px;
                        display: flex; justify-content: space-between; align-items: baseline; gap: 10px;
                        margin-bottom: 14px;">
                <span class="text-[12.5px]" style="color: var(--color-ink-mute);">Total accrued balance</span>
                <span class="lp-mono" style="font-weight: 600; font-size: 15px;
                          color: {{ $inHouseSheetData['total_accrued'] > 0 ? '#B85C42' : 'var(--color-ink)' }};">
                    &#8358;{{ number_format((int) $inHouseSheetData['total_accrued']) }}
                </span>
            </div>

            {{-- Top 5 by soonest checkout. Rows dispatch
                 `inhouse-sheet-panel-open` with a booking id — the
                 detail panel opens as a separate teleported layer at
                 a higher z-index, and the sheet stays visible behind
                 it. Not a link/button anymore so the guest info shows
                 without a page nav. --}}
            @if ($inHouseSheetData['top']->isNotEmpty())
                <div style="margin-bottom: 14px;">
                    <p class="text-[11.5px]" style="color: var(--color-ink-mute); text-transform: uppercase;
                              letter-spacing: 0.06em; margin: 0 0 6px;">
                        Departing soonest
                    </p>
                    <div style="display: grid; gap: 4px;">
                        @foreach ($inHouseSheetData['top'] as $b)
                            @php
                                $isOverdue = $b->checkout_date && $b->checkout_date->lt(\Illuminate\Support\Carbon::today());
                            @endphp
                            <button type="button"
                                    x-on:click="$dispatch('inhouse-sheet-panel-open', { id: {{ $b->id }} })"
                                    data-testid="in-house-sheet-row-{{ $b->booking_ref }}"
                                    style="width: 100%; text-align: left; cursor: pointer;
                                           background: {{ $isOverdue ? '#FEF3C7' : 'transparent' }};
                                           border: 0; border-bottom: 1px solid var(--color-line-soft);
                                           border-radius: 8px; padding: 8px 10px; font: inherit;
                                           display: flex; justify-content: space-between; align-items: baseline;">
                                <span>
                                    <span style="display: block; font-weight: 500; font-size: 13px; color: var(--color-ink);">
                                        {{ $b->guest_name }}
                                    </span>
                                    <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                        Rm {{ $b->room?->room_number ?? '-' }} · out {{ $b->checkout_date?->format('j M') ?? '-' }}
                                        @if ($isOverdue) · <span style="color: #B85C42;">overdue</span> @endif
                                    </span>
                                </span>
                                <span class="lp-mono text-[11px]"
                                      style="color: var(--color-accent-deep);">Open &rarr;</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-3 pt-3"
                 style="border-top: 1px solid var(--color-line-soft);">
                <button type="button" @click="open = false" class="lp-btn">Close</button>
                <a href="{{ route('tenant.in-house.index') }}" wire:navigate
                   class="lp-btn lp-btn--primary" data-testid="in-house-sheet-open">
                    Open In-house dashboard &rarr;
                </a>
            </div>
        </div>
    </div>
</div>
</template>
