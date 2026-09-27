<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Bookings\ApplyPostCheckinDiscount;
use App\Actions\Bookings\ExtendBooking;
use App\Actions\Bookings\PayBookingFromWallet;
use App\Actions\Bookings\RecordManualPayment;
use App\Enums\BookingChargeType;
use App\Enums\ManualDiscountType;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Customers\CustomerLinker;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Shared "Extend stay" modal used by the operator dashboard home
 * and the reservation-show page. Composes three existing actions
 * (ExtendBooking + ApplyPostCheckinDiscount + RecordManualPayment)
 * so nothing new lives in the money math.
 *
 * The trait deliberately keeps state *minimal* - just enough for
 * the server to run submitExtend(). Every fast-moving input on the
 * modal (discount %, payment amount, mode toggle) lives in Alpine
 * on the client. That's why openExtendModal() snapshots the current
 * total / balance / per-night rate / wallet balance onto the
 * component; the blade seeds Alpine's x-data from those snapshots
 * so the pricing preview + payment quick-picks respond instantly
 * without a wire trip per keystroke.
 */
trait HasExtendStayModal
{
    // ── Modal state (only what the server needs) ────────────────────
    public bool $extendOpen = false;

    public ?int $extendBookingId = null;

    /** Current values snapshotted at open() so Alpine can seed. */
    public string $extendCurrentCheckout = '';

    public int $extendCurrentTotal = 0;

    public int $extendCurrentBalance = 0;

    public int $extendRatePerNight = 0;

    public int $extendWalletBalance = 0;

    public string $extendGuestName = '';

    /** Base + breakfast tiers for the modal to price against. Only
     *  non-zero when the room's category has a breakfast option. */
    public int $extendBaseRate = 0;

    public int $extendBreakfastRate = 0;

    public bool $extendHasBreakfastOption = false;

    /** The original booking's breakfast opt-in - the modal seeds its
     *  toggle from this so extensions inherit the tier by default. */
    public bool $extendBookingBreakfast = false;

    /** Percent discount the guest already had on the original booking.
     *  Non-zero only when the booking has a manual_discount_type of
     *  percent — fixed-amount discounts don't scale to extra nights, so
     *  they are NOT inherited. Alpine reads this to price the extension
     *  at the guest's effective per-night rate. */
    public float $extendInheritedDiscountPercent = 0.0;

    /** Latest checkout the guest can extend to before running into
     *  the next reservation on the same room. Empty when there is no
     *  next reservation - the modal treats it as "no ceiling". */
    public string $extendMaxCheckout = '';

    /** Optional pre-selected new-checkout ISO the drag interaction on
     *  the calendar can pass in ("drag guest 3 days right"). Empty
     *  when the modal was opened by a plain click - the Alpine hydrate
     *  step falls back to the day after currentCheckout as before. */
    public string $extendPreselectCheckout = '';

