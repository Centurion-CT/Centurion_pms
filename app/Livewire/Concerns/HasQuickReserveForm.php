<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\CreateCategoryReservation;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\PayBookingFromWallet;
use App\Actions\Bookings\RecordManualPayment;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Discounts\DiscountEngine;
use App\Services\Pricing\PricingBreakdown;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Shared quick-reserve action-sheet logic. Any Livewire component
 * hosting the reserve modal uses this trait, state, lifecycle
 * hooks, phone→customer lookup, wallet-payment integration, and
 * the submit action all live here so the Reservations page and the
 * Bookings page stay in lock-step without duplicated wiring.
 *
 * Consumer responsibilities:
 *   - Include `livewire.dashboard.reservations._modals.reserve-sheet`
 *     in the view.
 *   - Pass `$availableRooms` to the view from render(), helper
 *     `availableRoomsForReserve($rooms)` does the filter.
 *   - Optionally override `quickReserveRedirectRoute()` to change
 *     the post-submit destination (default: tenant.bookings.show).
 *
 * @phpstan-require-extends \Livewire\Component
 */
trait HasQuickReserveForm
{
    /**
     * Server-side "should the sheet be open?" flag. Alpine mirrors it
     * for instant open/close; server needs this only so day-detail's
     * inline reserve mode can bind against a known state, and so the
     * calendar drag can trigger a load-and-open flow from the wire
     * side.
     */
    public bool $reserveSheetOpen = false;

    public string $reserveGuestName = '';

    public string $reservePhone = '';

    public ?int $reserveRoomId = null;

    /**
     * Category-only mode: hold a slot against a room category
     * without pinning a specific room. Front desk assigns a real
     * room at check-in via AssignRoomToBooking. Same shape as
     * ManualCreate's toggle; skips discount / breakfast / deposit /
     * wallet plumbing (all assume a concrete Room).
     */
    public bool $reserveCategoryOnly = false;

    public ?int $reserveCategoryId = null;

    public string $reserveCheckin = '';

    public string $reserveCheckout = '';

    public int $reserveAdults = 1;

    public string $reservePaymentStatus = 'confirmed';

    /**
     * How the guest paid, cash / transfer / card / pos / other.
     * Persisted on the Payment row + surfaces on Finance reports,
     * so it matters for reconciliation even though the total is
     * the same. Only meaningful when reservePaymentStatus is
     * 'confirmed' or 'on_hold' (pending has nothing to record;
     * wallet has its own implicit method).
     */
    public string $reservePaymentMethod = 'cash';

    /**
     * Amount (₦) the operator says the guest actually handed over.
     * Only meaningful when reservePaymentStatus is 'on_hold' -
     * "Paid in full" implicitly means `total`, and pending / wallet
     * paths derive their own amount. On the deposit path this can be:
     *   - Between the room policy's minimum and the total → OnHold
     *     with balance_due = total - amount.
     *   - Equal to the total → Confirmed, no balance owed.
     *   - Above the total → Confirmed, excess auto-credits the guest's
     *     wallet via RecordManualPayment's overpayment handling.
     */
    public ?int $reservePaymentAmount = null;

    /**
     * Minimum deposit (₦) allowed for the picked room + dates, per
     * the room category's `deposit_type` / `deposit_value` policy
     * (see {@see \App\Enums\DepositType}). 0 when the picked room's
     * category has DepositType::None or when no room is picked yet
     *, validation falls back to a floor of 1 in that case.
     */
    public int $reserveMinDeposit = 0;

    /** Set when phone lookup finds an existing Customer. */
    public ?int $reserveMatchedCustomerId = null;

    /** Live wallet balance of the matched customer (kobo). 0 if none. */
    public int $reserveWalletBalance = 0;

    /** Set when phone lookup finds an Active corp member. Powers the
     *  green "Corp staff matched" banner + primes the booking to be
     *  linked to the corp so the corp-guaranteed check-in path lights
     *  up on the reservation page. */
    public ?int $reserveMatchedCorpMemberId = null;

    public ?int $reserveMatchedCorpId = null;

    public string $reserveMatchedCorpName = '';

    /** Set when a live CorporateReservation covers the current
     *  (corp + member + property + dates) combination. Powers the
     *  "Use as corporate reservation" toggle + populates the allocated
     *  category summary the banner shows. */
    public ?int $reserveMatchedCorpReservationId = null;

    public string $reserveMatchedCorpReservationWindow = '';

    /** @var array<int, array{name: string, rooms: int}> */
    public array $reserveMatchedCorpReservationCategories = [];

    /** Operator's opt-in to treat this booking as filling a slot on
     *  the matched CorporateReservation. When true, we stamp
     *  assigned_booking_id + assigned_at on the reservation-member row
     *  on submit. Defaults ON when a match is detected. */
    public bool $reserveUseCorporateReservation = false;

    /** True when the operator's picked check-in / check-out dates
     *  actually overlap the matched CorporateReservation window. The
     *  blade toggles between "Falls on the ... reservation" (covered)
     *  and "Expected on the ... reservation. Set your dates to that
     *  window to fill a slot." (discovery mode). */
    public bool $reserveCorpReservationIsCovered = false;

    /** Estimated cost of the current pick, for the wallet-affordability check. */
    public int $reserveEstimatedTotal = 0;

    /**
     * How the operator entered the ad-hoc discount. 'percent' → the
     * slider / % input drives it; 'fixed' → operator types a flat
     * ₦ amount directly. Persisted on the booking so reports can
     * distinguish "gave a 10% off" from "knocked ₦5,000 off" even
     * though both denormalise to the same ₦ amount.
     */
    public string $reserveManualDiscountType = 'percent';

    /**
     * Percent input, 0-100, fractional allowed (e.g. 7.5). Only read
     * when reserveManualDiscountType === 'percent'.
     */
    public float $reserveManualDiscountPercent = 0.0;

    /**
     * Flat ₦ off input. Only read when reserveManualDiscountType ===
     * 'fixed'. Capped at the post-auto-discount total on the server.
     */
    public int $reserveManualDiscountFixedInput = 0;

    /** ₦ resolved from whichever mode is active. Live-computed. */
    public int $reserveManualDiscountAmount = 0;

