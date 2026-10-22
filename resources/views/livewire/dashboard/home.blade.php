{{--
    Operator dashboard, home.

    Lives inside the new tenant-auth sidebar shell so this view only
    renders the dashboard's main column. All previous data-testids are
    preserved so existing operator tests keep passing.
--}}
<div class="lp-dash cx-home"
     wire:poll.30s="refresh"
     x-data="{
        /* Outstanding-balances panel is Alpine-driven so opening +
           the per-row Part-pay toggle land instantly. `outstandingOpen`
           entangles with the server prop (no .live) so the modal stays
           in sync across Livewire renders without forcing a round-trip
           on every open/close. `openPartPayId` is Alpine-only until
           submit. */
        outstandingOpen: @entangle('outstandingOpen'),
        openPartPayId: null,
     }">
    @include('livewire.dashboard._partials.home-styles')


    {{-- ── Page header ────────────────────────────────────────────── --}}
    @php
        $firstName = explode(' ', trim((string) auth()->user()?->name))[0] ?? '';
        $arrivalsCount = $today['arrivals']->count();
        $outstandingTotal = (int) ($kpis['outstanding']['value'] ?? 0);

        // Contextual welcome line, surfaces today's most-relevant
        // numbers in plain English so the operator doesn't have to
        // parse cards first.
        $contextBits = [];
        if ($arrivalsCount > 0) {
            $contextBits[] = $arrivalsCount.' '.($arrivalsCount === 1 ? 'arrival' : 'arrivals').' today';
        }
        if ($outstandingTotal > 0) {
            $contextBits[] = money($outstandingTotal).' outstanding';
        }
        $contextLine = $contextBits === []
            ? "Here's how the property is doing."
            : implode(' · ', $contextBits).'.';
    @endphp

    @php
        // Reservations that can actually be checked in RIGHT NOW -
        // arrivals whose booked checkin date is today. Filtered from
        // the shared today['arrivals'] set (already scoped to Confirmed
        // + OnHold via DashboardSnapshot) so both the header CTA and
        // the "Today at a glance" widget below see the same list.
        $checkinCandidates = $today['arrivals']
            ->filter(fn ($b) => $b->checkin_date?->isSameDay(\Illuminate\Support\Carbon::today())
                && in_array($b->booking_status, [
                    \App\Enums\BookingStatus::Confirmed,
                    \App\Enums\BookingStatus::OnHold,
                ], true))
            ->values();
        $checkinCount = $checkinCandidates->count();

        // Check-out panel candidates. Every in-house guest goes in
        // (not just today's booked departures) so front desk can
        // handle early checkouts too. Count on the trigger badge
        // stays scoped to booked-for-today so the operator sees
        // "how many should leave right now" at a glance, but the
        // panel itself surfaces the whole in-house set - soonest
        // departures first, tomorrow's + later after those.
        $checkoutCandidates = ($today['inHouse'] ?? $today['departures'])->values();
        $checkoutCount = $today['departures']->count();
        $todayStr = \Illuminate\Support\Carbon::today()->toDateString();
    @endphp

    @php
        $greetHour = (int) now()->format('G');
        $greeting = $greetHour < 12 ? 'Good morning' : ($greetHour < 17 ? 'Good afternoon' : 'Good evening');
    @endphp
    <header class="cx-dh" data-testid="dashboard-hero">
        <div>
            <p class="cx-dh-date">{{ now()->format('l, j F') }}</p>
            <h1 class="cx-dh-title" data-testid="dashboard-heading">{{ $greeting }}{{ $firstName !== '' ? ', '.$firstName : '' }}</h1>
        </div>
        {{-- Quick check-in / check-out / add-charge panels, unchanged. --}}
        <div class="cx-dh-actions lp-dash-quick-wrap">
            {{-- Quick check-in - brand-coloured CTA + Alpine dropdown
                 of today's arrivals. Fires quickCheckIn(bookingId)
                 with a wire:confirm so a stray tap can't move the
                 wrong guest to In-house. --}}
            <div x-data="{ open: false }"
                 x-on:click.outside="open = false"
                 x-on:keydown.escape.window="open = false"
                 style="position: relative;">
                <button type="button"
                        @click="open = ! open"
                        data-testid="dash-quick-checkin-trigger"
                        :aria-expanded="open ? 'true' : 'false'"
                        class="lp-pill"
                        style="background: var(--color-accent);
                               color: var(--color-on-accent); border-color: var(--color-accent);
                               font-weight: 600; gap: 8px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"
                         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden>
                        <path d="M9 2H4v12h5M2 8h9m0 0L9 6m2 2l-2 2"/>
                    </svg>
                    Check in
                    @if ($checkinCount > 0)
                        <span data-testid="dash-quick-checkin-count"
                              style="display: inline-flex; align-items: center; justify-content: center;
                                     min-width: 18px; height: 18px; padding: 0 6px;
                                     background: var(--color-ink); color: var(--color-accent);
                                     border-radius: 999px;
                                     font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                                     font-size: 10.5px; font-weight: 700; line-height: 1;">
                            {{ $checkinCount }}
                        </span>
                    @endif
                </button>

                {{-- Dropdown panel - fixed width, anchors under the
                     trigger. Alpine visibility only; each row goes
                     through Livewire (quickCheckIn) with a confirm. --}}
                <div x-show="open" x-cloak
                     x-transition:enter="transition ease-out duration-140"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     data-testid="dash-quick-checkin-panel"
                     style="position: absolute; top: calc(100% + 8px); right: 0; z-index: 50;
                            width: min(360px, 92vw); max-height: 60vh; overflow-y: auto;
                            background: var(--color-card);
                            border: 1px solid var(--color-line);
                            border-radius: 14px;
                            box-shadow: 0 24px 48px -20px rgba(20, 17, 12, 0.22);
                            padding: 8px 0;">
                    <div style="padding: 10px 14px 8px; border-bottom: 1px solid var(--color-line-soft);">
                        <p class="lp-mono text-[10.5px]"
                           style="color: var(--color-ink-mute); letter-spacing: 0.16em; text-transform: uppercase;">
                            Arriving today
                        </p>
                        <p class="text-[13px]" style="color: var(--color-ink);">
                            {{ $checkinCount }} guest{{ $checkinCount === 1 ? '' : 's' }} ready for check-in
                        </p>
                    </div>

                    @if ($checkinCount === 0)
                        <p class="text-[12.5px]" style="padding: 18px 14px; margin: 0;
                                                        text-align: center; color: var(--color-ink-mute);"
                           data-testid="dash-quick-checkin-empty">
                            Nobody's due to arrive right now. Anyone who checks in later shows up here.
                        </p>
                    @else
                        <ul style="list-style: none; padding: 0; margin: 0;">
                            @foreach ($checkinCandidates as $g)
                                @php
                                    $statusTagClass = $g->booking_status === \App\Enums\BookingStatus::OnHold
                                        ? 'lp-tag--on-hold' : 'lp-tag--confirmed';
                                @endphp
                                <li data-testid="dash-quick-checkin-row-{{ $g->booking_ref }}"
                                    style="padding: 10px 14px; border-bottom: 1px solid var(--color-line-soft);
                                           display: flex; align-items: center; gap: 10px;">
                                    <div style="flex: 1; min-width: 0;">
                                        <p style="font-size: 13px; color: var(--color-ink); font-weight: 500;
                                                  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            {{ $g->guest_name }}
                                        </p>
                                        <p class="lp-mono text-[11px] mt-0.5" style="color: var(--color-ink-mute);">
                                            {{ $g->booking_ref }}
                                            @if ($g->room)
                                                <span style="color: var(--color-ink-mute);">·</span>
                                                Rm {{ $g->room->room_number ?? '-' }}
                                            @endif
                                            <span class="lp-tag {{ $statusTagClass }}"
                                                  style="font-size: 9.5px; margin-left: 4px;">
                                                {{ ucfirst($g->booking_status->value) }}
                                            </span>
                                        </p>
                                    </div>
                                    <button type="button"
                                            wire:click="quickCheckIn({{ $g->id }})"
                                            wire:confirm="Check in {{ $g->guest_name }} now?"
                                            wire:loading.attr="disabled"
                                            wire:target="quickCheckIn({{ $g->id }})"
                                            @click="open = false"
                                            data-testid="dash-quick-checkin-btn-{{ $g->booking_ref }}"
                                            style="background: var(--color-accent);
                                                   color: var(--color-on-accent);
                                                   border: 0; padding: 6px 12px;
                                                   border-radius: 999px;
                                                   font-family: inherit; font-size: 11.5px; font-weight: 600;
                                                   cursor: pointer; flex-shrink: 0;">
                                        <span wire:loading.remove wire:target="quickCheckIn({{ $g->id }})">
                                            Check in →
                                        </span>
                                        <span wire:loading wire:target="quickCheckIn({{ $g->id }})"
                                              x-cloak>
                                            Checking in…
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <a href="{{ route('tenant.reservations.index') }}?tab=arrivals" wire:navigate
                       @click="open = false"
                       data-testid="dash-quick-checkin-see-all"
                       style="display: block; padding: 10px 14px; margin-top: 4px;
                              text-align: center; font-size: 12px; text-decoration: none;
                              color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">
                        View all arrivals →
                    </a>
                </div>
            </div>

            {{-- Quick check-out - mirror of the check-in CTA. Panel
                 lists CheckedIn guests whose booked checkout is today;
                 rows with an outstanding balance disable the button
                 and inline the "Clear ₦X first" hint so front desk
                 sees what's blocking each departure. --}}
            <div x-data="{ open: false }"
                 x-on:click.outside="open = false"
                 x-on:keydown.escape.window="open = false"
                 style="position: relative;">
                <button type="button"
                        @click="open = ! open"
                        data-testid="dash-quick-checkout-trigger"
                        :aria-expanded="open ? 'true' : 'false'"
                        class="lp-pill"
                        style="background: var(--color-card);
                               color: var(--color-ink);
                               border-color: var(--color-ink);
                               font-weight: 600; gap: 8px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"
                         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden>
                        <path d="M7 2h5v12H7M14 8H5m0 0l2-2m-2 2l2 2"/>
                    </svg>
                    Check out
                    @if ($checkoutCount > 0)
                        <span data-testid="dash-quick-checkout-count"
                              style="display: inline-flex; align-items: center; justify-content: center;
                                     min-width: 18px; height: 18px; padding: 0 6px;
                                     background: var(--color-ink); color: var(--color-card);
                                     border-radius: 999px;
                                     font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                                     font-size: 10.5px; font-weight: 700; line-height: 1;">
                            {{ $checkoutCount }}
                        </span>
                    @endif
                </button>

                <div x-show="open" x-cloak
                     x-transition:enter="transition ease-out duration-140"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     data-testid="dash-quick-checkout-panel"
                     style="position: absolute; top: calc(100% + 8px); right: 0; z-index: 50;
                            width: min(380px, 92vw); max-height: 60vh; overflow-y: auto;
                            background: var(--color-card);
                            border: 1px solid var(--color-line);
                            border-radius: 14px;
                            box-shadow: 0 24px 48px -20px rgba(20, 17, 12, 0.22);
                            padding: 8px 0;">
                    <div style="padding: 10px 14px 8px; border-bottom: 1px solid var(--color-line-soft);">
                        <p class="lp-mono text-[10.5px]"
                           style="color: var(--color-ink-mute); letter-spacing: 0.16em; text-transform: uppercase;">
                            In-house · check out any time
                        </p>
                        <p class="text-[13px]" style="color: var(--color-ink);">
                            {{ $checkoutCount }} due today · {{ $checkoutCandidates->count() }} in-house
                        </p>
                    </div>

                    @if ($checkoutCandidates->isEmpty())
                        <p class="text-[12.5px]" style="padding: 18px 14px; margin: 0;
                                                        text-align: center; color: var(--color-ink-mute);"
                           data-testid="dash-quick-checkout-empty">
                            Nobody's currently in-house. Guests you check in will show up here for check-out.
                        </p>
                    @else
                        <ul style="list-style: none; padding: 0; margin: 0;">
                            @foreach ($checkoutCandidates as $g)
                                @php
                                    // Named locally so it doesn't shadow the outer
                                    // $outstanding collection used by widget-outstanding
                                    // further down the page (Blade @php variables
                                    // leak into the enclosing scope).
                                    $rowOutstanding = (int) $g->outstandingTotal();
                                    $canCheckout = $rowOutstanding <= 0;
                                    $isDueToday = $g->checkout_date?->toDateString() === $todayStr;
                                    $isOverdue = $g->checkout_date && $g->checkout_date->lt(\Illuminate\Support\Carbon::today());
                                @endphp
                                <li data-testid="dash-quick-checkout-row-{{ $g->booking_ref }}"
                                    style="padding: 10px 14px; border-bottom: 1px solid var(--color-line-soft);
                                           display: flex; align-items: center; gap: 10px;
                                           @if ($isDueToday) background: rgba(122, 110, 167, 0.06); @endif">
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: baseline; gap: 6px;">
                                            <p style="font-size: 13px; color: var(--color-ink); font-weight: 500;
                                                      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                {{ $g->guest_name }}
                                            </p>
                                            @if ($isOverdue)
                                                <span data-testid="dash-quick-checkout-tag-overdue"
                                                      style="font-size: 9.5px; padding: 1px 6px; border-radius: 999px;
                                                             background: #FEE2E2; color: #9F1F1F; font-weight: 600;
                                                             letter-spacing: 0.04em; text-transform: uppercase;">
                                                    Overdue
                                                </span>
                                            @elseif ($isDueToday)
                                                <span data-testid="dash-quick-checkout-tag-today"
                                                      style="font-size: 9.5px; padding: 1px 6px; border-radius: 999px;
                                                             background: rgba(122, 110, 167, 0.15);
                                                             color: #4B4570; font-weight: 600;
                                                             letter-spacing: 0.04em; text-transform: uppercase;">
                                                    Due today
                                                </span>
                                            @endif
                                        </div>
                                        <p class="lp-mono text-[11px] mt-0.5" style="color: var(--color-ink-mute);">
                                            {{ $g->booking_ref }}
                                            @if ($g->room)
                                                <span style="color: var(--color-ink-mute);">·</span>
                                                Rm {{ $g->room->room_number ?? '-' }}
                                            @endif
                                            @if (! $isDueToday && ! $isOverdue && $g->checkout_date)
                                                <span style="color: var(--color-ink-mute);">·</span>
                                                leaves {{ $g->checkout_date->format('j M') }}
                                            @endif
                                        </p>
                                        @if (! $canCheckout)
                                            <p class="text-[11px] mt-1"
                                               data-testid="dash-quick-checkout-blocked-{{ $g->booking_ref }}"
                                               style="color: #B85C42;">
                                                Clear {{ money($rowOutstanding) }} first
                                            </p>
                                        @endif
                                    </div>
                                    @php
                                        // Extra confirmation text for early
                                        // checkouts so the operator is clear
                                        // they're departing ahead of the
                                        // booked date (and the room + nights
                                        // won't be re-billed).
                                        $confirmText = $isDueToday || $isOverdue
                                            ? 'Check out '.$g->guest_name.' now?'
                                            : 'Early checkout - '.$g->guest_name.'\'s booked checkout is '
                                                .$g->checkout_date->format('j M').'. Continue?';
                                    @endphp
                                    @if ($canCheckout)
                                        <button type="button"
                                                wire:click="quickCheckOut({{ $g->id }})"
                                                wire:confirm="{{ $confirmText }}"
                                                wire:loading.attr="disabled"
                                                wire:target="quickCheckOut({{ $g->id }})"
                                                @click="open = false"
                                                data-testid="dash-quick-checkout-btn-{{ $g->booking_ref }}"
                                                style="background: var(--color-ink);
                                                       color: var(--color-card);
                                                       border: 0; padding: 6px 12px;
                                                       border-radius: 999px;
                                                       font-family: inherit; font-size: 11.5px; font-weight: 600;
                                                       cursor: pointer; flex-shrink: 0;">
                                            <span wire:loading.remove wire:target="quickCheckOut({{ $g->id }})">
                                                Check out →
                                            </span>
                                            <span wire:loading wire:target="quickCheckOut({{ $g->id }})" x-cloak>
                                                Checking out…
                                            </span>
                                        </button>
                                    @else
                                        <a href="{{ route('tenant.reservations.show', $g->booking_ref) }}"
                                           wire:navigate @click="open = false"
                                           data-testid="dash-quick-checkout-settle-{{ $g->booking_ref }}"
                                           style="background: transparent; color: var(--color-ink);
                                                  border: 1px solid var(--color-line);
                                                  padding: 5px 11px;
                                                  border-radius: 999px;
                                                  font-size: 11.5px; font-weight: 500;
                                                  text-decoration: none; flex-shrink: 0;">
                                            Settle →
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <a href="{{ route('tenant.reservations.index') }}?tab=departures" wire:navigate
                       @click="open = false"
                       data-testid="dash-quick-checkout-see-all"
                       style="display: block; padding: 10px 14px; margin-top: 4px;
                              text-align: center; font-size: 12px; text-decoration: none;
                              color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">
                        View all departures →
                    </a>
                </div>
            </div>

            {{-- Add charge - drops a charge onto any in-house guest
                 without leaving the dashboard. Handles the two flows
                 the front desk hits over a shift: an ad-hoc purchase
                 (room service, food, laundry, ...) and a damage fee
                 with the option to waive on the spot. POS purchases
                 route through the POS terminal via the "Open POS"
                 link at the bottom of the panel. --}}
            @php
                $inHouseList = ($today['inHouse'] ?? collect());
                $needle = trim((string) $addChargeSearch);
                $filteredInHouse = $needle === ''
                    ? $inHouseList->take(20)
                    : $inHouseList->filter(function ($b) use ($needle): bool {
                        $room = (string) ($b->room?->room_number ?? '');
                        return stripos((string) $b->guest_name, $needle) !== false
                            || ($room !== '' && stripos($room, $needle) !== false)
                            || stripos((string) $b->booking_ref, $needle) !== false;
                    })->take(20);
                $addChargeBooking = $addChargeBookingId !== null
                    ? $inHouseList->firstWhere('id', $addChargeBookingId)
                    : null;
                $chargeTypeOptions = [
                    'room_service'  => 'Room service',
                    'food_beverage' => 'Food & beverage',
                    'laundry'       => 'Laundry',
                    'transport'     => 'Transport',
                    'damage'        => 'Damage',
                    'other'         => 'Other',
                ];
            @endphp
            {{-- Panel visibility + guest-pick are Alpine-driven so
                 both transitions are instant, no server round-trip.
                 The server-side `addChargeOpen` + `addChargeBookingId`
                 props are still respected so programmatic callers
                 (tests, deep links) can drive the same UI. --}}
            <div x-data="{
                    open: @entangle('addChargeOpen'),
                    picked: null,
                    reset() { this.picked = null; },
                    openInstant() {
                        this.open = true;
                        this.reset();
                        // Server-side reset in the background - the panel
                        // is already visible so nobody's waiting on this.
                        $wire.call('openAddChargePanel');
                    },
                    closeInstant() {
                        this.open = false;
                        this.reset();
                        $wire.call('closeAddChargePanel');
                    },
                    hasPick() {
                        // Alpine pick OR server-side booking id (tests /
                        // deep links). Either surfaces the form.
                        return this.picked !== null || !!@js((int) ($addChargeBookingId ?? 0));
                    },
                 }"
                 x-on:click.outside="if (open) closeInstant()"
                 x-on:keydown.escape.window="if (open) closeInstant()"
                 style="position: relative;">
                <button type="button"
                        x-on:click="openInstant()"
                        data-testid="dash-add-charge-trigger"
                        :aria-expanded="open ? 'true' : 'false'"
                        class="lp-pill"
                        style="background: var(--color-card);
                               color: var(--color-ink);
                               border-color: var(--color-ink);
                               font-weight: 600; gap: 8px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"
                         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden>
                        <path d="M8 3v10M3 8h10"/>
                    </svg>
                    Add charge
                </button>

                <div x-show="open" x-cloak
                     x-transition:enter="transition ease-out duration-140"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     data-testid="dash-add-charge-panel"
                     style="position: absolute; top: calc(100% + 8px); right: 0; z-index: 50;
                            width: min(400px, 94vw); max-height: 78vh; overflow-y: auto;
                            background: var(--color-card);
                            border: 1px solid var(--color-line);
                            border-radius: 14px;
                            box-shadow: 0 24px 48px -20px rgba(20, 17, 12, 0.22);
                            padding: 14px 16px 16px;">

                    <div style="padding-bottom: 10px; border-bottom: 1px solid var(--color-line-soft);">
                        <p class="lp-mono text-[10.5px]"
                           style="color: var(--color-ink-mute); letter-spacing: 0.16em; text-transform: uppercase;">
                            Add charge
                        </p>
                        <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft); line-height: 1.4;">
                            Pick an in-house guest, add the charge, waive on the spot if needed.
                        </p>
                    </div>

                    {{-- Guest picker: search + list. Both the picker
                         and the form live in the DOM concurrently;
                         x-show swaps between them client-side so
                         picking a guest is instant. --}}
                    <div x-show="! hasPick()" x-cloak>
                        <div style="margin-top: 12px;">
                            <label class="lp-form-label" for="add-charge-search">Find guest</label>
                            <input id="add-charge-search" type="text"
                                   wire:model.live.debounce.250ms="addChargeSearch"
                                   data-testid="dash-add-charge-search"
                                   placeholder="Guest name or room number"
                                   class="lp-input" style="font-size: 13px;">
                        </div>
                        @if ($filteredInHouse->isEmpty())
                            <p class="text-[12px]" data-testid="dash-add-charge-no-guest"
                               style="padding: 20px 4px 6px; color: var(--color-ink-mute); text-align: center;">
                                @if ($inHouseList->isEmpty())
                                    Nobody's in-house right now.
                                @else
                                    No match. Try a room number or a different spelling.
                                @endif
                            </p>
                        @else
                            <ul style="list-style: none; padding: 0; margin: 10px 0 0;
                                       display: grid; gap: 4px; max-height: 240px; overflow-y: auto;">
                                @foreach ($filteredInHouse as $g)
                                    <li>
                                        <button type="button"
                                                x-on:click="picked = {
                                                    id: {{ (int) $g->id }},
                                                    name: {{ Illuminate\Support\Js::from($g->guest_name) }},
                                                    room: {{ Illuminate\Support\Js::from($g->room?->room_number ?? '-') }},
                                                    ref: {{ Illuminate\Support\Js::from($g->booking_ref) }}
                                                }"
                                                data-testid="dash-add-charge-guest-{{ $g->booking_ref }}"
                                                style="width: 100%; text-align: left; background: transparent;
                                                       border: 1px solid var(--color-line-soft);
                                                       border-radius: 8px; padding: 8px 10px;
                                                       cursor: pointer; font-family: inherit;
                                                       display: flex; align-items: center; gap: 10px;">
                                            <span style="flex: 1; min-width: 0;">
                                                <span style="display: block; font-size: 13px; font-weight: 500; color: var(--color-ink);
                                                             white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    {{ $g->guest_name }}
                                                </span>
                                                <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                                    Rm {{ $g->room?->room_number ?? '-' }}
                                                    <span style="color: var(--color-ink-mute); margin: 0 4px;">·</span>
                                                    {{ $g->booking_ref }}
                                                </span>
                                            </span>
                                            <span class="lp-mono text-[10.5px]"
                                                  style="color: var(--color-ink-mute); flex-shrink: 0;">
                                                Pick &rarr;
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <div x-show="hasPick()" x-cloak>
                        {{-- Guest picked. Show the charge form. Name /
                             room / ref come from Alpine's `picked` when
                             the user tapped, else from the server-side
                             $addChargeBooking (test / deep-link path). --}}
                        <div style="margin-top: 12px; padding: 8px 10px;
                                    background: var(--color-card-deep); border-radius: 8px;
                                    display: flex; align-items: center; gap: 8px;"
                             data-testid="dash-add-charge-picked">
                            <span style="flex: 1; min-width: 0;">
                                <span style="display: block; font-size: 13px; font-weight: 600; color: var(--color-ink);"
                                      x-text="picked?.name || {{ Illuminate\Support\Js::from($addChargeBooking?->guest_name ?? '') }}"></span>
                                <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                    Rm <span x-text="picked?.room || {{ Illuminate\Support\Js::from($addChargeBooking?->room?->room_number ?? '-') }}"></span>
                                    <span style="margin: 0 4px;">·</span>
                                    <span x-text="picked?.ref || {{ Illuminate\Support\Js::from($addChargeBooking?->booking_ref ?? '') }}"></span>
                                </span>
                            </span>
                            <button type="button"
                                    x-on:click="picked = null; $wire.set('addChargeBookingId', null)"
                                    data-testid="dash-add-charge-change-guest"
                                    style="background: transparent; border: 0; font-family: inherit;
                                           font-size: 11px; color: var(--color-ink);
                                           text-decoration: underline; text-underline-offset: 3px;
                                           cursor: pointer;">
                                Change
                            </button>
                        </div>

                        <div style="margin-top: 12px; display: grid; gap: 10px;">
                            <div>
                                <label class="lp-form-label" for="add-charge-type">Type</label>
                                <select id="add-charge-type" wire:model="addChargeType"
                                        data-testid="dash-add-charge-type" class="lp-input"
                                        style="font-size: 13px;">
                                    @foreach ($chargeTypeOptions as $val => $label)
                                        <option value="{{ $val }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="lp-form-label" for="add-charge-amount">Amount</label>
                                <div style="position: relative;">
                                    <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
                                                 font-size: 12px; color: var(--color-ink-mute); pointer-events: none;">
                                        {{ currency_symbol() }}
                                    </span>
                                    <input id="add-charge-amount" type="number" min="1" step="1"
                                           wire:model="addChargeAmount"
                                           data-testid="dash-add-charge-amount"
                                           class="lp-input"
                                           style="width: 100%; padding-left: 22px; font-size: 13px;">
                                </div>
                            </div>

                            <div>
                                <label class="lp-form-label" for="add-charge-description">
                                    Description
                                    <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                                </label>
                                <input id="add-charge-description" type="text" maxlength="240"
                                       wire:model="addChargeDescription"
                                       data-testid="dash-add-charge-description"
                                       placeholder="e.g. 2x dinner + drinks"
                                       class="lp-input" style="font-size: 13px;">
                            </div>

                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" wire:model="addChargeWaive"
                                       data-testid="dash-add-charge-waive"
                                       style="accent-color: var(--color-accent-deep);">
                                <span style="font-size: 12.5px; color: var(--color-ink);">
                                    Waive this charge
                                    <span style="color: var(--color-ink-mute); font-weight: 400;">
                                        · records on the folio for audit, flags as waived
                                    </span>
                                </span>
                            </label>

                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button"
                                        x-on:click="closeInstant()"
                                        data-testid="dash-add-charge-cancel"
                                        class="lp-btn" style="font-size: 12px;">
                                    Cancel
                                </button>
                                {{-- Sync the Alpine-side pick to the server
                                     just before submit (await = wait for the
                                     ack so the guard sees the right id), then
                                     fire submitAddCharge. --}}
                                <button type="button"
                                        x-on:click="async () => {
                                            if (picked !== null) {
                                                await $wire.set('addChargeBookingId', picked.id, true);
                                            }
                                            $wire.call('submitAddCharge');
                                        }"
                                        data-testid="dash-add-charge-submit"
                                        @disabled(($addChargeAmount ?? 0) <= 0)
                                        class="lp-btn lp-btn--primary" style="font-size: 12px;">
                                    <span wire:loading.remove wire:target="submitAddCharge">
                                        {{ $addChargeWaive ? 'Add & waive' : 'Add charge' }}
                                    </span>
                                    <span wire:loading wire:target="submitAddCharge" x-cloak>
                                        Recording...
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>{{-- /x-show="hasPick()" --}}

                    <a href="{{ route('tenant.pos.pick-outlet') }}" wire:navigate
                       @click="$wire.closeAddChargePanel()"
                       data-testid="dash-add-charge-pos-link"
                       style="display: block; padding: 10px 4px 0;
                              margin-top: 12px; border-top: 1px solid var(--color-line-soft);
                              text-align: center; font-size: 12px; text-decoration: none;
                              color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">
                        For a POS purchase, open the POS terminal &rarr;
                    </a>
                </div>
            </div>

            <livewire:dashboard.notifications-bell />
        </div> {{-- /.cx-dh-actions --}}
    </header>

    @include('livewire.dashboard._partials.home-tonight')

    {{-- ── Outstanding-balances panel ────────────────────────────
         Right-side slide-out sheet with every open book. Alpine
         drives visibility so the panel appears the moment the
         operator taps the Outstanding tile — no server round-trip.
         `outstandingOpen` entangles with the server prop so re-
         renders after actions (settle, part-pay) preserve the "still
         open" state.

         x-teleport="body" lifts the panel out of the dashboard's
         layout tree so `position: fixed` is relative to the viewport,
         not some ancestor with a transform / filter / contain rule. --}}
    <template x-teleport="body">
    {{-- lp-page wrapper is required here because .lp-btn / .lp-input
         styles are scoped under .lp-page in app.css. Teleporting to
         <body> escapes the dashboard's .lp-page shell, so without this
         wrapper the buttons + form fields inside the panel render
         unstyled ("Part pay", "Settle all", "Cancel", "Take payment"
         showed as plain text). --}}
    <div wire:key="outstanding-panel-root" class="lp-page">
        {{-- Backdrop — subtle scrim, click to close. --}}
        <div x-show="outstandingOpen"
             x-cloak
             x-transition.opacity.duration.150ms
             x-on:click="outstandingOpen = false; openPartPayId = null"
             style="position: fixed; inset: 0; z-index: 99;
                    background: rgba(20, 17, 12, 0.28);"></div>
        {{-- Panel — slides in from the right edge, full viewport
             height, fixed width so it reads as a side drawer rather
             than a centered modal. --}}
        <aside data-testid="outstanding-panel"
               role="dialog"
               aria-modal="true"
               x-show="outstandingOpen"
               x-cloak
               x-transition:enter="transition ease-out duration-200"
               x-transition:enter-start="translate-x-full"
               x-transition:enter-end="translate-x-0"
               x-transition:leave="transition ease-in duration-150"
               x-transition:leave-start="translate-x-0"
               x-transition:leave-end="translate-x-full"
               x-on:keydown.escape.window="outstandingOpen = false; openPartPayId = null"
               style="position: fixed; top: 0; right: 0; bottom: 0;
                      width: min(520px, 100vw);
                      z-index: 100;
                      background: var(--color-card);
                      border-left: 1px solid var(--color-line);
                      box-shadow: -24px 0 48px -24px rgba(20, 17, 12, 0.24);
                      display: flex; flex-direction: column;">
            <div style="padding: 20px 22px 14px; border-bottom: 1px solid var(--color-line-soft);
                        display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                <div>
                    <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                       style="color: var(--color-ink-mute);">
                        Dashboard
                    </p>
                    <h2 class="lp-display" style="font-size: 20px; margin-top: 4px; color: var(--color-ink);">
                        Outstanding balances
                    </h2>
                    <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                        Every open book. Settle or take a part payment inline.
                    </p>
                </div>
                <button type="button"
                        x-on:click="outstandingOpen = false; openPartPayId = null"
                        class="lp-btn" style="font-size: 12px;"
                        data-testid="outstanding-panel-close"
                        aria-label="Close panel">
                    Close
                </button>
            </div>
            <div style="flex: 1; overflow-y: auto; padding: 16px 22px 22px;">

                @if ($outstandingPanel->isEmpty())
                    <div data-testid="no-outstanding-panel" style="padding: 24px 0;">
                        @include('livewire.dashboard._partials._empty', [
                            'icon'  => 'wallet',
                            'title' => 'All balances are clear.',
                            'sub'   => 'Any outstanding folio would land here so you can settle in one place.',
                        ])
                    </div>
                @else
                    {{-- Global settle method picker (applies to
                         one-click Settle-all rows). --}}
                    <div style="margin-top: 14px; padding: 10px 12px;
                                background: var(--color-card-deep); border-radius: 8px;
                                display: flex; align-items: center; gap: 10px;">
                        <label class="lp-form-label" style="font-size: 10.5px; margin: 0;">
                            Settle method
                        </label>
                        <select wire:model.live="outstandingSettleMethod"
                                data-testid="outstanding-settle-method"
                                class="lp-input" style="flex: 1; font-size: 12.5px; padding: 6px 10px;">
                            <option value="cash">Cash</option>
                            <option value="transfer">Bank transfer</option>
                            <option value="card">Card</option>
                            <option value="pos">POS terminal</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    {{-- The panel body itself already scrolls, so this
                         wrapper doesn't need its own overflow-y. --}}
                    <div style="margin-top: 14px;">
                        <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 8px;">
                            @foreach ($outstandingPanel as $b)
                                @php
                                    $owed = $b->outstandingTotal();
                                    $balance = (int) $b->balance_due;
                                    $unsettledCount = $b->charges->where('settled', false)->count();
                                    $isOpenPartPay = (int) $outstandingPartPayBookingId === (int) $b->id;
                                @endphp
                                <li data-testid="outstanding-panel-row-{{ $b->id }}"
                                    style="border: 1px solid var(--color-line-soft);
                                           border-radius: 10px; padding: 12px 14px;">
                                    <div style="display: flex; align-items: start; justify-content: space-between; gap: 12px;">
                                        <div style="min-width: 0; flex: 1;">
                                            <a href="{{ route('tenant.reservations.show', $b->booking_ref) }}"
                                               wire:navigate
                                               style="color: var(--color-ink); text-decoration: none; font-weight: 500;">
                                                {{ $b->guest_name }}
                                            </a>
                                            <div class="text-[11.5px] mt-0.5 lp-mono" style="color: var(--color-ink-mute);">
                                                {{ $b->booking_ref }}
                                                @if ($b->room?->room_number)
                                                    <span style="margin: 0 4px;">·</span>
                                                    Rm {{ $b->room->room_number }}
                                                @endif
                                            </div>
                                            <div class="text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                                                @if ($balance > 0)
                                                    {{ money($balance) }} balance
                                                @endif
                                                @if ($balance > 0 && $unsettledCount > 0)
                                                    <span style="margin: 0 4px;">·</span>
                                                @endif
                                                @if ($unsettledCount > 0)
                                                    {{ $unsettledCount }} unsettled charge{{ $unsettledCount === 1 ? '' : 's' }}
                                                @endif
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div class="lp-num" style="font-size: 16px; color: #a36318; font-weight: 600;">
                                                {{ money($owed) }}
                                            </div>
                                            <div style="display: inline-flex; gap: 6px; margin-top: 6px;">
                                                <button type="button"
                                                        x-on:click="openPartPayId = (openPartPayId === {{ $b->id }} ? null : {{ $b->id }})"
                                                        data-testid="outstanding-row-partpay-{{ $b->id }}"
                                                        class="lp-btn" style="font-size: 11.5px;">
                                                    Part pay
                                                </button>
                                                <button type="button"
                                                        wire:click="settleOutstandingAll({{ $b->id }})"
                                                        wire:confirm="Take {{ money($owed) }} from {{ $b->guest_name }} via {{ $outstandingSettleMethod }}?"
                                                        data-testid="outstanding-row-settle-{{ $b->id }}"
                                                        class="lp-btn lp-btn--primary" style="font-size: 11.5px;">
                                                    <span wire:loading.remove wire:target="settleOutstandingAll({{ $b->id }})">Settle all</span>
                                                    <span wire:loading wire:target="settleOutstandingAll({{ $b->id }})">Recording...</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Inline part-payment form for the picked row. Kept in
                                         the DOM under x-show so opening + closing is instant.
                                         Each row has its own Alpine scope so the amount + raw
                                         input state stays per-row. --}}
                                    <div data-testid="outstanding-row-partpay-panel-{{ $b->id }}"
                                         x-show="openPartPayId === {{ $b->id }}"
                                         x-cloak
                                         x-data="{
                                            raw: null,
                                            outstanding: {{ (int) $owed }},
                                            get remaining() { return Math.max(0, this.outstanding - (Number(this.raw) || 0)); },
                                            get amountValid() { const n = Number(this.raw) || 0; return n > 0 && n <= this.outstanding; },
                                            format(n) { return n ? Number(n).toLocaleString('en-NG') : ''; },
                                            onAmountInput(e) {
                                                const digits = String(e.target.value).replace(/[^0-9]/g, '');
                                                this.raw = digits === '' ? null : Number(digits);
                                                e.target.value = this.format(this.raw);
                                            },
                                         }"
                                         style="margin-top: 12px; padding: 12px;
                                                background: var(--color-surface-2); border-radius: 8px;
                                                display: grid; gap: 10px;">
                                            <div class="grid gap-2 md:grid-cols-2">
                                                <div>
                                                    <label class="lp-form-label" style="font-size: 10.5px;">Amount</label>
                                                    <div style="position: relative;">
                                                        <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
                                                                     font-size: 12px; color: var(--color-ink-mute); pointer-events: none;">{{ currency_symbol() }}</span>
                                                        <input type="text" inputmode="numeric"
                                                               :value="format(raw)"
                                                               @input.debounce.150ms="onAmountInput($event)"
                                                               data-testid="outstanding-row-partpay-amount-{{ $b->id }}"
                                                               placeholder="e.g. {{ number_format((int) floor($owed / 2)) }}"
                                                               class="lp-input"
                                                               style="width: 100%; padding-left: 22px; font-size: 13px;">
                                                    </div>
                                                </div>
                                                <div>
                                                    <label class="lp-form-label" style="font-size: 10.5px;">Method</label>
                                                    <select wire:model="outstandingPartPayMethod"
                                                            data-testid="outstanding-row-partpay-method-{{ $b->id }}"
                                                            class="lp-input" style="font-size: 13px;">
                                                        <option value="cash">Cash</option>
                                                        <option value="transfer">Bank transfer</option>
                                                        <option value="card">Card</option>
                                                        <option value="pos">POS terminal</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <p class="text-[11.5px]"
                                               x-show="raw !== null && Number(raw) > 0"
                                               x-cloak
                                               :style="'margin: 0; color: ' + (amountValid ? 'var(--color-ink-soft)' : '#B85C42') + ';'">
                                                <template x-if="amountValid">
                                                    <span>
                                                        Remaining after this payment:
                                                        <strong x-text="@js(currency_symbol()) + format(remaining)"
                                                                style="color: var(--color-ink); font-weight: 600;"></strong>
                                                    </span>
                                                </template>
                                                <template x-if="! amountValid">
                                                    <span>
                                                        Amount exceeds the outstanding
                                                        <strong x-text="@js(currency_symbol()) + format(outstanding)"></strong>.
                                                    </span>
                                                </template>
                                            </p>
                                            <div class="flex justify-end gap-2">
                                                <button type="button"
                                                        x-on:click="openPartPayId = null; raw = null"
                                                        class="lp-btn" style="font-size: 12px;">Cancel</button>
                                                <button type="button"
                                                        @click="async () => {
                                                            await $wire.set('outstandingPartPayBookingId', {{ $b->id }}, false);
                                                            await $wire.set('outstandingPartPayAmount', Number(raw) || 0, false);
                                                            $wire.call('submitOutstandingPartPay');
                                                        }"
                                                        :disabled="! amountValid"
                                                        :style="! amountValid ? 'opacity: 0.5; cursor: not-allowed;' : ''"
                                                        data-testid="outstanding-row-partpay-submit-{{ $b->id }}"
                                                        class="lp-btn lp-btn--primary" style="font-size: 12px;">
                                                    <span wire:loading.remove wire:target="submitOutstandingPartPay">
                                                        Take payment
                                                    </span>
                                                    <span wire:loading wire:target="submitOutstandingPartPay">
                                                        Recording...
                                                    </span>
                                                </button>
                                            </div>
                                        </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>{{-- /scroll body --}}
        </aside>
    </div>{{-- /outstanding-panel-root --}}
    </template>

    {{-- ── Command centre: Today at a glance | Attention + Activity ── --}}
    <div class="cx-main-grid" data-testid="command-centre">

        {{-- Left column, one merged action-oriented list of every
             guest the front desk needs to touch today. Each row is a
             card with the primary next action (check-in / check-out /
             confirm) inlined so the operator can act without leaving
             the dashboard. --}}
        @php
            // Merge arrivals + departures into one time-ordered
            // list so the operator reads the day top-to-bottom
            // rather than jumping between two columns. Built at the
            // top of the section so the x-data attribute below has
            // $todayPanelData defined when the panel factory reads it.
            // Category-only reservations arriving today live on the
            // snapshot as unassignedArrivals. Merge them into the
            // schedule so the operator sees them alongside room-
            // pinned arrivals + departures; the row renderer already
            // handles a NULL room by showing "Category · Unassigned"
            // below.
            $unassignedToday = $today['unassignedArrivals'] ?? collect();
            $schedule = collect()
                ->concat($today['arrivals']->map(fn ($b) => ['kind' => 'arrival',   'booking' => $b]))
                ->concat($unassignedToday->map(fn ($b) => ['kind' => 'arrival',    'booking' => $b]))
                ->concat($today['departures']->map(fn ($b) => ['kind' => 'departure', 'booking' => $b]))
                ->sortBy(fn ($e) => $e['kind'] === 'arrival'
                    // 24-h format here is the SORT key (never displayed).
                    // Keeps chronological ordering; the render layer
                    // formats 12-h with AM/PM.
                    ? $e['booking']->checkin_date?->format('H:i') ?? '00:00'
                    : $e['booking']->checkout_date?->format('H:i') ?? '23:59'
                )
                ->values();

            $todayBookingIds = collect()
                ->concat($today['arrivals']->pluck('id'))
                ->concat($unassignedToday->pluck('id'))
                ->concat($today['departures']->pluck('id'))
                ->unique();
            $inHouseOthers = ($today['inHouse'] ?? collect())
                ->reject(fn ($b) => $todayBookingIds->contains($b->id))
                ->values();

            // Per-booking panel payload for the schedule (arrivals
            // + departures). Same shape as the in-house payload so
            // the shared Alpine panel factory reads either kind.
            $today['arrivals']->loadMissing(['charges', 'payments', 'room.category']);
            $today['departures']->loadMissing(['charges', 'payments', 'room.category']);
            $unassignedToday->loadMissing(['charges', 'payments', 'roomCategory']);
            $schedulePanelData = collect()
                ->concat($today['arrivals']->map(fn ($b) => ['kind' => 'arrival',   'booking' => $b]))
                ->concat($unassignedToday->map(fn ($b) => ['kind' => 'arrival',    'booking' => $b]))
                ->concat($today['departures']->map(fn ($b) => ['kind' => 'departure', 'booking' => $b]))
                ->map(function ($e) {
                    $b = $e['booking'];
                    $unsettled = $b->charges->where('settled', false)
                        ->map(fn ($c) => [
                            'description' => (string) $c->description,
                            'amount' => (int) $c->amount,
                        ])->values()->all();
                    $recentPayments = $b->payments->sortByDesc('paid_at')->take(5)
                        ->map(fn ($p) => [
                            'amount' => (int) $p->amount,
                            'method' => ucfirst((string) ($p->method ?? 'paystack')),
                            'paid_at' => $p->paid_at?->format('j M · g:i A') ?? '-',
                        ])->values()->all();
                    return [
                        'id' => $b->id,
                        'kind' => $e['kind'],
                        'ref' => (string) $b->booking_ref,
                        'guest_name' => (string) $b->guest_name,
                        'guest_phone' => (string) ($b->guest_phone ?? ''),
                        'room' => $b->room?->room_number ?? '-',
                        'category' => (string) ($b->room?->category?->name ?? ''),
                        'checkin' => $b->checkin_date?->format('j M Y') ?? '-',
                        'checkout' => $b->checkout_date?->format('j M Y') ?? '-',
                        'nights' => (int) ($b->nights ?? 0),
                        'total' => (int) $b->total_amount,
                        'deposit' => (int) $b->deposit_amount,
                        'balance' => (int) $b->balance_due,
                        'outstanding' => (int) $b->outstandingTotal(),
                        'unsettled' => $unsettled,
                        'payments' => $recentPayments,
                        'url' => route('tenant.reservations.show', $b->booking_ref),
                    ];
                })
                ->keyBy('id')
                ->all();

            $inHouseOthers->loadMissing(['charges', 'payments']);
            $inHousePanelData = $inHouseOthers->map(function ($b) {
                $unsettled = $b->charges->where('settled', false)
                    ->map(fn ($c) => [
                        'description' => (string) $c->description,
                        'amount' => (int) $c->amount,
                    ])->values()->all();
                $recentPayments = $b->payments->sortByDesc('paid_at')->take(5)
                    ->map(fn ($p) => [
                        'amount' => (int) $p->amount,
                        'method' => ucfirst((string) ($p->method ?? 'paystack')),
                        'paid_at' => $p->paid_at?->format('j M · g:i A') ?? '-',
                    ])->values()->all();
                return [
                    'id' => $b->id,
                    'kind' => 'in-house',
                    'ref' => (string) $b->booking_ref,
                    'guest_name' => (string) $b->guest_name,
                    'guest_phone' => (string) ($b->guest_phone ?? ''),
                    'room' => $b->room?->room_number ?? '-',
                    'category' => (string) ($b->room?->category?->name ?? ''),
                    'checkin' => $b->checkin_date?->format('j M Y') ?? '-',
                    'checkout' => $b->checkout_date?->format('j M Y') ?? '-',
                    'nights' => (int) ($b->nights ?? 0),
                    'total' => (int) $b->total_amount,
                    'deposit' => (int) $b->deposit_amount,
                    'balance' => (int) $b->balance_due,
                    'outstanding' => (int) $b->outstandingTotal(),
                    'unsettled' => $unsettled,
                    'payments' => $recentPayments,
                    'url' => route('tenant.reservations.show', $b->booking_ref),
                ];
            })->keyBy('id')->all();

            $todayPanelData = $schedulePanelData + $inHousePanelData;
        @endphp
        <section class="cx-card" data-testid="widget-today"
                 x-data="inHouseGuestPanel({
                    panels: {{ \Illuminate\Support\Js::from(array_values($todayPanelData)) }},
                    propertyName: @js((string) (\App\Models\Tenant::current()?->name ?? 'Property')),
                 })">
            @include('livewire.dashboard._partials.home-arrivals')

            @if (! empty($todayPanelData))
                @once
                    {{-- Alpine factory for the in-house detail panel.
                         Owns the openId + print-receipt behaviour.
                         @once so we only emit it per page, even if the
                         widget re-renders. --}}
                    <script>
                        window.inHouseGuestPanel = function ({ panels, propertyName }) {
                            return {
                                openId: null,
                                panels,
                                propertyName,
                                get panel() { return this.panels.find(p => p.id === this.openId) ?? null; },
                                money(n) { return @js(currency_symbol()) + Number(n || 0).toLocaleString(); },

                                /**
                                 * Pop a receipt-sized window (80mm thermal, ~72mm
                                 * printable) with the panel data, then trigger
                                 * print. Uses window.open so we don't need a
                                 * dedicated route or fight the dashboard's
                                 * global styles.
                                 */
                                printReceipt(panel) {
                                    if (!panel) return;
                                    const w = window.open('', '_blank', 'width=340,height=640');
                                    if (!w) {
                                        alert('Pop-up blocked - allow pop-ups for this site to print receipts.');
                                        return;
                                    }
                                    w.document.open();
                                    w.document.write(this.receiptHtml(panel));
                                    w.document.close();
                                },

                                receiptHtml(panel) {
                                    const money = (n) => @js(currency_symbol()) + Number(n || 0).toLocaleString();
                                    const now = new Date();
                                    const printedAt = now.toLocaleString('en-GB', {
                                        day: 'numeric', month: 'short', year: 'numeric',
                                        hour: '2-digit', minute: '2-digit',
                                    });
                                    const esc = (s) => String(s ?? '')
                                        .replaceAll('&', '&amp;').replaceAll('<', '&lt;')
                                        .replaceAll('>', '&gt;').replaceAll('"', '&quot;');

                                    const rows = [];
                                    rows.push(this.row('Total', money(panel.total)));
                                    if (panel.deposit > 0)  rows.push(this.row('Deposit', money(panel.deposit)));
                                    if (panel.balance > 0)  rows.push(this.row('Booked balance', money(panel.balance)));

                                    const unsettled = (panel.unsettled || []).map(c =>
                                        this.row(esc(c.description), money(c.amount))).join('');
                                    const payments = (panel.payments || []).map(p =>
                                        this.row(esc(p.paid_at) + ' · ' + esc(p.method), money(p.amount))).join('');

                                    return `<!doctype html>
<html><head><meta charset="utf-8"><title>Folio · ${esc(panel.ref)}</title>
<style>
    /* Thermal-receipt layout. 72mm is the safe print width for
       an 80mm roll; drop to 48mm printable if you swap to a 58mm
       printer (this same layout will still render fine, just
       narrower). */
    @page { size: 80mm auto; margin: 0; }
    html, body { margin: 0; padding: 0; }
    body {
        width: 72mm; padding: 4mm 4mm 6mm;
        font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
        font-size: 11px; line-height: 1.35; color: #000;
    }
    h1 { font-size: 13px; text-align: center; margin: 0 0 2px; letter-spacing: 0.5px; }
    .sub { text-align: center; font-size: 10px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; }
    .rule { border-top: 1px dashed #000; margin: 6px 0; }
    .row  { display: flex; justify-content: space-between; gap: 6px; }
    .row .l { flex: 1 1 auto; word-break: break-word; }
    .row .r { flex: 0 0 auto; font-variant-numeric: tabular-nums; }
    .meta { margin: 6px 0; }
    .meta div { display: flex; justify-content: space-between; gap: 6px; }
    .label { text-transform: uppercase; font-size: 9px; letter-spacing: 0.8px; color: #333; }
    .total { font-weight: 700; }
    .foot { text-align: center; font-size: 9.5px; margin-top: 8px; color: #333; }
    @media print { body { width: auto; } }
</style>
</head><body>
    <h1>${esc(this.propertyName)}</h1>
    <div class="sub">Guest Folio</div>

    <div class="row"><span class="label">Ref</span><span class="r">${esc(panel.ref)}</span></div>
    <div class="row"><span class="label">Printed</span><span class="r">${esc(printedAt)}</span></div>

    <div class="rule"></div>

    <div class="meta">
        <div><span class="l">Guest</span><span class="r">${esc(panel.guest_name)}</span></div>
        <div><span class="l">Room</span><span class="r">${esc(panel.room)}${panel.category ? ' · ' + esc(panel.category) : ''}</span></div>
        ${panel.guest_phone ? `<div><span class="l">Phone</span><span class="r">${esc(panel.guest_phone)}</span></div>` : ''}
        <div><span class="l">Check-in</span><span class="r">${esc(panel.checkin)}</span></div>
        <div><span class="l">Check-out</span><span class="r">${esc(panel.checkout)}</span></div>
        <div><span class="l">Nights</span><span class="r">${panel.nights}</span></div>
    </div>

    <div class="rule"></div>

    ${rows.join('')}
    <div class="row total"><span class="l">Outstanding</span><span class="r">${money(panel.outstanding)}</span></div>

    ${unsettled ? `<div class="rule"></div>
    <div class="label" style="margin-bottom:2px;">Unsettled charges</div>
    ${unsettled}` : ''}

    ${payments ? `<div class="rule"></div>
    <div class="label" style="margin-bottom:2px;">Recent payments</div>
    ${payments}` : ''}

    <div class="rule"></div>
    <div class="foot">Thank you for staying with us.</div>

    {{-- Manual escape hatch - always closes the window even if the
         browser suppresses afterprint (Safari and older Firefox
         are inconsistent). Hidden from the printed sheet via
         @media print. --}}
    <div class="no-print" style="text-align:center;margin-top:10px;">
        <button type="button" onclick="window.close()"
                style="font: inherit; padding: 6px 14px; border: 1px solid #000;
                       background: #fff; cursor: pointer; border-radius: 4px;">
            Close window
        </button>
    </div>
    <style>@media print { .no-print { display: none !important; } }</style>

    <script>
        (function () {
            var closed = false;
            function safeClose() {
                if (closed) return;
                closed = true;
                // setTimeout so the print pipeline finishes flushing
                // before we tear the window down (Chrome will noop the
                // close otherwise).
                setTimeout(function () { window.close(); }, 100);
            }

            // Register BEFORE printing so the event isn't missed if
            // the browser fires it synchronously on dialog dismissal.
            window.addEventListener('afterprint', safeClose);

            // matchMedia('print') is the more reliable signal in
            // Chrome/Edge - fires on both Print and Cancel.
            var mql = window.matchMedia && window.matchMedia('print');
            if (mql && mql.addEventListener) {
                mql.addEventListener('change', function (e) {
                    if (!e.matches) safeClose();
                });
            }

            window.addEventListener('load', function () {
                window.focus();
                window.print();
            });
        })();
    <\/script>
</body></html>`;
                                },

                                /** Convenience - one folio line "label ...... value". */
                                row(label, value) {
                                    return `<div class="row"><span class="l">${label}</span><span class="r">${value}</span></div>`;
                                },
                            };
                        };
                    </script>
                @endonce

                {{-- Guest folio side panel (unchanged). --}}
                <template x-teleport="body">
                    <div x-show="openId !== null"
                         x-cloak
                         @keydown.escape.window="openId = null"
                         data-testid="widget-today-inhouse-panel-backdrop"
                         class="lp-page"
                         style="position: fixed; inset: 0; z-index: 70;
                                background: rgba(20, 22, 24, 0.42);
                                backdrop-filter: blur(2px);"
                         @click.self="openId = null">
                        <aside x-show="openId !== null"
                               x-transition:enter="transition ease-out duration-160"
                               x-transition:enter-start="opacity-0 translate-x-4"
                               x-transition:enter-end="opacity-100 translate-x-0"
                               data-testid="widget-today-inhouse-panel"
                               style="position: fixed; top: 0; right: 0; bottom: 0;
                                      width: min(440px, 96vw);
                                      background: var(--color-card);
                                      box-shadow: -18px 0 40px -18px rgba(20, 17, 12, 0.28);
                                      display: flex; flex-direction: column;">
                            <template x-if="panel">
                                <div style="display: flex; flex-direction: column; height: 100%;">
                                    {{-- Head --}}
                                    <div style="display: flex; align-items: flex-start; justify-content: space-between;
                                                padding: 16px 20px 12px;
                                                border-bottom: 1px solid var(--color-line-soft);">
                                        <div>
                                            <p class="lp-mono text-[10.5px]"
                                               style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;">
                                                In-house · <span x-text="panel.ref"></span>
                                            </p>
                                            <h3 class="lp-display"
                                                style="font-size: 17px; font-weight: 600; margin-top: 1px; line-height: 1.15;"
                                                x-text="panel.guest_name"></h3>
                                            <p class="text-[12px]" style="color: var(--color-ink-soft); margin-top: 1px;"
                                               x-text="'Rm ' + panel.room + (panel.category ? ' · ' + panel.category : '')"></p>
                                        </div>
                                        <button type="button" @click="openId = null"
                                                aria-label="Close"
                                                style="background: transparent; border: 0; cursor: pointer;
                                                       font-size: 22px; color: var(--color-ink-mute);
                                                       padding: 2px 4px; line-height: 1;">×</button>
                                    </div>

                                    {{-- Body. Flex-column (not grid) so we don't get row-
                                         stretch from the aside's `flex: 1`. --}}
                                    <div style="flex: 1 1 auto; overflow-y: auto; padding: 14px 20px 16px;
                                                display: flex; flex-direction: column; gap: 12px; font-size: 12.5px;">

                                        {{-- Meta pairs - dense two-column list, no
                                             giant labels or vertical stack. --}}
                                        <dl style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
                                                   column-gap: 12px; row-gap: 8px; margin: 0;">
                                            <div>
                                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Check-in</dt>
                                                <dd style="margin: 0;" x-text="panel.checkin"></dd>
                                            </div>
                                            <div>
                                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Check-out</dt>
                                                <dd style="margin: 0;" x-text="panel.checkout"></dd>
                                            </div>
                                            <div>
                                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Nights</dt>
                                                <dd style="margin: 0;" x-text="panel.nights"></dd>
                                            </div>
                                            <div>
                                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Guest phone</dt>
                                                <dd style="margin: 0;" x-text="panel.guest_phone || '-'"></dd>
                                            </div>
                                        </dl>

                                        {{-- Payment summary --}}
                                        <div style="background: var(--color-card-deep); border-radius: 10px;
                                                    padding: 10px 12px; display: flex; flex-direction: column; gap: 4px;">
                                            <div class="flex justify-between">
                                                <span style="color: var(--color-ink-soft);">Total</span>
                                                <span class="lp-mono" x-text="money(panel.total)"></span>
                                            </div>
                                            <div class="flex justify-between" x-show="panel.deposit > 0">
                                                <span style="color: var(--color-ink-soft);">Deposit</span>
                                                <span class="lp-mono" x-text="money(panel.deposit)"></span>
                                            </div>
                                            <div class="flex justify-between" x-show="panel.balance > 0">
                                                <span style="color: var(--color-ink-soft);">Booked balance</span>
                                                <span class="lp-mono" x-text="money(panel.balance)"></span>
                                            </div>
                                            <div class="flex justify-between"
                                                 style="border-top: 1px solid var(--color-line-soft);
                                                        padding-top: 6px; margin-top: 2px;
                                                        font-weight: 600; color: var(--color-ink);">
                                                <span>Outstanding</span>
                                                <span class="lp-mono"
                                                      :style="panel.outstanding > 0 ? 'color: #a36318;' : ''"
                                                      x-text="money(panel.outstanding)"></span>
                                            </div>
                                        </div>

                                        {{-- Unsettled charges --}}
                                        <div x-show="panel.unsettled.length > 0">
                                            <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 4px;">Unsettled charges</p>
                                            <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 3px;">
                                                <template x-for="c in panel.unsettled" :key="c.description + c.amount">
                                                    <li class="flex justify-between"
                                                        style="font-size: 12px; color: var(--color-ink-soft);">
                                                        <span x-text="c.description" style="max-width: 220px;
                                                               overflow: hidden; text-overflow: ellipsis;
                                                               white-space: nowrap;"></span>
                                                        <span class="lp-mono" x-text="money(c.amount)"></span>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>

                                        {{-- Recent payments --}}
                                        <div x-show="panel.payments.length > 0">
                                            <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 4px;">Recent payments</p>
                                            <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 3px;">
                                                <template x-for="(p, i) in panel.payments" :key="i">
                                                    <li class="flex justify-between"
                                                        style="font-size: 12px; color: var(--color-ink-soft);">
                                                        <span>
                                                            <span x-text="p.paid_at"></span>
                                                            <span style="color: var(--color-ink-mute);">·</span>
                                                            <span x-text="p.method"></span>
                                                        </span>
                                                        <span class="lp-mono" x-text="money(p.amount)"></span>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>
                                        <p x-show="panel.payments.length === 0" x-cloak
                                           style="font-size: 12px; color: var(--color-ink-mute); margin: 0;">
                                            No payments recorded yet.
                                        </p>
                                    </div>

                                    {{-- Foot actions - kind-aware so an
                                         arrival gets a Check-in CTA, a
                                         departure gets Check-out, and an
                                         in-house guest keeps the full
                                         Extend / Print / Settle-or-Check-out
                                         set. --}}
                                    <div style="padding: 14px 22px;
                                                border-top: 1px solid var(--color-line-soft);
                                                display: flex; gap: 8px; flex-wrap: wrap;">
                                        <a :href="panel.url" wire:navigate
                                           @click="openId = null"
                                           data-testid="widget-today-inhouse-panel-open"
                                           style="background: transparent; color: var(--color-ink);
                                                  border: 1px solid var(--color-line);
                                                  padding: 6px 14px; border-radius: 999px;
                                                  font-size: 12px; font-weight: 500;
                                                  text-decoration: none;">
                                            Open reservation
                                        </a>

                                        {{-- Arrival: Check-in CTA. --}}
                                        <template x-if="panel.kind === 'arrival'">
                                            <button type="button"
                                                    @click="window.kevernaConfirm({ message: 'Check in ' + panel.guest_name + ' now?' }).then((ok) => { if (ok) { $wire.quickCheckIn(panel.id); openId = null; } })"
                                                    data-testid="widget-today-panel-checkin"
                                                    style="background: var(--color-ink);
                                                           color: var(--color-card);
                                                           border: 0; padding: 6px 14px;
                                                           border-radius: 999px;
                                                           font-family: inherit; font-size: 12px; font-weight: 600;
                                                           cursor: pointer; margin-left: auto;">
                                                Check in
                                            </button>
                                        </template>

                                        {{-- Departure: Settle if outstanding, else Check-out. --}}
                                        <template x-if="panel.kind === 'departure' && panel.outstanding > 0">
                                            <a :href="panel.url" wire:navigate
                                               @click="openId = null"
                                               data-testid="widget-today-panel-settle"
                                               style="background: transparent; color: #a36318;
                                                      border: 1px solid #a36318;
                                                      padding: 6px 14px; border-radius: 999px;
                                                      font-size: 12px; font-weight: 500;
                                                      text-decoration: none; margin-left: auto;">
                                                Settle balance
                                            </a>
                                        </template>
                                        <template x-if="panel.kind === 'departure' && panel.outstanding === 0">
                                            <button type="button"
                                                    @click="window.kevernaConfirm({ message: 'Check out ' + panel.guest_name + ' now?' }).then((ok) => { if (ok) { $wire.quickCheckOut(panel.id); openId = null; } })"
                                                    data-testid="widget-today-panel-checkout"
                                                    style="background: var(--color-ink);
                                                           color: var(--color-card);
                                                           border: 0; padding: 6px 14px;
                                                           border-radius: 999px;
                                                           font-family: inherit; font-size: 12px; font-weight: 600;
                                                           cursor: pointer; margin-left: auto;">
                                                Check out
                                            </button>
                                        </template>

                                        {{-- In-house: Extend / Print / Settle-or-Check-out. --}}
                                        <template x-if="panel.kind === 'in-house'">
                                            <span style="display: contents;">
                                                <button type="button"
                                                        @click="$wire.openExtendModal(panel.id); openId = null"
                                                        data-testid="widget-today-inhouse-panel-extend"
                                                        style="background: transparent; color: var(--color-ink);
                                                               border: 1px solid var(--color-line);
                                                               padding: 6px 14px; border-radius: 999px;
                                                               font-family: inherit; font-size: 12px; font-weight: 500;
                                                               cursor: pointer;">
                                                    Extend
                                                </button>
                                                <button type="button"
                                                        @click="printReceipt(panel)"
                                                        data-testid="widget-today-inhouse-panel-print"
                                                        title="Print folio to receipt printer"
                                                        style="background: transparent; color: var(--color-ink);
                                                               border: 1px solid var(--color-line);
                                                               padding: 6px 14px; border-radius: 999px;
                                                               font-family: inherit; font-size: 12px; font-weight: 500;
                                                               cursor: pointer;">
                                                    Print
                                                </button>
                                                <template x-if="panel.outstanding > 0">
                                                    <a :href="panel.url" wire:navigate
                                                       @click="openId = null"
                                                       data-testid="widget-today-inhouse-panel-settle"
                                                       style="background: transparent; color: #a36318;
                                                              border: 1px solid #a36318;
                                                              padding: 6px 14px; border-radius: 999px;
                                                              font-size: 12px; font-weight: 500;
                                                              text-decoration: none; margin-left: auto;">
                                                        Settle balance
                                                    </a>
                                                </template>
                                                <template x-if="panel.outstanding === 0">
                                                    <button type="button"
                                                            @click="window.kevernaConfirm({ message: 'Check out ' + panel.guest_name + ' now?' }).then((ok) => { if (ok) { $wire.quickCheckOut(panel.id); openId = null; } })"
                                                            data-testid="widget-today-inhouse-panel-checkout"
                                                            style="background: var(--color-ink);
                                                                   color: var(--color-card);
                                                                   border: 0; padding: 6px 14px;
                                                                   border-radius: 999px;
                                                                   font-family: inherit; font-size: 12px; font-weight: 600;
                                                                   cursor: pointer; margin-left: auto;">
                                                        Check out
                                                    </button>
                                                </template>
                                            </span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </aside>
                    </div>
                    </template>
            @endif
        </section>

        @include('livewire.dashboard._partials.home-rail')
    </div>

    @include('livewire.dashboard._partials.home-revenue')

    <div class="cx-bottom-grid">
    {{-- ── Rooms needing attention ──────────────────────────────
         Replaces the mini heatmap. Every open room inspection with
         its progress through the 3-signer chain (housekeeper →
         porter → supervisor). "1/3" means the housekeeper has
         checked, porter + supervisor still to go. The Calendar sits
         on its own page, this dashboard slot is for action queues
         where a manager sees what's blocking check-ins. --}}
    <section data-testid="widget-attention" class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Rooms needing attention</span>
            <span class="lp-dash-card-meta">
                <a href="{{ route('tenant.housekeeping.inspections.index') }}" wire:navigate
                   style="color: var(--color-ink-soft); text-decoration: underline; text-underline-offset: 4px;">
                    See all &rarr;
                </a>
            </span>
        </div>

        @if ($attentionRows->isEmpty())
            <div data-testid="no-attention">
                @include('livewire.dashboard._partials._empty', [
                    'icon'  => 'sparkle',
                    'title' => 'All rooms are cleared.',
                    'sub'   => 'Fresh inspections open automatically after each checkout.',
                ])
            </div>
        @else
            <ul class="divide-y" style="border-color: var(--color-line-soft);">
                @foreach ($attentionRows as $row)
                    @php
                        $ins = $row['inspection'];
                        $signed = $row['signed'];
                        $total = $row['total'];
                        $pct = $total > 0 ? (int) round(($signed / $total) * 100) : 0;
                        $isFailed = $row['is_failed'];
                    @endphp
                    <li data-testid="attention-row-{{ $ins->id }}" class="py-3">
                        <a href="{{ route('tenant.housekeeping.inspections.show', $ins) }}" wire:navigate
                           class="grid gap-2"
                           style="grid-template-columns: auto 1fr auto; align-items: center; text-decoration: none;">
                            <span class="lp-mono" style="font-weight: 600; color: var(--color-ink); min-width: 60px;">
                                Rm {{ $ins->room?->room_number ?? '-' }}
                            </span>

                            <div style="min-width: 0;">
                                <p class="text-[13px]" style="color: var(--color-ink); font-weight: 500;">
                                    {{ $ins->room?->category?->name ?? 'Uncategorised' }}
                                    <span style="color: var(--color-ink-mute); font-weight: 400;">
                                        · {{ $isFailed ? 'rejected, reopen' : 'waiting on '.$row['waiting_on'] }}
                                    </span>
                                </p>
                                @if (! $isFailed)
                                    {{-- Progress bar. Wider on desktop, narrows fine on mobile. --}}
                                    <div style="height: 4px; background: var(--color-card-deep); border-radius: 999px; margin-top: 6px; max-width: 240px;">
                                        <div style="height: 100%; width: {{ $pct }}%;
                                                    background: {{ $pct === 100 ? '#3D7B4F' : 'var(--color-accent-deep)' }};
                                                    border-radius: 999px;"></div>
                                    </div>
                                @endif
                            </div>

                            <span class="lp-mono text-[12.5px] shrink-0"
                                  data-testid="attention-progress-{{ $ins->id }}"
                                  style="{{ $isFailed
                                        ? 'color: #B85C42; font-weight: 600;'
                                        : 'color: var(--color-ink); font-weight: 500;' }}">
                                @if ($isFailed)
                                    Rejected
                                @else
                                    {{ $signed }}/{{ $total }}
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>


        <section data-testid="widget-outstanding" class="lp-dash-card">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Outstanding balances</span>
                <span class="lp-dash-card-meta">{{ $outstanding->count() }} open</span>
            </div>
            @if ($outstanding->isEmpty())
                <p data-testid="no-outstanding" class="text-[13.5px]" style="color: var(--color-ink-mute);">
                    All balances are clear.
                </p>
            @else
                <ul class="divide-y" style="border-color: var(--color-line-soft);">
                    @foreach ($outstanding as $b)
                        @php
                            // outstandingTotal rolls up balance_due (deposit
                            // remainder) + unsettled BookingCharges (mid-stay
                            // extras, POS charges, damage, etc). A guest
                            // whose initial balance_due is 0 but has open
                            // POS charges still owes real money; the raw
                            // balance_due column would render ₦0 and hide
                            // the exposure.
                            $owed = $b->outstandingTotal();
                            // Small hint for the due line, "due DATE" for
                            // an unpaid deposit remainder, "N unsettled
                            // charges" when the balance comes from extras.
                            $dueHint = $b->balance_due > 0
                                ? 'due '.($b->balance_due_at?->toFormattedDateString() ?? 'on check-in')
                                : $b->charges->where('settled', false)->count().' unsettled charge(s)';
                        @endphp
                        <li data-testid="outstanding-row" class="py-3">
                            <a href="{{ route('tenant.reservations.show', $b->booking_ref) }}" wire:navigate
                               class="flex items-baseline justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[13.5px] font-medium truncate" style="color: var(--color-ink);">
                                        {{ $b->guest_name }}
                                    </p>
                                    <p class="text-[11.5px] mt-0.5" style="color: var(--color-ink-mute);">
                                        {{ $dueHint }}
                                    </p>
                                </div>
                                <span class="lp-num text-[15px] shrink-0"
                                      style="color: {{ $owed > 0 ? '#a36318' : 'var(--color-ink)' }};">
                                    {{ money($owed) }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

    </div>

    @include('livewire.dashboard._partials.calendar-sheets')

    {{-- Shared extend-stay modal (from HasExtendStayModal trait) --}}
    @include('livewire.dashboard._partials.extend-stay-modal')
</div>
