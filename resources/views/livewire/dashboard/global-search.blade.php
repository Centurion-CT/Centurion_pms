<form wire:submit.prevent="runSearch"
      class="lp-gsearch"
      x-data="{
        open: false,
        cached: [],
        cacheReady: !!window.KevernaSearchCache && (window.KevernaSearchCache.enabled?.() ?? false),
        async runCache(term) {
            if (!this.cacheReady) { this.cached = []; return; }
            try {
                this.cached = await window.KevernaSearchCache.search(term);
            } catch { this.cached = []; }
        },
        reservationUrl(ref) {
            const base = @js(url('/dashboard/reservations'));
            return base + '/' + encodeURIComponent(ref);
        },
      }"
      x-on:keydown.escape.window="open = false"
      x-on:click.outside="open = false"
      data-testid="dash-global-search">
    <label for="gsearch-input" class="lp-gsearch-icon" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
            <circle cx="7" cy="7" r="5"/>
            <path d="m14 14-3.5-3.5"/>
        </svg>
    </label>
    <input id="gsearch-input"
           type="search"
           autocomplete="off"
           spellcheck="false"
           wire:model="query"
           x-on:focus="open = true"
           x-on:input="open = true; runCache($event.target.value)"
           placeholder="Find a booking, ref, guest name, email, phone"
           class="lp-gsearch-input"
           data-testid="dash-global-search-input">

    {{-- Subtle loading dot next to the input while a server search
         round-trips. Replaces the old "Searching…" button label
         so nothing shouts for attention next to the field. --}}
    <span wire:loading wire:target="runSearch"
          data-testid="dash-global-search-loading-dot"
          class="lp-gsearch-dot" aria-hidden></span>

    @if ($query !== '')
        <button type="button"
                wire:click="clear"
                x-on:click="cached = []"
                class="lp-gsearch-clear"
                data-testid="dash-global-search-clear"
                aria-label="Clear search">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
                <path d="M3 3l6 6M9 3l-6 6"/>
            </svg>
        </button>
    @endif

    {{-- Hidden submit so Enter still fires the form. No visible pill
         so the field reads as a search icon + input, nothing more. --}}
    <button type="submit"
            data-testid="dash-global-search-submit"
            aria-label="Search"
            tabindex="-1"
            style="position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; overflow: hidden;">
    </button>

    @php
        $rows = $this->results;
        $browse = $this->browse;
    @endphp
    <div class="lp-gsearch-panel"
         x-show="open"
         x-cloak
         x-ref="results"
         data-testid="dash-global-search-panel">

        <p class="lp-gsearch-empty"
           wire:loading.delay.short
           wire:target="runSearch"
           data-testid="dash-global-search-loading">
            Searching…
        </p>

        <div wire:loading.remove.delay.short wire:target="runSearch">

            {{-- Empty-query browse: two grouped lists — In-house now,
                 Arriving next. Renders as soon as the operator
                 focuses the input, without typing anything. --}}
            @if ($query === '')
                @if ($browse['in_house']->isEmpty() && $browse['arriving']->isEmpty())
                    <p class="lp-gsearch-empty" data-testid="dash-global-search-browse-empty">
                        Nothing in-house or arriving in the next 14 days. Type to search all bookings.
                    </p>
                @else
                    @if ($browse['in_house']->isNotEmpty())
                        <div class="lp-gsearch-group" data-testid="dash-global-search-group-in-house">
                            <p class="lp-gsearch-group-label">In-house now · {{ $browse['in_house']->count() }}</p>
                            @foreach ($browse['in_house'] as $b)
                                <a href="{{ route('tenant.reservations.show', $b->booking_ref) }}"
                                   class="lp-gsearch-result"
                                   data-gsearch-result
                                   data-testid="dash-global-search-browse-{{ $b->booking_ref }}">
                                    <span class="lp-mono lp-gsearch-ref">{{ $b->booking_ref }}</span>
                                    <span class="lp-gsearch-name">{{ $b->guest_name ?: '(no name)' }}</span>
                                    <span class="lp-gsearch-meta">
                                        {{ $b->room?->name ?? '—' }}
                                        @if ($b->checkout_date)
                                            &middot; out {{ $b->checkout_date->format('j M') }}
                                        @endif
                                    </span>
                                    <span class="lp-gsearch-contact">
                                        @if ($b->guest_phone) {{ $b->guest_phone }} @endif
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                    @if ($browse['arriving']->isNotEmpty())
                        <div class="lp-gsearch-group" data-testid="dash-global-search-group-arriving">
                            <p class="lp-gsearch-group-label">Arriving next · {{ $browse['arriving']->count() }}</p>
                            @foreach ($browse['arriving'] as $b)
                                <a href="{{ route('tenant.reservations.show', $b->booking_ref) }}"
                                   class="lp-gsearch-result"
                                   data-gsearch-result
                                   data-testid="dash-global-search-browse-{{ $b->booking_ref }}">
                                    <span class="lp-mono lp-gsearch-ref">{{ $b->booking_ref }}</span>
                                    <span class="lp-gsearch-name">{{ $b->guest_name ?: '(no name)' }}</span>
                                    <span class="lp-gsearch-meta">
                                        {{ $b->room?->name ?? '—' }}
                                        @if ($b->checkin_date)
                                            &middot; in {{ $b->checkin_date->format('j M') }}
                                        @endif
                                    </span>
                                    <span class="lp-gsearch-contact">
                                        @if ($b->guest_phone) {{ $b->guest_phone }} @endif
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endif
            @else
                {{-- Cache-hit rows (client-side, instant). --}}
                <template x-if="!$wire.searched && cached.length > 0">
                    <div data-testid="dash-global-search-cached">
                        <template x-for="b in cached" :key="b.id">
                            <a :href="reservationUrl(b.booking_ref)"
                               class="lp-gsearch-result"
                               data-gsearch-result>
                                <span class="lp-mono lp-gsearch-ref" x-text="b.booking_ref"></span>
                                <span class="lp-gsearch-name" x-text="b.guest_name || '(no name)'"></span>
                                <span class="lp-gsearch-meta">
                                    <span x-text="b.room_name || '—'"></span>
                                    <template x-if="b.property_name"><span>&nbsp;&middot; <span x-text="b.property_name"></span></span></template>
                                    <template x-if="b.checkin_date"><span>&nbsp;&middot; <span x-text="new Date(b.checkin_date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })"></span></span></template>
                                </span>
                                <span class="lp-gsearch-contact">
                                    <template x-if="b.guest_phone_normalised"><span x-text="b.guest_phone_normalised"></span></template>
                                    <template x-if="b.guest_phone_normalised && b.guest_email_lc"><span>&nbsp;&middot;&nbsp;</span></template>
                                    <template x-if="b.guest_email_lc"><span x-text="b.guest_email_lc"></span></template>
                                </span>
                            </a>
                        </template>
                        <p class="lp-gsearch-cache-hint" data-testid="dash-global-search-cache-hint">
                            <span>Local matches. Press Enter for server search.</span>
                        </p>
                    </div>
                </template>

                {{-- Server-driven results, after Enter. --}}
                <template x-if="$wire.searched || cached.length === 0">
                    <div>
                        @if (! $searched)
                            <p class="lp-gsearch-empty" data-testid="dash-global-search-hint">
                                Type at least 2 characters, then press Enter.
                            </p>
                        @elseif ($rows->isEmpty())
                            <p class="lp-gsearch-empty" data-testid="dash-global-search-empty">
                                No bookings match "{{ $query }}".
                            </p>
                        @else
                            @foreach ($rows as $b)
                                <a href="{{ route('tenant.reservations.show', $b->booking_ref) }}"
                                   class="lp-gsearch-result"
                                   data-gsearch-result
                                   data-testid="dash-global-search-result-{{ $b->booking_ref }}">
                                    <span class="lp-mono lp-gsearch-ref">{{ $b->booking_ref }}</span>
                                    <span class="lp-gsearch-name">{{ $b->guest_name ?: '(no name)' }}</span>
                                    <span class="lp-gsearch-meta">
                                        {{ $b->room?->name ?? '—' }}
                                        @if ($b->room?->property?->name)
                                            &middot; {{ $b->room->property->name }}
                                        @endif
                                        @if ($b->checkin_date)
                                            &middot; {{ $b->checkin_date->format('j M') }}
                                        @endif
                                    </span>
                                    <span class="lp-gsearch-contact">
                                        @if ($b->guest_phone) {{ $b->guest_phone }} @endif
                                        @if ($b->guest_phone && $b->guest_email) &middot; @endif
                                        @if ($b->guest_email) {{ $b->guest_email }} @endif
                                    </span>
                                </a>
                            @endforeach
                        @endif
                    </div>
                </template>
            @endif
        </div>
    </div>
</form>
