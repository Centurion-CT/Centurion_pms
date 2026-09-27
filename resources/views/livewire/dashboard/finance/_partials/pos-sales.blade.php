@php
    $delta = $posSummary['delta'];
    $deltaLabel = $delta === null
        ? 'no prior data'
        : (($delta >= 0 ? '+' : '').$delta.'% vs prior');
    $deltaColor = $delta === null ? 'var(--color-ink-mute)' : ($delta >= 0 ? 'var(--color-accent-deep)' : '#B85C42');
@endphp

<section class="lp-dash-card" data-testid="finance-pos-summary">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">POS sales</span>
        <span class="lp-dash-card-meta">
            bar, restaurant, laundry &middot;
            <a href="{{ route('tenant.pos.sales.index') }}" wire:navigate style="color: var(--color-accent-deep); text-decoration: underline;">
                full sales ledger
            </a>
        </span>
    </div>

    {{-- ── POS pulse mini-strip ───────────────────────────────── --}}
    @php
        $posTiles = [
            [
                'label' => 'POS revenue', 'sub' => $deltaLabel, 'subColor' => $deltaColor,
                'value' => '₦'.number_format($posSummary['total']),
                'dot' => 'var(--color-accent-deep)', 'color' => 'var(--color-ink)',
                'testid' => 'pos-kpi-total',
            ],
            [
                'label' => 'Sales', 'sub' => 'completed transactions',
                'value' => number_format($posSummary['count']),
                'dot' => '#4B7BB0', 'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Avg ticket', 'sub' => 'revenue ÷ sales',
                'value' => '₦'.number_format($posSummary['avgTicket']),
                'dot' => '#7A6EA7', 'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Charge-to-room',
                'sub' => '₦'.number_format($posSummary['chargeToRoomTotal']).' on folios · ₦'.number_format($posSummary['directTotal']).' direct',
                'value' => $posSummary['chargeToRoomShare'].'%',
                'dot' => 'var(--color-accent-deep)', 'color' => 'var(--color-ink)',
                'testid' => 'pos-kpi-split',
            ],
        ];

        // Complimentary rollup surfaces only when there's anything to
        // show — a quiet window doesn't need a "₦0 comped" tile taking
        // up the operator's eye.
        $compValue = (int) ($posSummary['complimentaryValue'] ?? 0);
        $compCount = (int) ($posSummary['complimentaryCount'] ?? 0);
        if ($compValue > 0 || $compCount > 0) {
            $posTiles[] = [
                'label' => 'Complimentary',
                'sub' => number_format($compCount).' '.Str::plural('sale', $compCount).' on the house',
                'value' => '₦'.number_format($compValue),
                'dot' => '#F97316', 'color' => 'var(--color-ink)',
                'testid' => 'pos-kpi-comp',
            ];
        }
    @endphp
    <div class="grid gap-2 grid-cols-2 lg:grid-cols-4 mb-4" data-testid="pos-kpis">
        @foreach ($posTiles as $tile)
            <div class="lp-dash-card"
                 @if ($tile['testid'] ?? null) data-testid="{{ $tile['testid'] }}" @endif
                 style="padding: 14px 16px; display: flex; align-items: center; gap: 12px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg" style="font-size: 20px; color: {{ $tile['color'] }};">
                        {{ $tile['value'] }}
                    </p>
                    <p class="lp-mono text-[10px] tracking-[0.12em] uppercase"
                       style="color: var(--color-ink-mute); margin-top: 2px;">
                        {{ $tile['label'] }}
                    </p>
                    <p class="text-[10.5px]"
                       style="color: {{ $tile['subColor'] ?? 'var(--color-ink-mute)' }};
                              margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        {{ $tile['sub'] }}
                    </p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── By-outlet stack ─────────────────────────────── --}}
    @if (empty($posByOutlet))
        <div data-testid="pos-outlets-empty">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'wallet',
                'title' => 'No POS activity in this window.',
                'sub'   => 'Once your bar or kitchen rings a sale it will show up here.',
            ])
        </div>
    @else
        @php
            $topOutletRevenue = collect($posByOutlet)->max('revenue') ?: 1;
        @endphp
        <ul class="lp-ledger lp-channel-stack">
            @foreach ($posByOutlet as $row)
                @php
                    $isLeader = (int) $row['revenue'] === (int) $topOutletRevenue && (int) $topOutletRevenue > 0;
                    $markerTone = $isLeader ? 'paid' : 'info';
                @endphp
                <li class="lp-ledger-item" data-testid="pos-outlet-row-{{ $row['outlet_id'] }}">
                    <span class="lp-ledger-marker lp-ledger-marker--{{ $markerTone }}" aria-hidden></span>
                    <div class="lp-ledger-body">
                        <div class="lp-gr-primary">
                            <span class="lp-ledger-desc" style="font-weight: 600; color: var(--color-ink);">
                                {{ $row['outlet_name'] }}
                            </span>
                            <span class="lp-category-type">{{ $row['outlet_type'] }}</span>
                            @if ($isLeader)
                                <span class="lp-tag lp-tag--confirmed" style="font-size: 9.5px;">Top</span>
                            @endif
                        </div>
                        <p class="lp-ledger-meta">
                            <span class="lp-mono">{{ number_format($row['sales_count']) }}</span>
                            {{ Str::plural('sale', (int) $row['sales_count']) }}
                            <span style="color: var(--color-ink-mute);">·</span>
                            avg <span class="lp-mono">₦{{ number_format($row['avg_ticket']) }}</span>
                            <span style="color: var(--color-ink-mute);">·</span>
                            <span class="lp-channel-bar" aria-hidden>
                                <span class="lp-channel-bar-fill" style="width: {{ $row['share'] }}%;"></span>
                            </span>
                            <span class="lp-mono" style="color: var(--color-ink-soft);">{{ $row['share'] }}%</span>
                        </p>
                    </div>
                    <div class="lp-ledger-right">
                        <span class="lp-invoice-total">₦{{ number_format($row['revenue']) }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

@if (! empty($posTopProducts))
    <section class="lp-dash-card" data-testid="pos-top-products" style="padding: 14px 16px;">
        <div class="lp-dash-card-head" style="margin-bottom: 12px;">
            <span class="lp-dash-card-title">Top-selling products</span>
            <span class="lp-dash-card-meta">by revenue in this window</span>
        </div>
        <ul class="lp-ledger">
            @foreach ($posTopProducts as $idx => $p)
                @php $tone = $idx === 0 ? 'paid' : ($idx < 3 ? 'info' : 'muted'); @endphp
                <li class="lp-ledger-item">
                    <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                    <div class="lp-ledger-body">
                        <div class="lp-gr-primary">
                            <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 11px;">
                                #{{ $idx + 1 }}
                            </span>
                            <span class="lp-ledger-desc" style="font-weight: 500; color: var(--color-ink);">
                                {{ $p['product_name'] }}
                            </span>
                            <span class="lp-category-type">{{ $p['outlet_name'] }}</span>
                        </div>
                        <p class="lp-ledger-meta">
                            <span class="lp-mono">{{ number_format($p['units']) }}</span>
                            {{ Str::plural('unit', (int) $p['units']) }} sold
                        </p>
                    </div>
                    <div class="lp-ledger-right">
                        <span class="lp-invoice-total">₦{{ number_format($p['revenue']) }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif
