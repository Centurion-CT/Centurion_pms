{{--
    Sticky availability search + category list, Livewire-driven.
    The "Check availability" button runs submit() which sanitises +
    re-renders in-place; the surrounding show page never reloads.
    wire:loading toggles the loader on the button so the user sees
    progress while the categoryAvailability() query runs.

    Category cards mirror the shape shipped on the plain-Blade
    version so the two visually match; only difference is the
    Livewire root wrapper + button loader spans.
--}}
@php
    $today = \Illuminate\Support\Carbon::today()->toDateString();
    $tomorrow = \Illuminate\Support\Carbon::today()->addDay()->toDateString();
    $checkinMin = $today;
    $checkoutMin = $from !== ''
        ? \Illuminate\Support\Carbon::parse($from)->addDay()->toDateString()
        : $tomorrow;
@endphp
<section class="mp-section" data-testid="stays-show-rooms">
    @php
        // Return-to-checkout floating pill - rendered fixed at the
        // bottom-right of the viewport when the guest came here
        // from the checkout's "Add another room" link + already
        // has room(s) in the cart. Always visible while they
        // scroll the property page + doesn't compete with the top
        // nav or the rooms section.
        $returnCartIds = array_values(array_filter(
            array_map('intval', array_map('trim', explode(',', (string) ($this->cart ?? '')))),
            fn ($v) => (int) $v > 0,
        ));
        $returnCartCount = count($returnCartIds);
        if ($returnCartCount > 0 && $property !== null) {
            $returnCheckoutUrl = route('marketplace.stays.book', ['slug' => $property->public_slug])
                .'?'.http_build_query(array_filter([
                    'rooms'    => $returnCartCount > 1 ? implode(',', $returnCartIds) : null,
                    'room'     => $returnCartCount === 1 ? $returnCartIds[0] : null,
                    'from'     => $this->from,
                    'to'       => $this->to,
                    'adults'   => $this->adults ?: null,
                    'children' => $this->children ?: null,
                ], fn ($v) => $v !== null && $v !== ''));
        } else {
            $returnCheckoutUrl = null;
        }
    @endphp
    @if ($returnCheckoutUrl !== null)
        <a href="{{ $returnCheckoutUrl }}"
           class="mp-return-checkout"
           data-testid="stays-show-return-checkout">
            <span class="mp-return-checkout-badge">{{ $returnCartCount }}</span>
            <span class="mp-return-checkout-label">
                <span class="mp-return-checkout-title">Return to checkout</span>
                <span class="mp-return-checkout-count">
                    {{ $returnCartCount }} room{{ $returnCartCount === 1 ? '' : 's' }} in cart
                </span>
            </span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 6l6 6-6 6"/>
            </svg>
        </a>
    @endif

    @php
        // Mirror the /stays checkout: single-unit properties are ONE
        // listing, so the section heading + availability chip use
        // "unit" copy instead of the multi-room "room" default.
        $isSingleUnitProperty = ($property?->unit_mode ?? 'multi_room') === 'single_unit';
    @endphp
    <h2>{{ $isSingleUnitProperty ? 'Availability' : 'Rooms available' }}</h2>

    {{-- Sentinel for the show page's scroll-hide observer.
         Placed just above the Livewire root so the sticky
         search bar takes the top row uncontested when the
         user scrolls past. --}}
    <div data-mp-availbar-sentinel aria-hidden="true"
         style="width: 100%; height: 1px; margin-bottom: -1px;"></div>

    @if ($this->auto_shift_notice !== '')
        <div class="mp-avail-notice"
             role="status"
             data-testid="stays-show-avail-notice"
             style="display: flex; align-items: flex-start; gap: 10px;
                    padding: 10px 14px; margin-bottom: 10px;
                    background: #FCEFD4; color: #6B4C00;
                    border: 1px solid #F0DA9B; border-radius: 10px;
                    font-size: 13px; line-height: 1.5;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;">
                <circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>
            </svg>
            <span>{{ $this->auto_shift_notice }}</span>
        </div>
    @endif

    <form wire:submit="submit"
          class="mp-avail-bar" data-testid="stays-show-availbar">
        <div class="mp-avail-field">
            <span class="mp-avail-label">Check-in</span>
            <input type="date" wire:model.live.blur="from"
                   min="{{ $checkinMin }}"
                   data-testid="availbar-from">
        </div>
        <div class="mp-avail-field">
            <span class="mp-avail-label">Check-out</span>
            <input type="date" wire:model.live.blur="to"
                   min="{{ $checkoutMin }}"
                   data-testid="availbar-to">
        </div>
        <div class="mp-avail-field">
            <span class="mp-avail-label">Guests</span>
            <input type="number" wire:model.blur="adults"
                   min="1" max="12"
                   data-testid="availbar-adults">
        </div>
        <button type="submit" class="mp-avail-bar-submit"
                wire:loading.attr="disabled" wire:target="submit,from,to,adults"
                data-testid="availbar-submit">
            <span wire:loading.remove wire:target="submit,from,to,adults">Check availability</span>
            <span wire:loading wire:target="submit,from,to,adults">Checking…</span>
        </button>
    </form>

    @if ($categories->isEmpty())
        <p style="color:#6A6A6A; font-size: 14px;">No rooms published yet.</p>
    @else
        <div wire:loading.class="opacity-60" wire:target="submit,from,to,adults">
            @foreach ($categories as $row)
                @php
                    /** @var \App\Models\RoomCategory $category */
                    $category = $row['category'];
                    $total   = (int) $row['total_units'];
                    $free    = (int) $row['available_units'];
                    $seedRoom = $row['first_available_room_id'];
                    $capacity = $category->maxOccupancy();
                    $catHero = method_exists($category, 'getFirstMediaUrl')
                        ? ($category->getFirstMediaUrl('gallery', 'medium') ?: '')
                        : '';
                    if ($catHero === '' && $property !== null) {
                        $catHero = $property->getFirstMediaUrl('gallery', 'medium')
                            ?: $property->getFirstMediaUrl('hero', 'medium');
                    }
                    // Effective rate resolved via RateResolver (falls back
                    // to base_rate when no rule matches). $baseRate is
                    // the un-adjusted price; when they differ we render
                    // a slash showing the original.
                    $rate = (int) ($row['rate'] ?? $category->base_rate);
                    $baseRate = (int) ($row['base_rate'] ?? $rate);
                    $rateRule = $row['rate_rule'] ?? null;
                    $stayNights = (int) ($row['stay_nights'] ?? 0);
                    $stayTotal = (int) ($row['stay_total'] ?? 0);
                    $stayBaseTotal = (int) ($row['stay_base_total'] ?? 0);

                    $availClass = $free === 0
                        ? 'mp-cat-avail-none'
                        : ($free <= 2 ? 'mp-cat-avail-low' : 'mp-cat-avail-ok');
                    $availLabel = $free === 0
                        ? 'Sold out for these dates'
                        : ($free === 1 ? '1 unit left' : $free.' units left');
                    if (! $hasDateFilter) {
                        $availLabel = $total === 1 ? '1 unit total' : $total.' units total';
                    }

                    // Cart pass-through: when the guest came back from
                    // the checkout's "Add another room" link, ?cart=
                    // carries the ids already in the cart. Reserve
                    // appends this room so the checkout opens on a
                    // fully-stacked ?rooms=X,Y,Z.
                    $incomingCartRaw = trim((string) ($this->cart ?? ''));
                    $incomingCart = array_values(array_filter(
                        array_map('intval', array_map('trim', explode(',', $incomingCartRaw))),
                        fn ($v) => (int) $v > 0,
                    ));
                    if ($incomingCart !== [] && $seedRoom !== null) {
                        $merged = $incomingCart;
                        if (! in_array($seedRoom, $merged, true)) $merged[] = $seedRoom;
                        $reserveHref = $bookBase.'?'.$qs([
                            'category' => $category->id,
                            'rooms'    => implode(',', $merged),
                        ]);
                    } else {
                        $reserveHref = $bookBase.'?'.$qs(
                            $seedRoom !== null
                                ? ['category' => $category->id, 'room' => $seedRoom]
                                : ['category' => $category->id]
                        );
                    }

                    $galleryImages = (array) ($row['gallery'] ?? []);
                    $galleryCount = count($galleryImages);
                    $galleryJson = json_encode(
                        array_map(fn ($img) => (string) $img['full'], $galleryImages),
                        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP,
                    ) ?: '[]';
                @endphp
                <div class="mp-cat-card {{ $free === 0 ? 'sold-out' : '' }}"
                     data-testid="category-card-{{ $category->id }}">
                    <div class="mp-cat-photo"
                         @if ($galleryCount > 0)
                             data-mp-gallery='{{ $galleryJson }}'
                             data-mp-gallery-title="{{ $category->name }}"
                         @endif
                         role="{{ $galleryCount > 0 ? 'button' : 'presentation' }}"
                         tabindex="{{ $galleryCount > 0 ? '0' : '-1' }}"
                         data-testid="category-photo-{{ $category->id }}">
                        @if ($catHero !== '')
                            <img src="{{ $catHero }}" alt="{{ $category->name }}">
                        @endif
                        @if ($galleryCount > 1)
                            <span class="mp-cat-photo-count"
                                  data-testid="category-photo-count-{{ $category->id }}">
                                <svg width="10" height="10" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="1.5" y="2.5" width="9" height="7" rx="1"/><circle cx="6" cy="6" r="1.6"/></svg>
                                {{ $galleryCount }}
                            </span>
                        @endif
                    </div>
                    @php
                        $staysCount = (int) ($row['stays_count'] ?? 0);
                        $ratingAvg = $row['rating_avg'] ?? null;
                        $ratingCount = (int) ($row['rating_count'] ?? 0);
                    @endphp
                    <div class="mp-cat-meta">
                        <h3>{{ $category->name }}</h3>
                        <div class="mp-cat-sub">
                            @if ($capacity > 0)
                                <span>Sleeps {{ $capacity }}</span>
                            @endif
                            <span class="mp-cat-avail {{ $availClass }}"
                                  data-testid="category-avail-{{ $category->id }}">
                                {{ $availLabel }}
                            </span>
                            @if ($ratingAvg !== null)
                                <span class="mp-cat-rating"
                                      title="{{ $ratingCount }} {{ $ratingCount === 1 ? 'review' : 'reviews' }}"
                                      data-testid="category-rating-{{ $category->id }}">
                                    <span class="mp-cat-rating-star">★</span>
                                    {{ number_format($ratingAvg, 1) }}
                                    <span class="mp-cat-rating-count">· {{ $ratingCount }}</span>
                                </span>
                            @endif
                            @if ($staysCount > 0)
                                <span class="mp-cat-stays"
                                      data-testid="category-stays-{{ $category->id }}">
                                    {{ number_format($staysCount) }} {{ $staysCount === 1 ? 'stay' : 'stays' }} hosted
                                </span>
                            @endif
                        </div>
                        @if (! empty($category->description))
                            <p class="mp-cat-blurb">{{ $category->description }}</p>
                        @endif
                    </div>
                    <div class="mp-cat-cta">
                        @if ($rate > 0)
                            <div class="mp-cat-price"
                                 data-testid="category-price-{{ $category->id }}">
                                @if ($rateRule && $baseRate > 0 && $baseRate !== $rate)
                                    <s style="color: #9A9A9A; font-weight: 500; margin-right: 6px;"
                                       data-testid="category-price-was-{{ $category->id }}">&#8358;{{ number_format($baseRate) }}</s>
                                @endif
                                &#8358;{{ number_format($rate) }}<span class="mp-cat-suffix"> / night</span>
                            </div>
                            @if ($stayNights > 0 && $stayTotal > 0)
                                <div style="font-size: 12px; color: #6A6A6A; margin-top: 2px;"
                                     data-testid="category-stay-total-{{ $category->id }}">
                                    @if ($stayBaseTotal > $stayTotal)
                                        <s style="color: #9A9A9A;">&#8358;{{ number_format($stayBaseTotal) }}</s>
                                    @endif
                                    <span style="font-weight: 600; color: #222;">&#8358;{{ number_format($stayTotal) }}</span>
                                    for {{ $stayNights }} {{ $stayNights === 1 ? 'night' : 'nights' }}
                                </div>
                            @endif
                        @endif
                        @if ($free === 0)
                            <span class="mp-cat-soldout" data-testid="category-soldout-{{ $category->id }}">
                                Sold out
                            </span>
                        @else
                            <a href="{{ $reserveHref }}"
                               class="mp-cat-reserve" wire:navigate.hover
                               data-testid="category-reserve-{{ $category->id }}">Reserve</a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
