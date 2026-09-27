<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Store</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Reconciliation
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft); max-width: 720px;">
            What the store received, what it issued to each outlet, what those
            outlets sold, and the variance. Answers "are we losing money?" at a
            glance.
        </p>
    </div>

    @if (! $centralStore)
        <section class="lp-dash-card" style="margin-top: 20px;">
            <p style="color: var(--color-ink-soft); font-size: 14px;">
                This branch runs the per-department stock model. There's no central store
                to reconcile. Switch to the "central store + requisition" model in
                <a href="{{ route('tenant.settings.property') }}"
                   style="color: var(--color-ink); text-decoration: underline;">Property settings</a>
                to use this report.
            </p>
        </section>
    @elseif ($store === null)
        <section class="lp-dash-card" style="margin-top: 20px;">
            <p style="color: var(--color-ink-soft); font-size: 14px;">
                No store set up for this branch yet.
            </p>
        </section>
    @else
        {{-- ── Date range ────────────────────────────────────────── --}}
        <div class="flex flex-wrap items-end gap-3 mt-4" data-testid="recon-date-picker">
            <div>
                <label class="lp-form-label" for="recon-from">From</label>
                <input id="recon-from" type="date" wire:model.live="from"
                       class="lp-input lp-mono" data-testid="recon-from">
            </div>
            <div>
                <label class="lp-form-label" for="recon-to">To</label>
                <input id="recon-to" type="date" wire:model.live="to"
                       class="lp-input lp-mono" data-testid="recon-to">
            </div>
        </div>

        {{-- ── KPI strip ─────────────────────────────────────────── --}}
        <div class="grid gap-3 grid-cols-2 lg:grid-cols-4" data-testid="recon-kpis" style="margin-top: 16px;">
            <div class="lp-kpi">
                <span class="label">Received</span>
                <span class="value">₦{{ number_format((int) $data['totals']['received']['value']) }}</span>
                <span class="sub">{{ number_format((int) $data['totals']['received']['qty']) }} unit(s) in</span>
            </div>
            <div class="lp-kpi">
                <span class="label">Issued to outlets</span>
                <span class="value">₦{{ number_format((int) $data['totals']['issued']['value']) }}</span>
                <span class="sub">{{ number_format((int) $data['totals']['issued']['qty']) }} unit(s) out</span>
            </div>
            <div class="lp-kpi">
                <span class="label">Outlet POS sales</span>
                <span class="value">₦{{ number_format((int) $data['totals']['sold']['value']) }}</span>
                <span class="sub">{{ number_format((int) $data['totals']['sold']['qty']) }} unit(s) sold</span>
            </div>
            <div class="lp-kpi">
                <span class="label">Variance</span>
                @php $variance = (int) $data['totals']['variance']['value']; @endphp
                <span class="value" style="color: {{ $variance === 0 ? 'var(--color-ink)' : ($variance < 0 ? '#B85C42' : 'var(--color-accent-deep)') }};">
                    ₦{{ number_format(abs($variance)) }}
                </span>
                <span class="sub">
                    {{ $variance === 0 ? 'clean sweep' : ($variance < 0 ? 'short' : 'over') }}
                    ({{ number_format(abs((int) $data['totals']['variance']['qty'])) }} unit(s))
                </span>
            </div>
        </div>

        {{-- ── Per-product table ────────────────────────────────── --}}
        <section class="lp-dash-card" style="margin-top: 16px;">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Per-product</span>
                <span class="lp-dash-card-meta">
                    {{ $data['window']['from'] }} → {{ $data['window']['to'] }}
                </span>
            </div>

            @if (empty($data['rows']))
                <p style="color: var(--color-ink-mute); font-size: 13.5px;">
                    No store products yet. Add products in
                    <a href="{{ route('tenant.store.index') }}"
                       style="color: var(--color-ink-soft); text-decoration: underline;">Store</a>
                    first.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="lp-table" data-testid="recon-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="text-align: right;">Opening</th>
                                <th style="text-align: right;">Received</th>
                                @foreach ($data['outlets'] as $out)
                                    <th style="text-align: right;">Issued → {{ $out['name'] }}</th>
                                @endforeach
                                @foreach ($data['outlets'] as $out)
                                    <th style="text-align: right;">Sold @ {{ $out['name'] }}</th>
                                @endforeach
                                <th style="text-align: right;">Expected</th>
                                <th style="text-align: right;">Variance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['rows'] as $row)
                                <tr data-testid="recon-row-{{ $row['product_id'] }}">
                                    <td style="color: var(--color-ink); font-weight: 500;">
                                        {{ $row['name'] }}
                                        @if ($row['sku'])
                                            <div class="lp-mono text-[11px] mt-0.5" style="color: var(--color-ink-mute);">
                                                {{ $row['sku'] }}
                                            </div>
                                        @endif
                                    </td>
                                    <td style="text-align: right; font-family: 'SF Mono', monospace;">
                                        {{ number_format((int) $row['opening']) }}
                                    </td>
                                    <td style="text-align: right; font-family: 'SF Mono', monospace;">
                                        {{ number_format((int) $row['received']['qty']) }}
                                        <div class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); margin-top: 2px;">
                                            ₦{{ number_format((int) $row['received']['value']) }}
                                        </div>
                                    </td>
                                    @foreach ($data['outlets'] as $out)
                                        @php $issued = $row['issued']['per_outlet'][$out['id']] ?? ['qty' => 0, 'value' => 0]; @endphp
                                        <td style="text-align: right; font-family: 'SF Mono', monospace; color: var(--color-ink-soft);">
                                            {{ number_format((int) $issued['qty']) }}
                                        </td>
                                    @endforeach
                                    @foreach ($data['outlets'] as $out)
                                        @php $sold = $row['sold']['per_outlet'][$out['id']] ?? ['qty' => 0, 'value' => 0]; @endphp
                                        <td style="text-align: right; font-family: 'SF Mono', monospace; color: var(--color-ink-soft);">
                                            {{ number_format((int) $sold['qty']) }}
                                            @if ((int) $sold['value'] > 0)
                                                <div class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); margin-top: 2px;">
                                                    ₦{{ number_format((int) $sold['value']) }}
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td style="text-align: right; font-family: 'SF Mono', monospace;">
                                        {{ number_format((int) $row['expected_closing']) }}
                                    </td>
                                    <td style="text-align: right; font-family: 'SF Mono', monospace;">
                                        @if ($row['physical_closing'] === null)
                                            <span style="color: var(--color-ink-mute); font-size: 11.5px;">no count</span>
                                        @else
                                            @php $v = (int) $row['variance']['qty']; @endphp
                                            <span style="color: {{ $v === 0 ? 'var(--color-ink)' : ($v < 0 ? '#B85C42' : 'var(--color-accent-deep)') }};">
                                                {{ $v > 0 ? '+' : '' }}{{ number_format($v) }}
                                            </span>
                                            @if ((int) $row['variance']['value'] !== 0)
                                                <div class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); margin-top: 2px;">
                                                    ₦{{ number_format(abs((int) $row['variance']['value'])) }}
                                                </div>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <p class="text-[11.5px] mt-3" style="color: var(--color-ink-mute); max-width: 720px;">
            Variance uses physical counts when the daily stock sheet has one saved for the
            period. Rows without a physical count show "no count" and are treated as
            balanced for the KPI totals. For a hard reconciliation, do a physical count
            first.
        </p>
    @endif
</div>
