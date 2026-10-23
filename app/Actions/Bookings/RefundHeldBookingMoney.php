<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Services\Bookings\HeldMoney;
use Illuminate\Support\Facades\DB;

/**
 * "Record refund" on held money (cancelled / no-show bookings still
 * holding cash). Validates the method, caps the amount at what is
 * still held and records it through {@see RecordBookingRefund}.
 *
 * Extracted from the web Refunds page so the page and the host app
 * inbox share the same rules. The held amount is re-read under a row
 * lock on the booking so two people can't refund the same money.
 */
class RefundHeldBookingMoney
{
    public function __construct(
        private readonly HeldMoney $heldMoney,
        private readonly RecordBookingRefund $record,
    ) {}

    /**
     * @throws HeldMoneyRefundException
     */
    public function handle(Booking $booking, Operator $operator, int $amount, string $method, ?string $note = null): Payment
    {
        if ($amount <= 0) {
            throw new HeldMoneyRefundException('amount_required', 'Amount required', 'Refund amount must be greater than zero.');
        }
        if (! in_array($method, PaymentMethod::refundValues(), true)) {
            throw new HeldMoneyRefundException('method_required', 'Method required', 'Pick a valid refund method.');
        }

        return DB::transaction(function () use ($booking, $operator, $amount, $method, $note): Payment {
            Booking::query()->lockForUpdate()->whereKey($booking->id)->first();

            // Cap at the currently-held amount so a typo can't push us
            // into a negative balance.
            $held = $this->heldMoney->heldFor($booking);
            if ($held <= 0) {
                throw new HeldMoneyRefundException('nothing_held', 'Nothing to refund', 'This booking has no held cash, it was already refunded.');
            }
            if ($amount > $held) {
                throw new HeldMoneyRefundException('amount_too_high', 'Amount too high', 'Held is ₦'.number_format($held).'. Enter that or less.');
            }

            return $this->record->handle(
                booking: $booking,
                operator: $operator,
                amount: $amount,
                method: $method,
                referenceNote: $note !== null && trim($note) !== '' ? trim($note) : null,
            );
        });
    }
}
