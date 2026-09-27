@php
    // Roll the daily series into ISO-week buckets so long ranges (YTD)
    // stay readable in the table.
    $weeks = [];
    foreach ($daily as $d) {
        try {
            $c = \Illuminate\Support\Carbon::parse($d['date']);
            $key = $c->isoWeekYear().'-W'.str_pad((string) $c->isoWeek(), 2, '0', STR_PAD_LEFT);
            $weekStart = $c->copy()->startOfWeek();
            $weekEnd = $c->copy()->endOfWeek();
        } catch (\Throwable) {
            continue;
        }

        if (! isset($weeks[$key])) {
            $weeks[$key] = [
                // Carry the iso-week key alongside so the row can
                // dispatch the exact same id Finance\Index used when
                // building the panel payload (`week:YYYY-Wnn`).
                'key'       => 'week:'.$key,
                'label'     => $weekStart->format('M j').' – '.$weekEnd->format('M j'),
                'billed'    => 0,
                'collected' => 0,
                'refunded'  => 0,
                'expenses'  => 0,
            ];
        }
        $weeks[$key]['billed']    += $d['billed'];
        $weeks[$key]['collected'] += $d['collected'];
        $weeks[$key]['refunded']  += $d['refunded'];
        $weeks[$key]['expenses']  += (int) ($d['expenses'] ?? 0);
    }
    $weeks = array_values(array_reverse($weeks));
@endphp

<section class="lp-dash-card" data-testid="finance-cashflow" style="padding: 14px 16px;">
    <div class="lp-dash-card-head" style="margin-bottom: 12px;">
        <span class="lp-dash-card-title">Cash flow</span>
        <span class="lp-dash-card-meta">weekly billed · collected · refunded · expenses · net</span>
    </div>

    @if (empty($weeks))
        @include('livewire.dashboard._partials._empty', [
            'icon'  => 'wallet',
            'title' => 'No activity in this window.',
            'sub'   => 'Widen the range to see previous weeks.',
        ])
    @else
        <div class="overflow-x-auto">
            <table class="lp-editorial-table">
                <thead>
                    <tr>
                        <th>Week</th>
                        <th style="text-align: right;">Billed</th>
                        <th style="text-align: right;">Collected</th>
                        <th style="text-align: right;">Refunded</th>
                        <th style="text-align: right;">Expenses</th>
                        <th style="text-align: right;">Net</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($weeks as $w)
                        @php
                            // Net now includes expenses so the last
                            // column reads as true cash retained after
                            // the week's outflows (refunds + money-out).
                            $net = $w['collected'] - $w['refunded'] - (int) $w['expenses'];
                        @endphp
                        <tr data-testid="finance-cashflow-row-{{ $w['key'] }}"
                            x-on:click="$dispatch('finance-breakdown-open', { id: '{{ $w['key'] }}' })">
                            <td class="lp-editorial-td-primary">{{ $w['label'] }}</td>
                            <td class="lp-mono lp-editorial-td-num">₦{{ number_format((int) $w['billed']) }}</td>
                            <td class="lp-mono lp-editorial-td-num">₦{{ number_format((int) $w['collected']) }}</td>
                            <td class="lp-mono lp-editorial-td-num" style="color: {{ $w['refunded'] > 0 ? '#B85C42' : 'var(--color-ink-mute)' }};">
                                ₦{{ number_format((int) $w['refunded']) }}
                            </td>
                            <td class="lp-mono lp-editorial-td-num" style="color: {{ $w['expenses'] > 0 ? '#F97316' : 'var(--color-ink-mute)' }};">
                                ₦{{ number_format((int) $w['expenses']) }}
                            </td>
                            <td class="lp-editorial-td-net" style="color: {{ $net < 0 ? '#B85C42' : 'var(--color-ink)' }};">
                                @if ($net < 0)−@endif₦{{ number_format(abs((int) $net)) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
