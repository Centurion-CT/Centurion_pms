<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\InitiateBookingPayment;
use App\Enums\BookingSource;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Availability\RangeNotAvailableException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Bridges the central-domain marketplace checkout into the per-tenant
 * booking pipeline. Runs in three phases inside a single call:
 *
 *   1. Cross-tenant lookup of the property + room via public_slug + id
 *      (marketplace routes have no tenant in URL context).
 *   2. Switches Tenant::current() to the property's tenant so
 *      {@see CreateBooking} + downstream (AvailabilityService, price
 *      calc, packages) see the correct scope, then hands off to it.
 *   3. Kicks off Paystack via {@see InitiateBookingPayment} with the
 *      marketplace callback URL + a distinct reference prefix so
 *      audit / support can spot marketplace-sourced charges at a
 *      glance in Paystack's dashboard.
 *
 * Always restores the previous tenant context (or clears it if there
 * wasn't one) on the way out - central-domain code paths must not
 * observe a leaked tenant just because a payment went through here.
 */
final class CreateStaysBooking
{
    public function __construct(
        private readonly CreateBooking $create,
        private readonly InitiateBookingPayment $init,
        // Category-hold path (property has stays_online_hold_mode = 'category').
        // Uses the same row-locked count-and-reserve the front-desk
        // category reservation already exercises, but priced against
        // the marketplace rate rules.
        private readonly \App\Actions\Bookings\CreateCategoryReservation $categoryCreator,
    ) {}

    /**
     * Cart-aware Paystack init - one transaction charges the
     * aggregate for a multi-room booking, stamped with the shared
     * cart_ref so the callback controller can walk sibling
     * bookings + confirm them all together.
     *
     * Bypasses InitiateBookingPayment because that action assumes
     * charge amount == primary booking's total; here it's the sum
     * across the cart. Reuses the same Paystack subaccount routing
     * logic InitiateBookingPayment uses for consistency.
     */
    private function initiateCartPayment(
        \App\Models\Booking $primary,
        int $chargeNgn,
        bool $isDeposit,
        string $cartRef,
        string $callbackUrl,
    ): string {
        $tenant = $primary->tenant;
        $subaccountCode = (string) ($tenant->paystack_subaccount_code ?? '');
        $client = $subaccountCode !== ''
            ? \App\Services\Paystack\PaystackClient::forPlatform()
            : (($secret = (string) ($tenant->paystack_secret_key ?? '')) !== ''
                ? \App\Services\Paystack\PaystackClient::forTenantKey($secret)
                : \App\Services\Paystack\PaystackClient::forPlatform());

        $reference = 'kev_stays_cart_'.$primary->id.'_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(16));

        // Single Payment row against the primary; sibling bookings
        // reach Confirmed via ConfirmBookingPayment's cart-walk.
        $payment = \App\Models\Payment::query()->create([
            'tenant_id'          => $primary->tenant_id,
            'booking_id'         => $primary->id,
            'paystack_reference' => $reference,
            'stays_cart_ref'     => $cartRef,
            'amount'             => $chargeNgn,
            'type'               => $isDeposit
                ? \App\Enums\PaymentType::Deposit
                : \App\Enums\PaymentType::Full,
            'status'             => \App\Enums\PaymentStatus::Pending,
        ]);

        try {
            $result = $client->initializeTransaction([
                'email'        => $primary->guest_email,
                'amount_ngn'   => $chargeNgn,
                'reference'    => $reference,
                'callback_url' => $callbackUrl,
                'metadata'     => [
                    'tenant_id'     => $primary->tenant_id,
                    'payment_id'    => $payment->id,
                    'primary_id'    => $primary->id,
                    'stays_cart_ref' => $cartRef,
                ],
                'subaccount'   => $subaccountCode !== '' ? $subaccountCode : null,
            ]);
        } catch (\Throwable $e) {
            $payment->forceFill(['status' => \App\Enums\PaymentStatus::Failed])->save();
            throw new RuntimeException(
                "Couldn't reach the payment provider. Please try again."
            );
        }

        return $result['authorization_url'];
    }

    /**
     * @param  array{
     *     property_slug: string,
     *     room_id?: int,
     *     room_ids?: list<int>,
     *     guest_name: string,
     *     guest_email: string,
     *     guest_phone: string,
     *     adults: int,
     *     children: int,
     *     checkin: string,
     *     checkout: string,
     *     special_requests?: string|null,
     *     payment_preference?: string,
     *     discount_code?: string|null,
     * }  $input
     * @return array{authorization_url: string, booking_ref: string, booking_refs: list<string>, cart_ref: ?string}
     *
     * @throws BookingValidationException on any guest-facing problem
     * @throws RangeNotAvailableException when a room was booked between
     *                                    the availability preview + submit
     * @throws RuntimeException           when Paystack init fails
     */
    public function handle(array $input, string $callbackUrl, ?\Illuminate\Http\Request $marketplaceRequest = null): array
    {
        // Accept EITHER room_ids (cart of >= 1) OR room_id (legacy
        // single-room signature). Normalise to a room_ids array so
        // the loop below handles both paths.
        $roomIds = [];
        if (isset($input['room_ids']) && is_array($input['room_ids'])) {
            $roomIds = array_values(array_unique(array_map('intval',
                array_filter($input['room_ids'], fn ($v) => (int) $v > 0))));
        } elseif (isset($input['room_id']) && (int) $input['room_id'] > 0) {
            $roomIds = [(int) $input['room_id']];
        }
        if ($roomIds === []) {
            throw new BookingValidationException('No room selected.');
        }

        // Cross-tenant property lookup - marketplace flows have no
        // tenant in context yet, so both Property + Room fetches must
        // bypass the global scope.
        $property = Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('public_slug', $input['property_slug'])
            ->where('tenant_id', Tenant::current()?->getKey()) // this business only (docs/MULTITENANCY.md)
            ->where('is_listed_publicly', true)
            ->with('tenant')
            ->first();
        if ($property === null) {
            throw new BookingValidationException('This property is no longer available on the marketplace.');
        }

        // Fetch all the cart's rooms in one query - preserve the
        // caller's requested order so the "primary" booking below is
        // the one the guest picked first (matters for the Reserve
        // CTA + confirmation page).
        $rooms = Room::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('id', $roomIds)
            ->where('property_id', $property->id)
            ->where('status', RoomStatus::Active->value)
            ->get()
            ->keyBy('id');
        $orderedRooms = collect($roomIds)
            ->map(fn (int $id) => $rooms->get($id))
            ->filter()
            ->values();
        if ($orderedRooms->count() !== count($roomIds)) {
            throw new BookingValidationException(
                'One or more of the rooms in your cart is no longer bookable.'
            );
        }

        // Shared cart identifier - only stamped when there's more
        // than one room, so single-room bookings look identical to
        // their pre-cart selves.
        $cartRef = $orderedRooms->count() > 1
            ? 'cart-'.strtolower(\Illuminate\Support\Str::random(16))
            : null;

        $previousTenant = Tenant::current();
        $property->tenant->makeCurrent();

        // Guest picked pay-full or pay-30%-deposit on the checkout
        // page. Both types create a real booking; only the up-front
        // Paystack charge differs.
        $preference = strtolower((string) ($input['payment_preference'] ?? 'full'));
        $isDeposit = $preference === 'deposit';

        // Email is optional on the marketplace checkout - guest can
        // skip it and still book (they always give us a phone).
        // bookings.guest_email is NOT NULL though, so when the guest
        // leaves it blank we stamp a phone-derived sentinel that
        // downstream mail listeners recognise as "no email on file,
        // don't try to send". Uses our own stays.keverna.local
        // domain so a listener that misses the sentinel still can't
        // spam a real inbox.
        $suppliedEmail = trim((string) ($input['guest_email'] ?? ''));
        if ($suppliedEmail === '') {
            $phoneDigits = preg_replace('/[^0-9]/', '', (string) ($input['guest_phone'] ?? '')) ?: 'unknown';
            $suppliedEmail = 'noreceipt-'.$phoneDigits.'@stays.keverna.local';
        }

        // Per-property preference for how /stays online bookings hold
        // inventory: 'room' = pre-picks a specific room (default);
        // 'category' = books the category and lets front desk assign
        // the room at check-in. Front-desk pathways are always 'room'.
        $categoryMode = ($property->stays_online_hold_mode ?? 'room') === 'category';
        $checkinCarbon = Carbon::parse((string) $input['checkin'])->startOfDay();
        $checkoutCarbon = Carbon::parse((string) $input['checkout'])->startOfDay();

        try {
            $bookings = collect();
            foreach ($orderedRooms as $room) {
                if ($categoryMode && $room->category !== null) {
                    // Category-level hold. Uses CreateCategoryReservation
                    // for the row-locked count-and-reserve + AvailabilityBlock
                    // rows scoped to (room_category_id, date). Marketplace
                    // fee overlay + rate resolver run against the created
                    // Booking below, same as the room-mode branch.
                    $booking = $this->categoryCreator->handle(
                        category: $room->category,
                        checkin: $checkinCarbon,
                        checkout: $checkoutCarbon,
                        guestName: (string) $input['guest_name'],
                        guestEmail: $suppliedEmail,
                        guestPhone: (string) $input['guest_phone'],
                        adults: max(1, (int) $input['adults']),
                        children: max(0, (int) $input['children']),
                        specialRequests: ! empty($input['special_requests']) ? (string) $input['special_requests'] : null,
                        source: BookingSource::Online,
                        sourceNote: 'Keverna Stays marketplace',
                        marketplaceRequest: $marketplaceRequest,
                    );
                } else {
                    $booking = $this->create->handle(
                        $room,
                        new CreateBookingRequest(
                            guestName: (string) $input['guest_name'],
                            guestEmail: $suppliedEmail,
                            guestPhone: (string) $input['guest_phone'],
                            adults: max(1, (int) $input['adults']),
                            children: max(0, (int) $input['children']),
                            checkin: $checkinCarbon,
                            checkout: $checkoutCarbon,
                            // Pass Full regardless - the deposit fraction is
                            // computed against the AGGREGATE below, not each
                            // per-room subtotal, so a per-booking Deposit
                            // paymentType here would double-book the split.
                            paymentType: PaymentType::Full,
                            specialRequests: ! empty($input['special_requests']) ? (string) $input['special_requests'] : null,
                            source: BookingSource::Online,
                            sourceNote: 'Keverna Stays marketplace',
                            discountCode: ! empty($input['discount_code']) ? (string) $input['discount_code'] : null,
                            autoApplyDiscounts: false,
                            marketplaceRequest: $marketplaceRequest,
                        ),
                    );
                }

                // Overlay marketplace fee policy on each booking so
                // per-room reports (operator dashboard's "Your payout"
                // line) reflect the correct guest total + Keverna cut.
                $subtotal = (int) $booking->room_total;
                $updates = [
                    'keverna_fee'  => \App\Support\Marketplace\FeePolicy::kevernaFeeFor($subtotal),
                    'paystack_fee' => \App\Support\Marketplace\FeePolicy::paystackFeeFor($subtotal),
                    'total_amount' => \App\Support\Marketplace\FeePolicy::guestTotalFor($subtotal),
                ];
                if ($cartRef !== null) {
                    $updates['stays_cart_ref'] = $cartRef;
                }
                $booking->forceFill($updates)->save();
                $bookings->push($booking->refresh());
            }

            // Aggregate up-front charge across the cart. Per-booking
            // total_amount already reflects the marketplace fee
            // overlay above, so the sum is the true guest total.
            $aggregateTotal = (int) $bookings->sum(fn ($b) => (int) $b->total_amount);
            $chargeNow = $aggregateTotal;

            if ($isDeposit) {
                $percent = max(1, min(99, (int) config('keverna.pricing.stays_deposit_percent', 30)));
                $chargeNow = (int) round($aggregateTotal * $percent / 100);
                // Distribute the deposit + balance across each booking
                // proportional to its share of the aggregate. Operators
                // reconcile per-booking so each row needs its own
                // deposit_amount + balance_due, not just the primary.
                $balanceRemaining = max(0, $aggregateTotal - $chargeNow);
                $depositRemaining = $chargeNow;
                $lastIndex = $bookings->count() - 1;
                foreach ($bookings as $i => $b) {
                    $share = (int) $b->total_amount;
                    if ($i === $lastIndex) {
                        // Last leg absorbs any rounding drift.
                        $bookingDeposit = $depositRemaining;
                        $bookingBalance = $balanceRemaining;
                    } else {
                        $bookingDeposit = (int) round($share * $percent / 100);
                        $bookingBalance = max(0, $share - $bookingDeposit);
                        $depositRemaining -= $bookingDeposit;
                        $balanceRemaining -= $bookingBalance;
                    }
                    $b->forceFill([
                        'deposit_amount' => $bookingDeposit,
                        'balance_due'    => $bookingBalance,
                    ])->save();
                }
                $bookings = $bookings->map(fn ($b) => $b->refresh());
            }

            $primary = $bookings->first();

            // Single-room fast-path: use the existing per-booking
            // InitiateBookingPayment (creates its own Payment row).
            if ($cartRef === null) {
                $authorizationUrl = $this->init->handle(
                    $primary,
                    callbackUrl: $callbackUrl,
                    referencePrefix: 'kev_stays_',
                );
            } else {
                // Cart path: ONE Paystack init for the AGGREGATE.
                // Create a single Payment row on the primary booking
                // with amount = $chargeNow + stamp the shared cart_ref
                // so the callback / webhook can walk siblings.
                $authorizationUrl = $this->initiateCartPayment(
                    $primary, $chargeNow, $isDeposit, $cartRef, $callbackUrl,
                );
            }

            return [
                'authorization_url' => $authorizationUrl,
                'booking_ref'       => $primary->booking_ref,
                'booking_refs'      => $bookings->pluck('booking_ref')->all(),
                'cart_ref'          => $cartRef,
            ];
        } finally {
            // Always restore the caller's tenant context - central-
            // domain code paths must never observe a leaked tenant
            // just because a marketplace booking went through here.
            Tenant::forgetCurrent();
            $previousTenant?->makeCurrent();
        }
    }
}
