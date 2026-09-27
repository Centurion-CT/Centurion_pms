<?php

declare(strict_types=1);

namespace App\Livewire\Marketplace;

use App\Actions\Bookings\BookingValidationException;
use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Marketplace\CreateStaysBooking;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;

/**
 * Keverna Stays checkout - the two-page-worth-of-form the guest fills
 * out after clicking Reserve on /stays/{slug}. On submit:
 *   - CreateStaysBooking creates the Booking + AvailabilityBlock in
 *     the property's tenant scope
 *   - InitiateBookingPayment kicks off Paystack (subaccount split 98/2)
 *   - The guest is redirected to Paystack's hosted card page; Paystack
 *     redirects back to /stays/pay/callback where we verify + finalize.
 *
 * Anonymous flow - no operator / guest login required. The Booking
 * carries the guest's email + phone; the confirmation email + WhatsApp
 * fire off the existing BookingConfirmed event pipeline the moment
 * ConfirmBookingPayment runs in the callback.
 */
final class StaysBookCheckout extends Component
{
    public string $slug = '';

    /** @var array{property?: Property, rooms?: \Illuminate\Support\Collection}|null */
    protected ?array $bundle = null;

    #[Url]
    public ?int $room = null;

    /**
     * Multi-room cart. URL-synced as a comma-separated list of room
     * ids (?rooms=42,43). Empty string means "single-room flow",
     * driven by $room above. Property-page Reserve buttons append
     * to this so guests can stack rooms without leaving the browser.
     */
    #[Url]
    public string $rooms = '';

    /**
     * Optional category seed - the property-detail page sends
     * `?category=<id>` on Reserve so checkout can pick a free room
     * inside that category (fallback if `?room=` is absent or the
     * pre-picked room got booked between page-load + click).
     */
    #[Url]
    public ?int $category = null;

    #[Url(as: 'from')]
    public string $checkin = '';

    #[Url(as: 'to')]
    public string $checkout = '';

    #[Url]
    #[Validate('required|integer|min:1|max:12')]
    public int $adults = 1;

    #[Url]
    #[Validate('integer|min:0|max:8')]
    public int $children = 0;

    #[Validate('required|string|max:120')]
    public string $guestName = '';

    #[Validate('nullable|email|max:180')]
    public string $guestEmail = '';

    #[Validate('required|string|min:8|max:32')]
    public string $guestPhone = '';

    #[Validate('nullable|string|max:600')]
    public string $specialRequests = '';

    /**
     * `full` charges the whole total via Paystack; `deposit`
     * charges only the platform-configured percentage (stays_deposit_
     * percent, default 30%) up-front + the guest pays the balance
     * at the property. Keverna's 5% service fee is deducted from
     * the up-front deposit either way so we're paid even when the
     * booking is a hold.
     */
    #[Url(as: 'pay')]
    #[Validate('required|string|in:full,deposit')]
    public string $paymentPreference = 'full';

    public ?string $errorMessage = null;
    public bool $submitting = false;

    /**
     * Coupon field the guest types. Not the source of truth for the
     * applied discount - kept editable so the guest can change it +
     * click Apply again without losing what's already applied. Once
     * validated, we lock the outcome into $appliedDiscountCode +
     * $discountAmount.
     */
    #[Url]
    public string $discountCode = '';

    /**
     * Successfully-applied code, frozen after the DiscountEngine
     * returned an accepted resolution. Passed through to
     * CreateStaysBooking on pay() so CreateBooking re-resolves it
     * against the same operator config we validated against - the
     * booking is authoritative, this is just the guest-side preview.
     */
    public ?string $appliedDiscountCode = null;

    /**
     * ₦ off, computed once at apply-time from the discount rule
     * against the current room subtotal. Kept as a materialised
     * value so a change to dates / room after apply doesn't
     * silently shift the discount under the guest's feet - if
     * they want to re-apply against the new subtotal they hit
     * Apply again.
     */
    public int $discountAmount = 0;

