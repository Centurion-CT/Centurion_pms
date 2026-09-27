@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Members</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Memberships
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Active workspace subscriptions. Pause, cancel or record manual renewals here.
            </p>
        </div>
        <a href="{{ route('tenant.memberships.create') }}" wire:navigate
           class="lp-pill" data-testid="create-link">
            New membership <span class="lp-pill-dot">{!! $pillArrow !!}</span>
        </a>
    </div>

    <div class="grid gap-3 sm:grid-cols-2" data-testid="stats">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Active members</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($stats['active']) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Estimated MRR</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($stats['mrr']) }}
            </p>
        </div>
    </div>
    <section class="lp-dash-card">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Filter</span></div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="m-q">Search member</label>
                <input id="m-q" type="search"
                       wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Name, email or phone" data-testid="search">
            </div>
            <div>
                <label class="lp-form-label" for="m-s">Status</label>
                <select id="m-s" wire:model.live="statusFilter" class="lp-input" data-testid="status-filter">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($memberships->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;" data-testid="empty">
                No memberships yet.
            </p>
            <p class="mt-2 text-[13px]" style="color: var(--color-ink-soft);">
                Members appear here once they sign up, either online or recorded manually.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="memberships-table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Plan</th>
                            <th>Workspace</th>
                            <th>Started</th>
                            <th>Next renewal</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($memberships as $m)
                            <tr data-testid="m-row-{{ $m->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $m->customer?->name ?? '-' }}
                                    <div style="color: var(--color-ink-mute); font-size: 12px;">{{ $m->customer?->contactLine() }}</div>
                                </td>
                                <td style="color: var(--color-ink-soft);">{{ $m->plan?->name ?? '-' }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $m->workspace?->name ?? 'Any' }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $m->started_at?->format('d M y') }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $m->next_renewal_at?->format('d M y') ?? '-' }}</td>
                                <td><span class="lp-table-amount">₦{{ number_format($m->amount_naira) }}</span></td>
                                <td>
                                    @php
                                        $statusClass = match($m->status->value) {
                                            'active' => 'lp-tag--confirmed',
                                            'pending', 'paused' => 'lp-tag--checked-in',
                                            default => 'lp-tag--checked-out',
                                        };
                                    @endphp
                                    <span class="lp-tag {{ $statusClass }}">{{ $m->status->label() }}</span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-3 text-[12.5px]" style="color: var(--color-ink-soft);">
                                        <a href="{{ route('tenant.memberships.edit', $m) }}" wire:navigate
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">Edit</a>
                                        @if ($m->status->value === 'active')
                                            <button type="button" wire:click="markRenewed({{ $m->id }})"
                                                    style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">
                                                Mark renewed
                                            </button>
                                            <button type="button" wire:click="pause({{ $m->id }})"
                                                    style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">
                                                Pause
                                            </button>
                                        @elseif ($m->status->value === 'paused')
                                            <button type="button" wire:click="resume({{ $m->id }})"
                                                    style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">
                                                Resume
                                            </button>
                                        @endif
                                        @if (in_array($m->status->value, ['active','pending','paused']))
                                            <button type="button" wire:click="cancel({{ $m->id }})"
                                                    wire:confirm="Cancel this membership? They lose access at expiry."
                                                    style="color: var(--color-danger, #b00); background: none; border: 0; cursor: pointer;">
                                                Cancel
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $memberships->links() }}</div>
        </section>
    @endif
</div>
