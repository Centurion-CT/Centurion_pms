@php
    $activeFilters = collect([
        $search       !== '' ? 'search' : null,
        $statusFilter !== '' ? 'status' : null,
    ])->filter()->count();
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + Add room CTA ─────────────────────
         Identity block on the left, "N rooms · N active"
         summary tile on the right. --}}
    <section class="lp-res-idx-hero" data-testid="rooms-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Rooms
                </h1>
                <span style="color: var(--color-ink-mute);" data-testid="rooms-summary">{{ collect($roomSummary)->map(fn ($n, $label) => $label.' '.$n)->implode(' · ') }}</span>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.rooms.create') }}" wire:navigate
                       data-testid="create-link"
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        Add room
                    </a>
                    <a href="{{ route('tenant.room-categories.index') }}" wire:navigate
                       class="lp-res-action">
                        Manage categories
                    </a>
                </div>
            </div>

        </div>
    </section>

    {{-- ── Filter strip ─────────────────────────────────── --}}
    <form data-testid="filters" wire:submit.prevent class="lp-filter-row">
        <input id="room-search" type="search" aria-label="Search rooms"
               wire:model.live.debounce.300ms="search"
               data-testid="search" class="lp-input is-search"
               placeholder="Room number, category name or type">
        <select id="room-status" aria-label="Status" wire:model.live="statusFilter"
                data-testid="status-filter" class="lp-input">
            <option value="">All statuses</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}">{{ ucfirst($s->value) }}</option>
            @endforeach
        </select>
    </form>

    {{-- ── Rooms list ───────────────────────────────────── --}}
    @if ($rooms->isEmpty())
        <section class="lp-dash-card" data-testid="empty" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'      => 'room',
                'title'     => $activeFilters > 0 ? 'No rooms match those filters.' : 'No rooms yet.',
                'sub'       => $activeFilters > 0
                                ? 'Try clearing the filters above.'
                                : 'Add your first room, then set nightly rate, capacity, and photos.',
                'ctaLabel'  => $activeFilters > 0 ? null : 'Add room',
                'ctaHref'   => $activeFilters > 0 ? null : route('tenant.rooms.create'),
            ])
        </section>
    @else
        <section class="lp-dash-card lp-table-card">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="rooms-table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Guest</th>
                            <th>Next arrival</th>
                            <th style="text-align: right;">Rate</th>
                            <th><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($rooms as $room)
                        @php $state = $roomStates[$room->id] ?? null; @endphp
                        <tr data-testid="room-row-{{ $room->id }}">
                            <td>
                                <a href="{{ route('tenant.rooms.edit', $room) }}" class="lp-row-title"
                                   aria-label="Open room {{ $room->room_number ?? '#'.$room->id }}">{{ $room->room_number ?? '-' }}</a>
                            </td>
                            <td>{{ $room->category?->name ?? 'Uncategorised' }}</td>
                            <td><span class="lp-tag {{ $state['class'] ?? 'lp-tag--checked-out' }}">{{ $state['label'] ?? 'Ready' }}</span></td>
                            <td style="color: var(--color-ink);">{{ $state['guest'] ?? '' }}</td>
                            <td>{{ $state['next'] ?? '' }}</td>
                            <td class="lp-num" style="text-align: right; color: var(--color-ink);">₦{{ number_format($room->base_rate) }}</td>
                            <td>
                                <div class="lp-row-actions">
                                    <a href="{{ route('tenant.rooms.edit', $room) }}" data-testid="edit-{{ $room->id }}">Edit</a>
                                    <a href="{{ route('tenant.rooms.pricing', $room) }}">Pricing</a>
                                    @if ($room->room_category_id)
                                        <a href="{{ route('tenant.room-categories.edit', $room->room_category_id) }}" wire:navigate>Photos</a>
                                    @endif
                                    <button type="button" wire:click="delete({{ $room->id }})"
                                            wire:confirm="Delete this room?"
                                            data-testid="delete-{{ $room->id }}"
                                            class="is-danger">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($rooms->hasPages())
                <div style="padding: 10px 18px;">{{ $rooms->links() }}</div>
            @endif
        </section>
    @endif
</div>
