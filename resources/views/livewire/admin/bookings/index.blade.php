<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">GMV</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Bookings &amp; GMV
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Trailing {{ $windowDays }}-day booking volume across every tenant.
                Pending and cancelled bookings are excluded from GMV.
            </p>
        </div>

        {{-- Window selector. --}}
        <div class="flex gap-2" data-testid="window-picker">
            @foreach (['7', '30', '90', '365'] as $opt)
                <button type="button"
                        wire:click="setWindow('{{ $opt }}')"
                        class="lp-tag"
                        data-testid="window-{{ $opt }}"
                        style="cursor: pointer; padding: 6px 12px;
                               {{ (string) $windowDays === $opt
                                  ? 'background: var(--color-ink); color: var(--color-card); border-color: var(--color-ink);'
                                  : '' }}">
                    {{ $opt }} days
                </button>
            @endforeach
        </div>
    </div>

    {{-- ── Counters ──────────────────────────────────────────────── --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="stats">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">GMV</span>
            <p data-testid="stat-gmv"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($stats['gmv_ngn']) }}
            </p>
            <span class="lp-tag lp-tag--confirmed" style="margin-top: 8px;">Realised</span>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Bookings volume</span>
            <p data-testid="stat-volume"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($stats['total_volume']) }}
            </p>
            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-top: 6px; display: inline-block;">
                {{ number_format($stats['realised_volume']) }} realised
            </span>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Avg booking value</span>
            <p data-testid="stat-aov"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($stats['aov_ngn']) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Keverna fee captured</span>
            <p data-testid="stat-fees"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($stats['keverna_fees_ngn']) }}
            </p>
            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-top: 6px; display: inline-block;">
                {{ $stats['cancellation_pct'] }}% cancelled
            </span>
        </div>
    </div>

    {{-- ── Status mix ────────────────────────────────────────────── --}}
    @if ($statusMix->isNotEmpty())
        <section class="lp-dash-card" data-testid="status-mix">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Status mix</span>
                <span class="lp-dash-card-meta">Last {{ $windowDays }} days</span>
            </div>

            {{-- Single stacked-bar visualisation, cheap to render, easy to scan. --}}
            <div style="display: flex; height: 14px; border-radius: 999px; overflow: hidden; background: var(--color-line-soft);">
                @foreach ($statusMix as $entry)
                    @php
                        $color = match ($entry->status) {
                            'confirmed', 'checked_in', 'checked_out' => 'var(--color-accent-deep, #6b7d2f)',
                            'on_hold', 'pending'                     => 'var(--color-accent, #a8b663)',
                            'cancelled', 'no_show'                   => '#b07474',
                            default                                  => 'var(--color-ink-mute)',
                        };
                    @endphp
                    <div style="width: {{ $entry->pct }}%; background: {{ $color }};"
                         title="{{ $entry->status }} · {{ $entry->count }} · {{ $entry->pct }}%"></div>
                @endforeach
            </div>

            <ul class="flex flex-wrap gap-x-4 gap-y-2 mt-4 text-[12.5px]" style="color: var(--color-ink-soft);">
                @foreach ($statusMix as $entry)
                    @php
                        $color = match ($entry->status) {
                            'confirmed', 'checked_in', 'checked_out' => 'var(--color-accent-deep, #6b7d2f)',
                            'on_hold', 'pending'                     => 'var(--color-accent, #a8b663)',
                            'cancelled', 'no_show'                   => '#b07474',
                            default                                  => 'var(--color-ink-mute)',
                        };
                    @endphp
                    <li class="flex items-center gap-2">
                        <span style="display: inline-block; width: 9px; height: 9px; border-radius: 2px; background: {{ $color }};"></span>
                        <span style="color: var(--color-ink); font-weight: 500;">{{ str_replace('_', ' ', $entry->status) }}</span>
                        <span style="color: var(--color-ink-mute);">{{ $entry->count }} · {{ $entry->pct }}%</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ── Top tenants ───────────────────────────────────────────── --}}
    <section class="lp-dash-card" style="padding: 0;" data-testid="top-tenants">
        <div class="lp-dash-card-head" style="padding: 20px 24px;">
            <span class="lp-dash-card-title">Top tenants by GMV</span>
            <span class="lp-dash-card-meta">Last {{ $windowDays }} days · top 10</span>
        </div>

        @if ($topTenants->isEmpty())
            <p class="text-center" style="padding: 56px 24px; color: var(--color-ink-soft); font-size: 14px;"
               data-testid="empty">
                No realised bookings in this window.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Tenant</th>
                            <th style="text-align: right;">Bookings</th>
                            <th style="text-align: right;">GMV</th>
                            <th style="text-align: right;">Keverna fee</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topTenants as $i => $row)
                            <tr data-testid="top-row-{{ $i }}">
                                <td class="lp-mono"
                                    style="color: var(--color-ink-mute); font-size: 12px;">
                                    {{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}
                                </td>
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $row->tenant?->name ?? 'Tenant #'.($row->tenant?->id ?? '?') }}
                                    @if ($row->tenant)
                                        <div class="lp-mono"
                                             style="color: var(--color-ink-mute); font-size: 11.5px; margin-top: 2px;">
                                            {{ $row->tenant->slug }}
                                        </div>
                                    @endif
                                </td>
                                <td style="text-align: right; color: var(--color-ink-soft);">
                                    {{ number_format($row->bookings_count) }}
                                </td>
                                <td style="text-align: right;">
                                    <span class="lp-table-amount">₦{{ number_format($row->gmv) }}</span>
                                </td>
                                <td style="text-align: right; color: var(--color-ink-soft); font-size: 12.5px;">
                                    ₦{{ number_format($row->keverna_fees) }}
                                </td>
                                <td style="text-align: right;">
                                    @if ($row->tenant)
                                        <a href="{{ route('admin.tenants.show', $row->tenant) }}" wire:navigate
                                           data-testid="view-tenant-{{ $i }}"
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px; font-size: 12.5px;">
                                            View
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
