@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';

    $tabs = [
        ['key' => 'all',           'label' => 'All',           'count' => $counts['all']],
        ['key' => 'active',        'label' => 'Active',        'count' => $counts['active']],
        ['key' => 'complimentary', 'label' => 'Complimentary', 'count' => $counts['complimentary']],
        ['key' => 'paid',          'label' => 'Paid',          'count' => $counts['paid']],
        ['key' => 'inactive',      'label' => 'Inactive',      'count' => $counts['all'] - $counts['active']],
    ];
@endphp

<div class="lp-dash">

    {{-- ── Header ───────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Add-ons</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Packages
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Optional extras guests can pick during booking or add later, breakfast, airport pickup, late checkout, spa credit, anything that complements the stay.
            </p>
        </div>
        <a href="{{ route('tenant.packages.create') }}" wire:navigate
           data-testid="package-create-link" class="lp-pill">
            New package
            <span class="lp-pill-dot">{!! $pillArrow !!}</span>
        </a>
    </div>
    {{-- ── Tabs ─────────────────────────────────────────────────── --}}
    <div class="lp-chips" data-testid="package-tabs">
        @foreach ($tabs as $t)
            <button type="button"
                    wire:click="$set('statusFilter', '{{ $t['key'] }}')"
                    class="lp-chip @if ($statusFilter === $t['key']) is-active @endif"
                    data-testid="tab-{{ $t['key'] }}">
                <span>{{ $t['label'] }}</span>
                <span class="count">{{ $t['count'] }}</span>
            </button>
        @endforeach
    </div>

    {{-- ── Filters ──────────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Search</span>
        </div>
        <form wire:submit.prevent>
            <input type="search" wire:model.live.debounce.300ms="search"
                   data-testid="package-search" class="lp-input"
                   placeholder="Package name or description">
        </form>
    </section>

    {{-- ── Table ────────────────────────────────────────────────── --}}
    @if ($packages->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="no-packages" class="text-[18px]"
               style="color: var(--color-ink); font-weight: 500;">
                @if ($search === '' && $statusFilter === 'all')
                    No packages yet.
                @else
                    No packages match these filters.
                @endif
            </p>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute);">
                @if ($search === '' && $statusFilter === 'all')
                    Add a complimentary breakfast or a paid airport pickup to give guests something extra to choose.
                @else
                    Try clearing the filters above.
                @endif
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table data-testid="packages-table" class="lp-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Price</th>
                            <th>Max per booking</th>
                            <th>Availability</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($packages as $p)
                            <tr data-testid="package-row-{{ $p->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $p->name }}
                                    @if ($p->description)
                                        <div class="text-[11.5px] mt-0.5" style="color: var(--color-ink-mute);">
                                            {{ \Illuminate\Support\Str::limit($p->description, 80) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if ($p->is_complimentary)
                                        <span class="lp-tag lp-tag--confirmed">Complimentary</span>
                                    @else
                                        <span class="lp-table-amount">₦{{ number_format($p->price) }}</span>
                                    @endif
                                </td>
                                <td style="color: var(--color-ink-soft);">{{ $p->max_per_booking }}</td>
                                <td style="color: var(--color-ink-soft);">
                                    <div class="text-[12.5px]">
                                        {{ $p->available_during_booking ? 'During booking' : '-' }}
                                        @if ($p->available_during_booking && $p->available_after_booking)
                                            <span style="color: var(--color-ink-mute);"> · </span>
                                        @endif
                                        {{ $p->available_after_booking ? 'After booking' : '' }}
                                    </div>
                                </td>
                                <td>
                                    <button type="button"
                                            wire:click="toggleActive({{ $p->id }})"
                                            data-testid="toggle-{{ $p->id }}"
                                            class="lp-tag {{ $p->active ? 'lp-tag--confirmed' : 'lp-tag--checked-out' }}"
                                            style="border: 0; cursor: pointer; font-family: inherit;">
                                        {{ $p->active ? 'Active' : 'Paused' }}
                                    </button>
                                </td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-4 text-[12.5px]"
                                         style="color: var(--color-ink-soft);">
                                        <a href="{{ route('tenant.packages.edit', $p) }}" wire:navigate
                                           data-testid="edit-{{ $p->id }}"
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">Edit</a>
                                        <button type="button" wire:click="delete({{ $p->id }})"
                                                wire:confirm="Remove this package from the catalog? Existing bookings keep their copy."
                                                data-testid="delete-{{ $p->id }}"
                                                style="color: #B85C42; background: transparent; border: 0; cursor: pointer; font-family: inherit; font-size: inherit;">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="text-[13px]" style="color: var(--color-ink-soft);">
            {{ $packages->links() }}
        </div>
    @endif
</div>