    // ── Post-create "Check in now?" prompt state ────────────────────
    // Populated when the operator saves a reservation whose checkin
    // date is today AND whose status can transition to CheckedIn.
    // Stays on the current page (instead of redirecting straight to
    // the booking show) so the modal has a surface to render on.
    public ?string $postCreateBookingRef = null;

    public string $postCreateGuestName = '';

    public bool $postCreateShowCheckInPrompt = false;

    /**
     * Guest opted into the "with complimentary breakfast" tier for
     * the picked room's category. Only meaningful when the category
     * has breakfast_included_rate set, reset() and updatedReserveRoomId()
     * snap it back to false when the picked room can't offer it.
     */
    public bool $reserveBreakfastIncluded = false;

    public function openReserveSheet(?string $checkin = null, ?string $checkout = null): void
    {
        $this->resetReserveState($checkin, $checkout);
        $this->reserveSheetOpen = true;
        $this->dispatch('quick-reserve-modal-open');
    }

    /**
     * Seed / reset the reserve form fields WITHOUT flipping the
     * standalone sheet's open state. Used by day-detail's inline
     * reserve mode so we don't stack two modals.
     */
    public function resetReserveState(?string $checkin = null, ?string $checkout = null): void
    {
        $this->reserveGuestName = '';
        $this->reservePhone = '';
        $this->reserveRoomId = null;
        $this->reserveCategoryOnly = false;
        $this->reserveCategoryId = null;
        $this->reserveCheckin = $checkin ?? Carbon::today()->toDateString();
        $this->reserveCheckout = $checkout ?? Carbon::today()->addDay()->toDateString();
        $this->reserveAdults = 1;
        $this->reservePaymentStatus = 'confirmed';
        $this->reservePaymentMethod = 'cash';
        $this->reservePaymentAmount = null;
        $this->reserveMinDeposit = 0;
        $this->reserveMatchedCustomerId = null;
        $this->reserveWalletBalance = 0;
        $this->reserveEstimatedTotal = 0;
        $this->reserveBreakfastIncluded = false;
        $this->reserveManualDiscountType = 'percent';
        $this->reserveManualDiscountPercent = 0.0;
        $this->reserveManualDiscountFixedInput = 0;
        $this->reserveManualDiscountAmount = 0;
        $this->resetValidation();
    }

    /**
     * Flipping the category-only toggle clears whichever branch the
     * operator was on so a stale room or category pick doesn't ride
     * into the wrong submit path. Payment settlement stays intact -
     * the guest can pay a deposit or the full stay at reservation
     * time even though the specific room is picked at check-in.
     */
    public function updatedReserveCategoryOnly(): void
    {
        if ($this->reserveCategoryOnly) {
            $this->reserveRoomId = null;
            $this->reserveBreakfastIncluded = false;
            $this->reserveManualDiscountPercent = 0.0;
            $this->reserveManualDiscountFixedInput = 0;
            $this->reserveManualDiscountAmount = 0;
        } else {
            $this->reserveCategoryId = null;
        }
        $this->refreshReserveEstimate();
    }

    public function updatedReserveCategoryId(): void
    {
        $this->refreshReserveEstimate();
    }

    /**
     * @return \Illuminate\Support\Collection<int, RoomCategory>
     */
    public function categoriesForReserve(): \Illuminate\Support\Collection
    {
        $allowed = $this->reserveAllowedCategoryIds();

        return RoomCategory::query()
            ->with('property')
            ->when(
                $allowed !== [],
                // When the operator is filling a corp reservation slot,
                // constrain the category picker to just the allocated
                // categories — a mistake booking against an off-list
                // category can't consume the reserved allocation.
                fn ($q) => $q->whereIn('id', $allowed),
            )
            ->orderBy('name')
            ->get();
    }

