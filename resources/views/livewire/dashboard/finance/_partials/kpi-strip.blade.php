@php
    // Delta helper, returns [label, color]. Delta rides on the sub-
    // label row of each tile so we keep the pulse-strip vocabulary
    // without losing the period-over-period signal Finance actually
    // needs.
    $delta = function (?float $d): array {
        if ($d === null) return ['label' => 'no prior data', 'color' => 'var(--color-ink-mute)'];
        $sign = $d >= 0 ? '+' : '';
        $color = $d >= 0 ? 'var(--color-accent-deep)' : '#B85C42';
        return ['label' => $sign.$d.'% vs prior', 'color' => $color];
    };
    $billedDelta = $delta($k['billedDelta']);
    $collectedDelta = $delta($k['collectedDelta']);

    // Net cash retained after refunds - what actually stayed in the
    // till. `collected` is gross so the tile below shows the net
    // number as its headline and folds the gross + refund figures
    // into the sub-label. Matches Payments' Net payout, Summary's
    // Total revenue, and Dashboard's Revenue MTD one-for-one.
    $netCollected = max(0, (int) $k['collected'] - (int) $k['refunded']);

    // `openKey` tiles are clickable - wire:click="openKpi(key)"
     // fires a server round-trip that hydrates the detail panel.
     // The kpi-panel partial renders a centered spinner while the
     // trip resolves so the click has visual feedback.
    $finTiles = [
        [
            'label' => 'Billed', 'sub' => $billedDelta['label'], 'subColor' => $billedDelta['color'],
            'value' => '₦'.number_format($k['billed']),
            'dot' => 'var(--color-accent-deep)', 'color' => 'var(--color-ink)',
            'testid' => 'kpi-billed',
            'openKey' => 'billed',
        ],
        [
            'label' => 'Net collected',
            'sub' => $k['refunded'] > 0
                ? 'gross ₦'.number_format($k['collected']).' · after ₦'.number_format($k['refunded']).' refunds'
                : $collectedDelta['label'],
            'subColor' => $k['refunded'] > 0 ? 'var(--color-ink-mute)' : $collectedDelta['color'],
            'value' => '₦'.number_format($netCollected),
            'dot' => 'var(--color-accent-deep)', 'color' => 'var(--color-ink)',
            'testid' => 'kpi-collected',
            'openKey' => 'collected',
        ],
        [
            'label' => 'Outstanding', 'sub' => 'open bookings, right now',
            'value' => '₦'.number_format($k['outstanding']),
            'dot' => $k['outstanding'] > 0 ? '#a36318' : 'var(--color-accent-deep)',
            'color' => $k['outstanding'] > 0 ? '#a36318' : 'var(--color-ink)',
            'testid' => 'kpi-outstanding',
            'openKey' => 'outstanding',
        ],
        [
            'label' => 'Refunds', 'sub' => 'issued in window',
            'value' => '₦'.number_format($k['refunded']),
            'dot' => $k['refunded'] > 0 ? '#B85C42' : 'var(--color-accent-deep)',
            'color' => $k['refunded'] > 0 ? '#B85C42' : 'var(--color-ink)',
            'testid' => 'kpi-refunds',
            'openKey' => 'refunded',
        ],
        [
            // Expenses in-window - bridges Finance ↔ Accounting so
            // the operator sees money-out on the same page they see
            // money-in. Clicking navigates to Accounting → Expenses
            // for the full breakdown + management.
            'label' => 'Expenses',
            'sub'   => 'logged this window · this branch + HQ',
            'value' => '₦'.number_format($k['expenses'] ?? 0),
            'dot'   => ($k['expenses'] ?? 0) > 0 ? '#F97316' : 'var(--color-accent-deep)',
            'color' => 'var(--color-ink)',
            'testid'=> 'kpi-expenses',
            'href'  => route('tenant.accounting.expenses.index'),
        ],
        [
            // Net profit = collected − refunds − expenses (cash basis).
            // Green when positive, red when the property spent more
            // than it made in the window. Clicking opens the P&L page
            // where the split by category + monthly trend live.
            'label' => 'Net profit',
            'sub'   => 'cash basis · collected − refunds − expenses',
            'value' => (($k['netProfit'] ?? 0) < 0 ? '−' : '')
                       .'₦'.number_format(abs((int) ($k['netProfit'] ?? 0))),
            'dot'   => ($k['netProfit'] ?? 0) < 0 ? '#B85C42' : 'var(--color-accent-deep)',
            'color' => ($k['netProfit'] ?? 0) < 0 ? '#B85C42' : 'var(--color-ink)',
            'testid'=> 'kpi-net-profit',
            'href'  => route('tenant.accounting.profit-loss.index'),
        ],
        [
            'label' => 'Refunds pending',
            'sub' => number_format($k['pendingRefundsCount'] ?? 0).' booking'.(($k['pendingRefundsCount'] ?? 0) === 1 ? '' : 's').' · not issued',
            'value' => '₦'.number_format($k['pendingRefunds'] ?? 0),
            'dot' => ($k['pendingRefunds'] ?? 0) > 0 ? '#A8650A' : 'var(--color-accent-deep)',
            'color' => ($k['pendingRefunds'] ?? 0) > 0 ? '#A8650A' : 'var(--color-ink)',
            'testid' => 'kpi-refunds-pending',
            'href'  => route('tenant.refunds.index'),
        ],
        [
            'label' => 'Avg booking', 'sub' => number_format($k['bookings']).' booking'.($k['bookings'] === 1 ? '' : 's'),
            'value' => '₦'.number_format($k['avgBookingValue']),
            'dot' => '#4B7BB0', 'color' => 'var(--color-ink)',
            'testid' => 'kpi-avg-value',
        ],
        [
            'label' => 'Forecast · '.$k['forecastHorizon'].'d',
            'sub' => '₦'.number_format($k['forecastOnBooks']).' on-books'
                . ($k['forecastTotal'] > $k['forecastOnBooks'] ? ' · rest est.' : ''),
            'value' => '₦'.number_format($k['forecastTotal']),
            'dot' => '#7A6EA7', 'color' => 'var(--color-ink)',
            'testid' => 'kpi-forecast',
            'openKey' => 'forecast',
        ],
        [
            'label' => 'Deposits on file',
            'sub' => number_format($k['fundedWallets'] ?? 0).' wallet'.(($k['fundedWallets'] ?? 0) === 1 ? '' : 's').' · open roster',
            'value' => '₦'.number_format($k['depositsOnFile'] ?? 0),
            'dot' => 'var(--color-accent-deep)', 'color' => 'var(--color-ink)',
            'testid' => 'kpi-deposits',
            'href'  => route('tenant.finance.wallets'),
        ],
    ];
