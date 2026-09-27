<?php

declare(strict_types=1);

namespace App\Actions\GuestPortal;

use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\ExtendBooking;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\GuestPortalIntent;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\RoomCategory;
use App\Services\Bookings\RoomAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs the follow-up action for a guest-portal payment once Paystack
 * confirms it. Called from both the return-to-portal callback
 * (synchronous) AND the Paystack webhook (async safety net) —
 * idempotent by intent status so a double-fire no-ops.
 *
 * Per-purpose flow:
 *   - extend  → run ExtendBooking with new checkout date; the
 *               added Extension BookingCharge is auto-settled from
 *               this Payment inline so the guest doesn't owe again
 *               at check-out.
 *   - order   → follow-up slice (Order tile card-pay is still TODO)
 *   - reserve → follow-up slice (Reserve card-pay is still TODO)
 *
 * Returns the completed intent so the caller can redirect the
 * guest back to the right portal step + flash.
 */
class ResolvePortalPaymentIntent
{
    public function handle(GuestPortalIntent $intent, ?Carbon $paidAt = null): GuestPortalIntent
    {
        // Idempotency — the callback handler and the webhook can both
        // race here. First one wins; second one no-ops.
        if ($intent->status === GuestPortalIntent::STATUS_COMPLETED) {
            return $intent;
        }

        $paidAt ??= Carbon::now();

        try {
            DB::transaction(function () use ($intent, $paidAt): void {
                // Mark the linked Payment succeeded (if not already).
                $payment = $intent->payment;
                if ($payment !== null && $payment->status !== PaymentStatus::Succeeded) {
                    $payment->forceFill([
                        'status' => PaymentStatus::Succeeded,
                        'paid_at' => $paidAt,
                    ])->save();
                }

                // Run the per-purpose action.
                match ($intent->purpose) {
                    GuestPortalIntent::PURPOSE_EXTEND => $this->resolveExtend($intent),
                    GuestPortalIntent::PURPOSE_RESERVE => $this->resolveReserve($intent),
                    GuestPortalIntent::PURPOSE_ORDER => $this->resolveOrder($intent),
                    // Balance payments have no follow-up action. The Payment
                    // row was already marked Succeeded above, which is what
                    // Booking::balance_due() reads to compute outstanding.
                    // Nothing else to do.
                    GuestPortalIntent::PURPOSE_BALANCE => null,
                    default => throw new \RuntimeException("Unknown portal intent purpose: {$intent->purpose}"),
                };

                $intent->forceFill([
                    'status' => GuestPortalIntent::STATUS_COMPLETED,
                    'completed_at' => Carbon::now(),
                ])->save();
            });
        } catch (\Throwable $e) {
            Log::error('Portal intent resolve failed', [
                'intent_id' => $intent->id,
                'purpose' => $intent->purpose,
                'error' => $e->getMessage(),
            ]);
            $intent->forceFill([
                'status' => GuestPortalIntent::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ])->save();
        }

        return $intent->refresh();
    }

    /**
     * Guest paid up-front for a NEW booking (different room, could
     * be a different category). Runs the allocator + CreateBooking,
     * then flips the freshly-created booking straight to Confirmed
     * (skipping the usual Pending → Paystack round-trip because the
     * Paystack payment already completed for this intent).
     *
     * The linked Payment row is rewritten to point at the NEW
     * booking (not the guest's current one) so the money trail lands
     * on the right folio.
     */
    private function resolveReserve(GuestPortalIntent $intent): void
    {
        $origin = $intent->booking;
        if ($origin === null) {
            throw new \RuntimeException('Reserve intent has no origin booking.');
        }
        $p = $intent->payload ?? [];
        $catId = (int) ($p['category_id'] ?? 0);
        $checkin = (string) ($p['checkin'] ?? '');
        $checkout = (string) ($p['checkout'] ?? '');
        if ($catId === 0 || $checkin === '' || $checkout === '') {
            throw new \RuntimeException('Reserve intent missing category/dates.');
        }
        $category = RoomCategory::query()->findOrFail($catId);
        $in = Carbon::parse($checkin)->startOfDay();
        $out = Carbon::parse($checkout)->startOfDay();

        // Re-check availability in case the picture changed between
        // Paystack redirect and callback. If the category filled up
        // we refuse to create the booking and let the intent land in
        // failed — front-desk sees the guest's Payment succeeded and
        // handles the refund manually.
        $allocator = app(RoomAllocator::class);
        $room = $allocator->allocate($category, $in, $out);
        if ($room === null) {
            throw new \RuntimeException('No rooms of that category are available for those dates. Front desk will follow up.');
        }

        $req = new CreateBookingRequest(
            guestName: (string) ($p['guest_name'] ?? $origin->guest_name),
            guestEmail: (string) ($p['guest_email'] ?? $origin->guest_email),
            guestPhone: (string) ($p['guest_phone'] ?? $origin->guest_phone),
            adults: max(1, (int) ($p['adults'] ?? 1)),
            children: max(0, (int) ($p['children'] ?? 0)),
            checkin: $in,
            checkout: $out,
            paymentType: PaymentType::Full,
            specialRequests: 'Reserved from in-room portal by '.$origin->guest_name.' ('.$origin->booking_ref.').',
            source: BookingSource::Online,
        );
        $newBooking = app(CreateBooking::class)->handle($room, $req);

        // Flip the fresh booking to Confirmed straight away and
        // re-point the intent's Payment at the new booking so the
        // money lands where the room-nights are.
        $newBooking->forceFill([
            'booking_status' => BookingStatus::Confirmed->value,
            'balance_due' => 0,
        ])->save();

        $payment = $intent->payment;
        if ($payment !== null) {
            $payment->forceFill([
                'booking_id' => $newBooking->id,
                'type' => PaymentType::Full,
                'reference_note' => 'Guest portal · reserve · booked by '.$origin->booking_ref,
            ])->save();
        }

        // Store the fresh booking id on the intent payload so the
        // portal home flash can link to it if we ever add "you
        // reserved room X" affordances.
        $intent->forceFill([
            'payload' => array_merge($intent->payload ?? [], ['new_booking_id' => $newBooking->id]),
        ])->save();
    }

