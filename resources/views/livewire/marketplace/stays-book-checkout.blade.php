{{--
    Keverna Stays checkout form. The Livewire component
    ({@see \App\Livewire\Marketplace\StaysBookCheckout}) collects
    guest info, calls CreateStaysBooking on submit, then redirects
    to Paystack's hosted card page.
--}}
@php
    /** @var \App\Models\Property|null $property */
    // $selectedRoom + $selectedCategory come off the Livewire
    // component so the view stays a thin projection of state -
    // no view-side collection dredging.
    $selectedRoom = $this->selectedRoom();
    $city = trim((string) ($property?->city ?? ''));
    $hero = $property ? ($property->getFirstMediaUrl('hero', 'medium')
        ?: $property->getFirstMediaUrl('gallery', 'medium')) : '';
@endphp
<div style="max-width: 1100px; margin: 0 auto; padding: 28px 24px 60px;">

    <style>
        .kv-checkout-shell { display: grid; grid-template-columns: 1fr; gap: 32px; }
        @media (min-width: 900px) { .kv-checkout-shell { grid-template-columns: minmax(0, 1fr) 380px; gap: 48px; } }

        /* Top row - brand on the left, back-to-property escape
           on the right. Wraps to two rows on narrow phones so the
           property name never truncates. */
        .kv-topbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .kv-brand {
            display: inline-flex; align-items: center; gap: 8px;
            font-weight: 700; font-size: 20px;
        }
        .kv-back-to-property {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; border-radius: 999px;
            border: 1px solid #DDDDDD; background: #fff;
            font-size: 13px; font-weight: 600; color: #14171A;
            text-decoration: none;
            transition: background 120ms ease, border-color 120ms ease;
        }
        .kv-back-to-property:hover { background: #14171A; border-color: #14171A; color: #fff; }
        .kv-brand-mark {
            width: 26px; height: 26px; border-radius: 7px;
            background: #14171A; color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .kv-brand-mark svg { width: 13px; height: 13px; }

        .kv-checkout-h1 { font-size: clamp(24px, 3.5vw, 30px); font-weight: 700; margin: 0 0 24px; letter-spacing: -0.02em; }

        .kv-checkout-form { display: grid; gap: 22px; }
        .kv-fieldset { border: 1px solid #EBEBEB; border-radius: 14px; padding: 20px 22px; background: #fff; }
        .kv-fieldset h3 { margin: 0 0 12px; font-size: 15px; font-weight: 700; color: #222; }
        .kv-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 640px) { .kv-grid-2 { grid-template-columns: 1fr; } }

        .kv-input, .kv-textarea, .kv-select {
            width: 100%; padding: 10px 12px; border: 1px solid #DDDDDD; border-radius: 10px;
            font: inherit; color: #222; background: #fff;
        }
        .kv-input:focus, .kv-textarea:focus, .kv-select:focus { outline: none; border-color: #14171A; }
        .kv-textarea { min-height: 90px; resize: vertical; }
        .kv-label { display: block; font-size: 12px; font-weight: 600; color: #6A6A6A; margin: 0 0 6px; text-transform: uppercase; letter-spacing: 0.05em; }
        .kv-error { font-size: 12.5px; color: #B85C42; margin-top: 4px; }

        .kv-room-picker { display: grid; gap: 10px; }

        /* Multi-room cart list - stacked cards, remove button on
           the right. Add-another link sits below either the cart
           list or the single-room summary. */
        .kv-cart-list { display: grid; gap: 8px; margin-bottom: 10px; }
        .kv-cart-item {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; padding: 12px 14px;
            border: 1px solid #E4E1DA; background: #FBFAF7;
            border-radius: 12px;
        }
        .kv-cart-item-title { font-weight: 700; font-size: 14.5px; color: #14171A; }
        .kv-cart-item-sub { font-size: 12.5px; color: #6A6A6A; margin-top: 2px; }
        .kv-cart-remove {
            background: none; border: none; padding: 0;
            font: inherit; color: #B85C42; font-weight: 600;
            font-size: 12.5px; text-decoration: underline; cursor: pointer;
        }
        .kv-cart-add-another {
            display: inline-block; margin-top: 10px;
            padding: 8px 14px; border-radius: 999px;
            border: 1px dashed #DDDDDD; background: #fff;
            font-size: 12.5px; font-weight: 600; color: #14171A;
            text-decoration: none;
        }
        .kv-cart-add-another:hover { border-color: #14171A; }
        .kv-room-selected {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; padding: 14px 16px;
            border: 1px solid #14171A; border-radius: 12px;
            background: #FBFAF7;
        }
        .kv-room-selected-name { font-weight: 700; font-size: 15px; color: #14171A; }
        .kv-room-selected-sub { font-size: 12.5px; color: #6A6A6A; margin-top: 2px; }
        .kv-room-change, .kv-room-cancel {
            padding: 8px 16px; border-radius: 999px;
            background: transparent; border: 1.5px solid #14171A;
            color: #14171A; font: inherit; font-weight: 700; font-size: 12.5px;
            cursor: pointer; transition: background 120ms ease, color 120ms ease;
        }
        .kv-room-change:hover, .kv-room-cancel:hover { background: #14171A; color: #fff; }
        .kv-room-cancel { margin-top: 10px; align-self: flex-end; }
        .kv-room-option {
            display: grid; grid-template-columns: auto 1fr auto; gap: 12px;
            align-items: center;
            padding: 12px 14px; border: 1px solid #EBEBEB; border-radius: 12px;
            cursor: pointer;
        }
        .kv-room-option.selected { border-color: #14171A; box-shadow: 0 0 0 1px #14171A inset; }
        .kv-room-option input[type=radio] { margin: 0; }
        .kv-room-option-title { font-weight: 600; font-size: 14.5px; }
        .kv-room-option-sub { font-size: 12.5px; color: #6A6A6A; }
        .kv-room-option-price { font-weight: 700; font-size: 14px; }

        .kv-summary {
            position: sticky; top: 24px;
            border: 1px solid #EBEBEB; border-radius: 16px;
            background: #FBFAF7;
            padding: 20px 22px;
        }
        .kv-summary h3 { font-size: 15px; font-weight: 700; margin: 0 0 12px; }
        .kv-summary-hero { width: 100%; aspect-ratio: 16 / 10; border-radius: 12px; overflow: hidden; background: #EEE; margin-bottom: 12px; }
        .kv-summary-hero img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .kv-summary-line { display: flex; justify-content: space-between; padding: 6px 0; font-size: 13.5px; color: #333; }
        .kv-summary-line.total { border-top: 1px solid #E4E1DA; margin-top: 8px; padding-top: 12px; font-weight: 700; font-size: 15px; color: #14171A; }

        /* Coupon block - collapsed input + Apply while empty,
           expands to a compact chip + Remove once applied. */
        .kv-coupon { margin: 10px 0 6px; }
        .kv-coupon-form {
            display: flex; gap: 6px; align-items: stretch;
            padding: 8px 0 4px;
        }
        .kv-coupon-form .kv-input {
            flex: 1; padding: 8px 10px; font-size: 13px;
            border: 1px solid #DDDDDD; border-radius: 8px;
        }
        .kv-coupon-apply {
            padding: 8px 14px; border-radius: 8px;
            background: #14171A; color: #fff; border: none;
            font: inherit; font-weight: 700; font-size: 12.5px;
            cursor: pointer; white-space: nowrap;
        }
        .kv-coupon-apply:disabled { opacity: 0.7; cursor: not-allowed; }
        .kv-coupon-applied {
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 12px; margin-top: 4px;
            border: 1px solid #B2CE7E; background: #F1F7E6;
            border-radius: 8px;
            font-size: 13px; color: #4A6B1E;
        }
        .kv-coupon-applied strong { font-weight: 700; }
        .kv-coupon-off { margin-left: 8px; color: #4A6B1E; font-weight: 600; }
        .kv-coupon-remove {
            background: none; border: none; padding: 0;
            font: inherit; color: #4A6B1E; font-weight: 600;
            text-decoration: underline; cursor: pointer; font-size: 12.5px;
        }
        .kv-coupon-error {
            font-size: 12px; color: #B85C42;
            padding: 6px 10px; margin: 4px 0 0;
            background: rgba(184, 92, 66, 0.08); border-radius: 6px;
        }

        /* Payment choice tiles - two radio-labels stacked. Selected
           tile gets an ink border + slightly-warmer background so the
           picked option reads as chosen without a chunky filled state. */
        .kv-pay-choice {
            display: grid; gap: 8px; margin-top: 14px;
        }
        .kv-pay-option {
            display: grid; grid-template-columns: auto 1fr; gap: 10px; align-items: start;
            padding: 12px 14px; border-radius: 10px;
            border: 1px solid #DDDDDD; background: #fff;
            cursor: pointer; transition: border-color 120ms ease, background 120ms ease;
        }
        .kv-pay-option:hover { border-color: #14171A; }
        .kv-pay-option.selected {
            border-color: #14171A; background: #FBFAF7;
            box-shadow: 0 0 0 1px #14171A inset;
        }
        .kv-pay-option input[type=radio] { margin-top: 3px; }
        .kv-pay-option-title { font-weight: 700; font-size: 13.5px; color: #14171A; }
        .kv-pay-option-sub { font-size: 12px; color: #6A6A6A; margin-top: 2px; line-height: 1.4; }
        .kv-summary-note { font-size: 11.5px; color: #6A6A6A; margin-top: 8px; }

        .kv-pay {
            display: block; width: 100%;
            padding: 14px 18px; border-radius: 12px;
            background: linear-gradient(135deg, #ADCB52 0%, #8AAE3A 100%); color: #fff;
            font-weight: 700; font-size: 15px; text-align: center;
            border: none; cursor: pointer;
        }
        .kv-pay:hover { background: linear-gradient(135deg, #8AAE3A 0%, #6E8E29 100%); color: #fff; }
        .kv-pay:disabled { opacity: 0.7; cursor: not-allowed; }

        .kv-topbar-error {
            border: 1px solid rgba(184, 92, 66, 0.35);
            background: rgba(184, 92, 66, 0.06);
            color: #7A3927;
            font-size: 13.5px; padding: 12px 14px; border-radius: 12px;
            margin-bottom: 18px;
        }
    </style>

    <div class="kv-topbar" data-testid="checkout-topbar">
        <a href="/" class="kv-brand" data-testid="checkout-brand">
            <span class="kv-brand-mark" aria-hidden>
                <svg viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
            </span>
            <span>{{ \App\Models\Tenant::current()?->name }}</span>
        </a>

        {{-- Back-to-property escape. Preserves the guest's date +
             guest-count context so /stays/{slug} opens on the same
             search they came from. Property name lives in the link
             copy so the destination is obvious. --}}
        @php
            $propertyBackUrl = route('marketplace.stays.show', ['slug' => $slug]);
            $backQs = http_build_query(array_filter([
                'from' => $checkin, 'to' => $checkout,
                'adults' => $adults ?: null,
                'children' => $children ?: null,
            ], fn ($v) => $v !== null && $v !== ''));
            if ($backQs !== '') $propertyBackUrl .= '?'.$backQs;
        @endphp
        <a href="{{ $propertyBackUrl }}" class="kv-back-to-property"
           data-testid="checkout-cancel">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none"
                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M10 3L4 8l6 5"/>
            </svg>
            <span>Cancel</span>
        </a>
    </div>

    <h1 class="kv-checkout-h1" data-testid="checkout-h1">Confirm and pay</h1>

    @if ($errorMessage)
        <div class="kv-topbar-error" data-testid="checkout-error">{{ $errorMessage }}</div>
    @endif

    <div class="kv-checkout-shell">

        <form wire:submit="pay" class="kv-checkout-form" data-testid="checkout-form">

            {{-- Dates + guests --}}
            @php
                $kvToday = \Illuminate\Support\Carbon::today()->toDateString();
                $kvTomorrow = \Illuminate\Support\Carbon::today()->addDay()->toDateString();
                $kvCheckoutMin = $this->checkin !== ''
                    ? \Illuminate\Support\Carbon::parse($this->checkin)->addDay()->toDateString()
                    : $kvTomorrow;
            @endphp
            <fieldset class="kv-fieldset" data-testid="checkout-when">
                <h3>Your trip</h3>
                <div class="kv-grid-2">
                    <div>
                        <label for="ci" class="kv-label">Check-in</label>
                        <input id="ci" type="date" wire:model.blur="checkin"
                               min="{{ $kvToday }}"
                               class="kv-input" data-testid="checkout-checkin">
                        @error('checkin') <p class="kv-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="co" class="kv-label">Check-out</label>
                        <input id="co" type="date" wire:model.blur="checkout"
                               min="{{ $kvCheckoutMin }}"
                               class="kv-input" data-testid="checkout-checkout">
                        @error('checkout') <p class="kv-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ad" class="kv-label">Adults</label>
                        <input id="ad" type="number" min="1" max="12" wire:model.blur="adults"
                               class="kv-input" data-testid="checkout-adults">
                        @error('adults') <p class="kv-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ch" class="kv-label">Children</label>
                        <input id="ch" type="number" min="0" max="8" wire:model.blur="children"
                               class="kv-input" data-testid="checkout-children">
                        @error('children') <p class="kv-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>

            {{-- Room picker - collapsed by default (shows only the
                 selected category + a Change trigger). Expanding
                 renders one row per category, not per door number,
                 so a property with 30 Deluxe rooms doesn't dump
                 30 radio options into the checkout page. --}}
            @php
                $selCategory = $this->selectedCategory();
                $selRoom = $this->selectedRoom();
                $selCapacity = $selCategory ? $selCategory->maxOccupancy() : 0;
                $cartRoomsPreview = $this->cartRooms();
                $cartHasMultiple = $cartRoomsPreview->count() > 1;
                // Category-hold properties don't reveal a specific
                // room number at checkout — the front desk assigns
                // the actual unit at check-in via AssignRoomToBooking.
                // Hides the "Room 103" line + the "Change room"
                // affordance (there's nothing to change; the guest
                // holds a category, not a room).
                $categoryHoldMode = (($property?->stays_online_hold_mode ?? 'room') === 'category');
                // Single-unit properties (standalone apartments) use
                // "unit" copy — "Your unit / Add another unit" — since
                // there's no "room 103" concept and often no more units
                // to add on this property.
                $isSingleUnitProperty = (($property?->unit_mode ?? 'multi_room') === 'single_unit');
                $unitLabel = $isSingleUnitProperty ? 'unit' : 'room';
                $unitLabelPlural = $isSingleUnitProperty ? 'units' : 'rooms';
                $addAnotherHref = route('marketplace.stays.show', ['slug' => $slug])
                    .'?'.http_build_query(array_filter([
                        'from' => $checkin, 'to' => $checkout,
                        'adults' => $adults ?: null,
                        'children' => $children ?: null,
                        'cart' => implode(',', $this->cartRoomIds()),
                    ], fn ($v) => $v !== null && $v !== ''));
            @endphp
            <fieldset class="kv-fieldset" data-testid="checkout-rooms">
                <h3>
                    Your {{ $cartHasMultiple ? $unitLabelPlural : $unitLabel }}
                    @if ($cartHasMultiple)
                        <span style="color: #6A6A6A; font-weight: 500; font-size: 13px;">
                            · {{ $cartRoomsPreview->count() }} {{ $unitLabelPlural }}
                        </span>
                    @endif
                </h3>

                @if ($cartHasMultiple)
                    {{-- Multi-room cart view. Each room gets its own row
                         with a Remove button so the guest can trim the
                         cart without leaving the checkout. --}}
                    <div class="kv-cart-list" data-testid="checkout-cart-list">
                        @foreach ($cartRoomsPreview as $cartRoom)
                            @php
                                $cCat = $cartRoom->category;
                                $cName = $cCat?->name ?: ('Room '.$cartRoom->room_number);
                                $cCap = $cCat ? $cCat->maxOccupancy() : 0;
                            @endphp
                            <div class="kv-cart-item"
                                 wire:key="cart-item-{{ $cartRoom->id }}"
                                 data-testid="checkout-cart-item-{{ $cartRoom->id }}">
                                <div>
                                    <div class="kv-cart-item-title">{{ $cName }}</div>
                                    <div class="kv-cart-item-sub">
                                        @if ($cCap > 0) Sleeps {{ $cCap }} · @endif
                                        @if (! $categoryHoldMode)
                                            Room {{ $cartRoom->room_number }} ·
                                        @endif
                                        &#8358;{{ number_format((int) $cartRoom->base_rate) }} / night
                                    </div>
                                </div>
                                <button type="button" class="kv-cart-remove"
                                        wire:click="removeFromCart({{ $cartRoom->id }})"
                                        data-testid="checkout-cart-remove-{{ $cartRoom->id }}">
                                    Remove
                                </button>
                            </div>
                        @endforeach
                    </div>
                    @if (! $isSingleUnitProperty)
                        <a href="{{ $addAnotherHref }}" class="kv-cart-add-another"
                           data-testid="checkout-cart-add-another">
                            + Add another {{ $unitLabel }} from this property
                        </a>
                    @endif
                @elseif (! $expandedRoomPicker && $selCategory)
                    {{-- Single-room collapsed view - selected + Change +
                         "Add another" so the guest can spin up a cart
                         mid-checkout without going back to the property. --}}
                    <div class="kv-room-selected" data-testid="checkout-room-selected">
                        <div>
                            <div class="kv-room-selected-name">{{ $selCategory->name }}</div>
                            <div class="kv-room-selected-sub">
                                @if ($selCapacity > 0) Sleeps {{ $selCapacity }} @endif
                                @if ($selRoom && ! $categoryHoldMode) · Room {{ $selRoom->room_number }} @endif
                                @if ($categoryHoldMode)
                                    <span style="display: block; margin-top: 4px; font-size: 12.5px; color: #6B6B6B;">
                                        Your specific room is assigned by the front desk at check-in.
                                    </span>
                                @endif
                            </div>
                        </div>
                        @if (! $categoryHoldMode && ! $isSingleUnitProperty)
                            <button type="button" class="kv-room-change"
                                    wire:click="expandRoomPicker"
                                    data-testid="checkout-room-change">
                                Change room
                            </button>
                        @endif
                    </div>
                    @if (! $isSingleUnitProperty)
                        <a href="{{ $addAnotherHref }}" class="kv-cart-add-another"
                           data-testid="checkout-cart-add-another">
                            + Add another {{ $unitLabel }} from this property
                        </a>
                    @endif
                @else
                    {{-- Expanded - one row per unique category. --}}
                    <div class="kv-room-picker">
                        @foreach ($this->categoryOptions() as $row)
                            @php
                                /** @var \App\Models\RoomCategory $cat */
                                $cat = $row['category'];
                                $count = (int) $row['room_count'];
                                $catCap = $cat->maxOccupancy();
                                $isSelected = $selCategory && $selCategory->id === $cat->id;
                            @endphp
                            <label class="kv-room-option {{ $isSelected ? 'selected' : '' }}"
                                   wire:key="cat-{{ $cat->id }}"
                                   data-testid="checkout-category-option-{{ $cat->id }}">
                                <input type="radio" name="checkout-category" value="{{ $cat->id }}"
                                       @checked($isSelected)
                                       wire:click="selectCategory({{ $cat->id }})">
                                <div>
                                    <div class="kv-room-option-title">{{ $cat->name }}</div>
                                    <div class="kv-room-option-sub">
                                        @if ($catCap > 0) Sleeps {{ $catCap }} @endif
                                        · {{ $count }} unit{{ $count === 1 ? '' : 's' }} on this property
                                    </div>
                                </div>
                                <div class="kv-room-option-price">
                                    &#8358;{{ number_format((int) $cat->base_rate) }}<span style="font-weight: 400; color: #6A6A6A;"> / night</span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    <button type="button" class="kv-room-cancel"
                            wire:click="collapseRoomPicker"
                            data-testid="checkout-room-cancel">
                        Done
                    </button>
                @endif
                @error('room') <p class="kv-error">{{ $message }}</p> @enderror
            </fieldset>

            {{-- Guest info. Phone first so the updatedGuestPhone hook
                 can look up a matching customer + autofill the rest
                 of the form for repeat guests, saving them the retype. --}}
            <fieldset class="kv-fieldset" data-testid="checkout-guest">
                <h3>Your details</h3>
                <p style="font-size: 12px; color: #6A6A6A; margin: -4px 0 12px;">
                    Start with your phone - we'll fill in the rest if you've stayed with us before.
                </p>
                <div class="kv-grid-2">
                    <div style="grid-column: 1 / -1;">
                        <label for="gp" class="kv-label">Phone</label>
                        <input id="gp" type="tel" wire:model.blur="guestPhone" maxlength="32"
                               class="kv-input" data-testid="checkout-guest-phone"
                               placeholder="+234 803 000 0000" required autofocus>
                        @error('guestPhone') <p class="kv-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="gn" class="kv-label">Full name</label>
                        <input id="gn" type="text" wire:model.blur="guestName" maxlength="120"
                               class="kv-input" data-testid="checkout-guest-name" required>
                        @error('guestName') <p class="kv-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ge" class="kv-label">
                            Email
                            <span style="color: #6A6A6A; font-weight: 400; text-transform: none; letter-spacing: 0;">
                                (optional)
                            </span>
                        </label>
                        <input id="ge" type="email" wire:model.blur="guestEmail" maxlength="180"
                               class="kv-input" data-testid="checkout-guest-email">
                        <p style="font-size: 11.5px; color: #6A6A6A; margin: 4px 0 0;"
                           data-testid="checkout-email-hint">
                            We'll send booking confirmation + receipt to this email if you add one.
                        </p>
                        @error('guestEmail') <p class="kv-error">{{ $message }}</p> @enderror
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label for="sr" class="kv-label">Special requests (optional)</label>
                        <textarea id="sr" wire:model.blur="specialRequests" maxlength="600"
                                  class="kv-textarea" data-testid="checkout-special-requests"
                                  placeholder="Early check-in? Airport pickup?"></textarea>
                        @error('specialRequests') <p class="kv-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>
        </form>

        {{-- Sticky summary + pay --}}
        <aside>
            <div class="kv-summary" data-testid="checkout-summary">
                @if ($property)
                    <div class="kv-summary-hero">
                        @if ($hero !== '')
                            <img src="{{ $hero }}" alt="{{ $property->name }}">
                        @endif
                    </div>
                    <h3 style="margin: 0 0 4px;">{{ $property->name }}</h3>
                    <div style="font-size: 13px; color: #6A6A6A; margin-bottom: 14px;">{{ $city }}</div>
                @endif

                @php
                    $bd = $this->breakdown();
                @endphp

                @if ($selectedRoom && $bd)
                    <div class="kv-summary-line" data-testid="checkout-line-room">
                        @if (! empty($bd['rates_vary']))
                            <span>
                                {{ $bd['nights'] }} night{{ $bd['nights'] === 1 ? '' : 's' }}
                                <span style="font-size: 12px; color: #6B6B6B; font-weight: 400;">(rate varies by date)</span>
                            </span>
                        @else
                            <span>&#8358;{{ number_format($bd['base_rate']) }} × {{ $bd['nights'] }} night{{ $bd['nights'] === 1 ? '' : 's' }}</span>
                        @endif
                        <span>&#8358;{{ number_format($bd['subtotal']) }}</span>
                    </div>

                    {{-- Coupon code input. Guest types + hits Apply;
                         we validate via DiscountEngine (per-tenant)
                         and show the amount off inline. Kept above
                         the fees so the reduction reads naturally in
                         the totals stack. --}}
                    <div class="kv-coupon" data-testid="checkout-coupon">
                        @if ($appliedDiscountCode)
                            <div class="kv-coupon-applied" data-testid="checkout-coupon-applied">
                                <span>
                                    <strong>{{ $appliedDiscountCode }}</strong>
                                    <span class="kv-coupon-off">&minus;&#8358;{{ number_format($discountAmount) }}</span>
                                </span>
                                <button type="button" class="kv-coupon-remove"
                                        wire:click="removeDiscount"
                                        data-testid="checkout-coupon-remove">Remove</button>
                            </div>
                            <div class="kv-summary-line" data-testid="checkout-line-discount">
                                <span>Coupon <em style="color: #6A6A6A;">({{ $appliedDiscountCode }})</em></span>
                                <span style="color: #4A6B1E;">&minus;&#8358;{{ number_format($discountAmount) }}</span>
                            </div>
                        @else
                            <div class="kv-coupon-form">
                                <input type="text" wire:model.live.debounce.500ms="discountCode"
                                       class="kv-input" placeholder="Coupon code"
                                       maxlength="80" autocomplete="off"
                                       data-testid="checkout-coupon-input">
                                <button type="button" class="kv-coupon-apply"
                                        wire:click="applyDiscount"
                                        wire:loading.attr="disabled" wire:target="applyDiscount"
                                        data-testid="checkout-coupon-apply">
                                    <span wire:loading.remove wire:target="applyDiscount">Apply</span>
                                    <span wire:loading wire:target="applyDiscount">…</span>
                                </button>
                            </div>
                            @if ($discountError)
                                <p class="kv-coupon-error" data-testid="checkout-coupon-error">
                                    {{ $discountError }}
                                </p>
                            @endif
                        @endif
                    </div>

                    @if ($bd['platform_fee'] > 0)
                        <div class="kv-summary-line" data-testid="checkout-line-platform">
                            <span title="The booking fee for online bookings">
                                {{ config('brand.name') }} service fee
                                <!-- <span style="color: #6A6A6A; font-size: 12px;">
                                    ({{ rtrim(rtrim(number_format($bd['platform_fee_percent'], 1), '0'), '.') }}%)
                                </span> -->
                            </span>
                            <span>&#8358;{{ number_format($bd['platform_fee']) }}</span>
                        </div>
                    @endif
                    @if ($bd['processing_fee'] > 0)
                        <div class="kv-summary-line" data-testid="checkout-line-processing">
                            <span title="Paystack's card-processing fee">Payment processing</span>
                            <span>&#8358;{{ number_format($bd['processing_fee']) }}</span>
                        </div>
                    @endif
                    <div class="kv-summary-line total" data-testid="checkout-total">
                        <span>Total</span>
                        <span>&#8358;{{ number_format($bd['total']) }}</span>
                    </div>

                    {{-- Pay full vs pay deposit choice. Deposit
                         collects a platform-configured percentage
                         up-front (default 30%) via Paystack; the
                         guest settles the balance at the property. --}}
                    <div class="kv-pay-choice" role="radiogroup" aria-label="Payment option"
                         data-testid="checkout-payment-choice">
                        <label class="kv-pay-option {{ $paymentPreference === 'full' ? 'selected' : '' }}"
                               data-testid="checkout-pay-full-option">
                            <input type="radio" wire:model.live="paymentPreference" value="full">
                            <div>
                                <div class="kv-pay-option-title">Pay in full</div>
                                <div class="kv-pay-option-sub">
                                    &#8358;{{ number_format($bd['total']) }} charged to your card now.
                                </div>
                            </div>
                        </label>
                        <label class="kv-pay-option {{ $paymentPreference === 'deposit' ? 'selected' : '' }}"
                               data-testid="checkout-pay-deposit-option">
                            <input type="radio" wire:model.live="paymentPreference" value="deposit">
                            <div>
                                <div class="kv-pay-option-title">
                                    Pay {{ (int) config('keverna.pricing.stays_deposit_percent', 30) }}% deposit
                                </div>
                                <div class="kv-pay-option-sub">
                                    &#8358;{{ number_format($bd['due_now']) }} now,
                                    &#8358;{{ number_format($bd['due_at_property']) }} at the property.
                                </div>
                            </div>
                        </label>
                    </div>

                    @if ($paymentPreference === 'deposit')
                        <div class="kv-summary-line" data-testid="checkout-line-due-now"
                             style="border-top: 1px solid #E4E1DA; margin-top: 6px; padding-top: 10px;">
                            <span>Due now</span>
                            <span>&#8358;{{ number_format($bd['due_now']) }}</span>
                        </div>
                        <div class="kv-summary-line" data-testid="checkout-line-due-at-property">
                            <span>Due at property</span>
                            <span>&#8358;{{ number_format($bd['due_at_property']) }}</span>
                        </div>
                    @endif
                @elseif ($selectedRoom)
                    <div class="kv-summary-line" data-testid="checkout-pick-dates">
                        <span style="color: #6A6A6A;">Pick check-in + check-out to see the price</span>
                    </div>
                @endif

                @php
                    $canPay = $selectedRoom && $bd && $bd['total'] > 0 && $this->isContactComplete();
                @endphp
                <button type="submit" form="" wire:click="pay" wire:loading.attr="disabled"
                        class="kv-pay" data-testid="checkout-pay-btn"
                        style="margin-top: 14px;"
                        @if (! $canPay) disabled @endif>
                    <span wire:loading.remove wire:target="pay">
                        {{ $this->payButtonLabel() }}
                    </span>
                    <span wire:loading wire:target="pay">Setting up Paystack…</span>
                </button>

                <p style="font-size: 11px; color: #6A6A6A; text-align: center; margin-top: 10px;">
                    Charged securely by Paystack. Cancellation windows follow the property's policy.
                </p>
            </div>
        </aside>

    </div>

    
</div>