@endphp

<div class="grid gap-2 grid-cols-2 md:grid-cols-4" data-testid="finance-kpis">
    @foreach ($finTiles as $tile)
        @php
            // Priority: `openKey` (wire:click for the KPI panel) →
            // `href` (nav link, e.g. Deposits → Wallets page) → plain div.
            $hasOpen = ! empty($tile['openKey']);
            $hasHref = ! empty($tile['href']);
            $Tag = ($hasOpen || $hasHref) ? ($hasOpen ? 'button' : 'a') : 'div';
        @endphp
        <{{ $Tag }}
            @if ($hasOpen)
                type="button"
                wire:click="openKpi('{{ $tile['openKey'] }}')"
            @elseif ($hasHref) href="{{ $tile['href'] }}" wire:navigate @endif
            class="lp-dash-card"
            data-testid="{{ $tile['testid'] }}"
            style="padding: 14px 16px; display: flex; align-items: center; gap: 12px;
                   {{ $hasOpen || $hasHref ? 'cursor: pointer;' : '' }}
                   {{ $hasOpen ? 'text-align: left; font: inherit; border: 1px solid var(--color-line-soft);' : '' }}">
            <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                      background: {{ $tile['dot'] }};
                                      box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                      flex-shrink: 0;"></span>
            <div style="min-width: 0; flex: 1;">
                <p class="lp-num-lg" style="font-size: 18px; color: {{ $tile['color'] }};">
                    {{ $tile['value'] }}
                </p>
                <p class="lp-mono text-[10px] tracking-[0.12em] uppercase"
                   style="color: var(--color-ink-mute); margin-top: 2px; overflow: hidden; text-overflow: ellipsis;">
                    {{ $tile['label'] }}
                </p>
                <p class="text-[10.5px]"
                   style="color: {{ $tile['subColor'] ?? 'var(--color-ink-mute)' }};
                          margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    {{ $tile['sub'] }}
                </p>
            </div>
        </{{ $Tag }}>
    @endforeach
</div>
