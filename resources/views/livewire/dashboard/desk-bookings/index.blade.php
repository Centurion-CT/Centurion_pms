@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Day passes &amp; hourly</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Desk bookings
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                One-off workspace reservations, hourly meeting room slots, day passes.
            </p>
        </div>
        <a href="{{ route('tenant.desk-bookings.create') }}" wire:navigate
           class="lp-pill" data-testid="create-link">
            New booking <span class="lp-pill-dot">{!! $pillArrow !!}</span>
        </a>
    </div>
    <section class="lp-dash-card">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Filter</span></div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="d-q">Search</label>
                <input id="d-q" type="search"
                       wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Ref, name, email or phone" data-testid="search">
            </div>
            <div>
                <label class="lp-form-label" for="d-w">When</label>
                <select id="d-w" wire:model.live="whenFilter" class="lp-input" data-testid="when-filter">
                    <option value="upcoming">Upcoming</option>
                    <option value="past">Past</option>
                    <option value="">All</option>
                </select>
            </div>
            <div>
                <label class="lp-form-label" for="d-s">Status</label>
                <select id="d-s" wire:model.live="statusFilter" class="lp-input" data-testid="status-filter">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($bookings->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;" data-testid="empty">
                No desk bookings.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="desk-bookings-table">
                    <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Workspace</th>
                            <th>Customer</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $b)
                            <tr data-testid="db-row-{{ $b->id }}">
                                <td class="lp-mono" style="color: var(--color-ink);">{{ $b->reference }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $b->workspace?->name ?? '-' }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $b->customer?->name ?? 'Walk-in' }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $b->start_at->format('d M · g:i A') }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $b->end_at->format('d M · g:i A') }}</td>
                                <td><span class="lp-table-amount">₦{{ number_format($b->amount_naira) }}</span></td>
                                <td>
                                    @php
                                        $tagClass = match($b->status->value) {
                                            'confirmed','in_use' => 'lp-tag--confirmed',
                                            'pending' => 'lp-tag--checked-in',
                                            'completed' => 'lp-tag--checked-out',
                                            default => 'lp-tag--checked-out',
                                        };
                                    @endphp
                                    <span class="lp-tag {{ $tagClass }}">{{ $b->status->label() }}</span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-3 text-[12.5px]" style="color: var(--color-ink-soft);">
                                        <a href="{{ route('tenant.desk-bookings.edit', $b) }}" wire:navigate
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">Edit</a>
                                        @if ($b->status->value === 'confirmed')
                                            <button type="button" wire:click="mark({{ $b->id }}, 'in_use')"
                                                    style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">Check in</button>
                                        @endif
                                        @if (in_array($b->status->value, ['confirmed','in_use']))
                                            <button type="button" wire:click="mark({{ $b->id }}, 'completed')"
                                                    style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">Complete</button>
                                            <button type="button" wire:click="mark({{ $b->id }}, 'cancelled')"
                                                    wire:confirm="Cancel this booking?"
                                                    style="color: var(--color-danger, #b00); background: none; border: 0; cursor: pointer;">Cancel</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $bookings->links() }}</div>
        </section>
    @endif
</div>
