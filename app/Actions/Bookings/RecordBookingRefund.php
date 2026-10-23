<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Pos\RefundPosSaleLines;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records a manual refund against a booking — used when a room
 * reassignment lands the guest in a cheaper room and they've already
 * paid for the pricier one, or when any other post-hoc refund needs
 * to hit the ledger.
 *
 * Mirrors the pattern from {@see RefundPosSaleLines}
 * (POS-side refunds): a Payment row with `status = Refunded` and the
 * refund method (cash / transfer / card / paystack). Finance netting
 * subtracts these from Collected revenue automatically because it
 * already reads PaymentStatus::Refunded rows.
 *
 * The refund is recorded even if it would push the booking's paid
 * total below its `total_amount` — that's the expected outcome of a
 * downgrade after payment. The Reservations show page's KPIs will
 * pick up the new net-paid figure via existing Payment aggregations.
 */
class RecordBookingRefund
{
    /**
     * @param  'cash'|'transfer'|'card'|'paystack'|'other'  $method
     */
    public function handle(
        Booking $booking,
        Operator $operator,
        int $amount,
        string $method,
        ?string $referenceNote = null,
        ?Carbon $paidAt = null,
    ): Payment {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be positive.');
        }

        $paidAt = $paidAt ?? Carbon::now();

        return DB::transaction(function () use ($booking, $operator, $amount, $method, $referenceNote, $paidAt): Payment {
            $payment = Payment::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'invoice_id' => null,
                'paystack_reference' => 'refund-'.Str::ulid()->toBase32(),
                'amount' => $amount,
                'type' => PaymentType::Balance,
                'method' => $method,
                // payments.reference_note is varchar(120).
                'reference_note' => $referenceNote !== null && $referenceNote !== ''
                    ? \Illuminate\Support\Str::limit($referenceNote, 117)
                    : null,
                'status' => PaymentStatus::Refunded,
                'paid_at' => $paidAt,
            ]);

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('refund_recorded')
                ->withProperties([
                    'booking_ref' => $booking->booking_ref,
                    'amount' => $amount,
                    'method' => $method,
                    'payment_id' => $payment->id,
                ])
                ->log("Operator {$operator->name} recorded {$method} refund of ₦".number_format($amount)." against booking {$booking->booking_ref}.");

            // Bust the cached dashboard snapshot so Revenue MTD and
            // the pending-refunds badge reflect the new payment
            // immediately. Was invalidated only from Home.php's own
            // wire methods before, so refunds recorded from any
            // other surface (Refunds page, post-cancel modal,
            // reservation reconcile) stayed stale for up to 60s.
            DashboardSnapshot::forget();

            return $payment->refresh();
        });
    }
}
