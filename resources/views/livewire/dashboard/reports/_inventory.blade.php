{{-- ── KPI tiles ────────────────────────────────────────────────── --}}
<div class="grid gap-3 grid-cols-2 lg:grid-cols-4" data-testid="inventory-kpis">
    <div class="lp-kpi">
        <span class="label">Rooms</span>
        <span class="value">{{ number_format((int) $d['rooms']['total']) }}</span>
        <span class="sub">
            {{ $d['rooms']['active'] }} active
            @if ($d['rooms']['inactive'] > 0)
                · {{ $d['rooms']['inactive'] }} inactive
            @endif
        </span>
    </div>
    <div class="lp-kpi">
        <span class="label">Categories</span>
        <span class="value">{{ number_format((int) $d['categories']['total']) }}</span>
        <span class="sub">{{ $d['categories']['active'] }} active</span>
    </div>
    <div class="lp-kpi" style="background: {{ $d['rooms']['inactive'] > 0 ? 'rgba(168, 101, 10, 0.10)' : 'var(--color-card-deep)' }};">
        <span class="label">Out of service</span>
        <span class="value" style="color: {{ $d['rooms']['inactive'] > 0 ? '#A8650A' : 'var(--color-ink)' }};">
            {{ number_format((int) $d['rooms']['inactive']) }}
        </span>
        <span class="sub">inactive rooms</span>
    </div>
    <div class="lp-kpi" style="background: {{ count($d['issues']) > 0 ? 'rgba(168, 101, 10, 0.10)' : 'var(--color-card-deep)' }};">
        <span class="label">Issues</span>
        <span class="value" style="color: {{ count($d['issues']) > 0 ? '#A8650A' : 'var(--color-ink)' }};">
            {{ count($d['issues']) }}
        </span>
        <span class="sub">things to fix</span>
    </div>
</div>

{{-- ── Issues ────────────────────────────────────────────────── --}}
@if (count($d['issues']) > 0)
    <section class="lp-dash-card" data-testid="inventory-issues"
             style="background: rgba(168, 101, 10, 0.06); border-color: rgba(168, 101, 10, 0.25);">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title" style="color: #A8650A;">Things to fix</span>
            <span class="lp-dash-card-meta">click through to address each</span>
        </div>
        <ul class="list-none p-0 m-0" style="display: grid; gap: 10px;">
            @foreach ($d['issues'] as $issue)
                <li data-testid="issue-row" class="flex items-start justify-between gap-4"
                    style="padding: 12px 14px; background: var(--color-card); border: 1px solid var(--color-line-soft); border-radius: 10px;">
                    <div>
                        <div style="font-family: var(--font-display); font-weight: 600; font-size: 14px; color: var(--color-ink); letter-spacing: -0.01em;">
                            {{ $issue['label'] }}
                        </div>
                        <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                            {{ $issue['detail'] }}
                        </p>
                    </div>
                    <a href="{{ route('tenant.room-categories.edit', $issue['category_id']) }}" wire:navigate
                       class="lp-pill-sm" style="white-space: nowrap;">
                        Fix
                        <span class="dot">
                            <svg width="10" height="10" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif

{{-- ── Category roster ──────────────────────────────────────────── --}}
<section class="lp-dash-card" data-testid="inventory-by-category">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">Category roster</span>
        <span class="lp-dash-card-meta">every class on this property</span>
    </div>
    @if (empty($d['byCategory']))
        <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
            No categories defined yet.
            <a href="{{ route('tenant.room-categories.create') }}" wire:navigate
               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                Create one
            </a>
            to start grouping your rooms.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="lp-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Base rate</th>
                        <th>Rooms</th>
                        <th>Photos</th>
                        <th>Status</th>
                        <th style="text-align: right;">Manage</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['byCategory'] as $row)
                        @php
                            $statusClass = $row['status'] === 'active' ? 'lp-tag--confirmed' : 'lp-tag--checked-out';
                        @endphp
                        <tr>
                            <td style="color: var(--color-ink); font-weight: 500;">
                                {{ $row['name'] }}
                                @if (! empty($row['type']))
                                    <span class="text-[11px] mt-0.5" style="display:block; color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">{{ $row['type'] }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="lp-table-amount">₦{{ number_format((int) $row['base_rate']) }}</span>
                            </td>
                            <td style="color: var(--color-ink-soft);">
                                <span class="lp-mono text-[12px]" style="color: var(--color-ink);">
                                    {{ $row['active_rooms'] }}
                                </span>
                                @if ($row['inactive_rooms'] > 0)
                                    <span class="text-[11px]" style="color: var(--color-ink-mute); margin-left: 4px;">
                                        · {{ $row['inactive_rooms'] }} inactive
                                    </span>
                                @endif
                            </td>
                            <td>
                                <span class="lp-mono text-[12px]" style="color: {{ $row['photos'] === 0 ? '#A8650A' : 'var(--color-ink)' }};">
                                    {{ $row['photos'] }}
                                </span>
                            </td>
                            <td>
                                <span class="lp-tag {{ $statusClass }}">{{ ucfirst($row['status']) }}</span>
                            </td>
                            <td style="text-align: right;">
                                <a href="{{ route('tenant.room-categories.edit', $row['id']) }}" wire:navigate
                                   class="text-[12.5px]"
                                   style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