    /**
     * Rejection reason from DiscountEngine when apply() failed
     * ("Code not found", "Expired", "Blackout dates", ...). Shows
     * as a soft inline error under the coupon field. Cleared on
     * every successful apply or remove.
     */
    public ?string $discountError = null;

    /**
     * When true, the room picker shows the full category list;
     * when false (default landing state), only the currently-
     * selected room shows + a "Change room" trigger. Prevents
     * dumping every door number on a property with 10+ rooms.
     */
    public bool $expandedRoomPicker = false;

    /**
     * Auto-advance the checkout date when the guest changes the
     * check-in. Only overwrites when the checkout is empty OR is
     * on/before the new check-in - a manually-picked longer stay
     * is preserved. Mirrors the vanilla-JS pattern on the /stays
     * search + /stays/{slug} availability bars.
     */
    public function updatedCheckin(string $value): void
    {
        if ($value === '') return;
        try {
            $ci = Carbon::parse($value)->startOfDay();
        } catch (\Throwable) { return; }

        $next = $ci->copy()->addDay()->toDateString();
        if ($this->checkout === '') {
            $this->checkout = $next;
            return;
        }
        try {
            $co = Carbon::parse($this->checkout)->startOfDay();
            if ($co->lessThanOrEqualTo($ci)) {
                $this->checkout = $next;
            }
        } catch (\Throwable) {
            $this->checkout = $next;
        }
    }

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $this->bundle = $this->loadBundle();
        if ($this->bundle === null) {
            abort(404);
        }

        // Room resolution order:
        //   1. explicit ?room=<id> that still resolves to an Active
        //      room on this property (property-detail page pre-picks
        //      the first free one so this is the common path)
        //   2. ?category=<id> - pick a room in that category (any
        //      Active room; per-date availability is re-checked in
        //      CreateBooking on submit, so a race gets caught cleanly)
        //   3. cheapest active room on the property - falls back to
        //      this so the page loads with a valid selection instead
        //      of a stuck-empty state
        if ($this->room !== null && ! $this->bundle['rooms']->contains('id', $this->room)) {
            $this->room = null;
        }
        if ($this->room === null && $this->category !== null) {
            $inCategory = $this->bundle['rooms']->filter(
                fn (Room $r) => (int) $r->room_category_id === (int) $this->category
            );
            $this->room = $inCategory->first()?->id;
        }
        if ($this->room === null) {
            $cheapest = $this->bundle['rooms']
                ->sortBy(fn (Room $r): int => (int) $r->base_rate)
                ->first();
            $this->room = $cheapest?->id;
        }

        // Cart sync: filter ?rooms= to ids that actually exist on
        // the property + are Active. If nothing survives, wipe to
        // empty so we fall back to the single-room path driven by
        // $this->room (which is guaranteed set above).
        if ($this->rooms !== '') {
            $rawIds = array_map('intval', array_filter(
                array_map('trim', explode(',', $this->rooms)),
                fn ($v) => (int) $v > 0,
            ));
            $validIds = array_values(array_filter($rawIds,
                fn (int $id) => $this->bundle['rooms']->contains('id', $id)));
            // If the primary $room isn't in the cart, prepend it so
            // rooms selected via the "Change room" picker stay visible.
            if ($this->room !== null && ! in_array((int) $this->room, $validIds, true)) {
                array_unshift($validIds, (int) $this->room);
            }
            $this->rooms = count($validIds) > 1 ? implode(',', $validIds) : '';
            if (count($validIds) >= 1) {
                $this->room = $validIds[0];
            }
        }

        // Sanitise + default dates. Ensures the page always loads
        // with a valid pair regardless of what came in via URL:
        //   * unparseable check-in    -> today + 7
        //   * past check-in           -> today
        //   * unparseable check-out   -> check-in + 1
        //   * check-out <= check-in   -> check-in + 1
        $today = Carbon::today();
        try {
            $ci = $this->checkin !== ''
                ? Carbon::parse($this->checkin)->startOfDay()
                : $today->copy()->addDays(7);
        } catch (\Throwable) {
            $ci = $today->copy()->addDays(7);
        }
        if ($ci->lessThan($today)) $ci = $today->copy();
        $this->checkin = $ci->toDateString();