    public function openExtendModal(int $bookingId, ?string $preselectCheckout = null): void
    {
        $booking = Booking::query()
            ->with(['room.category', 'customer.wallet'])
            ->find($bookingId);
        if ($booking === null) {
            return;
        }
        $this->extendBookingId = $bookingId;
        $this->extendCurrentCheckout = $booking->checkout_date->toDateString();
        $this->extendCurrentTotal = (int) $booking->total_amount;
        // Show the guest's REAL outstanding, not just booking.balance_due.
        // outstandingTotal() sums the booking balance + unsettled
        // BookingCharges (post-checkin extras, room-service, overstay
        // auto-charges pending checkout) + still-uncharged overstay
        // nights - which is the number the sidebar's "OUTSTANDING" tile
        // shows too. Without this, the "Full" quick-pay preset offered
        // only the base booking balance and left the operator to type
        // the real figure by hand.
        $this->extendCurrentBalance = $booking->outstandingTotal();

        // Snapshot both tiers so Alpine can flip the preview instantly
        // between base + breakfast without a wire trip. When the
        // category doesn't offer breakfast, breakfast_included_rate is
        // 0 and the toggle stays hidden.
        $category = $booking->room?->category;
        $this->extendBaseRate = (int) ($category?->base_rate ?? $booking->room?->base_rate ?? 0);
        $this->extendBreakfastRate = (int) ($category?->breakfast_included_rate ?? 0);
        $this->extendHasBreakfastOption = $category !== null && $category->hasBreakfastOption();
        $this->extendBookingBreakfast = (bool) $booking->breakfast_included;

        // Effective per-night rate the guest paid on this booking
        // (committed tier at booking time). Alpine multiplies this
        // by "extra nights" to preview the extension cost - swaps
        // to the other tier the moment the operator flips the toggle.
        $this->extendRatePerNight = $this->extendBookingBreakfast && $this->extendBreakfastRate > 0
            ? $this->extendBreakfastRate
            : $this->extendBaseRate;
        // Carry the guest's original percent discount forward so the
        // Alpine preview shows the discounted rate (and ExtendBooking
        // charges the same). Fixed-amount discounts stay at 0 — they
        // don't scale to added nights.
        $this->extendInheritedDiscountPercent = $booking->manual_discount_type === \App\Enums\ManualDiscountType::Percent
            ? (float) $booking->manual_discount_percent
            : 0.0;

        $this->extendWalletBalance = (int) ($booking->customer?->walletBalance() ?? 0);
        $this->extendGuestName = (string) $booking->guest_name;
        // Ceiling for the extend datepicker - the day the next
        // reservation on this room checks in. Empty when nothing is
        // booked forward-of-checkout.
        $maxCheckout = $booking->maxExtendableCheckoutDate();
        $this->extendMaxCheckout = $maxCheckout?->toDateString() ?? '';

        // Preselected checkout from the calendar drag. Clamp to the
        // valid range so a stray drag past the next reservation can't
        // seed an impossible date. Empty falls through to the "day
        // after current" default the modal already applies.
        $preselect = trim((string) $preselectCheckout);
        if ($preselect !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $preselect) === 1) {
            $minAllowed = $booking->checkout_date->copy()->addDay()->toDateString();
            if ($preselect >= $minAllowed
                && ($this->extendMaxCheckout === '' || $preselect <= $this->extendMaxCheckout)) {
                $this->extendPreselectCheckout = $preselect;
            } else {
                $this->extendPreselectCheckout = '';
            }
        } else {
            $this->extendPreselectCheckout = '';
        }