    /**
     * Guest paid by card for a room-service order. The Draft PosSale
     * is created up-front (in the portal component's
     * submitOrderPayByCard); this step completes it with tender
     * 'card' + records the linked portal Payment.
     */
    private function resolveOrder(GuestPortalIntent $intent): void
    {
        $booking = $intent->booking;
        if ($booking === null) {
            throw new \RuntimeException('Order intent has no booking.');
        }
        $saleId = (int) ($intent->payload['pos_sale_id'] ?? 0);
        if ($saleId === 0) {
            throw new \RuntimeException('Order intent missing pos_sale_id.');
        }
        $sale = \App\Models\PosSale::query()->find($saleId);
        if ($sale === null) {
            throw new \RuntimeException('POS sale not found.');
        }
        // Idempotent: if the operator (or a webhook race) already
        // completed this sale, don't try again.
        if ($sale->status === \App\Enums\PosSaleStatus::Completed) {
            return;
        }

        $operator = Operator::query()
            ->where('tenant_id', $booking->tenant_id)
            ->where('role', OperatorRole::Manager->value)
            ->orderBy('id')
            ->firstOrFail();

        app(\App\Actions\Pos\CompletePosSale::class)->handle(
            sale: $sale,
            operator: $operator,
            method: 'card',
            referenceNote: 'Guest portal · card via '.($intent->payment?->paystack_reference ?? 'paystack'),
        );

        // Link the portal Payment row to the freshly-completed sale
        // so the money trail reads cleanly on the sale ledger too.
        if ($intent->payment !== null) {
            $intent->payment->forceFill(['pos_sale_id' => $sale->id])->save();
        }
    }

    private function resolveExtend(GuestPortalIntent $intent): void
    {
        $booking = $intent->booking;
        if ($booking === null) {
            throw new \RuntimeException('Extend intent has no booking.');
        }
        $newCheckout = $intent->payload['new_checkout_date'] ?? null;
        if ($newCheckout === null) {
            throw new \RuntimeException('Extend intent missing new_checkout_date.');
        }

        $operator = Operator::query()
            ->where('tenant_id', $booking->tenant_id)
            ->where('role', OperatorRole::Manager->value)
            ->orderBy('id')
            ->firstOrFail();

        // ExtendBooking adds an Extension charge on the booking. The
        // paid Payment above stands as the settlement — the linkage
        // is through booking_id, so the outstanding-balance guard on
        // MarkCheckedOut nets to zero (added charge − matching paid
        // Payment = 0). See BookingSettlementService::outstandingTotal.
        //
        // Breakfast tier rides on the payload so the ledger charge
        // matches what the guest just paid on Paystack. Missing key
        // (legacy intents pre-toggle) means "inherit from booking".
        $payloadBreakfast = array_key_exists('breakfast_included', $intent->payload)
            ? (bool) $intent->payload['breakfast_included']
            : null;
        app(ExtendBooking::class)->handle(
            $booking->fresh(), Carbon::parse((string) $newCheckout), $operator,
            breakfastIncluded: $payloadBreakfast,
        );

        // Mark the fresh Extension charge as settled + link to the
        // portal Payment so the ledger reads cleanly on the folio.
        $charge = \App\Models\BookingCharge::query()
            ->where('booking_id', $booking->id)
            ->where('type', \App\Enums\BookingChargeType::Extension->value)
            ->orderByDesc('id')
            ->first();
        if ($charge !== null && ! $charge->settled) {
            $charge->forceFill([
                'settled' => true,
                'settled_at' => Carbon::now(),
                'settled_method' => 'card',
            ])->save();
        }
    }
}
