@php
    $filters = [
        ['key' => 'unresolved', 'label' => 'Payout owed'],
        ['key' => 'resolved',   'label' => 'Since resolved'],
        ['key' => 'all',        'label' => 'All'],
    ];
@endphp

<div class="lp-dash">

    <div>
        <span class="lp-eyebrow-strip">Ops</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Paystack fallback payouts
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Bookings whose guests paid into Keverna&rsquo;s own Paystack account because the operator hadn&rsquo;t connected their own.
            Every row here needs a manual bank transfer to the operator (or a nudge to complete their Paystack setup).
        </p>
    </div>

    {{-- ── KPI strip ────────────────────────────────────────────── --}}
    <div class="grid gap-3 grid-cols-2 lg:grid-cols-4" data-testid="fallback-kpis">
        <div class="lp-kpi">
            <span class="label">Owed to tenants</span>
            <span class="value" data-testid="fallback-kpi-tenants">{{ number_format($kpi['tenants']) }}</span>
            <span class="sub">payout runs pending</span>
        </div>
        <div class="lp-kpi">
            <span class="label">Amount to disburse</span>
            <span class="value" data-testid="fallback-kpi-amount">&#8358;{{ number_format($kpi['amount']) }}</span>
            <span class="sub">succeeded payments only</span>
        </div>
        <div class="lp-kpi">
            <span class="label">Fallback bookings</span>
            <span class="value">{{ number_format($kpi['bookings']) }}</span>
            <span class="sub">across all listed tenants</span>
        </div>
        <div class="lp-kpi">
            <span class="label">Tenants w/o Paystack</span>
            <span class="value" data-testid="fallback-kpi-unpaid-tenants">{{ number_format($kpi['unpaid_tenants']) }}</span>
            <span class="sub">will fall back on next booking</span>
        </div>
    </div>

    {{-- ── Filter chips ─────────────────────────────────────────── --}}
    <div class="lp-chips" data-testid="fallback-filter">
        @foreach ($filters as $f)
            <button type="button"
                    wire:click="$set('viewFilter', '{{ $f['key'] }}')"
                    class="lp-chip @if ($viewFilter === $f['key']) is-active @endif"
                    data-testid="fallback-filter-{{ $f['key'] }}">
                {{ $f['label'] }}
            </button>
        @endforeach
    </div>

    {{-- ── Per-tenant table ────────────────────────────────────── --}}
    <section class="lp-dash-card">
        @if (empty($rows))
            <p class="text-[13.5px]" data-testid="fallback-empty" style="color: var(--color-ink-mute);">
                No fallback bookings for this filter. Nice, every tenant is settling directly.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Paystack setup</th>
                            <th style="text-align: right;">Succeeded bookings</th>
                            <th style="text-align: right;">Amount owed</th>
                            <th style="text-align: right;">Attempted (incl. abandoned)</th>
                            <th>Latest fallback</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-testid="fallback-row-{{ $row['tenant_slug'] }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $row['tenant_name'] }}
                                    <span class="lp-mono text-[10.5px]" style="display:block; color: var(--color-ink-mute);">
                                        {{ $row['tenant_slug'] }}
                                    </span>
                                </td>
                                <td>
                                    @if ($row['has_own_paystack'])
                                        <span class="lp-mono text-[10.5px]"
                                              style="padding: 3px 8px; border-radius: 999px; background: var(--color-accent-soft, rgba(173, 203, 82,0.18)); color: var(--color-accent-deep); letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;">
                                            Connected
                                        </span>
                                    @else
                                        <span class="lp-mono text-[10.5px]"
                                              style="padding: 3px 8px; border-radius: 999px; background: rgba(224, 122, 95, 0.14); color: #B85C42; letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;">
                                            Fallback active
                                        </span>
                                    @endif
                                </td>
                                <td style="text-align: right;" class="lp-mono">
                                    {{ number_format($row['succeeded_bookings']) }}
                                </td>
                                <td style="text-align: right;">
                                    <span class="lp-table-amount">&#8358;{{ number_format($row['succeeded_amount']) }}</span>
                                </td>
                                <td style="text-align: right; color: var(--color-ink-mute);" class="lp-mono text-[11.5px]">
                                    {{ number_format($row['total_bookings']) }} &middot; &#8358;{{ number_format($row['total_amount']) }}
                                </td>
                                <td class="lp-mono text-[11.5px]" style="color: var(--color-ink-soft);">
                                    {{ optional($row['latest_at'])->diffForHumans() ?? '-' }}
                                </td>
                                <td>
                                    <a href="{{ route('admin.tenants.show', $row['tenant_slug']) }}"
                                       wire:navigate
                                       class="lp-mono text-[11px]"
                                       style="color: var(--color-accent-deep); text-decoration: underline; letter-spacing: 0.04em;">
                                        Open →
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <p class="text-[12.5px]" style="color: var(--color-ink-mute); line-height: 1.55;">
        <strong style="color: var(--color-ink); font-weight: 500;">How this works:</strong>
        every time a guest checks out on a property whose operator hasn&rsquo;t connected their Paystack subaccount, we route the payment to Keverna&rsquo;s own account so the booking never breaks.
        The <em>Amount owed</em> column sums only <em>Succeeded</em> Payment rows. Abandoned checkouts don&rsquo;t count.
        Once you&rsquo;ve made a manual bank transfer to the operator, chase them to connect their subaccount so future bookings settle automatically.
    </p>
</div>
