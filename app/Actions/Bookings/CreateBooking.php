<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Packages\AttachPackagesToNewBooking;
use App\Enums\BookingStatus;
use App\Enums\ManualDiscountType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\BookingDiscount;
use App\Models\Package;
use App\Models\Room;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Bookings\BookingReferenceGenerator;
use App\Services\Corporates\CorporateBookingService;
use App\Services\Customers\CustomerLinker;
use App\Services\Discounts\DiscountEngine;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a Booking + the corresponding AvailabilityBlock rows atomically
 * (PRD §11). Returns a Pending booking — Paystack confirmation flips it to
 * Confirmed or OnHold later via the BookingWebhookController.
 *
 * Race-condition guarantee: even if two guests race past `isAvailable()`,
 * only one of their CreateBooking transactions can succeed. The other hits
 * the (tenant_id, room_id, date) unique constraint inside reserveRangeForBooking
 * and the whole transaction rolls back — no orphan Booking row, no
 * partial AvailabilityBlocks.
 */
class CreateBooking
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingCalculator $pricing,
        private readonly BookingReferenceGenerator $refs,
        private readonly CustomerLinker $customers,
        private readonly DiscountEngine $discounts,
        private readonly CorporateBookingService $corporates,
        private readonly AttachPackagesToNewBooking $packages,
    ) {}

    /**
     * @throws BookingValidationException guest-facing input problem
     * @throws RangeNotAvailableException dates collided with another booking after the price-preview check
     */
    public function handle(Room $room, CreateBookingRequest $req): Booking
    {
        // Offline-sync idempotency short-circuit. Client-generated UUID
        // is stamped on any reservation that was queued locally; if the
        // sync worker retries after a partial failure, we return the
        // already-created row instead of duplicating.
        if ($req->offlineKey !== null && $req->offlineKey !== '') {
            $existing = Booking::query()->where('offline_key', $req->offlineKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $this->validate($room, $req);

        // Pre-compute the paid packages total so pricing + fees see it
        // before we enter the transaction. The AttachPackagesToNewBooking
        // action below re-loads the catalog inside the transaction to
        // catch any race where the operator deactivates a package mid-flow.
        $packagesTotal = $this->previewPackagesTotal($room->tenant_id, $req->selectedPackages);

        $breakdown = $this->pricing->calculate(
            $room, $req->checkin, $req->checkout, $packagesTotal, $req->breakfastIncluded,
            $req->marketplaceRequest,
        );
        $isHold = $req->paymentType === PaymentType::Deposit;

        // Resolve discount BEFORE the transaction so a bad code surfaces
        // as a validation error without rolling anything back. The
        // engine's recordUsage() call later runs inside the transaction
        // so the cap-check is race-safe.
        $resolution = null;
        if ($req->discountCode !== null && trim($req->discountCode) !== '') {
            $resolution = $this->discounts->resolveByCode(
                code: $req->discountCode,
                room: $room,
                checkin: $req->checkin,
                checkout: $req->checkout,
                channel: $req->source,
                netSubtotal: $breakdown->netSubtotal,
            );
            if ($resolution === null || ! $resolution->applies) {
                throw new BookingValidationException(
                    $resolution?->reason ?? 'Discount code not found.',
                );
            }
        } elseif ($req->autoApplyDiscounts) {
            $resolution = $this->discounts->bestAutoApply(
                room: $room,
                checkin: $req->checkin,
                checkout: $req->checkout,
                channel: $req->source,
                netSubtotal: $breakdown->netSubtotal,
            );
        }

        $discountOff = ($resolution !== null && $resolution->applies) ? $resolution->amountOff : 0;
        // `room_total` is the room-only operator payout (excluding
        // packages); `total_amount` is what the guest pays. Packages
        // are already inside `netSubtotal` / `totalDue` via the
        // calculator, so subtracting the discount and reusing them is
        // safe.
        $finalRoomTotal = max(0, $breakdown->netSubtotal - $discountOff - $packagesTotal);

        // Paystack + Keverna platform fees only apply when the booking
        // actually settles through the guest-facing engine (Online).
        // For walk-ins / OTAs / phone / etc. the guest pays the operator
        // directly, so tacking those fees onto total_amount over-bills
        // them ("this room is ₦50k but the total shows ₦51,100"). We
        // zero the fees at persistence time and rebuild total_amount
        // from netSubtotal - discount so the operator sees the real
        // out-of-pocket owed.
        $chargesFees   = $req->source->chargesPlatformFees();
        $paystackFee   = $chargesFees ? $breakdown->paystackFee : 0;
        $kevernaFee    = $chargesFees ? $breakdown->kevernaFee : 0;
        $finalTotalDue = $chargesFees
            ? max(0, $breakdown->totalDue - $discountOff)
            : max(0, $breakdown->netSubtotal - $discountOff);

        // Manual front-desk discount — stacks on top of any auto-apply
        // Discount. Two entry shapes, chosen by the operator:
        //   Percent → 0-100 (fractional), resolves to floor(total × pct/100)
        //   Fixed   → verbatim ₦ off, capped at the current total
        // Both denormalise to `manual_discount_amount` so downstream
        // reports never have to branch on the type field.
        $manualDiscountType = $req->manualDiscountType;
        if ($manualDiscountType === ManualDiscountType::Percent) {
            $manualDiscountPercent = max(0.0, min(100.0, (float) $req->manualDiscountPercent));
            $manualDiscountAmount = (int) floor($finalTotalDue * $manualDiscountPercent / 100);
        } else {
            $manualDiscountAmount = max(0, min((int) $req->manualDiscountAmount, $finalTotalDue));
            // Persist a derived percent so reports (avg discount %,
            // "how deep are our cuts?") don't have to branch on type.
            $manualDiscountPercent = $finalTotalDue > 0
                ? round($manualDiscountAmount * 100 / $finalTotalDue, 2)
                : 0.0;
        }
        if ($manualDiscountAmount > 0) {
            $finalTotalDue = max(0, $finalTotalDue - $manualDiscountAmount);
            $finalRoomTotal = max(0, $finalRoomTotal - $manualDiscountAmount);
        }
        $manualDiscountByOperatorId = $manualDiscountAmount > 0 ? $req->manualDiscountByOperatorId : null;

        return DB::transaction(function () use ($room, $req, $breakdown, $isHold, $resolution, $discountOff, $finalRoomTotal, $finalTotalDue, $packagesTotal, $paystackFee, $kevernaFee, $manualDiscountType, $manualDiscountPercent, $manualDiscountAmount, $manualDiscountByOperatorId): Booking {
            // Race-safe cap check — recordUsage() does atomic UPDATE …
            // WHERE used_count < max_uses and returns false if another
            // booking just took the last slot. We bail with a friendly
            // error and the whole transaction rolls back.
            if ($resolution !== null && $resolution->applies && $resolution->discount !== null) {
                if (! $this->discounts->recordUsage($resolution->discount)) {
                    throw new BookingValidationException(
                        'That discount just hit its usage cap. Try again without it.',
                    );
                }
            }

            $booking = Booking::query()->create([
                'tenant_id' => $room->tenant_id,
                'room_id' => $room->id,
                'booking_ref' => $this->refs->generate(),
                'offline_key' => $req->offlineKey,
                'guest_name' => $req->guestName,
                'guest_email' => $req->guestEmail,
                'guest_phone' => $req->guestPhone,
                'adults' => $req->adults,
                'children' => $req->children,
                'special_requests' => $req->specialRequests,
                'checkin_date' => $req->checkin->toDateString(),
                'checkout_date' => $req->checkout->toDateString(),
                'nights' => $breakdown->nights,
                'room_total' => $finalRoomTotal,
                'breakfast_included' => $req->breakfastIncluded
                    && $room->category?->hasBreakfastOption(),
                'packages_total' => $packagesTotal,
                'paystack_fee' => $paystackFee,
                'keverna_fee' => $kevernaFee,
                'total_amount' => $finalTotalDue,
                'manual_discount_type' => $manualDiscountType,
                'manual_discount_percent' => $manualDiscountPercent,
                'manual_discount_amount' => $manualDiscountAmount,
                'manual_discount_by_operator_id' => $manualDiscountByOperatorId,
                'deposit_amount' => $isHold ? $breakdown->depositAmount : 0,
                'balance_due' => $isHold ? ($finalTotalDue - $breakdown->depositAmount) : 0,
                // PRD §6.4.3: balance due on check-in. Future tier could let
                // operators move this earlier via a room-level offset.
                'balance_due_at' => $isHold ? $req->checkin->copy()->startOfDay() : null,
                'booking_status' => BookingStatus::Pending,
                'payment_status' => PaymentStatus::Pending,
                'source' => $req->source,
                'source_note' => $req->sourceNote,
            ]);

            // Persist the discount application for audit / display.
            if ($resolution !== null && $resolution->applies && $resolution->discount !== null && $discountOff > 0) {
                BookingDiscount::query()->create([
                    'tenant_id' => $room->tenant_id,
                    'booking_id' => $booking->id,
                    'discount_id' => $resolution->discount->id,
                    'amount_off' => $discountOff,
                    'code_used' => $resolution->code,
                    'auto_applied' => $resolution->autoApplied,
                ]);
            }

            // Persist package selections — throws BookingValidationException
            // if the catalog changed between preview and commit (operator
            // deactivated a package mid-flow). Rolls back the whole booking.
            $this->packages->handle($booking, $req->selectedPackages);

            // Reserve nights — throws RangeNotAvailableException on race; the
            // whole transaction rolls back so the Booking row goes too.
            $this->availability->reserveRangeForBooking(
                $room, $req->checkin, $req->checkout, $booking->id,
            );

            // Corporate attachment — resolves the guest's email to a
            // Corporate, applies any negotiated rate, enforces member
            // daily/monthly caps, and places the wallet hold. Rolls
            // back the whole booking transaction on cap-exceeded or
            // insufficient wallet. Walk-in bookings (no resolved
            // corporate / property opted out) skip this silently.
            try {
                $this->corporates->attachToBooking(
                    $booking->refresh(),
                    deferWalletHold: $req->corporateInvoiceLater,
                );
            } catch (RuntimeException $e) {
                throw new BookingValidationException($e->getMessage());
            }

            // Attach to (or create) the CRM Customer record. Runs inside
            // the same transaction so a race rollback also rolls back the
            // customer creation + timeline row.
            $this->customers->attachToBooking($booking->fresh() ?? $booking);

            return $booking->fresh() ?? $booking;
        });
    }

    /**
     * Sums the effective unit price × quantity for every active package
     * in the selection. Mirrors AttachPackagesToNewBooking's validation
     * so a guest can't sneak a paid package through with a price of 0
     * by submitting a stale catalog id.
     *
     * @param  list<array{package_id: int, quantity: int}>  $selections
     */
    private function previewPackagesTotal(int $tenantId, array $selections): int
    {
        $cleaned = [];
        foreach ($selections as $row) {
            $id = (int) ($row['package_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);
            if ($id <= 0 || $qty <= 0) {
                continue;
            }
            $cleaned[$id] = ($cleaned[$id] ?? 0) + $qty;
        }
        if ($cleaned === []) {
            return 0;
        }

        $catalog = Package::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->whereIn('id', array_keys($cleaned))
            ->get()
            ->keyBy('id');

        $total = 0;
        foreach ($cleaned as $packageId => $qty) {
            $package = $catalog->get($packageId);
            if ($package === null) {
                throw new BookingValidationException('One of the selected add-ons is no longer available.');
            }
            $total += $package->effectivePrice() * $qty;
        }

        return $total;
    }

    private function validate(Room $room, CreateBookingRequest $req): void
    {
        if ($room->status !== RoomStatus::Active) {
            throw new BookingValidationException('Room is not available for booking.');
        }

        if ($req->checkin->lessThan(Carbon::today())) {
            throw new BookingValidationException('Check-in cannot be in the past.');
        }
        if ($req->checkout->lessThanOrEqualTo($req->checkin)) {
            throw new BookingValidationException('Check-out must be after check-in.');
        }

        $nights = (int) $req->checkin->diffInDays($req->checkout, absolute: true);
        if ($nights < $room->min_nights) {
            throw new BookingValidationException(
                "Minimum stay is {$room->min_nights} night(s) for this room.",
            );
        }

        if ($req->adults < 1) {
            throw new BookingValidationException('At least one adult is required.');
        }
        if ($req->adults > $room->capacity_adults) {
            throw new BookingValidationException(
                "This room sleeps up to {$room->capacity_adults} adult(s).",
            );
        }
        if ($req->children > $room->capacity_children) {
            throw new BookingValidationException(
                "This room sleeps up to {$room->capacity_children} child(ren).",
            );
        }
    }
}
