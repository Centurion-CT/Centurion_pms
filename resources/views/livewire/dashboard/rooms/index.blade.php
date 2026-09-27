@php
    $activeFilters = collect([
        $search       !== '' ? 'search' : null,
        $statusFilter !== '' ? 'status' : null,
    ])->filter()->count();
    $totalRooms = $rooms->total() ?? $rooms->count();
    $activeRoomCount = method_exists($rooms, 'getCollection')
        ? $rooms->getCollection()->where('status.value', 'active')->count()
        : $rooms->where('status.value', 'active')->count();
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + Add room CTA ─────────────────────
         Identity block on the left, "N rooms · N active"
         summary tile on the right. --}}
    <section class="lp-res-idx-hero" data-testid="rooms-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Inventory</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Rooms
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                    {{ number_format($totalRooms) }} {{ Str::plural('room', $totalRooms) }} on this property. Manage room numbers, capacity, rates and photos.
                </p>
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

            <aside class="lp-res-idx-hero-money" aria-label="Room count">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Total rooms</p>
                    <p class="lp-ih-hero-accrued-value" style="color: var(--color-ink);">
                        {{ number_format($totalRooms) }}
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Filter strip ─────────────────────────────────── --}}
    <section class="lp-dash-card" style="padding: 14px 16px;">
        <form data-testid="filters" wire:submit.prevent class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="room-search" class="lp-form-label">Search</label>
                <input id="room-search" type="search"
                       wire:model.live.debounce.300ms="search"
                       data-testid="search" class="lp-input"
                       placeholder="Room number, category name or type">
            </div>
            <div>
                <label for="room-status" class="lp-form-label">Status</label>
                <select id="room-status" wire:model.live="statusFilter"
                        data-testid="status-filter" class="lp-input">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ ucfirst($s->value) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

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
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">
                    {{ $activeFilters > 0 ? 'Matching rooms' : 'All rooms' }}
                </span>
                <span class="lp-dash-card-meta">
                    {{ $rooms->firstItem() ? number_format($rooms->firstItem()).'–'.number_format($rooms->lastItem()) : '0' }}
                    of {{ number_format($rooms->total()) }}
                </span>
            </div>

            <ul class="lp-ledger lp-rooms-stack" data-testid="rooms-table">
                @foreach ($rooms as $room)
                    @php
                        $isActive = $room->status->value === 'active';
                        $tone     = $isActive ? 'paid' : 'muted';
                        $statusClass = $isActive ? 'lp-tag--confirmed' : 'lp-tag--checked-out';
                        $hero     = $room->category?->heroImage();
                        $thumbUrl = $hero?->getUrl('thumb') ?: $hero?->getUrl();
                        $catEditUrl = $room->room_category_id
                            ? route('tenant.room-categories.edit', $room->room_category_id)
                            : null;
                    @endphp
                    <li class="lp-ledger-item lp-category-row" data-testid="room-row-{{ $room->id }}">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        @if ($thumbUrl)
                            <a href="{{ $catEditUrl }}" wire:navigate
                               class="lp-category-thumb"
                               data-testid="room-thumb-{{ $room->id }}"
                               aria-label="{{ $room->category?->name }} photos">
                                <img src="{{ $thumbUrl }}" alt="" loading="lazy">
                            </a>
                        @elseif ($catEditUrl)
                            <a href="{{ $catEditUrl }}" wire:navigate
                               class="lp-category-thumb lp-category-thumb--empty"
                               data-testid="room-thumb-empty-{{ $room->id }}"
                               aria-label="Add photos for {{ $room->category?->name }}"
                               title="Upload a photo">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10.5" r="1.5"/><path d="M21 15l-5-5-11 11"/></svg>
                            </a>
                        @else
                            <span class="lp-category-thumb lp-category-thumb--empty" aria-hidden>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10.5" r="1.5"/><path d="M21 15l-5-5-11 11"/></svg>
                            </span>
                        @endif
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <a href="{{ route('tenant.rooms.edit', $room) }}"
                                   class="lp-mono lp-room-number"
                                   aria-label="Open room {{ $room->room_number ?? '#'.$room->id }}">
                                    {{ $room->room_number ?? '-' }}
                                </a>
                                @if ($room->category)
                                    <span class="lp-ledger-desc" style="font-weight: 500;">{{ $room->category->name }}</span>
                                    @if ($room->category->type)
                                        <span class="lp-category-type">{{ $room->category->type }}</span>
                                    @endif
                                @else
                                    <span class="lp-ledger-desc" style="color: var(--color-ink-mute);">Uncategorised</span>
                                @endif
                                <span class="lp-tag {{ $statusClass }}">{{ ucfirst($room->status->value) }}</span>
                            </div>
                            <p class="lp-ledger-meta">
                                Sleeps
                                <span class="lp-mono">{{ $room->capacity_adults }}</span>
                                adult{{ (int) $room->capacity_adults === 1 ? '' : 's' }}
                                @if ((int) $room->capacity_children > 0)
                                    + <span class="lp-mono">{{ $room->capacity_children }}</span>
                                    child{{ (int) $room->capacity_children === 1 ? '' : 'ren' }}
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right lp-category-right">
                            <span class="lp-category-rate">₦{{ number_format($room->base_rate) }}</span>
                            <div class="lp-ledger-actions">
                                <a href="{{ route('tenant.rooms.edit', $room) }}"
                                   data-testid="edit-{{ $room->id }}"
                                   class="lp-ledger-action">Edit</a>
                                <a href="{{ route('tenant.rooms.pricing', $room) }}"
                                   class="lp-ledger-action">Pricing</a>
                                @if ($room->room_category_id)
                                    <a href="{{ route('tenant.room-categories.edit', $room->room_category_id) }}" wire:navigate
                                       class="lp-ledger-action">Photos</a>
                                @endif
                                {{-- Per-room QR retired: the portal now uses one
                                     QR per branch, downloadable from Branches. The
                                     legacy /dashboard/rooms/{room}/qr.png route stays
                                     for backwards compat but isn't linked here. --}}
                                <button type="button" wire:click="delete({{ $room->id }})"
                                        wire:confirm="Delete this room?"
                                        data-testid="delete-{{ $room->id }}"
                                        class="lp-ledger-action lp-ledger-action--danger">Delete</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($rooms->hasPages())
                <div class="mt-4">{{ $rooms->links() }}</div>
            @endif
        </section>
    @endif
</div>