        $this->extendOpen = true;
        $this->dispatch('extend-modal-open');
    }

    public function closeExtendModal(): void
    {
        $this->extendOpen = false;
        $this->extendBookingId = null;
    }

    /**
     * Submit accepts explicit args from the Alpine modal - no reliance
     * on @entangle for the fast-moving values. One round-trip per
     * extension attempt, period.
     */
    public function submitExtend(
        string $newCheckout,
        string $discountMode = 'percent',
        float $discountPercent = 0.0,
        int $discountFixed = 0,
        int $paymentAmount = 0,
        string $paymentMethod = 'cash',
        string $reason = '',
        ?bool $breakfastIncluded = null,
        ExtendBooking $extend = null,
        ApplyPostCheckinDiscount $discount = null,
        RecordManualPayment $recordPayment = null,
        PayBookingFromWallet $payFromWallet = null,
        CustomerLinker $linker = null,
    ): void {
        // Livewire's method resolution injects action classes via the
        // container when called from the client. Null-defaults let us
        // keep the signature explicit while still being callable from
        // the Alpine modal as $wire.submitExtend(...).
        $extend        ??= app(ExtendBooking::class);
        $discount      ??= app(ApplyPostCheckinDiscount::class);
        $recordPayment ??= app(RecordManualPayment::class);
        $payFromWallet ??= app(PayBookingFromWallet::class);
        $linker        ??= app(CustomerLinker::class);

        if ($this->extendBookingId === null) {
            return;
        }
        if (! in_array($discountMode, ['percent', 'fixed'], true)) {
            $discountMode = 'percent';
        }
        if (! in_array($paymentMethod, ['cash', 'transfer', 'card', 'pos', 'other', 'wallet'], true)) {
            $paymentMethod = 'cash';
        }

        $op = auth('web')->user();
        if (! $op instanceof Operator) {
            $this->dispatch('notify', title: 'Not signed in', message: 'Sign in and try again.', kind: 'err');
            return;
        }

        $booking = Booking::query()->find($this->extendBookingId);
        if ($booking === null) {
            $this->closeExtendModal();
            return;
        }

        try {
            $newCheckoutDate = Carbon::parse($newCheckout);
            $booking = $extend->handle($booking, $newCheckoutDate, $op, $breakfastIncluded);
        } catch (RangeNotAvailableException) {
            $this->dispatch('notify',
                title: 'Room not available',
                message: 'Those extra nights are not available - try a shorter extension.',
                kind: 'err',
            );
            return;
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t extend', message: $e->getMessage(), kind: 'err');
            return;
        }

        // Optional discount step - runs against the fresh, post-
        // extension total, which is the operator's intent ("10% off
        // the extended stay").
        if (($discountMode === 'percent' && $discountPercent > 0)
            || ($discountMode === 'fixed' && $discountFixed > 0)) {
            $totalBeforeDiscount = (int) $booking->total_amount;
            try {
                $booking = $discount->handle(
                    booking: $booking,
                    operator: $op,
                    mode: $discountMode === 'fixed'
                        ? ManualDiscountType::Fixed
                        : ManualDiscountType::Percent,
                    percent: $discountPercent,
                    fixedAmount: $discountFixed,
                    reason: $reason !== ''
                        ? 'Extension: '.$reason
                        : 'Applied at stay extension',
                );

                // Attribute the discount to the extension charge so the
                // ledger + outstanding math + "Mark paid" all agree.
                // ApplyPostCheckinDiscount only reduces booking-level
                // total_amount + bumps manual_discount_amount - it
                // never touches the specific charge that triggered
                // the discount. Without this, the charge shows the
                // pre-discount amount (e.g., ₦100k), the outstanding
                // is inflated by the discount, and "Mark paid" bills
                // the guest for the full pre-discount value. Now the
                // extension charge amount reads the post-discount ₦
                // AND its description carries the "₦X discount
                // applied" trail so the front desk + accountant see
                // exactly what happened.
                $discountApplied = max(0, $totalBeforeDiscount - (int) $booking->total_amount);
                if ($discountApplied > 0) {
                    $extensionCharge = BookingCharge::query()
                        ->where('booking_id', $booking->id)
                        ->where('type', BookingChargeType::Extension->value)
                        ->where('settled', false)
                        ->latest('id')
                        ->first();
                    if ($extensionCharge !== null) {
                        $newAmount = max(0, (int) $extensionCharge->amount - $discountApplied);
                        $extensionCharge->forceFill([
                            'amount' => $newAmount,
                            'description' => rtrim((string) $extensionCharge->description, ' ·')
                                .sprintf(' · ₦%s discount applied', number_format($discountApplied)),
                        ])->save();
                    }
                }
            } catch (Throwable $e) {
                $this->dispatch('notify',
                    title: 'Extended, discount not applied',
                    message: $e->getMessage(),
                    kind: 'err',
                );
            }
        }

        if ($paymentAmount > 0) {
            try {
                if ($paymentMethod === 'wallet') {
                    // PayBookingFromWallet debits the wallet ledger + writes
                    // a Payment row (method=wallet). RecordManualPayment
                    // would only do the latter, so wallet has its own path.
                    $payFromWallet->handle(
                        booking: $booking->refresh(),
                        operator: $op,
                        amount: $paymentAmount,
                    );
                } else {
                    $recordPayment->handle(
                        $booking->refresh(),
                        $op,
                        amount: $paymentAmount,
                        method: $paymentMethod,
                    );
                }
                $booking = $booking->refresh();
            } catch (Throwable $e) {
                $this->dispatch('notify',
                    title: 'Extended, payment not recorded',
                    message: $e->getMessage(),
                    kind: 'err',
                );
            }
        }

        $linker->recordBookingEvent(
            booking: $booking->refresh(),
            event: 'booking.extended',
            subject: 'Stay extended to '.$booking->checkout_date->toFormattedDateString(),
            body: $reason !== '' ? $reason : null,
        );

        \App\Services\Dashboard\DashboardSnapshot::forget();

        $this->closeExtendModal();
        // Ping the Alpine modal to close its local `open` state -
        // closeExtendModal() only clears the server-side flag, but
        // the modal's visibility is driven by the Alpine `open`
        // variable which doesn't observe Livewire props directly.
        $this->dispatch('extend-modal-close');
        $this->dispatch('notify',
            title: 'Extended',
            message: 'Stay now runs to '.$booking->checkout_date->toFormattedDateString().'.',
            kind: 'ok',
        );
    }
}
