@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Contracts</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Leases
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Signed residential tenancy contracts. Each lease auto-generates its rent schedule.
            </p>
        </div>
        <a href="{{ route('tenant.leases.create') }}" wire:navigate
           class="lp-pill" data-testid="create-link">
            New lease <span class="lp-pill-dot">{!! $pillArrow !!}</span>
        </a>
    </div>

    <div class="grid gap-3 sm:grid-cols-3" data-testid="stats">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Active leases</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">{{ number_format($stats['active']) }}</p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Expiring &lt; 60 days</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">{{ number_format($stats['expiring_soon']) }}</p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Annual rent roll</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">₦{{ number_format($stats['annual_roll']) }}</p>
        </div>
    </div>
    {{-- ── 12-month rent-roll bar chart ────────────────────────────
         Rolling window: last 6 months + current + next 5 months. The
         current month glows olive so an operator scans "am I above or
         below the trend line?" at a glance. Sums the expected
         monthly rent across every Active lease that overlaps each
         month, so a lease starting mid-window pops into the bar the
         month it begins. Rendered as inline SVG so it stays snappy
         and prints cleanly (no canvas / no external chart lib). --}}
    @php
        $maxBar = max(1, ...array_map(fn ($b) => $b['amount'], $rentRollBars));
        $totalRoll = array_sum(array_map(fn ($b) => $b['amount'], $rentRollBars));
    @endphp
    <section class="lp-dash-card" data-testid="rent-roll-chart" style="padding: 20px 22px;">
        <div class="lp-dash-card-head" style="margin-bottom: 6px;">
            <span class="lp-dash-card-title">Rent roll · 12 months</span>
            <span class="lp-dash-card-meta">
                &#8358;{{ number_format($totalRoll) }} expected across the window
            </span>
        </div>
        <div style="display: grid; grid-template-columns: repeat({{ count($rentRollBars) }}, 1fr); gap: 8px; align-items: end; padding: 10px 0 6px; min-height: 84px;">
            @foreach ($rentRollBars as $bar)
                @php
                    $h = $maxBar > 0 ? max(4, (int) round(($bar['amount'] / $maxBar) * 68)) : 4;
                    $bg = $bar['is_current']
                        ? 'linear-gradient(180deg, var(--color-accent, #B6D97E) 0%, var(--color-accent-deep, #7DAE3F) 100%)'
                        : 'color-mix(in srgb, var(--color-ink) 12%, transparent)';
                @endphp
                <div style="display: flex; flex-direction: column; align-items: center; gap: 4px;"
                     title="&#8358;{{ number_format($bar['amount']) }} in {{ $bar['iso'] }}"
                     data-testid="rent-roll-bar-{{ $bar['iso'] }}">
                    <div style="width: 100%; height: {{ $h }}px; background: {{ $bg }}; border-radius: 4px 4px 2px 2px;"></div>
                </div>
            @endforeach
        </div>
        <div style="display: grid; grid-template-columns: repeat({{ count($rentRollBars) }}, 1fr); gap: 8px; margin-top: 4px;">
            @foreach ($rentRollBars as $bar)
                <span style="text-align: center; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 10px; letter-spacing: 0.06em; color: {{ $bar['is_current'] ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }}; font-weight: {{ $bar['is_current'] ? 600 : 400 }};">
                    {{ $bar['month'] }}
                </span>
            @endforeach
        </div>
    </section>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Filter</span></div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="l-q">Search</label>
                <input id="l-q" type="search"
                       wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Ref, tenant or unit" data-testid="search">
            </div>
            <div>
                <label class="lp-form-label" for="l-s">Status</label>
                <select id="l-s" wire:model.live="statusFilter" class="lp-input" data-testid="status-filter">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($leases->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;" data-testid="empty">
                No leases yet.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="leases-table">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Unit</th>
                            <th>Tenant</th>
                            <th>Started</th>
                            <th>Expires</th>
                            <th>Annual rent</th>
                            <th>Rent state</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leases as $l)
                            <tr data-testid="lease-row-{{ $l->id }}">
                                <td class="lp-mono" style="color: var(--color-ink);">{{ $l->reference }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $l->unit?->name ?? '-' }}</td>
                                <td style="color: var(--color-ink-soft);">
                                    {{ $l->customer?->name ?? '-' }}
                                    <div style="color: var(--color-ink-mute); font-size: 12px;">{{ $l->customer?->contactLine() }}</div>
                                </td>
                                <td style="color: var(--color-ink-soft);">{{ $l->started_at?->format('d M y') }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $l->expires_at?->format('d M y') }}</td>
                                <td><span class="lp-table-amount">₦{{ number_format($l->annual_rent_naira) }}</span></td>
                                <td>
                                    @php
                                        $rentState = $rentStates[$l->id] ?? 'new';
                                        [$rentColor, $rentBg, $rentLabel] = match ($rentState) {
                                            'paid'     => ['#166534', 'rgba(76, 149, 108, 0.14)', 'Paid'],
                                            'upcoming' => ['#A8650A', 'rgba(201, 148, 65, 0.16)', 'Upcoming'],
                                            'overdue'  => ['#B91C1C', 'rgba(220, 38, 38, 0.14)', 'Overdue'],
                                            default    => ['var(--color-ink-mute)', 'color-mix(in srgb, var(--color-ink) 5%, transparent)', 'New'],
                                        };
                                    @endphp
                                    <span data-testid="rent-state-{{ $l->id }}"
                                          data-rent-state="{{ $rentState }}"
                                          style="display: inline-flex; align-items: center; gap: 6px;
                                                 padding: 3px 9px; border-radius: 999px;
                                                 background: {{ $rentBg }};
                                                 color: {{ $rentColor }};
                                                 font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                                                 font-size: 11px;
                                                 letter-spacing: 0.10em;
                                                 text-transform: uppercase;">
                                        <span aria-hidden="true"
                                              style="width: 6px; height: 6px; border-radius: 999px;
                                                     background: currentColor;"></span>
                                        {{ $rentLabel }}
                                    </span>
                                </td>
                                <td>
                                    @php
                                        $tagClass = match($l->status->value) {
                                            'active' => 'lp-tag--confirmed',
                                            'pending','draft' => 'lp-tag--checked-in',
                                            default => 'lp-tag--checked-out',
                                        };
                                    @endphp
                                    <span class="lp-tag {{ $tagClass }}">{{ $l->status->label() }}</span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-3 text-[12.5px]" style="color: var(--color-ink-soft);">
                                        <a href="{{ route('tenant.leases.edit', $l) }}" wire:navigate
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">Edit</a>
                                        @if ($l->document_path)
                                            <a href="{{ \Storage::disk('public')->url($l->document_path) }}" target="_blank" rel="noopener"
                                               style="color: var(--color-ink-soft);">PDF</a>
                                        @endif
                                        @if (in_array($l->status->value, ['active','pending']))
                                            <button type="button" wire:click="terminate({{ $l->id }})"
                                                    wire:confirm="Terminate this lease? The unit becomes available again."
                                                    style="color: var(--color-danger, #b00); background: none; border: 0; cursor: pointer;">Terminate</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $leases->links() }}</div>
        </section>
    @endif
</div>
