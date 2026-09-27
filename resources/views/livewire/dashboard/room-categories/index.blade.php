@php
    $activeFilters = collect([
        $search       !== '' ? 'search' : null,
        $statusFilter !== '' ? 'status' : null,
    ])->filter()->count();
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + New category CTA ────────────────── --}}
    <section class="lp-res-idx-hero" data-testid="categories-hero">
        <div class="lp-res-idx-hero-body" style="grid-template-columns: 1fr;">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Inventory</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Room categories
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    The class of a room, Deluxe, Studio, Suite. Pricing, capacity and photos live here; individual rooms inherit them.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.room-categories.create') }}" wire:navigate
                       data-testid="category-create-link"
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        New category
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Filter strip ─────────────────────────────────── --}}
    <section class="lp-dash-card" style="padding: 14px 16px;">
        <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="cat-search" class="lp-form-label">Search</label>
                <input id="cat-search" type="search" wire:model.live.debounce.300ms="search"
                       data-testid="category-search" class="lp-input"
                       placeholder="Name or type">
            </div>
            <div>
                <label for="cat-status" class="lp-form-label">Status</label>
                <select id="cat-status" wire:model.live="statusFilter"
                        data-testid="category-status-filter" class="lp-input">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ ucfirst($s->value) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    {{-- ── Categories list ──────────────────────────────── --}}
    @if ($categories->isEmpty())
        @php
            $isFilterEmpty = $search === '' && $statusFilter === '';
        @endphp
        <section class="lp-dash-card" data-testid="no-categories" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'      => 'room',
                'title'     => $isFilterEmpty ? 'No categories yet.' : 'No categories match those filters.',
                'sub'       => $isFilterEmpty
                                ? 'Create one to start grouping your rooms by class. Deluxe, Studio, Suite.'
                                : 'Try clearing the filters above.',
                'ctaLabel'  => $isFilterEmpty ? 'New category' : null,
                'ctaHref'   => $isFilterEmpty ? route('tenant.room-categories.create') : null,
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">
                    {{ $activeFilters > 0 ? 'Matching categories' : 'All categories' }}
                </span>
                <span class="lp-dash-card-meta">
                    {{ $categories->firstItem() ? number_format($categories->firstItem()).'–'.number_format($categories->lastItem()) : '0' }}
                    of {{ number_format($categories->total()) }}
                </span>
            </div>

            <ul class="lp-ledger lp-categories-stack" data-testid="categories-table">
                @foreach ($categories as $cat)
                    @php
                        $isActive = $cat->status->value === 'active';
                        $tone     = $isActive ? 'paid' : 'muted';
                        $statusClass = $isActive ? 'lp-tag--confirmed' : 'lp-tag--checked-out';
                        $hero     = $cat->heroImage();
                        $thumbUrl = $hero?->getUrl('thumb') ?: $hero?->getUrl();
                        $editUrl  = route('tenant.room-categories.edit', $cat);
                    @endphp
                    <li class="lp-ledger-item lp-category-row" data-testid="category-row-{{ $cat->id }}">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        @if ($thumbUrl)
                            <a href="{{ $editUrl }}" wire:navigate
                               class="lp-category-thumb"
                               data-testid="category-thumb-{{ $cat->id }}"
                               aria-label="Manage {{ $cat->name }} photos">
                                <img src="{{ $thumbUrl }}" alt="" loading="lazy">
                            </a>
                        @else
                            <a href="{{ $editUrl }}" wire:navigate
                               class="lp-category-thumb lp-category-thumb--empty"
                               data-testid="category-thumb-empty-{{ $cat->id }}"
                               aria-label="Add photos for {{ $cat->name }}"
                               title="Upload a photo">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10.5" r="1.5"/><path d="M21 15l-5-5-11 11"/></svg>
                                <span class="lp-category-thumb-cta">Add photo</span>
                            </a>
                        @endif
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <a href="{{ route('tenant.room-categories.edit', $cat) }}" wire:navigate
                                   class="lp-ledger-desc lp-category-name">
                                    {{ $cat->name }}
                                </a>
                                @if ($cat->type)
                                    <span class="lp-mono lp-category-type">{{ $cat->type }}</span>
                                @endif
                                <span class="lp-tag {{ $statusClass }}">{{ ucfirst($cat->status->value) }}</span>
                            </div>
                            <p class="lp-ledger-meta">
                                <span class="lp-mono">{{ $cat->rooms_count }}</span>
                                {{ Str::plural('room', (int) $cat->rooms_count) }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                sleeps
                                <span class="lp-mono">{{ $cat->capacity_adults }}</span> adult{{ (int) $cat->capacity_adults === 1 ? '' : 's' }}
                                @if ((int) $cat->capacity_children > 0)
                                    + <span class="lp-mono">{{ $cat->capacity_children }}</span> child{{ (int) $cat->capacity_children === 1 ? '' : 'ren' }}
                                @endif
                                @if ($cat->description)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span>{{ \Illuminate\Support\Str::limit($cat->description, 60) }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right lp-category-right">
                            <span class="lp-category-rate">₦{{ number_format((int) $cat->base_rate) }}</span>
                            <div class="lp-ledger-actions">
                                <a href="{{ route('tenant.room-categories.edit', $cat) }}" wire:navigate
                                   data-testid="edit-{{ $cat->id }}"
                                   class="lp-ledger-action">Edit</a>
                                <button type="button" wire:click="delete({{ $cat->id }})"
                                        wire:confirm="Delete this category? Rooms must be reassigned first."
                                        data-testid="delete-{{ $cat->id }}"
                                        class="lp-ledger-action lp-ledger-action--danger">Delete</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($categories->hasPages())
                <div class="mt-4">{{ $categories->links() }}</div>
            @endif
        </section>
    @endif
</div>
