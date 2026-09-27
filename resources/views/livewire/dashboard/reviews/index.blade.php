@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <x-dash-toolbar
        eyebrow="Brand"
        title="Reviews"
        subtitle="Guest reviews for your property and individual rooms.">
        <x-slot:actions>
            <a href="{{ route('tenant.reviews.create') }}" wire:navigate
               data-testid="new-review" class="lp-pill">
                Add review
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </a>
        </x-slot:actions>
    </x-dash-toolbar>

    {{-- ── Aggregate card ──────────────────────────────────────────
         Above the filter so an operator scanning the page reads
         "here's the shape of my reviews" before drilling into the
         list. Three panels: score + histogram, monthly cadence
         sparkline, and the top words guests are actually typing. --}}
    @if ($reviewCount > 0)
        <section class="lp-dash-card lp-review-agg" data-testid="reviews-aggregate"
                 style="display: grid; gap: 24px; grid-template-columns: 1.1fr 1.4fr 1.4fr; align-items: stretch;">
            {{-- 1) Rating score + histogram ------------------------------ --}}
            <div style="display: flex; flex-direction: column; justify-content: space-between; gap: 16px;">
                <div>
                    <span class="lp-eyebrow"
                          style="font-family: ui-monospace, SFMono-Regular, monospace; font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">
                        Overall
                    </span>
                    <div style="display: flex; align-items: baseline; gap: 10px; margin-top: 4px;">
                        <span class="lp-num-lg"
                              data-testid="reviews-avg"
                              style="font-family: var(--font-display); font-weight: 500; font-size: 44px; line-height: 1; letter-spacing: -0.02em; color: var(--color-ink);">
                            {{ $avgRating !== null ? number_format($avgRating, 1) : '-' }}
                        </span>
                        <span style="font-size: 13.5px; color: var(--color-ink-soft);">
                            / 5 &middot; {{ $reviewCount }} {{ $reviewCount === 1 ? 'review' : 'reviews' }}
                        </span>
                    </div>
                    <p style="margin-top: 6px; font-size: 12.5px; color: var(--color-ink-soft);"
                       data-testid="reviews-response-rate">
                        {{ $responseCount }} replied to
                        @if ($reviewCount > 0)
                            &middot; {{ (int) round(($responseCount / $reviewCount) * 100) }}% response rate
                        @endif
                    </p>
                </div>
                {{-- Histogram — five 4px-min rows so even a 0-count row
                     reads as an axis rather than disappearing entirely. --}}
                <div style="display: grid; gap: 6px;">
                    @php $maxBucket = max(1, ...array_values($ratingHistogram)); @endphp
                    @foreach ($ratingHistogram as $star => $count)
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="width: 22px; font-size: 11.5px; color: var(--color-ink-mute); font-variant-numeric: tabular-nums;">
                                {{ $star }}★
                            </span>
                            <div style="flex: 1; height: 8px; background: color-mix(in srgb, var(--color-ink) 8%, transparent); border-radius: 4px; overflow: hidden;">
                                <div style="width: {{ ($count / $maxBucket) * 100 }}%;
                                            height: 100%;
                                            background: var(--color-ink);
                                            min-width: {{ $count > 0 ? '4px' : '0' }};"></div>
                            </div>
                            <span class="lp-mono" style="min-width: 20px; text-align: right; font-size: 11.5px; color: var(--color-ink-soft);">
                                {{ $count }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- 2) Monthly trend sparkline ------------------------------- --}}
            <div>
                <span class="lp-eyebrow"
                      style="font-family: ui-monospace, SFMono-Regular, monospace; font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">
                    Last 12 months
                </span>
                @php
                    $maxCount = max(1, ...array_map(fn ($m) => $m['count'], $monthlyTrend));
                    $ratingLine = [];
                    foreach ($monthlyTrend as $i => $m) {
                        // Map rating 0..5 to y in an 80px band. Empty
                        // months collapse to the baseline so the line
                        // reads "no reviews here" without a break.
                        $x = ($i / max(1, count($monthlyTrend) - 1)) * 100;
                        $y = 80 - ((($m['avg'] ?? 0) / 5) * 76);
                        $ratingLine[] = round($x, 2).','.round($y, 2);
                    }
                    $ratingPath = 'M '.implode(' L ', $ratingLine);
                @endphp
                <svg data-testid="reviews-sparkline"
                     viewBox="0 0 100 100"
                     preserveAspectRatio="none"
                     style="width: 100%; height: 110px; margin-top: 10px; display: block;">
                    {{-- Gridline at rating = 4 (guest-perceived "good") --}}
                    <line x1="0" y1="{{ 80 - ((4 / 5) * 76) }}" x2="100" y2="{{ 80 - ((4 / 5) * 76) }}"
                          stroke="rgba(20, 23, 26, 0.08)" stroke-width="0.5" />
                    {{-- Volume bars in the background — thin, low-opacity --}}
                    @foreach ($monthlyTrend as $i => $m)
                        @php
                            $x = ($i / max(1, count($monthlyTrend) - 1)) * 100;
                            $h = ($m['count'] / $maxCount) * 18;
                        @endphp
                        <rect x="{{ $x - 2 }}" y="{{ 80 - $h }}" width="4" height="{{ $h }}"
                              fill="rgba(20, 23, 26, 0.1)" rx="0.5"></rect>
                    @endforeach
                    {{-- Rating trend line --}}
                    <path d="{{ $ratingPath }}"
                          fill="none"
                          stroke="var(--color-ink)"
                          stroke-width="1"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          vector-effect="non-scaling-stroke"></path>
                </svg>
                <div style="display: flex; justify-content: space-between; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 10px; letter-spacing: 0.06em; color: var(--color-ink-mute); margin-top: 4px;">
                    @foreach ($monthlyTrend as $i => $m)
                        @if ($i % 2 === 0)
                            <span>{{ $m['month'] }}</span>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- 3) Keyword cloud ------------------------------------------ --}}
            <div>
                <span class="lp-eyebrow"
                      style="font-family: ui-monospace, SFMono-Regular, monospace; font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">
                    Guests mention
                </span>
                @if (empty($topKeywords))
                    <p style="margin-top: 10px; font-size: 13px; color: var(--color-ink-soft);">
                        Reviews are still light. Themes will surface once guests use similar words twice.
                    </p>
                @else
                    <div data-testid="reviews-keywords"
                         style="display: flex; flex-wrap: wrap; gap: 6px 8px; margin-top: 10px;">
                        @php $maxKeyword = max(1, ...array_map(fn ($k) => $k['count'], $topKeywords)); @endphp
                        @foreach ($topKeywords as $kw)
                            @php
                                // Scale font-size 11 → 16px against the top mention so
                                // the operator sees "quiet was mentioned 12 times" bigger
                                // than "breakfast 3 times" without needing a number.
                                $scale = 11 + (($kw['count'] / $maxKeyword) * 5);
                                // Two-tone by polarity so an operator's eye
                                // lands on negatives ("noisy", "dirty") first
                                // — those are the words that need action,
                                // positives are the ones that need repeating.
                                $polarity = $kw['polarity'] ?? 'neutral';
                                [$bg, $fg, $meta] = match ($polarity) {
                                    'positive' => [
                                        'color-mix(in srgb, #4C956C 12%, transparent)',
                                        '#166534',
                                        '#166534',
                                    ],
                                    'negative' => [
                                        'color-mix(in srgb, #B85C42 14%, transparent)',
                                        '#B91C1C',
                                        '#B91C1C',
                                    ],
                                    default => [
                                        'color-mix(in srgb, var(--color-ink) 5%, transparent)',
                                        'var(--color-ink)',
                                        'var(--color-ink-mute)',
                                    ],
                                };
                            @endphp
                            <span data-polarity="{{ $polarity }}"
                                  data-testid="reviews-keyword-{{ $kw['term'] }}"
                                  style="display: inline-flex; align-items: center; gap: 4px;
                                         padding: 4px 10px; border-radius: 999px;
                                         background: {{ $bg }};
                                         color: {{ $fg }};
                                         font-size: {{ number_format($scale, 1) }}px; font-weight: {{ $polarity === 'neutral' ? 500 : 600 }};">
                                {{ $kw['term'] }}
                                <span class="lp-mono" style="font-size: 10px; color: {{ $meta }}; font-variant-numeric: tabular-nums; opacity: 0.75;">
                                    {{ $kw['count'] }}
                                </span>
                            </span>
                        @endforeach
                    </div>
                    <div style="display: flex; gap: 12px; margin-top: 10px; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute);">
                        <span style="display: inline-flex; align-items: center; gap: 5px;">
                            <span aria-hidden="true" style="width: 8px; height: 8px; border-radius: 999px; background: #166534;"></span>
                            Positive
                        </span>
                        <span style="display: inline-flex; align-items: center; gap: 5px;">
                            <span aria-hidden="true" style="width: 8px; height: 8px; border-radius: 999px; background: #B91C1C;"></span>
                            Needs attention
                        </span>
                    </div>
                @endif
            </div>
        </section>

        {{-- Collapse the aggregate to a single vertical column on
             narrower widths so nothing overflows or shrinks below its
             readable minimum. --}}
        <style>
            @media (max-width: 900px) {
                .lp-page .lp-review-agg { grid-template-columns: 1fr !important; }
            }
        </style>
    @endif

    {{-- ── Filters ──────────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Filter</span>
        </div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3 items-end">
            <div>
                <label for="scope" class="lp-form-label">Scope</label>
                <select id="scope" wire:model.live="scopeFilter"
                        data-testid="scope-filter" class="lp-input">
                    <option value="all">All reviews</option>
                    <option value="property">Property-wide only</option>
                    @foreach ($rooms as $r)
                        <option value="{{ $r->id }}">Room: {{ $r->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="lp-check-row" style="padding-top: 6px;">
                    <input type="checkbox" wire:model.live="includeHidden" data-testid="include-hidden">
                    <span>Include hidden reviews</span>
                </label>
            </div>
        </form>
    </section>

    {{-- ── Reviews table ────────────────────────────────────────── --}}
    @if ($reviews->isEmpty())
        <x-dash-empty
            testid="no-reviews"
            title="No reviews match the current filter."
            hint="Try clearing the filter above, or add a review to seed the list.">
            <x-slot:cta>
                <a href="{{ route('tenant.reviews.create') }}" wire:navigate class="lp-pill">
                    Add review
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </a>
            </x-slot:cta>
        </x-dash-empty>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Rating</th>
                            <th>Scope</th>
                            <th>Body</th>
                            <th>Displayed</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reviews as $review)
                            <tr data-testid="review-row-{{ $review->id }}"
                                style="@if (! $review->is_displayed) opacity: 0.55; @endif">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        @if ($review->photo_path)
                                            <img src="{{ $review->photoUrl() }}"
                                                 alt="Review photo"
                                                 loading="lazy"
                                                 data-testid="review-row-photo-{{ $review->id }}"
                                                 style="width: 32px; height: 32px; object-fit: cover; border-radius: 6px; border: 1px solid var(--color-line); flex-shrink: 0;">
                                        @else
                                            <span aria-hidden="true"
                                                  style="width: 32px; height: 32px; border-radius: 6px; background: var(--color-card-deep); border: 1px dashed var(--color-line); display: inline-flex; align-items: center; justify-content: center; color: var(--color-ink-mute); flex-shrink: 0;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="8" r="3"/>
                                                    <path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>
                                                </svg>
                                            </span>
                                        @endif
                                        <div style="min-width: 0;">
                                            <div>{{ $review->guest_name }}</div>
                                            @if (! empty($review->guest_country))
                                                @php $rowFlag = \App\Services\Locale\Countries::flagFor($review->guest_country); @endphp
                                                <div class="text-[11px]" style="color: var(--color-ink-mute); font-weight: 400;">
                                                    @if ($rowFlag)<span style="font-size: 12px;">{{ $rowFlag }}</span> @endif{{ $review->guest_country }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="lp-stars" title="{{ $review->rating }} / 5">
                                        {{ str_repeat('★', $review->rating) }}<span style="color: var(--color-ink-mute);">{{ str_repeat('★', 5 - $review->rating) }}</span>
                                    </span>
                                </td>
                                <td>
                                    @if ($review->room_id)
                                        <span class="lp-tag lp-tag--checked-in">
                                            {{ $review->room?->name ?? 'unknown' }}
                                        </span>
                                    @else
                                        <span class="lp-tag lp-tag--pending">Property-wide</span>
                                    @endif
                                </td>
                                <td style="color: var(--color-ink-soft); max-width: 420px;">
                                    <span style="font-style: italic; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        &ldquo;{{ $review->body }}&rdquo;
                                    </span>
                                </td>
                                <td>
                                    <button type="button"
                                            wire:click="toggleDisplayed({{ $review->id }})"
                                            data-testid="toggle-{{ $review->id }}"
                                            class="lp-tag {{ $review->is_displayed ? 'lp-tag--confirmed' : 'lp-tag--checked-out' }}"
                                            style="border: 0; cursor: pointer; font-family: inherit;">
                                        {{ $review->is_displayed ? 'Visible' : 'Hidden' }}
                                    </button>
                                </td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-4 text-[12.5px]"
                                         style="color: var(--color-ink-soft);">
                                        <a href="{{ route('tenant.reviews.edit', $review->id) }}" wire:navigate
                                           data-testid="edit-{{ $review->id }}"
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">Edit</a>
                                        <button type="button" wire:click="delete({{ $review->id }})"
                                                wire:confirm="Delete this review?"
                                                data-testid="delete-{{ $review->id }}"
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
            {{ $reviews->links() }}
        </div>
    @endif
</div>
