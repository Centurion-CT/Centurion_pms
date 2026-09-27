<div class="lp-dash">

    <div>
        <span class="lp-eyebrow-strip">Collections</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Rent schedule
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Upcoming + overdue rent invoices across every active lease.
        </p>
    </div>

    <div class="grid gap-3 sm:grid-cols-3" data-testid="stats">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Overdue</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">₦{{ number_format($stats['overdue']) }}</p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Due in 30 days</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">₦{{ number_format($stats['due_soon']) }}</p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Collected YTD</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">₦{{ number_format($stats['collected_ytd']) }}</p>
        </div>
    </div>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Filter</span></div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="r-q">Search</label>
                <input id="r-q" type="search"
                       wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Lease ref, tenant or unit" data-testid="search">
            </div>
            <div>
                <label class="lp-form-label" for="r-w">View</label>
                <select id="r-w" wire:model.live="whenFilter" class="lp-input" data-testid="when-filter">
                    <option value="open">Open</option>
                    <option value="paid">Paid</option>
                    <option value="">All</option>
                </select>
            </div>
            <div>
                <label class="lp-form-label" for="r-s">Status</label>
                <select id="r-s" wire:model.live="statusFilter" class="lp-input" data-testid="status-filter">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($payments->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;" data-testid="empty">
                Nothing on the schedule.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="rent-table">
                    <thead>
                        <tr>
                            <th>Due</th>
                            <th>Lease</th>
                            <th>Tenant</th>
                            <th>Unit</th>
                            <th>Item</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $p)
                            <tr data-testid="pay-row-{{ $p->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">{{ $p->due_at->format('d M y') }}</td>
                                <td class="lp-mono" style="color: var(--color-ink-soft);">{{ $p->lease?->reference }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $p->lease?->customer?->name ?? '-' }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $p->lease?->unit?->name ?? '-' }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $p->label }}</td>
                                <td><span class="lp-table-amount">₦{{ number_format($p->amount_naira) }}</span></td>
                                <td>
                                    @php
                                        $tagClass = match($p->status->value) {
                                            'paid' => 'lp-tag--confirmed',
                                            'due' => 'lp-tag--checked-in',
                                            'overdue' => 'lp-tag--checked-out',
                                            'waived' => 'lp-tag--checked-out',
                                            default => 'lp-tag--checked-in',
                                        };
                                    @endphp
                                    <span class="lp-tag {{ $tagClass }}">{{ $p->status->label() }}</span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-3 text-[12.5px]" style="color: var(--color-ink-soft);">
                                        @if (! in_array($p->status->value, ['paid','waived']))
                                            <button type="button" wire:click="markPaid({{ $p->id }})"
                                                    style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px; background: none; border: 0; cursor: pointer;"
                                                    data-testid="mark-paid-{{ $p->id }}">Mark paid</button>
                                            <button type="button" wire:click="waive({{ $p->id }})"
                                                    wire:confirm="Waive this charge?"
                                                    style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">Waive</button>
                                        @else
                                            @if ($p->paid_at)
                                                <span style="color: var(--color-ink-mute);">Paid {{ $p->paid_at->format('d M y') }}</span>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $payments->links() }}</div>
        </section>
    @endif
</div>