    /**
     * Free-units count for the picked category across the reserve
     * date range. Drives the "N units free for those dates" hint the
     * operator sees before submitting.
     */
    public function categoryFreeUnitsForReserve(): ?int
    {
        if (! $this->reserveCategoryOnly
            || $this->reserveCategoryId === null
            || $this->reserveCheckin === ''
            || $this->reserveCheckout === '') {
            return null;
        }

        try {
            $category = RoomCategory::query()->find($this->reserveCategoryId);
            if ($category === null) {
                return null;
            }
            $ci = Carbon::parse($this->reserveCheckin);
            $co = Carbon::parse($this->reserveCheckout);
            if ($co->lessThanOrEqualTo($ci)) {
                return null;
            }

            return app(AvailabilityService::class)->categoryFreeUnits($category, $ci, $co);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Live-clamp the slider input and reprice so the "New total" line
     * in the sheet is always truthful.
     */
    public function updatedReserveManualDiscountPercent(): void
    {
        $this->reserveManualDiscountPercent = max(0.0, min(100.0, (float) $this->reserveManualDiscountPercent));
        $this->refreshReserveEstimate();
    }

    public function updatedReserveManualDiscountFixedInput(): void
    {
        $this->reserveManualDiscountFixedInput = max(0, (int) $this->reserveManualDiscountFixedInput);
        $this->refreshReserveEstimate();
    }

    public function updatedReserveManualDiscountType(): void
    {
        // Switching mode should not carry the OTHER mode's value into
        // pricing — a stale ₦-amount left over after flipping back to
        // percent would silently double up.
        if ($this->reserveManualDiscountType === 'percent') {
            $this->reserveManualDiscountFixedInput = 0;
        } else {
            $this->reserveManualDiscountPercent = 0.0;
        }
        $this->refreshReserveEstimate();
    }

    public function closeReserveSheet(): void
    {
        $this->reserveSheetOpen = false;
        $this->resetValidation();
        $this->dispatch('quick-reserve-modal-close');
    }

    /**
     * Livewire lifecycle hook, fires whenever the phone input changes.
     * Auto-lookup once the number has at least 7 digits.
     */
    public function updatedReservePhone(string $value): void
    {
        $this->lookupCustomerByPhone();
    }

    public function lookupCustomerByPhone(): void
    {
        $this->reserveMatchedCustomerId = null;
        $this->reserveWalletBalance = 0;
        $this->reserveMatchedCorpMemberId = null;
        $this->reserveMatchedCorpId = null;
        $this->reserveMatchedCorpName = '';

        $normalized = preg_replace('/\D+/', '', (string) $this->reservePhone) ?? '';
        if (strlen($normalized) < 7) {
            return;
        }

        // Match by suffix so `+234…` in DB still matches a local `080…`
        // typed by the operator.
        $customer = Customer::query()
            ->where(function ($q): void {
                $normalized = preg_replace('/\D+/', '', (string) $this->reservePhone) ?? '';
                $q->where('phone', $this->reservePhone)
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?", ['%'.$normalized]);
            })
            ->with('wallet')
            ->first();

        if ($customer !== null) {
            $this->reserveMatchedCustomerId = $customer->id;
            $this->reserveWalletBalance = (int) $customer->walletBalance();
            if ($this->reserveGuestName === '') {
                $this->reserveGuestName = $customer->name;
            }
        }

        // Also check the corporate member roster. A phone might belong
        // to both a standalone Customer AND a profiled corp staffer
        // (someone who stayed as a walk-in before their employer
        // registered them here) — the corp match is what drives the
        // guarantor path, so we surface both signals independently.
        $corp = app(\App\Services\Corporates\CorporateResolver::class)
            ->resolveByPhone($this->reservePhone);
        if ($corp !== null) {
            $this->reserveMatchedCorpMemberId = (int) $corp['member']->id;
            $this->reserveMatchedCorpId = (int) $corp['corporate']->id;
            $this->reserveMatchedCorpName = (string) $corp['corporate']->name;
            // Auto-fill the guest name when the operator hasn't typed
            // one yet AND we didn't already pull it from a Customer
            // row above — the corp roster is authoritative on their
            // staff identity.
            if ($this->reserveGuestName === '') {
                $this->reserveGuestName = (string) $corp['member']->name;
            }
            // Default to the corporate-invoice payment path when the
            // operator hasn't touched the payment status yet. Most
            // corp bookings settle by invoice after the stay, not by
            // cash at the desk. If the operator explicitly picked a
            // different mode already (they typed the phone late in
            // the sheet flow) we respect that.
            if ($this->reservePaymentStatus === 'confirmed') {
                $this->reservePaymentStatus = 'corp_invoice';
            }
        } elseif ($this->reservePaymentStatus === 'corp_invoice') {
            // Corp match evaporated (operator retyped a different
            // phone) — snap back to the default so a stale corp_invoice
            // doesn't post against a non-corp guest.
            $this->reservePaymentStatus = 'confirmed';
        }

        $this->detectMatchingCorporateReservation();
    }

    /**
     * Look for a live CorporateReservation that covers the current
     * (corp + member + property + dates) combination and stash a
     * summary for the banner. Also runs whenever dates change so the
     * match tracks the operator's edits.
     *
     * When dates aren't set yet (a fresh sheet before the operator
     * types the window), we fall back to any UPCOMING reservation for
     * the same corp + member + property. The banner reveals it early
     * so the operator knows which dates to type in. The toggle is
     * only turned on automatically when the dates actually cover the
     * reservation window — otherwise we show it without the toggle,
     * as a discovery hint.
     */
    protected function detectMatchingCorporateReservation(): void
    {
        $this->reserveMatchedCorpReservationId = null;
        $this->reserveMatchedCorpReservationWindow = '';
        $this->reserveMatchedCorpReservationCategories = [];
        $this->reserveUseCorporateReservation = false;
        $this->reserveCorpReservationIsCovered = false;

        if ($this->reserveMatchedCorpId === null || $this->reserveMatchedCorpMemberId === null) {
            return;
        }

        // The trait is used by pages that all live under a single
        // Property (branch), so we resolve the property via the
        // room / category the operator has already picked. When
        // neither is picked yet, fall back to the currently-scoped
        // Property in PropertyContext.
        $propertyId = null;
        if ($this->reserveRoomId) {
            $propertyId = (int) (Room::query()->where('id', $this->reserveRoomId)->value('property_id') ?? 0);
        }
        if (! $propertyId && $this->reserveCategoryId) {
            $propertyId = (int) (RoomCategory::query()->where('id', $this->reserveCategoryId)->value('property_id') ?? 0);
        }
        if (! $propertyId) {
            $propertyId = (int) (app(\App\Services\Properties\PropertyContext::class)->current()?->id ?? 0);
        }
        if (! $propertyId) {
            return;
        }

        $match = null;
        $datesSet = $this->reserveCheckin !== '' && $this->reserveCheckout !== '';

        if ($datesSet) {
            try {
                $checkin = Carbon::parse($this->reserveCheckin)->startOfDay();
                $checkout = Carbon::parse($this->reserveCheckout)->startOfDay();

                $match = \App\Models\CorporateReservation::activeForMemberInWindow(
                    corporateId: $this->reserveMatchedCorpId,
                    memberId: $this->reserveMatchedCorpMemberId,
                    propertyId: $propertyId,
                    checkin: $checkin,
                    checkout: $checkout,
                );
            } catch (Throwable) {
                // Fall through to the upcoming lookup so the operator
                // still sees the hint if their date input was bad.
            }
        }

        // No overlap match (either dates aren't set or the picked dates
        // don't cover any reservation window). Fall back to any
        // upcoming reservation for this corp + member so the operator
        // gets an "expected on Sep 12→14" nudge.
        $isOverlapMatch = $match !== null;
        if ($match === null) {
            $match = \App\Models\CorporateReservation::upcomingForMember(
                corporateId: $this->reserveMatchedCorpId,
                memberId: $this->reserveMatchedCorpMemberId,
                propertyId: $propertyId,
            );
        }
        if ($match === null) {
            return;
        }

        $this->reserveMatchedCorpReservationId = (int) $match->id;
        $this->reserveMatchedCorpReservationWindow = sprintf(
            '%s → %s',
            $match->starts_at?->toFormattedDateString() ?? '?',
            $match->ends_at?->toFormattedDateString() ?? '?',
        );
        $this->reserveMatchedCorpReservationCategories = $match->categories
            ->map(fn ($c) => [
                'id'    => (int) $c->room_category_id,
                'name'  => (string) ($c->roomCategory?->name ?? 'Category #'.$c->room_category_id),
                'rooms' => (int) $c->rooms_count,
            ])
            ->all();

        // Auto-fill dates from the reservation window when the operator
        // hasn't picked their own yet. Respects any dates they've typed
        // — we never overwrite intent, only fill blanks.
        if ($this->reserveCheckin === '' && $match->starts_at !== null) {
            $this->reserveCheckin = $match->starts_at->toDateString();
        }
        if ($this->reserveCheckout === '' && $match->ends_at !== null) {
            $this->reserveCheckout = $match->ends_at->toDateString();
        }
        // Auto-filling dates may flip us from "discovery" to "covered"
        // — recompute the flag against the freshly-filled dates.
        try {
            $checkinNow = Carbon::parse($this->reserveCheckin)->startOfDay();
            $checkoutNow = Carbon::parse($this->reserveCheckout)->startOfDay();
            $matchStart = $match->starts_at?->copy()->startOfDay();
            $matchEnd = $match->ends_at?->copy()->startOfDay();
            $overlapsNow = $matchStart !== null && $matchEnd !== null
                && $matchStart->lessThanOrEqualTo($checkoutNow)
                && $matchEnd->greaterThanOrEqualTo($checkinNow);
            $isOverlapMatch = $isOverlapMatch || $overlapsNow;
        } catch (Throwable) {
            // Leave $isOverlapMatch as-is.
        }

        // Only auto-enable the toggle when the operator's picked dates
        // actually cover the reservation window — otherwise the hint
        // is discovery-only and we don't want a stale "on" state
        // consuming a slot when the operator hasn't set dates yet.
        $this->reserveUseCorporateReservation = $isOverlapMatch;
        $this->reserveCorpReservationIsCovered = $isOverlapMatch;

        // If we auto-filled dates, kick the estimate + downstream
        // computations so the sheet reflects the new stay length.
        if ($isOverlapMatch && method_exists($this, 'refreshReserveEstimate')) {
            $this->refreshReserveEstimate();
        }
    }

    /**
     * Category IDs the operator can pick from when the toggle "Use as
     * corporate reservation" is active. Empty when no corp reservation
     * is in play — blades interpret that as "no filter, show all
     * categories/rooms as before".
     *
     * @return array<int, int>
     */
    public function reserveAllowedCategoryIds(): array
    {
        if (! $this->reserveUseCorporateReservation
            || $this->reserveMatchedCorpReservationId === null) {
            return [];
        }
        return array_values(array_map(
            fn ($c) => (int) ($c['id'] ?? 0),
            $this->reserveMatchedCorpReservationCategories,
        ));
    }

    public function updatedReserveRoomId(): void
    {
        // Category can differ from the previous pick, snap the
        // breakfast opt-in off when the newly picked room doesn't
        // offer the tier, else the flag would silently stick.
        $room = $this->reserveRoomId ? Room::query()->with('category')->find($this->reserveRoomId) : null;
        if ($room === null || ! $room->category?->hasBreakfastOption()) {
            $this->reserveBreakfastIncluded = false;
        }
        $this->refreshReserveEstimate();
    }

    public function updatedReserveCheckin(): void
    {
        // Auto-fill checkout to checkin + 1 night when it's empty
        // or currently sits at/before the new checkin. Operator can
        // still adjust the field afterwards, this only ever writes
        // when the current value would produce an invalid range.
        if ($this->reserveCheckin !== '') {
            try {
                $checkin = Carbon::parse($this->reserveCheckin);
                $needsAutoFill = $this->reserveCheckout === '';
                if (! $needsAutoFill) {
                    try {
                        $needsAutoFill = Carbon::parse($this->reserveCheckout)->lessThanOrEqualTo($checkin);
                    } catch (Throwable) {
                        $needsAutoFill = true;
                    }
                }
                if ($needsAutoFill) {
                    $this->reserveCheckout = $checkin->copy()->addDay()->toDateString();
                }
            } catch (Throwable) {
                // Invalid date typed in, leave both fields alone and
                // let the pricing preview / validator surface it.
            }
        }

        $this->refreshReserveEstimate();
        $this->detectMatchingCorporateReservation();
    }

    public function updatedReserveCheckout(): void
    {
        $this->refreshReserveEstimate();
        $this->detectMatchingCorporateReservation();
    }

    public function updatedReserveBreakfastIncluded(): void
    {
        $this->refreshReserveEstimate();
    }

    /**
     * Clear the manual amount when the operator flips away from the
     * deposit path, "Paid in full" / "Pending" / "Wallet" all derive
     * their own amount, so any leftover value would be misleading.
     */
    public function updatedReservePaymentStatus(string $value): void
    {
        if ($value !== 'on_hold') {
            $this->reservePaymentAmount = null;
        }
    }

    protected function refreshReserveEstimate(): void
    {
        // Kept for backward compat with anything still reading
        // $reserveEstimatedTotal directly (e.g. wallet-covers hint).
        // The rich itemised preview lives on reservePricingPreview()
        // and mirrors what CreateBooking will actually charge.
        $this->reserveEstimatedTotal = 0;
        $this->reserveMinDeposit = 0;
        $this->reserveManualDiscountAmount = 0;

        // Category-only path has no room + no PricingCalculator run.
        // Estimated total = nights × category.base_rate; no minimum
        // deposit (there's no per-room policy to enforce until the
        // front desk pins a room at check-in).
        if ($this->reserveCategoryOnly
            && $this->reserveCategoryId !== null
            && $this->reserveCheckin !== ''
            && $this->reserveCheckout !== '') {
            try {
                $category = RoomCategory::query()->find($this->reserveCategoryId);
                $ci = Carbon::parse($this->reserveCheckin);
                $co = Carbon::parse($this->reserveCheckout);
                if ($category !== null && $co->greaterThan($ci)) {
                    $nights = (int) $ci->diffInDays($co, absolute: true);
                    $this->reserveEstimatedTotal = $nights * (int) $category->base_rate;
                }
            } catch (Throwable) {
                // Leave estimate at 0; the confirm-and-pay form on
                // reservation-show can still take payment later.
            }

            return;
        }

        $preview = $this->reservePricingPreview();
        if ($preview !== null) {
            /** @var PricingBreakdown $breakdown */
            $breakdown = $preview['breakdown'];
            $discountOff = (int) $preview['discountOff'];
            $corporateDiscountOff = (int) ($preview['corporateDiscountOff'] ?? 0);

            // When a corp rate is in play the blade hides the manual
            // discount section — reset the state here so any prior
            // value the operator entered can't quietly post at submit.
            // Runs on every refresh so dating out of a corp window
            // re-enables manual naturally.
            if ($corporateDiscountOff > 0) {
                $this->reserveManualDiscountPercent = 0.0;
                $this->reserveManualDiscountFixedInput = 0;
            }

            // Manual discount base = netSubtotal - autoDiscount — the
            // corp discount is applied AFTER manual server-side
            // (CreateBooking sets total then attachToBooking subtracts).
            // Match that order here so the preview never lies.
            $preManualTotal = max(0, $breakdown->netSubtotal - $discountOff);
            $this->reserveManualDiscountAmount = $this->reserveManualDiscountType === 'fixed'
                ? min((int) $this->reserveManualDiscountFixedInput, $preManualTotal)
                : (int) floor($preManualTotal * $this->reserveManualDiscountPercent / 100);
            $this->reserveEstimatedTotal = max(0, $preManualTotal - $this->reserveManualDiscountAmount - $corporateDiscountOff);
            // Min deposit == the room policy's computed deposit for
            // this stay. Zero means the category's DepositType is None
            // (no policy), validation floors that to 1.
            $this->reserveMinDeposit = max(0, min(
                (int) $breakdown->depositAmount,
                $this->reserveEstimatedTotal,
            ));
        }
    }

    /**
     * Full itemised price preview for the reserve modal. Returns
     * null when the selection isn't complete. Otherwise returns
     * everything the blade partial needs to render the same math
     * CreateBooking::handle() will land on submit, including the
     * auto-apply Discount so the operator sees the real net, not a
     * base-rate-times-nights approximation.
     *
     * `preManualTotal` is the post-auto-discount total the manual
     * discount is applied against. Exposed as a bare int so the
     * blade can seed the Alpine `x-data` that computes the discount
     * client-side — the operator sees the slider preview update
     * instantly instead of waiting on a round-trip per keystroke.
     *
     * @return array{
     *   breakdown: PricingBreakdown,
     *   discountOff: int,
     *   discountLabel: ?string,
     *   discountAutoApplied: bool,
     *   preManualTotal: int
     * }|null
     */
    public function reservePricingPreview(): ?array
    {
        if ($this->reserveRoomId === null || $this->reserveCheckin === '' || $this->reserveCheckout === '') {
            return null;
        }

        try {
            $room = Room::query()->with('category')->find($this->reserveRoomId);
            if ($room === null) {
                return null;
            }
            $checkin = Carbon::parse($this->reserveCheckin);
            $checkout = Carbon::parse($this->reserveCheckout);
            if ($checkout->lessThanOrEqualTo($checkin)) {
                return null;
            }

            $breakdown = app(PricingCalculator::class)->calculate(
                $room, $checkin, $checkout, 0, $this->reserveBreakfastIncluded,
            );

            // Reserve modal is always walk-in, CreateBooking is called
            // downstream with BookingSource::WalkIn, so mirror that
            // channel when scanning auto-apply rules.
            $resolution = app(DiscountEngine::class)->bestAutoApply(
                room: $room,
                checkin: $checkin,
                checkout: $checkout,
                channel: BookingSource::WalkIn,
                netSubtotal: $breakdown->netSubtotal,
            );

            $discountOff = $resolution !== null && $resolution->applies ? $resolution->amountOff : 0;

            // Corporate rate + LoS discount. Applies whenever the phone
            // matched an Active corp member (whether or not the operator
            // opted into "Use as corporate reservation") because
            // CorporateBookingService::attachToBooking will apply it at
            // create time. Previewing it here keeps the operator's total
            // aligned with what actually posts to the folio.
            $nights = (int) $breakdown->nights;
            $corporateDiscountOff = 0;
            $corporateDiscountLabel = null;
            if ($this->reserveMatchedCorpId !== null && $nights > 0) {
                $corp = \App\Models\Corporate::query()->find($this->reserveMatchedCorpId);
                if ($corp !== null) {
                    $rate = app(\App\Services\Corporates\CorporatePricingResolver::class)
                        ->forRoom($corp, $room, $nights);
                    if ($rate !== null) {
                        $corporateDiscountOff = max(0, (int) $rate['off_per_unit'] * $nights);
                        $corpRate = $rate['rate'];
                        $corporateDiscountLabel = $this->corporateRateLabel($corpRate, $corp->name);
                    }
                }
            }

            return [
                'breakdown' => $breakdown,
                'discountOff' => $discountOff,
                'discountLabel' => $resolution?->discount?->name,
                'discountAutoApplied' => (bool) ($resolution?->autoApplied ?? false),
                'corporateDiscountOff' => $corporateDiscountOff,
                'corporateDiscountLabel' => $corporateDiscountLabel,
                // Manual discount base — matches server ordering:
                // CreateBooking applies manual BEFORE attachToBooking
                // subtracts the corp discount, so the manual % is
                // computed on (netSubtotal - autoDiscount) here too.
                'preManualTotal' => max(0, $breakdown->netSubtotal - $discountOff),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Short human-friendly label for a corporate rate row that the
     * preview breakdown shows in the discount line.
     */
    private function corporateRateLabel(\App\Models\CorporateRate $rate, string $corpName): string
    {
        $type = $rate->rate_type;
        if ($type === \App\Enums\CorporateRateType::PercentOff) {
            $descriptor = $rate->rate_value.'% off';
        } elseif ($type === \App\Enums\CorporateRateType::FixedOff) {
            $descriptor = '₦'.number_format($rate->rate_value).' off';
        } else {
            $descriptor = 'flat ₦'.number_format($rate->rate_value);
        }
        $tail = '';
        if ($rate->min_nights !== null && $rate->min_nights > 1) {
            $tail = ' (long-stay '.$rate->min_nights.'n+)';
        }
        return $corpName.' corp rate · '.$descriptor.$tail;
    }

    /**
     * Filter a room collection down to those with no Booked/Blocked
     * availability across the picked date range. Falls back to the
     * full list if either date is missing or invalid.
     *
     * @param  Collection<int, Room>  $rooms
     * @return Collection<int, Room>
     */
    public function availableRoomsForReserve(Collection $rooms): Collection
    {
        // When the operator is filling a corp reservation slot, drop
        // rooms whose category isn't in the reservation's allocation.
        // Applies BEFORE the availability filter so the returned list
        // reflects the "reserved category constraint" even when dates
        // aren't set yet.
        $allowedCats = $this->reserveAllowedCategoryIds();
        if ($allowedCats !== []) {
            $rooms = $rooms->filter(
                fn (Room $r) => in_array((int) $r->room_category_id, $allowedCats, true),
            )->values();
        }

        if ($this->reserveCheckin === '' || $this->reserveCheckout === '') {
            return $rooms;
        }

        try {
            $checkin = Carbon::parse($this->reserveCheckin);
            $checkout = Carbon::parse($this->reserveCheckout);
        } catch (Throwable) {
            return $rooms;
        }

        if ($checkout->lessThanOrEqualTo($checkin)) {
            return $rooms;
        }

        $unavailableIds = AvailabilityBlock::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->whereDate('date', '>=', $checkin->toDateString())
            ->whereDate('date', '<', $checkout->toDateString())
            ->distinct()
            ->pluck('room_id');

        return $rooms->reject(fn (Room $r) => $unavailableIds->contains($r->id))->values();
    }

    /**
     * Post-submit redirect route. Consumers override to point at a
     * component-specific detail page; default sends the operator to
     * the canonical booking show.
     */
    protected function quickReserveRedirectRoute(): string
    {
        return 'tenant.bookings.show';
    }

    public function submitReserve(
        CreateBooking $create,
        RecordManualPayment $recordPayment,
        PayBookingFromWallet $walletPay,
        CreateCategoryReservation $createCategory,
    ): void {
        // Category-only path - hold inventory against a room category
        // without pinning a specific room; front desk assigns at
        // check-in. Skips discount / breakfast / deposit / wallet
        // (all assume a concrete Room), lands as Pending + Pending.
        if ($this->reserveCategoryOnly) {
            $this->submitReserveCategoryOnly($createCategory);

            return;
        }

        $rules = [
            'reserveGuestName'     => ['required', 'string', 'max:120'],
            'reservePhone'         => ['required', 'string', 'max:20'],
            'reserveRoomId'        => ['required', 'integer', 'exists:rooms,id'],
            'reserveCheckin'       => ['required', 'date', 'after_or_equal:today'],
            'reserveCheckout'      => ['required', 'date', 'after:reserveCheckin'],
            'reserveAdults'        => ['required', 'integer', 'min:1'],
            'reservePaymentStatus' => ['required', 'in:confirmed,on_hold,pending,wallet,corp_invoice'],
        ];
        if ($this->reservePaymentStatus === 'on_hold') {
            $rules['reservePaymentAmount'] = ['required', 'integer', 'min:1'];
        }
        if (in_array($this->reservePaymentStatus, ['confirmed', 'on_hold'], true)) {
            $rules['reservePaymentMethod'] = ['required', 'in:cash,transfer,card,pos,other'];
        }
        // Corp-invoice path is only valid when the phone actually
        // matched an Active corp member. Otherwise the operator picked
        // a mode that has no corp to bill — refuse to silently fall
        // back to Pending, force them to pick a real path.
        if ($this->reservePaymentStatus === 'corp_invoice'
            && $this->reserveMatchedCorpId === null) {
            $this->addError(
                'reservePaymentStatus',
                'Corporate invoice needs a matched corp — enter a phone that\'s on a corp\'s roster.',
            );
            return;
        }
        $this->validate($rules, [
            'reserveCheckin.after_or_equal' => 'Reservations can\'t be made for past dates.',
            'reserveCheckout.after' => 'Check-out must be after check-in.',
            'reservePaymentAmount.required' => 'Enter how much the guest handed over.',
            'reservePaymentAmount.min' => 'Amount must be at least ₦1.',
        ]);

        // Deposit floor from the room category's policy, enforced so
        // an operator can't accept a walk-in reservation with a token
        // ₦100 deposit when the category requires 30%. No ceiling:
        // over-total deposits are allowed and their surplus flows to
        // the guest's wallet automatically.
        if ($this->reservePaymentStatus === 'on_hold' && $this->reserveMinDeposit > 0) {
            if ((int) $this->reservePaymentAmount < $this->reserveMinDeposit) {
                $this->addError(
                    'reservePaymentAmount',
                    'Deposit must be at least ₦'.number_format($this->reserveMinDeposit).' for this room category.',
                );

                return;
            }
        }

        if ($this->reservePaymentStatus === 'wallet') {
            if ($this->reserveMatchedCustomerId === null) {
                $this->addError('reservePaymentStatus', 'Wallet payment needs a matched customer, enter a known phone number first.');

                return;
            }
            if ($this->reserveWalletBalance <= 0) {
                $this->addError('reservePaymentStatus', 'That customer has no wallet balance to spend.');

                return;
            }
        }

        $room = Room::query()->findOrFail($this->reserveRoomId);

        $req = new CreateBookingRequest(
            guestName:  $this->reserveGuestName,
            guestEmail: '',
            guestPhone: $this->reservePhone,
            adults:     $this->reserveAdults,
            children:   0,
            checkin:    Carbon::parse($this->reserveCheckin),
            checkout:   Carbon::parse($this->reserveCheckout),
            paymentType: $this->reservePaymentStatus === 'on_hold'
                ? PaymentType::Deposit
                : PaymentType::Full,
            source:     BookingSource::WalkIn,
            // Let CreateBooking scan for a matching auto-apply
            // discount inside its own transaction, same behaviour
            // as ManualCreate / PropertyView / Home. The quick-
            // reserve sheet doesn't carry a manual-code field yet,
            // but discounts that don't require a code should still
            // attach when the room + dates + channel match.
            autoApplyDiscounts: true,
            breakfastIncluded: $this->reserveBreakfastIncluded,
            manualDiscountType: $this->reserveManualDiscountType === 'fixed'
                ? \App\Enums\ManualDiscountType::Fixed
                : \App\Enums\ManualDiscountType::Percent,
            manualDiscountPercent: (float) $this->reserveManualDiscountPercent,
            manualDiscountAmount: (int) $this->reserveManualDiscountFixedInput,
            manualDiscountByOperatorId: auth()->guard('web')->id(),
            corporateInvoiceLater: $this->reservePaymentStatus === 'corp_invoice',
        );

        try {
            $booking = $create->handle($room, $req);
        } catch (BookingValidationException $e) {
            $this->addError('reserveGeneral', $e->getMessage());

            return;
        } catch (RangeNotAvailableException) {
            $this->addError('reserveGeneral', 'Those dates are not available for this room.');

            return;
        } catch (Throwable $e) {
            $this->addError('reserveGeneral', $e->getMessage());

            return;
        }

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        // Corp-reservation slot: stamp assigned_booking_id + assigned_at
        // on the matching CorporateReservationMember row so the front
        // desk (and reporting) knows this booking is filling one of
        // the reserved slots. Guarded by the operator's opt-in toggle
        // even though we default it to on when a match exists — a
        // stale detection from earlier in the sheet shouldn't silently
        // consume a slot the operator didn't intend.
        if (
            $this->reserveUseCorporateReservation
            && $this->reserveMatchedCorpReservationId !== null
            && $this->reserveMatchedCorpMemberId !== null
        ) {
            $stamped = \App\Models\CorporateReservationMember::query()
                ->where('corporate_reservation_id', $this->reserveMatchedCorpReservationId)
                ->where('corporate_member_id', $this->reserveMatchedCorpMemberId)
                ->whereNull('assigned_booking_id')
                ->update([
                    'assigned_booking_id' => $booking->id,
                    'assigned_at'         => Carbon::now(),
                ]);

            // First slot filled → the reservation is no longer just a
            // set-up plan; someone has actually arrived (or is booked
            // to arrive). Flip Pending → Active so the manager sees
            // the reservation reflecting reality. Later slot fills
            // land on an Active row and are a no-op here.
            if ($stamped > 0) {
                \App\Models\CorporateReservation::query()
                    ->where('id', $this->reserveMatchedCorpReservationId)
                    ->where('status', \App\Enums\CorporateReservationStatus::Pending)
                    ->update([
                        'status' => \App\Enums\CorporateReservationStatus::Active,
                    ]);
            }
        }

        // Wallet path, CreateBooking with paymentType=Full sets
        // balance_due=0 (assumes settled). Reset it so PayBookingFromWallet
        // actually sees an outstanding to clear.
        if ($this->reservePaymentStatus === 'wallet') {
            $booking->forceFill([
                'balance_due'    => (int) $booking->total_amount,
                'payment_status' => PaymentStatus::Pending,
            ])->save();

            try {
                $walletPay->handle($booking->refresh(), $operator);
            } catch (Throwable $e) {
                $this->addError('reserveGeneral', 'Wallet charge failed: '.$e->getMessage());

                return;
            }
            $booking->refresh();
            $fullyPaid = $booking->outstandingTotal() <= 0;
            $booking->forceFill([
                'booking_status' => $fullyPaid ? BookingStatus::Confirmed : BookingStatus::OnHold,
            ])->save();

            $this->closeReserveSheet();
            $msg = $fullyPaid
                ? "Reserved {$booking->booking_ref}, paid from wallet."
                : "Reserved {$booking->booking_ref}, partial wallet payment; balance still due.";
            session()->flash('status', $msg);
            $this->finaliseReserveOutcome($booking);

            return;
        }

        // Deposit path: derive booking/payment status from what was
        // actually collected. Auto-upgrade to Confirmed when the
        // deposit covers or exceeds the total, the reservation is
        // paid for, and RecordManualPayment routes any surplus to
        // the guest's wallet.
        if ($this->reservePaymentStatus === 'on_hold') {
            $total = (int) $booking->total_amount;
            $collected = (int) $this->reservePaymentAmount;
            $covers = $collected >= $total;
            $depositBooked = min($collected, $total);

            $booking->forceFill([
                'deposit_amount' => $depositBooked,
                'balance_due'    => max(0, $total - $depositBooked),
                'booking_status' => $covers ? BookingStatus::Confirmed : BookingStatus::OnHold,
                'payment_status' => PaymentStatus::Succeeded,
            ])->save();
        } else {
            [$bookingStatus, $paymentStatus] = match ($this->reservePaymentStatus) {
                'confirmed'    => [BookingStatus::Confirmed, PaymentStatus::Succeeded],
                // Corp invoice: the org guarantees payment, so the
                // stay is Confirmed but payment is Pending (settles
                // via the invoice cycle after the stay). balance_due
                // stays at total_amount for the reconciliation.
                'corp_invoice' => [BookingStatus::Confirmed, PaymentStatus::Pending],
                default        => [BookingStatus::Pending,   PaymentStatus::Pending],
            };
            $booking->forceFill([
                'booking_status' => $bookingStatus,
                'payment_status' => $paymentStatus,
                // Corp invoice path never collects at the desk, so
                // the outstanding always equals the total.
                'balance_due' => $this->reservePaymentStatus === 'corp_invoice'
                    ? (int) $booking->total_amount
                    : $booking->balance_due,
            ])->save();
        }

        if (! in_array($this->reservePaymentStatus, ['pending', 'corp_invoice'], true)) {
            // "Paid in full" → collect exactly the total. Deposit path
            // → collect the operator-supplied amount; RecordManualPayment
            // caps the Payment row at booking.total_amount and routes
            // any surplus to the guest's wallet as change.
            $amount = $this->reservePaymentStatus === 'on_hold'
                ? (int) $this->reservePaymentAmount
                : (int) $booking->total_amount;
            $recordPayment->handle(
                $booking->refresh(),
                $operator,
                amount: $amount,
                method: $this->reservePaymentMethod,
            );
        }

        $this->closeReserveSheet();
        session()->flash('status', "Reserved {$booking->booking_ref} for {$booking->guest_name}.");
        $this->finaliseReserveOutcome($booking);
    }

    /**
     * Post-create branch point: if the reservation is for today and
     * the booking can transition to CheckedIn (Confirmed / OnHold),
     * show the "Check in now?" modal instead of redirecting. Every
     * other case skips straight to the reservation-show page.
     */
    private function finaliseReserveOutcome(Booking $booking): void
    {
        if ($this->shouldPromptCheckIn($booking)) {
            $this->postCreateBookingRef = $booking->booking_ref;
            $this->postCreateGuestName = $booking->guest_name;
            $this->postCreateShowCheckInPrompt = true;
            $this->dispatch('checkin-prompt-open');

            return;
        }

        $this->redirectRoute(
            $this->quickReserveRedirectRoute(),
            ['bookingRef' => $booking->booking_ref],
            navigate: true,
        );
    }

    private function shouldPromptCheckIn(Booking $booking): bool
    {
        if (! $booking->checkin_date->isSameDay(Carbon::today())) {
            return false;
        }

        return in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold], true);
    }

    /**
     * Modal "Yes, check them in" action. Runs MarkCheckedIn, then
     * navigates to the booking-show. Ignored silently when there's
     * no pending post-create booking so a stray double-click is
     * a no-op.
     */
    public function confirmPostCreateCheckIn(MarkCheckedIn $action): void
    {
        if ($this->postCreateBookingRef === null) {
            return;
        }

        $booking = Booking::query()->where('booking_ref', $this->postCreateBookingRef)->first();
        if ($booking === null) {
            $this->skipPostCreateCheckIn();

            return;
        }

        $op = auth('web')->user();
        if ($op instanceof Operator) {
            try {
                $action->handle($booking, $op);
                session()->flash('status', "Checked in {$booking->guest_name}.");
            } catch (Throwable $e) {
                session()->flash('status', 'Check-in failed: '.$e->getMessage());
            }
        }

        $this->redirectAfterPostCreatePrompt($booking->booking_ref);
    }

    /**
     * Modal "No, later" action. Just redirects to the booking-show
     * without touching the booking status.
     */
    public function skipPostCreateCheckIn(): void
    {
        $ref = $this->postCreateBookingRef;
        if ($ref === null) {
            return;
        }
        $this->redirectAfterPostCreatePrompt($ref);
    }

    private function redirectAfterPostCreatePrompt(string $bookingRef): void
    {
        $this->postCreateBookingRef = null;
        $this->postCreateGuestName = '';
        $this->postCreateShowCheckInPrompt = false;

        $this->redirectRoute(
            $this->quickReserveRedirectRoute(),
            ['bookingRef' => $bookingRef],
            navigate: true,
        );
    }

    /**
     * Category-only submit path. Holds one slot in the picked
     * category; skips discount + breakfast plumbing (those assume a
     * concrete Room). Payment IS supported here: the guest can pay
     * a deposit ("on_hold"), the full stay ("confirmed"), or defer
     * ("pending"). Wallet path is skipped for MVP since matched
     * customer + wallet balance UI already suppresses on this path.
     */
    private function submitReserveCategoryOnly(CreateCategoryReservation $create): void
    {
        $rules = [
            'reserveGuestName'     => ['required', 'string', 'max:120'],
            'reservePhone'         => ['required', 'string', 'max:20'],
            'reserveCategoryId'    => ['required', 'integer', 'exists:room_categories,id'],
            'reserveCheckin'       => ['required', 'date', 'after_or_equal:today'],
            'reserveCheckout'      => ['required', 'date', 'after:reserveCheckin'],
            'reserveAdults'        => ['required', 'integer', 'min:1'],
            'reservePaymentStatus' => ['required', 'in:confirmed,on_hold,pending'],
        ];
        if ($this->reservePaymentStatus === 'on_hold') {
            $rules['reservePaymentAmount'] = ['required', 'integer', 'min:1'];
        }
        if (in_array($this->reservePaymentStatus, ['confirmed', 'on_hold'], true)) {
            $rules['reservePaymentMethod'] = ['required', 'in:cash,transfer,card,pos,other'];
        }
        $this->validate($rules);

        $category = RoomCategory::query()->find($this->reserveCategoryId);
        if ($category === null) {
            $this->addError('reserveCategoryId', 'Category not found.');

            return;
        }

        try {
            $booking = $create->handle(
                category: $category,
                checkin: Carbon::parse($this->reserveCheckin),
                checkout: Carbon::parse($this->reserveCheckout),
                guestName: $this->reserveGuestName,
                guestEmail: '',
                guestPhone: $this->reservePhone,
                adults: $this->reserveAdults,
                children: 0,
                source: BookingSource::WalkIn,
            );
        } catch (BookingValidationException $e) {
            $this->addError('reserveCategoryId', $e->getMessage());

            return;
        } catch (RangeNotAvailableException $e) {
            $this->addError('reserveCheckin', $e->getMessage());

            return;
        }

        // Apply the payment settlement now that the booking exists.
        // CreateCategoryReservation lands it Pending + Pending +
        // balance_due=0 so callers don't have to reason about a mid-
        // creation payment state; we adjust here.
        $this->applyCategoryOnlyPayment($booking);

        $this->closeReserveSheet();
        session()->flash('status', "Reserved {$booking->booking_ref} against {$category->name} - unassigned.");

        // No check-in prompt on this path - MarkCheckedIn refuses
        // an unassigned booking, front desk has to pick a room from
        // reservation-show first.
        $this->redirectRoute(
            $this->quickReserveRedirectRoute(),
            ['bookingRef' => $booking->booking_ref],
            navigate: true,
        );
    }

    /**
     * Post-create payment settlement for the category-only path.
     * Mirrors the specific-room submit's tail-end without the
     * wallet + discount + surplus logic (all skipped for MVP -
     * they can be added on the reservation-show page later).
     */
    private function applyCategoryOnlyPayment(Booking $booking): void
    {
        $total = (int) $booking->total_amount;
        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        if ($this->reservePaymentStatus === 'pending' || $total <= 0) {
            return;   // Pending + Pending + balance_due=0 stands.
        }

        if ($this->reservePaymentStatus === 'on_hold') {
            $collected = (int) $this->reservePaymentAmount;
            $covers = $collected >= $total;
            $depositBooked = min($collected, $total);

            $booking->forceFill([
                'deposit_amount' => $depositBooked,
                'balance_due'    => max(0, $total - $depositBooked),
                'booking_status' => $covers ? BookingStatus::Confirmed : BookingStatus::OnHold,
                'payment_status' => PaymentStatus::Succeeded,
            ])->save();

            app(RecordManualPayment::class)->handle(
                $booking->refresh(),
                $operator,
                amount: $collected,
                method: $this->reservePaymentMethod,
            );

            return;
        }

        // Full path: total collected, booking lands Confirmed.
        // Bump balance_due to total first so RecordManualPayment
        // knows there's actually money owed to cap the Payment
        // row against; then zero it out after the payment writes.
        $booking->forceFill([
            'balance_due'    => $total,
            'booking_status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Succeeded,
        ])->save();

        app(RecordManualPayment::class)->handle(
            $booking->refresh(),
            $operator,
            amount: $total,
            method: $this->reservePaymentMethod,
        );

        $booking->refresh()->forceFill(['balance_due' => 0])->save();
    }
}
