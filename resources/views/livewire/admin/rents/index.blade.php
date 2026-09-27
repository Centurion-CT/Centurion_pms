<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Residential</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Rents &amp; leases
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Trailing {{ $windowDays }}-day rent collection across every residential tenant on the platform.
                Active leases + overdue debt are point-in-time totals.
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
                  style="color: var(--color-ink-mute);">Collected (window)</span>
            <p data-testid="stat-collected"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($stats['collected_ngn']) }}
            </p>
            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-top: 6px; display: inline-block;">
                {{ $stats['collection_pct'] }}% collection rate
            </span>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Overdue debt</span>
            <p data-testid="stat-overdue"
               class="lp-display mt-2 text-[28px]"
               style="color: {{ $stats['overdue_value'] > 0 ? '#8c1e1e' : 'var(--color-ink)' }}; font-weight: 600;">
                ₦{{ number_format($stats['overdue_value']) }}
            </p>
            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-top: 6px; display: inline-block;">
                {{ number_format($stats['overdue_count']) }} payment{{ $stats['overdue_count'] === 1 ? '' : 's' }}
            </span>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Active leases</span>
            <p data-testid="stat-active"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($stats['active_leases']) }}
            </p>
            @if ($stats['expiring_soon'] > 0)
                <span class="lp-tag lp-tag--checked-in" style="margin-top: 8px;">
                    {{ $stats['expiring_soon'] }} expiring &lt; 60d
                </span>
            @endif
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Open + upcoming</span>
            <p data-testid="stat-due-open"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink-soft); font-weight: 600;">
                ₦{{ number_format($stats['due_open_ngn']) }}
            </p>
            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-top: 6px; display: inline-block;">
                Not yet billed or due
            </span>
        </div>
    </div>

    {{-- ── Status mix ────────────────────────────────────────────── --}}
    @if ($statusMix->isNotEmpty())
        <section class="lp-dash-card" data-testid="status-mix">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Payment status mix</span>
                <span class="lp-dash-card-meta">All lease payments ever</span>
            </div>

            <div style="display: flex; height: 14px; border-radius: 999px; overflow: hidden; background: var(--color-line-soft);">
                @foreach ($statusMix as $entry)
                    @php
                        $color = match ($entry->status) {
                            'paid'             => 'var(--color-accent-deep, #6b7d2f)',
                            'due', 'upcoming'  => 'var(--color-accent, #a8b663)',
                            'overdue'          => '#b07474',
                            'waived'           => 'var(--color-ink-mute, #7A7F87)',
                            default            => 'var(--color-ink-mute)',
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
                            'paid'             => 'var(--color-accent-deep, #6b7d2f)',
                            'due', 'upcoming'  => 'var(--color-accent, #a8b663)',
                            'overdue'          => '#b07474',
                            'waived'           => 'var(--color-ink-mute, #7A7F87)',
                            default            => 'var(--color-ink-mute)',
                        };
                    @endphp
                    <li class="flex items-center gap-2">
                        <span style="display: inline-block; width: 9px; height: 9px; border-radius: 2px; background: {{ $color }};"></span>
                        <span style="color: var(--color-ink); font-weight: 500;">{{ $entry->status }}</span>
                        <span style="color: var(--color-ink-mute);">{{ $entry->count }} · {{ $entry->pct }}%</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ── Top landlords ─────────────────────────────────────────── --}}
    <section class="lp-dash-card" style="padding: 0;" data-testid="top-landlords">
        <div class="lp-dash-card-head" style="padding: 20px 24px;">
            <span class="lp-dash-card-title">Top landlords by rent collected</span>
            <span class="lp-dash-card-meta">Last {{ $windowDays }} days · top 10</span>
        </div>

        @if ($topLandlords->isEmpty())
            <p class="text-center" style="padding: 56px 24px; color: var(--color-ink-soft); font-size: 14px;"
               data-testid="empty">
                No rent collected in this window.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Tenant</th>
                            <th style="text-align: right;">Active leases</th>
                            <th style="text-align: right;">Payments</th>
                            <th style="text-align: right;">Collected</th>
                            <th style="text-align: right;">Overdue</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topLandlords as $i => $row)
                            <tr data-testid="top-row-{{ $i }}">
                                <td class="lp-mono"
                                    style="color: var(--color-ink-mute); font-size: 12px;">
                                    {{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}
                                </td>
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $row->tenant?->name ?? 'Tenant #?' }}
                                    @if ($row->tenant)
                                        <div class="lp-mono"
                                             style="color: var(--color-ink-mute); font-size: 11.5px; margin-top: 2px;">
                                            {{ $row->tenant->slug }}
                                        </div>
                                    @endif
                                </td>
                                <td style="text-align: right; color: var(--color-ink-soft);">
                                    {{ number_format($row->active_leases) }}
                                </td>
                                <td style="text-align: right; color: var(--color-ink-soft);">
                                    {{ number_format($row->collected_count) }}
                                </td>
                                <td style="text-align: right;">
                                    <span class="lp-table-amount">₦{{ number_format($row->collected) }}</span>
                                </td>
                                <td style="text-align: right;
                                           color: {{ $row->overdue > 0 ? '#8c1e1e' : 'var(--color-ink-mute)' }};
                                           font-size: 12.5px;">
                                    ₦{{ number_format($row->overdue) }}
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
