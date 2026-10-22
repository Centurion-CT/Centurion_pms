@php
    $photos = $category->getMedia('gallery');
    $photoCount = $photos->count();

    // Cap visible tiles at 5, the lightbox shows the rest. The CSS
    // grid below switches layout based on this count.
    $tileCount = min(5, $photoCount);
    $layoutKey = $photoCount === 0
        ? 'empty'
        : ($photoCount >= 5 ? 'many' : (string) $photoCount);

    // URL list for the JS lightbox. Each entry is the medium URL +
    // alt text; the lightbox <img> swaps src on prev/next.
    $lightboxPhotos = $photos->map(fn ($m) => [
        'url' => $m->getUrl('full'),
        'alt' => $m->getCustomProperty('alt') ?: $category->name,
    ])->values();

    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

{{-- Phase 3 category detail. Looks like room-detail but operates on a
     RoomCategory. On submit, RoomAllocator picks a specific physical
     room from this category, then CreateBooking runs as usual. The
     guest never sees the unit-level pick, only the class. --}}
<div class="rd-page" wire:poll.15s="refresh">
    @include('partials.public.detail-styles')

    <div class="rd-wrap">

        {{-- ── Breadcrumbs + branch switcher ─────────────────────── --}}
        <div class="rd-crumbs-row">
            <p class="rd-crumbs">
                <a href="{{ route('tenant.rooms.browse') }}">Rooms</a>
                <span class="sep">/</span>
                <span>{{ $category->name }}</span>
            </p>
            @include('tenant.partials.branch-switcher', [
                'branches' => $branches ?? collect(),
                'current' => $property,
            ])
        </div>

        {{-- ── Header ─────────────────────────────────────────────
             Tightened per the booking-first pass: no serif eyebrow,
             room name leads, everything else collapses onto a single
             meta line. Rating chip pulled out so it reads as social
             proof, not just another middle-dot item. --}}
        <header class="rd-header">
            <div style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 0.75rem;">
                <h1 data-testid="category-name" class="rd-title">{{ $category->name }}</h1>
                @if ($rating['count'] > 0)
                    <span class="rd-rating" style="padding: 4px 12px; background: var(--rd-paper-2, #F2EEE6); border-radius: 9999px; font-size: 13px;">
                        <span class="star">★</span>
                        <strong>{{ $rating['average'] }}</strong>
                        <span class="mute">&middot; {{ $rating['count'] }} review{{ $rating['count'] === 1 ? '' : 's' }}</span>
                    </span>
                @endif
            </div>
            <div class="rd-meta" style="margin-top: 0.6rem;">
                <span data-testid="category-type">{{ $category->type }}</span>
                <span class="rd-meta-dot">·</span>
                <span>Sleeps up to {{ $category->maxOccupancy() }}</span>
                <span class="rd-meta-dot">·</span>
                <span>Min {{ $category->min_nights }} night{{ $category->min_nights === 1 ? '' : 's' }}</span>
                @if ($totalActive > 0)
                    <span class="rd-meta-dot">·</span>
                    <span data-testid="category-units">
                        @if ($isFree !== null)
                            {{ $availableCount }} of {{ $totalActive }} available
                        @elseif ($totalActive > 1)
                            {{ $totalActive }} units
                        @endif
                    </span>
                @endif
            </div>
        </header>

        {{-- ── Gallery ──────────────────────────────────────────── --}}
        @if ($photoCount === 0)
            <div class="rd-gallery" data-count="empty" data-testid="gallery">
                <div class="g-empty" style="grid-column: 1 / -1; grid-row: 1 / -1; min-height: clamp(360px, 50vw, 480px);">
                    Photos coming soon
                </div>
            </div>
        @else
            @php $remainingAfterFifth = max(0, $photoCount - 5); @endphp
            <div class="rd-gallery"
                 data-count="{{ $layoutKey }}"
                 data-photo-count="{{ $photoCount }}"
                 data-testid="gallery">
                @foreach ($photos->take($tileCount) as $i => $m)
                    @php $isFifthWithMore = $i === 4 && $remainingAfterFifth > 0; @endphp
                    <button type="button" class="g-tile"
                            data-testid="g-tile-{{ $i }}"
                            data-lightbox-open
                            data-lightbox-index="{{ $i }}">
                        <x-blurred-image :media="$m"
                            conversion="medium"
                            :alt="$m->getCustomProperty('alt') ?: $category->name"
                            :loading="$i === 0 ? 'eager' : 'lazy'"
                            wrapperClass="g-tile-img" />
                        @if ($isFifthWithMore)
                            <span class="g-more" aria-hidden>
                                + {{ $remainingAfterFifth }} more
                                <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif

        {{-- ── Lightbox ─────────────────────────────────────────── --}}
        @if ($photoCount > 0)
            <div class="rd-lightbox" data-rd-lightbox data-open="false"
                 role="dialog" aria-modal="true" aria-label="Photo viewer">
                <div class="rd-lightbox-stage">
                    <img src="" alt="" data-rd-lightbox-img>
                    <button type="button" class="rd-lightbox-close"
                            data-rd-lightbox-close aria-label="Close">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 5l10 10M15 5L5 15"/></svg>
                    </button>
                    @if ($photoCount > 1)
                        <button type="button" class="rd-lightbox-prev"
                                data-rd-lightbox-prev aria-label="Previous photo">
                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13 5L7 11l6 6"/></svg>
                        </button>
                        <button type="button" class="rd-lightbox-next"
                                data-rd-lightbox-next aria-label="Next photo">
                            <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l6 6-6 6"/></svg>
                        </button>
                    @endif
                    <div class="rd-lightbox-counter" data-rd-lightbox-counter></div>
                </div>
            </div>

            {{-- Tiny vanilla controller so the lightbox works without
                 a Livewire round-trip on every click. Photos are
                 embedded as JSON and indexed by the tile's
                 data-lightbox-index. --}}
            <script>
                (function () {
                    var root = document.querySelector('[data-rd-lightbox]');
                    if (!root) return;
                    var photos = @json($lightboxPhotos);
                    if (!photos.length) return;

                    var img      = root.querySelector('[data-rd-lightbox-img]');
                    var counter  = root.querySelector('[data-rd-lightbox-counter]');
                    var closeBtn = root.querySelector('[data-rd-lightbox-close]');
                    var prevBtn  = root.querySelector('[data-rd-lightbox-prev]');
                    var nextBtn  = root.querySelector('[data-rd-lightbox-next]');

                    var index = 0;
                    function render() {
                        var p = photos[index];
                        if (!p) return;
                        img.src = p.url;
                        img.alt = p.alt || '';
                        if (counter) counter.textContent = (index + 1) + ' / ' + photos.length;
                    }
                    function open(i) {
                        index = ((i % photos.length) + photos.length) % photos.length;
                        render();
                        root.setAttribute('data-open', 'true');
                        document.body.style.overflow = 'hidden';
                    }
                    function close() {
                        root.setAttribute('data-open', 'false');
                        document.body.style.overflow = '';
                    }
                    function next() { open(index + 1); }
                    function prev() { open(index - 1); }

                    document.querySelectorAll('[data-lightbox-open]').forEach(function (el) {
                        el.addEventListener('click', function (e) {
                            e.preventDefault();
                            open(parseInt(el.getAttribute('data-lightbox-index') || '0', 10));
                        });
                    });
                    closeBtn && closeBtn.addEventListener('click', close);
                    nextBtn  && nextBtn.addEventListener('click', next);
                    prevBtn  && prevBtn.addEventListener('click', prev);

                    // Click outside the image closes; clicks ON the
                    // image itself don't bubble through.
                    root.addEventListener('click', function (e) {
                        if (e.target === root) close();
                    });

                    document.addEventListener('keydown', function (e) {
                        if (root.getAttribute('data-open') !== 'true') return;
                        if (e.key === 'Escape')      close();
                        if (e.key === 'ArrowRight')  next();
                        if (e.key === 'ArrowLeft')   prev();
                    });
                })();
            </script>
        @endif

        {{-- ── Body: content + booking widget ───────────────────── --}}
        <div class="rd-body">

            {{-- Left: content sections --}}
            <div>
                @if ($category->description)
                    <section class="rd-section" data-testid="category-description">
                        <h2>About this room</h2>
                        <p class="rd-prose">{!! nl2br(e($category->description)) !!}</p>
                    </section>
                @endif

                @if ($category->rules)
                    <section class="rd-section" data-testid="category-rules">
                        <h2>House rules</h2>
                        <p class="rd-prose">{!! nl2br(e($category->rules)) !!}</p>
                    </section>
                @endif

                @if ($property && $property->amenities)
                    <section class="rd-section" data-testid="property-amenities">
                        <h2>What&rsquo;s included</h2>
                        <ul class="rd-amenities">
                            @foreach ($property->amenities as $a)
                                <li>{{ is_string($a) ? $a : ($a['label'] ?? '') }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($property && $property->address)
                    <section class="rd-section" data-testid="room-location">
                        <h2>Where you&rsquo;ll be</h2>
                        <p class="rd-prose">
                            {{ collect([$property->address, $property->city, $property->state])->filter()->unique()->implode(', ') }}
                        </p>
                        @if ($property->isSectionVisible('map'))
                            <div class="rd-map">
                                <iframe loading="lazy"
                                        src="https://www.google.com/maps?q={{ urlencode($property->address.', '.($property->city ?? '').', '.($property->state ?? '')) }}&output=embed"
                                        style="width:100%; height:320px; border:0; display:block;"></iframe>
                            </div>
                        @endif
                    </section>
                @endif

                {{-- Good to know — check-in / check-out + late-fee.
                     Same block as the room-detail and landing pages.
                     Sits after location so logistics-first, then social
                     proof. Whole section self-hides when nothing is
                     configured; late-fee tile self-hides when the
                     policy switch is off. --}}
                @php
                    $gtkRules = $property?->houseRules() ?? [];
                    $gtkPolicy = $property?->checkoutPolicy() ?? [
                        'late_checkout_enabled' => false,
                        'late_checkout_grace_minutes' => 0,
                        'late_checkout_hourly_fee' => 0,
                        'late_checkout_full_day_after_hours' => 0,
                    ];
                    $gtkFmt = static function (string $t): string {
                        if ($t === '' || ! preg_match('/^(\d{1,2}):(\d{2})$/', $t)) return '';
                        try { return \Carbon\Carbon::createFromFormat('H:i', $t)->format('g:i A'); }
                        catch (\Throwable) { return ''; }
                    };
                    $gtkInFrom = $gtkFmt($gtkRules['check_in_from'] ?? '');
                    $gtkInTo = $gtkFmt($gtkRules['check_in_to'] ?? '');
                    $gtkOutFrom = $gtkFmt($gtkRules['check_out_from'] ?? '');
                    $gtkOutTo = $gtkFmt($gtkRules['check_out_to'] ?? '');
                    $gtkInText = match (true) {
                        $gtkInFrom !== '' && $gtkInTo !== '' => "{$gtkInFrom} to {$gtkInTo}",
                        $gtkInFrom !== '' => "from {$gtkInFrom}",
                        $gtkInTo !== '' => "until {$gtkInTo}",
                        default => '',
                    };
                    $gtkOutText = match (true) {
                        $gtkOutFrom !== '' && $gtkOutTo !== '' => "{$gtkOutFrom} to {$gtkOutTo}",
                        $gtkOutFrom !== '' => "from {$gtkOutFrom}",
                        $gtkOutTo !== '' => "until {$gtkOutTo}",
                        default => '',
                    };
                    $gtkFeeEnabled = ! empty($gtkPolicy['late_checkout_enabled'])
                        && ((int) $gtkPolicy['late_checkout_hourly_fee'] > 0
                            || (int) $gtkPolicy['late_checkout_full_day_after_hours'] > 0);
                    $gtkFeeLines = [];
                    if ($gtkFeeEnabled) {
                        $deadline = $gtkOutTo !== '' ? $gtkOutTo : ($gtkOutFrom !== '' ? $gtkOutFrom : 'the end of check-out');
                        if ((int) $gtkPolicy['late_checkout_hourly_fee'] > 0) {
                            $gtkFeeLines[] = '₦'.number_format((int) $gtkPolicy['late_checkout_hourly_fee']).'/hour past '.$deadline
                                .((int) $gtkPolicy['late_checkout_grace_minutes'] > 0
                                    ? ' ('.(int) $gtkPolicy['late_checkout_grace_minutes'].'-min grace)'
                                    : '')
                                .'.';
                        }
                        if ((int) $gtkPolicy['late_checkout_full_day_after_hours'] > 0) {
                            $gtkFeeLines[] = 'Stay '.(int) $gtkPolicy['late_checkout_full_day_after_hours'].'+ hours late and the full room rate is charged.';
                        }
                    }
                    $gtkAny = $gtkInText !== '' || $gtkOutText !== '' || $gtkFeeEnabled;
                @endphp
                @if ($gtkAny)
                    <section class="rd-section" data-testid="category-good-to-know">
                        <h2>Good to know</h2>
                        <div class="rd-gtk-grid">
                            @if ($gtkInText !== '')
                                <div class="rd-gtk-tile" data-testid="cd-gtk-checkin">
                                    <span class="rd-gtk-label">Check-in</span>
                                    <span class="rd-gtk-value">{{ $gtkInText }}</span>
                                </div>
                            @endif
                            @if ($gtkOutText !== '')
                                <div class="rd-gtk-tile" data-testid="cd-gtk-checkout">
                                    <span class="rd-gtk-label">Check-out</span>
                                    <span class="rd-gtk-value">{{ $gtkOutText }}</span>
                                </div>
                            @endif
                            @if ($gtkFeeEnabled)
                                <div class="rd-gtk-tile rd-gtk-tile--wide" data-testid="cd-gtk-late-fee">
                                    <span class="rd-gtk-label">Late check-out fee</span>
                                    <span class="rd-gtk-value">{{ implode(' ', $gtkFeeLines) }}</span>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif

                @if ($reviews->isNotEmpty())
                    <section class="rd-section" data-testid="property-reviews">
                        <h2>What guests say <span style="color: var(--rd-ink-mute); font-weight: 400; font-size: 0.75em; letter-spacing: 0;">·  {{ $rating['count'] }}</span></h2>
                        <div class="rd-reviews">
                            @foreach ($reviews as $r)
                                <blockquote class="rd-review">
                                    <div class="stars">
                                        {{ str_repeat('★', $r->rating) }}<span class="empty">{{ str_repeat('★', 5 - $r->rating) }}</span>
                                    </div>
                                    <p>&ldquo;{{ $r->body }}&rdquo;</p>
                                    <div class="rd-review-foot">
                                        <cite>- {{ $r->guest_name }}</cite>
                                        @if ($r->isVerifiedStay())
                                            <span data-testid="verified-stay" class="rd-verified">
                                                Verified stay
                                            </span>
                                        @endif
                                    </div>
                                </blockquote>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            {{-- Right: booking widget --}}
            <aside id="book" class="rd-widget-wrap">
                @include('partials.public.booking-widget', [
                    'bookable' => $category,
                    'isCategory' => true,
                    'notAvailableText' => 'Every room of this type is booked for these dates. Please pick other dates.',
                    'totalActive' => $totalActive,
                    'corporateContext' => $this->corporateContext,
                ])
            </aside>
        </div>

        {{-- ── Sticky mobile booking CTA ─────────────────────────
             Anchors back to #book so the guest fills the form on
             the actual widget, no duplicate state to keep in sync.
             Hidden on tablets+ via the .rd-mobile-cta media query. --}}
        @php
            // Reuse the same final-total math the inline price summary
            // does, so the sticky bar can never disagree with the form.
            $mobileCtaOff = (int) ($appliedDiscountAmountOff ?? 0);
            $mobileCtaTotal = $preview ? max(0, $preview->totalDue - $mobileCtaOff) : null;
        @endphp
        <a href="#book" class="rd-mobile-cta" data-testid="rd-mobile-cta">
            <span class="rd-mobile-cta-price">
                @if ($mobileCtaTotal !== null)
                    ₦{{ number_format($mobileCtaTotal) }}
                    <span class="per">total · {{ $preview->nights }} night{{ $preview->nights === 1 ? '' : 's' }}</span>
                @else
                    ₦{{ number_format($category->base_rate) }}
                    <span class="per">/ night</span>
                @endif
            </span>
            <span class="rd-mobile-cta-btn">
                Book
                <span class="dot">{!! $pillArrow !!}</span>
            </span>
        </a>

        {{-- Property-level house rules band removed, the category's
             own rules already render in the left content column when
             $category->rules is set, and having the property band
             underneath duplicated the content. Operators who want a
             property-wide rules page can surface one via the mini-CMS
             Pages feature. --}}

        {{-- ── Related categories ───────────────────────────────── --}}
        @if ($relatedCategories->isNotEmpty())
            <section class="rd-related" data-testid="related-categories">
                <div class="rd-related-head">
                    <div>
                        <span class="rd-eyebrow">Also at {{ $property?->name }}</span>
                        <h2 class="mt-3">Other rooms you might like</h2>
                    </div>
                </div>

                <div class="rd-related-track">
                    @foreach ($relatedCategories as $r)
                        @php $hero = $r->heroImage(); @endphp
                        <a href="{{ route('tenant.categories.show', $r) }}" class="rd-related-card"
                           data-testid="related-category-{{ $r->id }}">
                            <div class="rd-related-photo">
                                @if ($hero)
                                    <img src="{{ $hero->getUrl('medium') }}"
                                         alt="{{ $hero->getCustomProperty('alt') ?: $r->name }}"
                                         loading="lazy">
                                @endif
                            </div>
                            <div class="rd-related-body">
                                <div class="rd-related-name">{{ $r->name }}</div>
                                <div class="rd-related-meta">
                                    {{ $r->type }} &middot; sleeps {{ $r->maxOccupancy() }}
                                </div>
                                <div class="rd-related-foot">
                                    <span class="rd-related-price">
                                        <span class="from">From</span>
                                        ₦{{ number_format($r->base_rate) }}
                                        <span class="per">/ night</span>
                                    </span>
                                    <span class="rd-related-cta">
                                        View →
                                    </span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
