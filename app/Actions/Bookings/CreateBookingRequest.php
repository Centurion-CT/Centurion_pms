<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingSource;
use App\Enums\ManualDiscountType;
use App\Enums\PaymentType;
use Illuminate\Support\Carbon;

/**
 * Validated input bundle for {@see CreateBooking}. Keeping it as a typed
 * value object rather than a loose array makes call-sites self-documenting
 * and gives PHPStan something to chew on.
 */
final readonly class CreateBookingRequest
{
    /**
     * @param  list<array{package_id: int, quantity: int}>  $selectedPackages
     */
    public function __construct(
        public string $guestName,
        public string $guestEmail,
        public string $guestPhone,
        public int $adults,
        public int $children,
        public Carbon $checkin,
        public Carbon $checkout,
        public PaymentType $paymentType,        // Full or Deposit
        public ?string $specialRequests = null,
        public BookingSource $source = BookingSource::Online,
        // Free-text tag paired with $source — surfaces on reports so the
        // operator sees their own vocabulary ("Facebook Marketplace",
        // "Booking.com account #2") alongside the canonical enum label.
        public ?string $sourceNote = null,
        // Optional coupon code typed by the operator / guest. When set,
        // CreateBooking runs DiscountEngine::resolveByCode and applies
        // the result. NULL with `autoApplyDiscounts=true` lets the
        // engine pick the best codeless rule.
        public ?string $discountCode = null,
        public bool $autoApplyDiscounts = true,
        // List of packages the guest picked on the booking form.
        // Each entry: ['package_id' => int, 'quantity' => int].
        // Quantities of 0 are dropped before persistence.
        public array $selectedPackages = [],
        // Guest opted for the "with complimentary breakfast" tier.
        // Ignored when the room's category doesn't have the tier set
        // (falls back to base_rate). Persisted on booking.breakfast_included.
        public bool $breakfastIncluded = false,
        // Which shape the operator entered the manual discount in.
        // Defaults to Percent so legacy call-sites and old callers
        // continue to work unchanged.
        public ManualDiscountType $manualDiscountType = ManualDiscountType::Percent,
        // Only read when manualDiscountType === Percent. 0-100, fractional
        // allowed (e.g. 7.5). Applies AFTER any auto-apply Discount so the
        // operator cut is a top-off on the already-priced total.
        public float $manualDiscountPercent = 0.0,
        // Only read when manualDiscountType === Fixed. ₦ off, taken
        // verbatim and capped at the post-auto-discount total.
        public int $manualDiscountAmount = 0,
        // Operator id captured for the audit trail — "who authorised
        // the ₦X off?". Null when unknown (e.g. system-created).
        public ?int $manualDiscountByOperatorId = null,
        // Client-generated UUID for reservations queued offline. When
        // present, CreateBooking treats it as an idempotency key: a
        // second call with the same UUID returns the existing booking
        // instead of creating a duplicate. Null for online bookings.
        public ?string $offlineKey = null,
        // "Corporate — invoice later" flag. When true, the corporate
        // attachment applies the negotiated rate + stamps corp columns
        // but does NOT place a wallet hold — the corp will settle by
        // invoice after the stay rather than draw down a prefunded
        // wallet. balance_due stays at total_amount for the invoice
        // reconciliation.
        public bool $corporateInvoiceLater = false,
        // Marketplace request context — when non-null the PricingCalculator
        // consults RateResolver per night and applies any matching
        // RateRule (device / referrer / time-of-day gated). Threaded
        // in only by the /stays online checkout flow so front-desk +
        // OTA bookings continue to price against base_rate.
        public ?\Illuminate\Http\Request $marketplaceRequest = null,
    ) {}
}