        try {
            $co = $this->checkout !== ''
                ? Carbon::parse($this->checkout)->startOfDay()
                : $ci->copy()->addDay();
        } catch (\Throwable) {
            $co = $ci->copy()->addDay();
        }
        if ($co->lessThanOrEqualTo($ci)) $co = $ci->copy()->addDay();
        $this->checkout = $co->toDateString();
    }

    /**
     * @return array{property: Property, rooms: \Illuminate\Support\Collection}|null
     */
    protected function loadBundle(): ?array
    {
        $property = Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('public_slug', $this->slug)
            ->where('is_listed_publicly', true)
            ->with(['media', 'tenant'])
            ->first();
        if ($property === null) return null;

        $rooms = Room::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('property_id', $property->id)
            ->where('status', RoomStatus::Active->value)
            ->with(['category' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->get();
        if ($rooms->isEmpty()) return null;

        return ['property' => $property, 'rooms' => $rooms];
    }

    public function selectedRoom(): ?Room
    {
        if ($this->bundle === null) return null;

        return $this->bundle['rooms']->firstWhere('id', $this->room);
    }

    /**
     * The full cart of rooms the guest picked - always ≥ 1 entry.
     * If ?rooms=42,43 is set, all listed rooms are returned in the
     * URL order. Otherwise falls back to the single-room path
     * driven by $this->room.
     *
     * @return \Illuminate\Support\Collection<int, Room>
     */
    public function cartRooms(): Collection
    {
        if ($this->bundle === null) return collect();
        $ids = $this->cartRoomIds();
        return collect($ids)
            ->map(fn (int $id) => $this->bundle['rooms']->firstWhere('id', $id))
            ->filter()
            ->values();
    }

    /**
     * @return list<int>
     */
    public function cartRoomIds(): array
    {
        $raw = trim($this->rooms);
        if ($raw !== '') {
            $ids = array_values(array_unique(array_map('intval',
                array_filter(explode(',', $raw), fn ($v) => (int) trim($v) > 0))));
            if ($ids !== []) return $ids;
        }
        // Fall back to single-room path.
        return $this->room !== null ? [(int) $this->room] : [];
    }

    public function cartCount(): int
    {
        return count($this->cartRoomIds());
    }

    /**
     * Append a room to the cart. Called from the "Add another room"
     * flow - the property page's Reserve buttons redirect via URL
     * param, but this action lets Livewire clients add without a
     * full page reload once they're already on checkout.
     */
    public function addToCart(int $roomId): void
    {
        if ($roomId <= 0) return;
        // $bundle is a per-request protected property + is null on
        // action-request cycles - reload it for the validity check.
        $bundle = $this->bundle ?? $this->loadBundle();
        if ($bundle === null) return;
        if (! $bundle['rooms']->contains('id', $roomId)) return;
        $this->bundle = $bundle;
        $current = $this->cartRoomIds();
        if (in_array($roomId, $current, true)) return;
        $current[] = $roomId;
        $this->rooms = implode(',', $current);
        if ($this->room === null) $this->room = $current[0];
        // Adding a room changes the fee base + subtotal, so any
        // previously-applied coupon needs a fresh Apply pass.
        $this->appliedDiscountCode = null;
        $this->discountAmount = 0;
    }

    /**
     * Drop a room from the cart. If only one remains after the
     * remove, we blank the $rooms URL param so we're back on the
     * single-room path (keeps URLs tidy).
     */
    public function removeFromCart(int $roomId): void
    {
        $ids = array_values(array_filter($this->cartRoomIds(), fn ($id) => $id !== $roomId));
        if ($ids === []) {
            // Don't let the guest empty the cart entirely - the
            // checkout page has to have a room selected. Reload with
            // the single-room path pointing at whatever's cheapest,
            // matching mount()'s original behaviour.
            $this->rooms = '';
            $this->room = null;
            return;
        }
        if (count($ids) === 1) {
            $this->rooms = '';
            $this->room = $ids[0];
        } else {
            $this->rooms = implode(',', $ids);
            $this->room = $ids[0];
        }
        // Removing a room invalidates any applied coupon (fee math
        // changes) - make the guest reapply.
        $this->appliedDiscountCode = null;
        $this->discountAmount = 0;
    }

    public function nightsPreview(): int
    {
        try {
            $ci = Carbon::parse($this->checkin)->startOfDay();
            $co = Carbon::parse($this->checkout)->startOfDay();

            // Explicit absolute:true - Carbon 3 flipped the sign
            // convention on diffInDays(). The prior `->diffInDays(_, false)
            // * -1` idiom returned 0 for a valid 1-night stay under
            // Carbon 3, which is why the checkout page rendered "0 nights"
            // for the default today+7 -> today+8 seed.
            if ($co->lessThanOrEqualTo($ci)) return 0;
            return (int) $ci->diffInDays($co, absolute: true);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Guest-facing pricing breakdown for the current room + range +
     * payment preference. Reads directly from
     * {@see \App\Support\Marketplace\FeePolicy} - the single source
     * of truth CreateStaysBooking also uses to override the
     * booking's stored amounts, so preview + persisted totals
     * never disagree.
     *
     * Guest sees sticker (nights × rate) + Paystack processing.
     * Keverna's platform fee is deducted from the operator payout
     * at settlement time - NOT added to the guest total.
     *
     * When `paymentPreference === 'deposit'`, adds two extra fields:
     *   * due_now         - deposit paid via Paystack (default 30%)
     *   * due_at_property - remainder collected by the operator
     *
     * @return array{
     *     nights: int,
     *     base_rate: int,
     *     subtotal: int,
     *     processing_fee: int,
     *     total: int,
     *     platform_fee: int,
     *     platform_fee_percent: float,
     *     operator_payout: int,
     *     due_now: int,
     *     due_at_property: int,
     *     payment_preference: string,
     * }|null
     */
    public function breakdown(): ?array
    {
        $rooms = $this->cartRooms();
        if ($rooms->isEmpty()) return null;
        $nights = $this->nightsPreview();
        if ($nights < 1) return null;

        // Aggregate subtotal across every room in the cart. Fees +
        // coupon are computed once against the sum, not per-room,
        // so multi-room carts pay Keverna 2.5% + Paystack 1.5%
        // ONCE - matches what a merged single-Paystack transaction
        // actually charges.
        //
        // Rates run through PricingCalculator with the current
        // marketplace Request so the sticker line always mirrors
        // what the guest is about to pay — no drift between the
        // /stays detail page's resolved rate and the checkout total.
        $calc = app(\App\Services\Pricing\PricingCalculator::class);
        $ci = Carbon::parse($this->checkin)->startOfDay();
        $co = Carbon::parse($this->checkout)->startOfDay();
        $subtotal = 0;
        $baseRate = 0;
        $ratesVary = false;
        foreach ($rooms as $r) {
            $br = $calc->calculate($r, $ci, $co, 0, false, request());
            $subtotal += (int) $br->subtotal;
            // "Per-night sticker" rate is the first night's rate per
            // room, summed. Matches the display convention used pre-rules.
            $baseRate += (int) ($br->nightlyRates[0]->rate ?? $r->base_rate);
            // Detect mixed-rate stays so the sticker line can render
            // "N nights (rate varies by date)" instead of a misleading
            // "₦X × N nights" that pins on the first night only.
            if (! empty($br->nightlyRates)) {
                $first = (int) $br->nightlyRates[0]->rate;
                foreach ($br->nightlyRates as $nr) {
                    if ((int) $nr->rate !== $first) { $ratesVary = true; break; }
                }
            }
        }
        if ($subtotal <= 0) return null;

        // Apply coupon before computing fees so the discount actually
        // reduces the fee bases the guest pays (2.5% + 1.5%) - matches
        // what CreateBooking does when it resolves the same code.
        $discountAmount = min($this->discountAmount, $subtotal);
        $netSubtotal = max(0, $subtotal - $discountAmount);

        $policy = \App\Support\Marketplace\FeePolicy::class;
        $processing = $policy::paystackFeeFor($netSubtotal);
        $platformFee = $policy::kevernaFeeFor($netSubtotal);
        $platformPct = $policy::percentFor($netSubtotal);
        $operatorPayout = $policy::operatorPayoutFor($netSubtotal);
        $total = $policy::guestTotalFor($netSubtotal);

        $depositPercent = max(1, min(99, (int) config('keverna.pricing.stays_deposit_percent', 30)));
        if ($this->paymentPreference === 'deposit') {
            $dueNow = (int) round($total * $depositPercent / 100);
            $dueAtProperty = $total - $dueNow;
        } else {
            $dueNow = $total;
            $dueAtProperty = 0;
        }

        return [
            'nights'               => $nights,
            'base_rate'            => $baseRate,
            'rates_vary'           => $ratesVary,
            'subtotal'             => $subtotal,
            'discount_code'        => $this->appliedDiscountCode,
            'discount_amount'      => $discountAmount,
            'net_subtotal'         => $netSubtotal,
            'processing_fee'       => $processing,
            'total'                => $total,
            'platform_fee'         => $platformFee,
            'platform_fee_percent' => $platformPct,
            'operator_payout'      => $operatorPayout,
            'due_now'              => $dueNow,
            'due_at_property'      => $dueAtProperty,
            'payment_preference'   => $this->paymentPreference,
        ];
    }

    /**
     * Validates the guest's typed coupon code against the property's
     * DiscountEngine. On accept: freezes the code + amount into the
     * applied slots + clears any previous error. On reject: keeps
     * the typed code (so the guest can tweak + retry) but wipes any
     * previously-accepted amount so a live "-₦X" doesn't linger
     * against a code we no longer honour.
     */
    public function applyDiscount(): void
    {
        $code = trim($this->discountCode);
        if ($code === '') {
            $this->discountError = 'Enter a coupon code first.';
            $this->appliedDiscountCode = null;
            $this->discountAmount = 0;
            return;
        }

        $nights = $this->nightsPreview();
        if ($nights < 1 || $this->room === null) {
            $this->discountError = 'Pick your dates before applying a coupon.';
            return;
        }

        // Property + Room fetched fresh here (not via $this->bundle -
        // that's a protected property + is null on updated / action
        // request cycles). Switching into the property's tenant scope
        // is what lets Discount's BelongsToTenant hit the right table
        // subset for the coupon lookup.
        $property = Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('public_slug', $this->slug)
            ->with('tenant')
            ->first();
        if ($property === null) {
            $this->discountError = 'Property is no longer available.';
            return;
        }

        $previous = Tenant::current();
        $property->tenant->makeCurrent();
        try {
            $roomLive = Room::query()->find($this->room);
            if ($roomLive === null) {
                $this->discountError = 'That room is no longer bookable.';
                return;
            }
            $subtotal = $nights * (int) $roomLive->base_rate;

            $resolution = app(\App\Services\Discounts\DiscountEngine::class)->resolveByCode(
                code: $code,
                room: $roomLive,
                checkin: Carbon::parse($this->checkin)->startOfDay(),
                checkout: Carbon::parse($this->checkout)->startOfDay(),
                channel: \App\Enums\BookingSource::Online,
                netSubtotal: $subtotal,
            );
        } finally {
            Tenant::forgetCurrent();
            $previous?->makeCurrent();
        }

        if ($resolution === null || ! $resolution->applies) {
            $this->discountError = $resolution?->reason ?? 'No discount found for that code.';
            $this->appliedDiscountCode = null;
            $this->discountAmount = 0;
            return;
        }

        $this->appliedDiscountCode = $code;
        $this->discountAmount = (int) $resolution->amountOff;
        $this->discountError = null;
    }

    /**
     * Clears a previously-applied coupon so the guest can move on
     * without one (or type a new one from scratch).
     */
    public function removeDiscount(): void
    {
        $this->appliedDiscountCode = null;
        $this->discountAmount = 0;
        $this->discountCode = '';
        $this->discountError = null;
    }

    /**
     * When the guest types a phone, look up existing customers in
     * our DB (property's tenant first, then anywhere across Keverna
     * Stays) and autofill their name + email. Speeds up repeat
     * bookings without asking the guest to type the same info twice.
     *
     * Only fills fields the guest hasn't already touched - a typed
     * name is preserved over the DB name so the guest can correct a
     * stale record ("Ada N." -> "Ada Nwokolo") without us
     * over-writing them mid-keystroke.
     */
    public function updatedGuestPhone(string $value): void
    {
        $normalised = preg_replace('/[^0-9+]/', '', (string) $value) ?? '';
        $digitsOnly = preg_replace('/[^0-9]/', '', $normalised) ?? '';
        // Need at least 8 digits before it's worth a lookup - avoids
        // matching every 3-digit prefix against a customer whose
        // number happens to start with those digits.
        if (strlen($digitsOnly) < 8) return;

        // $bundle is a protected property that lives only for the
        // current Livewire request; on the updated-hook cycle it's
        // null. Query the property's tenant_id directly by slug so
        // we don't have to rehydrate the full bundle just to answer
        // "who owns this property".
        $tenantId = (int) Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('public_slug', $this->slug)
            ->value('tenant_id');

        // Prefer a match on the property's own tenant - the same
        // guest returning to the same operator is the highest-signal
        // hit. Fall through to any tenant so a first-timer at
        // Property A whose phone we know from a stay at Property B
        // still gets autofilled.
        $match = \App\Models\Customer::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('phone', $normalised)
            ->when($tenantId > 0, fn ($q) => $q->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId]))
            ->orderByDesc('last_booking_at')
            ->first();

        if ($match === null) return;

        if (trim($this->guestName) === '' && trim((string) $match->name) !== '') {
            $this->guestName = (string) $match->name;
        }
        if (trim($this->guestEmail) === '' && trim((string) $match->email) !== '') {
            $this->guestEmail = (string) $match->email;
        }
    }

    /**
     * True when the required guest-info fields (name + phone) are
     * populated + syntactically plausible. Used to gate the Pay
     * button so the guest can't kick off a Paystack init that will
     * only fail server-side validation.
     *
     * Email is optional - guest can skip it and still complete the
     * booking; receipts + confirmation just don't go out over email.
     * When provided it must be a syntactically plausible address
     * (FILTER_VALIDATE_EMAIL, structure only).
     */
    public function isContactComplete(): bool
    {
        if (trim($this->guestName) === '') return false;
        // If the guest typed SOMETHING in the email field, it needs to
        // look like an email. If they left it blank, that's fine.
        $email = trim($this->guestEmail);
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        // Strip common phone separators so "+234 803 000 0000" counts as 13
        // digits, not 17 characters that fail a strict count.
        $phoneDigits = preg_replace('/[^0-9]/', '', $this->guestPhone) ?? '';
        return strlen($phoneDigits) >= 8;
    }

    /**
     * Convenience for the CTA copy - "Pay ₦X" reads better than
     * "Reserve ₦X" when it's a full charge; "Pay ₦X deposit" makes
     * the split-payment intent explicit. Falls through to a
     * "fill your details" prompt when contact info is incomplete so
     * a disabled button still tells the guest what's missing.
     */
    public function payButtonLabel(): string
    {
        $bd = $this->breakdown();
        if ($bd === null) return 'Pick your dates';
        if (! $this->isContactComplete()) return 'Fill your details to continue';
        $amount = '₦'.number_format($bd['due_now']);
        return $this->paymentPreference === 'deposit'
            ? 'Pay '.$amount.' deposit'
            : 'Pay '.$amount;
    }

    public function totalPreview(): int
    {
        return (int) ($this->breakdown()['total'] ?? 0);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{category: \App\Models\RoomCategory, room_count: int, first_room_id: int}>
     */
    public function categoryOptions(): Collection
    {
        if ($this->bundle === null) return collect();

        return $this->bundle['rooms']
            ->filter(fn (Room $r) => $r->category !== null)
            ->groupBy(fn (Room $r) => $r->category->id)
            ->map(fn ($group) => [
                'category'      => $group->first()->category,
                'room_count'    => $group->count(),
                'first_room_id' => (int) $group->first()->id,
            ])
            ->sortBy(fn (array $row) => (int) $row['category']->base_rate)
            ->values();
    }

    public function selectedCategory(): ?\App\Models\RoomCategory
    {
        return $this->selectedRoom()?->category;
    }

    public function expandRoomPicker(): void
    {
        $this->expandedRoomPicker = true;
    }

    public function collapseRoomPicker(): void
    {
        $this->expandedRoomPicker = false;
    }

    /**
     * Swap the selected room to the first Active one in the given
     * category. Availability is re-checked at pay() time by the
     * underlying CreateBooking action, so a race between this pick
     * and the click on "Pay" is caught cleanly with a friendly
     * "someone booked those dates" error.
     */
    public function selectCategory(int $categoryId): void
    {
        $bundle = $this->bundle ?? $this->loadBundle();
        if ($bundle === null) return;
        $firstRoom = $bundle['rooms']
            ->filter(fn (Room $r) => (int) $r->room_category_id === $categoryId)
            ->first();
        if ($firstRoom !== null) {
            $this->room = (int) $firstRoom->id;
            $this->category = $categoryId;
        }
        $this->expandedRoomPicker = false;
    }

    public function pay(): mixed
    {
        $this->submitting = true;
        $this->errorMessage = null;

        $data = $this->validate() + [
            'checkin' => ['required', 'date', 'after_or_equal:today'],
            'checkout' => ['required', 'date', 'after:checkin'],
            'room' => ['required', 'integer'],
        ];

        // Livewire's #[Validate] doesn't handle cross-field date
        // rules, so re-check here before the action call.
        try {
            $ci = Carbon::parse($this->checkin)->startOfDay();
            $co = Carbon::parse($this->checkout)->startOfDay();
        } catch (\Throwable) {
            $this->submitting = false;
            $this->addError('checkin', 'Enter valid check-in and check-out dates.');
            return null;
        }
        if ($co->lessThanOrEqualTo($ci)) {
            $this->submitting = false;
            $this->addError('checkout', 'Check-out must be after check-in.');
            return null;
        }
        if ($ci->lessThan(Carbon::today())) {
            $this->submitting = false;
            $this->addError('checkin', 'Check-in cannot be in the past.');
            return null;
        }

        try {
            /** @var CreateStaysBooking $service */
            $service = app(CreateStaysBooking::class);
            $result = $service->handle(
                [
                    'property_slug'      => $this->slug,
                    // Whole cart via room_ids; single-room case
                    // falls back to $this->room inside the service.
                    'room_ids'           => $this->cartRoomIds(),
                    'guest_name'         => $this->guestName,
                    'guest_email'        => $this->guestEmail,
                    'guest_phone'        => $this->guestPhone,
                    'adults'             => $this->adults,
                    'children'           => $this->children,
                    'checkin'            => $this->checkin,
                    'checkout'           => $this->checkout,
                    'special_requests'   => $this->specialRequests !== '' ? $this->specialRequests : null,
                    'payment_preference' => $this->paymentPreference,
                    'discount_code'      => $this->appliedDiscountCode,
                ],
                callbackUrl: route('marketplace.stays.pay.callback'),
                marketplaceRequest: request(),
            );
        } catch (BookingValidationException $e) {
            $this->submitting = false;
            $this->errorMessage = $e->getMessage();
            return null;
        } catch (RangeNotAvailableException) {
            $this->submitting = false;
            $this->errorMessage = 'Someone booked those dates just before you. Please pick different dates.';
            return null;
        } catch (RuntimeException $e) {
            $this->submitting = false;
            $this->errorMessage = $e->getMessage();
            return null;
        }

        // Full-page redirect to Paystack's hosted card form.
        return $this->redirect($result['authorization_url'], navigate: false);
    }

    #[Layout('layouts.marketplace')]
    public function render(): View
    {
        $bundle = $this->bundle ?? $this->loadBundle();
        $this->bundle = $bundle;

        return view('livewire.marketplace.stays-book-checkout', [
            'property' => $bundle['property'] ?? null,
        ]);
    }
}
