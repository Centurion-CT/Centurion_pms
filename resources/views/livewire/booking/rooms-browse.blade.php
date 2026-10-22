@php
    $today = \Illuminate\Support\Carbon::today()->toDateString();
    // Carry the search into the room page so the guest doesn't re-pick dates.
    $stayQuery = $datesValid ? '?'.http_build_query(['in' => $checkin, 'out' => $checkout, 'adults' => $adults, 'children' => $children]) : '';
    $nightsChosen = $datesValid
        ? \Illuminate\Support\Carbon::parse($checkin)->diffInDays(\Illuminate\Support\Carbon::parse($checkout))
        : null;
@endphp
{{-- Room search results (Centurion "Harbour", approved mockup 1).
     Date / guest bar on top, then one row per room type with tonight's
     or the chosen dates' availability and the stay total. --}}
<div class="cx-browse" wire:poll.10s="refresh">
    <style>
        .cx-browse { padding-bottom: 72px; }
        .cx-searchbar { background: var(--white); border-bottom: 1px solid var(--line); position: sticky; top: 72px; z-index: 30; }
        .cx-searchbar-inner { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; padding-block: 14px; align-items: stretch; }
        @media (min-width: 900px) { .cx-searchbar-inner { grid-template-columns: repeat(4, minmax(0, 1fr)) auto auto; } }
        .cx-searchbar .cx-field { min-height: 56px; }
        .cx-searchbar-go { height: 100%; min-height: 48px; padding: 0 22px; border: 0; border-radius: 8px; background: var(--blue); color: var(--white); font: inherit; font-weight: 600; cursor: pointer; }
        .cx-searchbar-go:hover { background: var(--blue-600); }
        .cx-searchbar-clear { align-self: center; border: 0; background: none; color: var(--blue-600); font: inherit; font-weight: 600; cursor: pointer; padding: 8px; }
        @media (max-width: 899px) { .cx-searchbar-go, .cx-searchbar-clear { grid-column: span 1; } .cx-searchbar { position: static; } }

        .cx-results-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; padding-top: 40px; }
        .cx-results-title { font-family: var(--display); font-weight: 600; font-size: clamp(28px, 3.6vw, 40px); line-height: 1.1; margin: 0; }
        .cx-results-sub { color: var(--ink-soft); margin: 6px 0 0; }
        .cx-live { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink-mute); margin-top: 8px; }
        .cx-live-dot { width: 7px; height: 7px; border-radius: 999px; background: var(--emerald); }
        .cx-sort { display: inline-flex; align-items: center; gap: 8px; height: 44px; padding: 0 12px; border-radius: 8px; border: 1px solid var(--line); background: var(--white); font-size: 14px; }
        .cx-sort span { color: var(--ink-mute); }
        .cx-sort select { border: 0; font: inherit; font-weight: 600; background: transparent; color: var(--ink); outline: none; }

        .cx-rows { margin-top: 28px; display: grid; gap: 18px; }
        .cx-row { display: grid; grid-template-columns: 1fr; background: var(--white); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; text-decoration: none; transition: border-color 150ms ease; }
        @media (min-width: 820px) { .cx-row { grid-template-columns: 340px 1fr; } }
        .cx-row:hover { border-color: rgba(51, 103, 153, 0.45); }
        .cx-row-photo { min-height: 220px; background: var(--blue-50); }
        .cx-row-photo img { width: 100%; height: 100%; object-fit: cover; }
        .cx-row-body { padding: 22px 24px; display: flex; flex-direction: column; gap: 6px; }
        .cx-row-top { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .cx-row-name { font-family: var(--display); font-weight: 600; font-size: 24px; margin: 0; }
        .cx-tags { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
        .cx-tag { display: inline-flex; align-items: center; height: 28px; padding: 0 10px; border-radius: 6px; background: var(--mist); color: var(--ink-soft); font-size: 13px; }
        .cx-row-foot { margin-top: auto; padding-top: 18px; display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
        .cx-row-total { font-size: 14px; color: var(--ink-soft); margin-top: 4px; }
        .cx-row-cta { margin-left: auto; height: 44px; padding: 0 22px; border-radius: 8px; background: var(--blue); color: var(--white); font-weight: 600; display: inline-flex; align-items: center; }
        .cx-row.is-full { opacity: 0.6; }
        .cx-row.is-full .cx-row-cta { background: var(--white); color: var(--ink); border: 1px solid var(--line); }
        .cx-hints { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
    </style>

    {{-- Date + guest bar. Live-bound: results update as the guest edits. --}}
    <form wire:submit.prevent class="cx-searchbar" data-testid="rooms-filters">
        <div class="cx-wrap cx-searchbar-inner">
            <label class="cx-field"><span class="cx-field-label">Check in</span>
                <input type="date" wire:model.live.debounce.400ms="checkin" min="{{ $today }}" data-testid="filter-checkin"></label>
            <label class="cx-field"><span class="cx-field-label">Check out</span>
                <input type="date" wire:model.live.debounce.400ms="checkout" min="{{ $today }}" data-testid="filter-checkout"></label>
            <label class="cx-field"><span class="cx-field-label">Adults</span>
                <select wire:model.live="adults" data-testid="filter-adults">
                    @for ($i = 1; $i <= 10; $i++)
                        <option value="{{ $i }}">{{ $i }} adult{{ $i === 1 ? '' : 's' }}</option>
                    @endfor
                </select></label>
            <label class="cx-field"><span class="cx-field-label">Children</span>
                <select wire:model.live="children" data-testid="filter-children">
                    @for ($i = 0; $i <= 8; $i++)
                        <option value="{{ $i }}">{{ $i }} child{{ $i === 1 ? '' : 'ren' }}</option>
                    @endfor
                </select></label>
            <button type="submit" class="cx-searchbar-go" data-testid="filter-search">Update search</button>
            <button type="button" wire:click="clearFilters" class="cx-searchbar-clear" data-testid="filter-clear">Clear</button>
        </div>
    </form>

    <div class="cx-wrap">
        <div class="cx-results-head">
            <div>
                <h1 class="cx-results-title">
                    @if ($totalCategoryCount === 0)
                        No rooms published yet
                    @elseif ($categories->isEmpty())
                        Nothing free for your search
                    @elseif ($datesValid)
                        {{ $categories->count() }} {{ $categories->count() === 1 ? 'room type' : 'room types' }} free for your dates
                    @else
                        All {{ $categories->count() === 1 ? 'room type' : $categories->count().' room types' }}
                    @endif
                </h1>
                <p class="cx-results-sub">
                    @if ($datesValid)
                        {{ \Illuminate\Support\Carbon::parse($checkin)->format('D j M') }} to {{ \Illuminate\Support\Carbon::parse($checkout)->format('D j M') }},
                        {{ $nightsChosen }} {{ $nightsChosen === 1 ? 'night' : 'nights' }}, {{ $guestCount }} {{ $guestCount === 1 ? 'guest' : 'guests' }}. Prices include all fees.
                    @elseif ($checkin !== '' || $checkout !== '')
                        Pick a check-out date after your check-in date to see availability.
                    @else
                        Add your dates to see what's free and what your stay will cost.
                    @endif
                    <span wire:loading wire:target="checkin,checkout,adults,children,sort" style="margin-left: 8px; color: var(--blue-600);">Updating…</span>
                </p>
                <span class="cx-live" data-testid="rb-live-indicator" aria-live="polite"><span class="cx-live-dot" aria-hidden="true"></span>Live availability</span>
            </div>
            @if ($categories->count() > 1)
                <label class="cx-sort"><span>Sort</span>
                    <select wire:model.live="sort" data-testid="filter-sort">
                        <option value="price_asc">Price, low to high</option>
                        <option value="price_desc">Price, high to low</option>
                        <option value="capacity_desc">Largest first</option>
                    </select>
                </label>
            @endif
        </div>

        @if ($categories->isEmpty())
            <section class="cx-empty" data-testid="no-results">
                <h2>
                    @if ($totalCategoryCount === 0) No room types are live yet @else Nothing matches just yet @endif
                </h2>
                <p>
                    @if ($totalCategoryCount === 0)
                        {{ $property?->name ?? 'This property' }} hasn't published any rooms. Check back soon or contact the front desk.
                    @elseif ($unavailableCount > 0 && $tooSmallCount > 0)
                        {{ $unavailableCount }} {{ $unavailableCount === 1 ? 'room type is' : 'room types are' }} full for those dates, and {{ $tooSmallCount }} {{ $tooSmallCount === 1 ? "doesn't fit" : "don't fit" }} {{ $guestCount }} {{ $guestCount === 1 ? 'guest' : 'guests' }}.
                    @elseif ($unavailableCount > 0)
                        Every room type that fits your group is full for those dates. Try other dates.
                    @elseif ($tooSmallCount > 0)
                        No room type fits {{ $guestCount }} {{ $guestCount === 1 ? 'guest' : 'guests' }}. Try fewer guests or contact the front desk.
                    @else
                        We couldn't find a match for your search.
                    @endif
                </p>
                @if ($totalCategoryCount > 0)
                    <div class="cx-hints">
                        <span class="cx-tag">Try moving your dates by a day or two</span>
                        <span class="cx-tag">Change the number of guests</span>
                        <button type="button" wire:click="clearFilters" class="cx-tag" style="border: 0; cursor: pointer; color: var(--blue-600); font-weight: 600;">Clear your search</button>
                    </div>
                @endif
            </section>
        @else
            <div class="cx-rows" data-testid="rooms-grid">
                @foreach ($categories as $entry)
                    @php
                        $category = $entry->model;
                        $available = $entry->available;
                        $preview = $entry->preview;
                        $isFull = $available === 0;
                    @endphp
                    <a href="{{ route('tenant.categories.show', $category) }}{{ $stayQuery }}"
                       class="cx-row {{ $isFull ? 'is-full' : '' }}" data-testid="category-card-{{ $category->id }}">
                        <div class="cx-row-photo">
                            @if ($entry->hero)
                                <img src="{{ $entry->hero->getUrl('medium') }}" alt="{{ $entry->hero->getCustomProperty('alt') ?: $category->name }}" loading="lazy">
                            @endif
                        </div>
                        <div class="cx-row-body">
                            <div class="cx-row-top">
                                <h3 class="cx-row-name">{{ $category->name }}</h3>
                                @if ($available !== null)
                                    <span class="cx-avail {{ $isFull ? 'is-full' : ($available === 1 ? 'is-low' : '') }}" data-testid="availability-badge-{{ $category->id }}">
                                        {{ $isFull ? 'Full for these dates' : ($available === 1 ? 'Only 1 left' : $available.' left for your dates') }}
                                    </span>
                                @elseif ($entry->totalActive > 1)
                                    <span class="cx-avail is-full">{{ $entry->totalActive }} units</span>
                                @endif
                            </div>
                            <div class="cx-card-meta">{{ $category->type }}. Sleeps {{ $category->maxOccupancy() }}.</div>
                            <div class="cx-tags">
                                <span class="cx-tag">{{ $category->capacity_adults }} {{ $category->capacity_adults === 1 ? 'adult' : 'adults' }}</span>
                                @if ($category->capacity_children > 0)
                                    <span class="cx-tag">{{ $category->capacity_children }} {{ $category->capacity_children === 1 ? 'child' : 'children' }}</span>
                                @endif
                                @if ($category->min_nights > 1)
                                    <span class="cx-tag">Minimum {{ $category->min_nights }} nights</span>
                                @endif
                            </div>
                            <div class="cx-row-foot">
                                <div>
                                    <div class="cx-price">₦{{ number_format($category->base_rate) }} <small>/ night</small></div>
                                    @if ($preview)
                                        <div class="cx-row-total">₦{{ number_format($preview->totalDue) }} for {{ $preview->nights }} {{ $preview->nights === 1 ? 'night' : 'nights' }}, all fees included</div>
                                    @endif
                                </div>
                                <span class="cx-row-cta">{{ $isFull ? 'Change dates' : 'Choose' }}</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
