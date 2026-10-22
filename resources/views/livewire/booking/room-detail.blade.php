@php
    $photos = $room->getMedia('gallery');
    $heroPhoto = $photos->first();
    $thumbs = $photos->skip(1)->take(4);

    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

{{-- Per-tenant guest-facing room detail. Inherits --primary,
     --display-font, --body-font from layouts.tenant-public so accents
     follow the operator's brand. The cream/ink palette matches the
     property landing page so a guest moving from the landing to a room
     never feels they crossed a brand boundary. --}}
<div class="rd-page" wire:poll.15s="refresh">
    @include('partials.public.detail-styles')

    <div class="rd-wrap">

        {{-- ── Breadcrumbs + branch switcher ─────────────────────── --}}
        <div class="rd-crumbs-row">
            <p class="rd-crumbs">
                <a href="{{ route('tenant.rooms.browse') }}">Rooms</a>
                <span class="sep">/</span>
                <span>{{ $room->name }}</span>
            </p>
            @include('tenant.partials.branch-switcher', [
                'branches' => $branches ?? collect(),
                'current' => $property,
            ])
        </div>

        {{-- ── Header ─────────────────────────────────────────────
             Same tightening pass as category-detail: no serif eyebrow,
             room name leads with the rating chip pulled out to sit
             beside it. Meta line collapses type / occupancy / min-nights
             onto a single row. --}}
        <header class="rd-header">
            <div style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 0.75rem;">
                <h1 data-testid="room-name" class="rd-title">{{ $room->name }}</h1>
                @if ($rating['count'] > 0)
                    <span class="rd-rating" style="padding: 4px 12px; background: var(--rd-paper-2, #F2EEE6); border-radius: 9999px; font-size: 13px;">
                        <span class="star">★</span>
                        <strong>{{ $rating['average'] }}</strong>
                        <span class="mute">&middot; {{ $rating['count'] }} review{{ $rating['count'] === 1 ? '' : 's' }}</span>
                    </span>
                @endif
            </div>
            <div class="rd-meta" style="margin-top: 0.6rem;">
                <span data-testid="room-type">{{ $room->type }}</span>
                <span class="rd-meta-dot">·</span>
                <span>Sleeps up to {{ $room->maxOccupancy() }}</span>
                <span class="rd-meta-dot">·</span>
                <span>Min {{ $room->min_nights }} night{{ $room->min_nights === 1 ? '' : 's' }}</span>
            </div>
        </header>

        {{-- ── Gallery ──────────────────────────────────────────── --}}
        <div class="rd-gallery" data-testid="gallery">
            @if ($heroPhoto)
                <div class="g-main">
                    <img src="{{ $heroPhoto->getUrl('medium') }}"
                         alt="{{ $heroPhoto->getCustomProperty('alt') ?: $room->name }}">
                </div>
                @foreach ($thumbs as $m)
                    <div>
                        <img src="{{ $m->getUrl('medium') }}"
                             alt="{{ $m->getCustomProperty('alt') ?: $room->name }}"
                             loading="lazy">
                    </div>
                @endforeach
            @else
                <div class="g-main g-empty">Photos coming soon</div>
            @endif
        </div>

        {{-- ── Body: content + booking widget ───────────────────── --}}
        <div class="rd-body">

            {{-- Left: content sections --}}
            <div>
                @if ($room->description)
                    <section class="rd-section" data-testid="room-description">
                        <h2>About this room</h2>
                        <p class="rd-prose">{!! nl2br(e($room->description)) !!}</p>
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
                     Sits between the location and reviews so a guest
                     evaluating this exact room sees the logistics
                     before the social-proof scroll. Whole section
                     self-hides when no field is populated; late-fee
                     tile self-hides when the policy switch is off. --}}
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
                    <section class="rd-section" data-testid="room-good-to-know">
                        <h2>Good to know</h2>
                        <div class="rd-gtk-grid">
                            @if ($gtkInText !== '')
                                <div class="rd-gtk-tile" data-testid="rd-gtk-checkin">
                                    <span class="rd-gtk-label">Check-in</span>
                                    <span class="rd-gtk-value">{{ $gtkInText }}</span>
                                </div>
                            @endif
                            @if ($gtkOutText !== '')
                                <div class="rd-gtk-tile" data-testid="rd-gtk-checkout">
                                    <span class="rd-gtk-label">Check-out</span>
                                    <span class="rd-gtk-value">{{ $gtkOutText }}</span>
                                </div>
                            @endif
                            @if ($gtkFeeEnabled)
                                <div class="rd-gtk-tile rd-gtk-tile--wide" data-testid="rd-gtk-late-fee">
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
                    'bookable' => $room,
                    'isCategory' => false,
                    'notAvailableText' => 'This room is booked for these dates. Please pick other dates.',
                    'totalActive' => 1,
                    'corporateContext' => $this->corporateContext,
                ])
            </aside>
        </div>

        {{-- ── Sticky mobile booking CTA ─────────────────────────
             Anchors back to #book so the guest fills the form on
             the actual widget, no duplicate state to keep in sync.
             Hidden on tablets+ via the .rd-mobile-cta media query. --}}
        @php
            $mobileCtaOff = (int) ($appliedDiscountAmountOff ?? 0);
            $mobileCtaTotal = $preview ? max(0, $preview->totalDue - $mobileCtaOff) : null;
        @endphp
        <a href="#book" class="rd-mobile-cta" data-testid="rd-mobile-cta">
            <span class="rd-mobile-cta-price">
                @if ($mobileCtaTotal !== null)
                    ₦{{ number_format($mobileCtaTotal) }}
                    <span class="per">total · {{ $preview->nights }} night{{ $preview->nights === 1 ? '' : 's' }}</span>
                @else
                    ₦{{ number_format($room->base_rate) }}
                    <span class="per">/ night</span>
                @endif
            </span>
            <span class="rd-mobile-cta-btn">
                Book
                <span class="dot">{!! $pillArrow !!}</span>
            </span>
        </a>

        {{-- ── Related rooms ────────────────────────────────────── --}}
        @if ($relatedRooms->isNotEmpty())
            <section class="rd-related" data-testid="related-rooms">
                <div class="rd-related-head">
                    <div>
                        <span class="rd-eyebrow">Also at {{ $property?->name }}</span>
                        <h2 class="mt-3">Other rooms you might like</h2>
                    </div>
                </div>

                <div class="rd-related-track">
                    @foreach ($relatedRooms as $r)
                        @php $hero = $r->heroImage(); @endphp
                        <a href="/rooms/{{ $r->id }}" class="rd-related-card"
                           data-testid="related-room-{{ $r->id }}">
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
