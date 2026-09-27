@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Inventory</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Units
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                {{ $units->total() }} unit{{ $units->total() === 1 ? '' : 's' }} on this property.
            </p>
        </div>
        <a href="{{ route('tenant.units.create') }}" wire:navigate
           class="lp-pill" data-testid="create-link">
            Add unit <span class="lp-pill-dot">{!! $pillArrow !!}</span>
        </a>
    </div>
    <section class="lp-dash-card">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Filter</span></div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="u-q">Search</label>
                <input id="u-q" type="search"
                       wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Name or address" data-testid="search">
            </div>
            <div>
                <label class="lp-form-label" for="u-s">Status</label>
                <select id="u-s" wire:model.live="statusFilter" class="lp-input" data-testid="status-filter">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($units->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;" data-testid="empty">
                No units yet, add your first one.
            </p>
            <p class="mt-2 text-[13px]" style="color: var(--color-ink-soft);">
                A unit is a flat, room, or house that you lease out to tenants.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="units-table">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Address</th>
                            <th>Beds / baths</th>
                            <th>Annual rent</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($units as $u)
                            <tr data-testid="unit-row-{{ $u->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">{{ $u->name }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $u->address }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $u->bedrooms }} bed / {{ $u->bathrooms }} bath</td>
                                <td><span class="lp-table-amount">₦{{ number_format($u->annual_rent_naira) }}</span></td>
                                <td>
                                    @php
                                        $tagClass = match($u->status->value) {
                                            'available' => 'lp-tag--confirmed',
                                            'leased' => 'lp-tag--checked-in',
                                            default => 'lp-tag--checked-out',
                                        };
                                    @endphp
                                    <span class="lp-tag {{ $tagClass }}">{{ $u->status->label() }}</span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-4 text-[12.5px]" style="color: var(--color-ink-soft);">
                                        <a href="{{ route('tenant.units.edit', $u) }}" wire:navigate
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">Edit</a>
                                        <button type="button" wire:click="delete({{ $u->id }})"
                                                wire:confirm="Archive this unit?"
                                                style="color: var(--color-danger, #b00); background: none; border: 0; cursor: pointer;">Archive</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $units->links() }}</div>
        </section>
    @endif
</div>
