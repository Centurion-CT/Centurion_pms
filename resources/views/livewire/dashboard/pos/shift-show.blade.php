@php
    $sales = $report['sales'];
    $tenders = $report['tenders'];
    $stock = $report['stock'];
    $isOpen = $report['is_open'];
    $showCloseControls = $isOpen && (
        auth('web')->user()?->id === $shift->operator_id
        || auth('web')->user()?->role?->value === 'manager'
    );
@endphp

<div class="lp-dash">
    @include('_partials._back-button')

    {{-- ── Hero: shift identity + net-till side panel ────── --}}
    <section class="lp-res-idx-hero mt-3" data-testid="shift-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">POS · Shift · {{ $shift->outlet?->name ?? '—' }}</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    {{ $shift->operator?->name ?? 'Operator' }}
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Opened {{ $shift->opened_at->format('D, j M · g:i A') }}
                    @if ($shift->closed_at)
                        · Closed {{ $shift->closed_at->format('D, j M · g:i A') }}
                        · {{ $shift->opened_at->diffForHumans($shift->closed_at, ['parts' => 2]) }} on shift
                    @else
                        · Still on shift ({{ $shift->opened_at->diffForHumans(null, ['parts' => 2]) }} so far)
                    @endif
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.pos.shifts.export', $shift) }}"
                       data-testid="shift-export"
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M8 2v9M4 7l4 4 4-4M2 13h12"/></svg>
                        Download CSV
                    </a>
                    @if ($isOpen)
                        <span class="lp-res-action" style="cursor: default; background: rgba(93, 132, 44, 0.12); color: var(--color-accent-deep); border-color: rgba(93, 132, 44, 0.35);">
                            Open shift
                        </span>
                    @endif
                </div>
                @if ($shift->close_reason)
                    <p class="mt-3 text-[12px]" style="color: #a36318;">
                        Close reason: <em>"{{ $shift->close_reason }}"</em>
                        @if ($shift->closedBy && $shift->closed_by_operator_id !== $shift->operator_id)
                            · force-closed by {{ $shift->closedBy->name }}
                        @endif
                    </p>
                @endif
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Net till">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Net till</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ $sales['net'] > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};"
                       data-testid="shift-hero-net">
                        ₦{{ number_format($sales['net']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        {{ $sales['count'] }} completed
                        @if ($sales['voided_count'] > 0)
                            · {{ $sales['voided_count'] }} voided
                        @endif
                        @if ($sales['comp_count'] > 0)
                            · {{ $sales['comp_count'] }} comp
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Close shift form (own shift or manager) ────── --}}
    @if ($showCloseControls)
        <section class="lp-dash-card" style="padding: 14px 16px; border: 1px solid rgba(93, 132, 44, 0.35);">
            <div class="lp-dash-card-head" style="margin-bottom: 10px;">
                <span class="lp-dash-card-title">Close shift</span>
                <span class="lp-dash-card-meta">Snapshots closing stock and locks the till.</span>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="close-reason" class="lp-rate-sub-label">Reason (optional)</label>
                    <input id="close-reason" type="text" wire:model="closeReason"
                           class="lp-input" maxlength="200"
                           placeholder="e.g. End of shift · handoff to next operator">
                </div>
                <div>
                    <label for="close-notes" class="lp-rate-sub-label">Notes (optional)</label>
                    <input id="close-notes" type="text" wire:model="closeNotes"
                           class="lp-input" maxlength="500"
                           placeholder="Cash count remarks, incidents…">
                </div>
            </div>
            <div class="mt-3" style="display: flex; justify-content: flex-end;">
                <button type="button" wire:click="closeShift"
                        wire:confirm="Close this shift now? Closing stock will be snapshotted from current levels."
                        data-testid="shift-close-submit"
                        class="lp-res-action lp-res-action--primary">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4 8l3 3 5-7"/></svg>
                    Close shift now
                </button>
            </div>
        </section>
    @endif

    {{-- ── Sales KPIs ─────────────────────────────────── --}}
    @php
        $tiles = [
            ['label' => 'Sales', 'value' => number_format($sales['count']), 'sub' => 'completed', 'dot' => 'var(--color-accent-deep)'],
            ['label' => 'Gross', 'value' => '₦'.number_format($sales['gross']), 'sub' => 'chargeable total', 'dot' => '#4B7BB0'],
            ['label' => 'Avg ticket', 'value' => '₦'.number_format($sales['avg_ticket']), 'sub' => 'net ÷ sales', 'dot' => '#7A6EA7'],
            ['label' => 'Comp value', 'value' => '₦'.number_format($sales['comp_value']), 'sub' => $sales['comp_count'].' on the house', 'dot' => $sales['comp_value'] > 0 ? '#F97316' : 'var(--color-ink-mute)'],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-2 lg:grid-cols-4" data-testid="shift-kpis">
        @foreach ($tiles as $t)
            <div class="lp-dash-card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $t['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $t['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: var(--color-ink);">{{ $t['value'] }}</p>
                    <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                       style="color: var(--color-ink-mute); margin-top: 2px;">
                        {{ $t['label'] }}
                        <span style="text-transform: none; letter-spacing: 0; color: var(--color-ink-mute);
                                     font-size: 11px; margin-left: 4px;">
                            {{ $t['sub'] }}
                        </span>
                    </p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Tender rollup ────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="shift-tenders" style="padding: 14px 16px;">
        <div class="lp-dash-card-head" style="margin-bottom: 10px;">
            <span class="lp-dash-card-title">By tender</span>
            <span class="lp-dash-card-meta">how the till got paid</span>
        </div>
        @if (empty($tenders))
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'wallet',
                'title' => 'No settled tenders in this shift yet.',
                'sub'   => $isOpen ? 'Complete a sale from the terminal — it will land here.' : 'This shift finished with no tenders (possibly all comps).',
            ])
        @else
            <table class="lp-editorial-table">
                <thead>
                    <tr>
                        <th>Method</th>
                        <th style="text-align: right;">Count</th>
                        <th style="text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tenders as $t)
                        <tr>
                            <td class="lp-editorial-td-primary" style="text-transform: capitalize;">{{ $t['method'] }}</td>
                            <td class="lp-mono lp-editorial-td-num">{{ number_format($t['count']) }}</td>
                            <td class="lp-editorial-td-net">₦{{ number_format($t['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    {{-- ── Stock reconciliation ─────────────────────── --}}
    <section class="lp-dash-card" data-testid="shift-stock" style="padding: 14px 16px;">
        <div class="lp-dash-card-head" style="margin-bottom: 10px;">
            <span class="lp-dash-card-title">Stock reconciliation</span>
            <span class="lp-dash-card-meta">opening → sold → other → expected vs reported</span>
        </div>
        @if (empty($stock))
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'box',
                'title' => 'No tracked products on this shift.',
                'sub'   => 'Products without track_stock don\'t show up here.',
            ])
        @else
            <div style="overflow-x: auto;">
                <table class="lp-editorial-table" data-testid="shift-stock-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align: right;">Opening</th>
                            <th style="text-align: right;">Sold</th>
                            <th style="text-align: right;">Other ±</th>
                            <th style="text-align: right;">Expected close</th>
                            <th style="text-align: right;">Reported close</th>
                            <th style="text-align: right;">Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stock as $row)
                            @php
                                $variance = $row['variance'];
                                $varianceColor = 'var(--color-ink)';
                                if ($variance !== null) {
                                    if ($variance < 0) $varianceColor = '#B85C42';
                                    elseif ($variance > 0) $varianceColor = '#a36318';
                                    else $varianceColor = 'var(--color-accent-deep)';
                                }
                            @endphp
                            <tr>
                                <td class="lp-editorial-td-primary">{{ $row['name'] }}</td>
                                <td class="lp-mono lp-editorial-td-num">{{ number_format($row['opening_qty']) }}</td>
                                <td class="lp-mono lp-editorial-td-num" style="color: {{ $row['sold_qty'] > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};">
                                    {{ $row['sold_qty'] > 0 ? number_format($row['sold_qty']) : '—' }}
                                </td>
                                <td class="lp-mono lp-editorial-td-num" style="color: {{ $row['other_delta'] !== 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};">
                                    @if ($row['other_delta'] > 0) +@endif{{ $row['other_delta'] !== 0 ? number_format($row['other_delta']) : '—' }}
                                </td>
                                <td class="lp-mono lp-editorial-td-num">{{ number_format($row['expected_closing']) }}</td>
                                <td class="lp-mono lp-editorial-td-num">
                                    {{ $row['closing_qty'] !== null ? number_format($row['closing_qty']) : 'still open' }}
                                </td>
                                <td class="lp-mono lp-editorial-td-num" style="color: {{ $varianceColor }}; font-weight: 600;">
                                    @if ($variance === null)
                                        —
                                    @else
                                        @if ($variance > 0)+@endif{{ number_format($variance) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-[11.5px]" style="color: var(--color-ink-mute);">
                <strong>Expected close</strong> = opening − sold + other movements.
                <strong>Variance</strong> = reported − expected. Negative means missing stock, positive means surplus. Zero is clean.
            </p>
        @endif
    </section>
</div>
