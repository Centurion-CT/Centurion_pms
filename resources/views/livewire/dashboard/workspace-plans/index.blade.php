@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Pricing</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Workspace plans
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Membership tiers + day-pass / hourly rates. Public site lists active plans.
            </p>
        </div>
        <a href="{{ route('tenant.workspace-plans.create') }}" wire:navigate
           class="lp-pill" data-testid="create-link">
            New plan <span class="lp-pill-dot">{!! $pillArrow !!}</span>
        </a>
    </div>
    <section class="lp-dash-card">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Filter</span></div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="p-q">Search</label>
                <input id="p-q" type="search"
                       wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Plan name" data-testid="search">
            </div>
            <div>
                <label class="lp-form-label" for="p-i">Billing interval</label>
                <select id="p-i" wire:model.live="intervalFilter" class="lp-input" data-testid="interval-filter">
                    <option value="">All intervals</option>
                    @foreach ($intervals as $i)
                        <option value="{{ $i->value }}">{{ $i->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($plans->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;" data-testid="empty">
                No plans yet.
            </p>
            <p class="mt-2 text-[13px]" style="color: var(--color-ink-soft);">
                Plans describe how a workspace is sold, ₦80,000/month hot desk, ₦5,000/day pass, etc.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="plans-table">
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Workspace</th>
                            <th>Price</th>
                            <th>Cap</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plans as $p)
                            <tr data-testid="plan-row-{{ $p->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">{{ $p->name }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $p->workspace?->name ?? 'Any workspace' }}</td>
                                <td><span class="lp-table-amount">{{ $p->priceLabel() }}</span></td>
                                <td style="color: var(--color-ink-soft);">{{ $p->member_cap ?? '∞' }}</td>
                                <td>
                                    <span class="lp-tag {{ $p->is_active ? 'lp-tag--confirmed' : 'lp-tag--checked-out' }}">
                                        {{ $p->is_active ? 'Active' : 'Hidden' }}
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-4 text-[12.5px]" style="color: var(--color-ink-soft);">
                                        <a href="{{ route('tenant.workspace-plans.edit', $p) }}" wire:navigate
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">Edit</a>
                                        <button type="button" wire:click="toggleActive({{ $p->id }})"
                                                style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">
                                            {{ $p->is_active ? 'Hide' : 'Activate' }}
                                        </button>
                                        <button type="button" wire:click="delete({{ $p->id }})"
                                                wire:confirm="Delete this plan?"
                                                style="color: var(--color-danger, #b00); background: none; border: 0; cursor: pointer;">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $plans->links() }}</div>
        </section>
    @endif
</div>
