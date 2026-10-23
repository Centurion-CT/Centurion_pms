<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Invoicing\RecordInvoicePayment;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Payment;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a manual (off-Paystack) receipt against a booking — for
 * walk-ins, phone bookings, or any confirmation the operator marks
 * as "Paid in full" from the dashboard.
 *
 * Why this exists: prior to this action, four separate call sites
 * (ManualCreate, Home's sheet, Calendar's sheet, and ConfirmBooking)
 * flipped `booking.payment_status = Succeeded` WITHOUT ever inserting
 * into the `payments` table. That left Finance's "Collected" and
 * Reports' revenue cards reading 0 for revenue that had actually
 * been paid — the ledger was blind to off-Paystack money.
 *
 * Payment rows are the source of truth for cash-in. Creating one here
 * ensures the ledger reflects reality regardless of how the operator
 * confirmed the booking.
 *
 * Idempotent: if any Succeeded Payment already exists for the booking
 * that covers the total, this is a no-op. Re-clicking "Confirm" or
 * re-processing a webhook after a manual mark-as-paid won't
 * double-book cash.
 *
 * Overpayment handling: if the operator collects more than the
 * booking's outstanding balance, the surplus auto-credits the linked
 * Customer's wallet (as a Topup with source='overpayment_at_checkin').
 * The Payment row only covers the actual booking balance so revenue
 * reports stay honest; the wallet holds the change for the guest to
 * spend later.
 *
 * Convention mirrored from {@see RecordInvoicePayment}:
 * synthetic `paystack_reference` of `manual-<ulid>` keeps the
 * unique-index constraint happy without borrowing Paystack's namespace.
 */
class RecordManualPayment
{
    public function __construct(private readonly WalletService $wallet) {}

    /**
     * @param  'cash'|'transfer'|'card'|'pos'|'other'  $method
     */
    public function handle(
        Booking $booking,
        ?Operator $operator = null,
        ?int $amount = null,
        string $method = 'cash',
        ?string $referenceNote = null,
        ?Carbon $paidAt = null,
    ): ?Payment {
        $paidAt = $paidAt ?? Carbon::now();
        $amount = $amount ?? (int) $booking->total_amount;

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($booking, $operator, $amount, $method, $referenceNote, $paidAt): ?Payment {
            // Idempotency: skip if the booking is already covered by
            // one or more Succeeded payments summing to at least the
            // requested amount. Prevents a re-click on Confirm from
            // duplicating cash on the ledger.
            $alreadyPaid = (int) Payment::query()
                ->where('booking_id', $booking->id)
                ->where('status', PaymentStatus::Succeeded)
                ->sum('amount');

            if ($alreadyPaid >= $amount) {
                return null;
            }

            // Cap the Payment row at whatever the booking still owes.
            // Any surplus falls through to the wallet block below —
            // keeps Finance's "Collected" honest to booking totals.
            $bookingRemaining = max(0, (int) $booking->total_amount - $alreadyPaid);
            $paymentAmount = min($amount - $alreadyPaid, $bookingRemaining);
            $surplus = ($amount - $alreadyPaid) - $paymentAmount;

            $payment = null;
            if ($paymentAmount > 0) {
                $payment = Payment::query()->create([
                    'tenant_id' => $booking->tenant_id,
                    'booking_id' => $booking->id,
                    'invoice_id' => null,
                    'paystack_reference' => 'manual-'.Str::ulid()->toBase32(),
                    'amount' => $paymentAmount,
                    'type' => $alreadyPaid > 0 ? PaymentType::Balance : PaymentType::Full,
                    'method' => $method,
                    'collected_by_operator_id' => $operator?->getKey(),
                    // payments.reference_note is varchar(120).
                    'reference_note' => $referenceNote !== null && $referenceNote !== ''
                        ? Str::limit($referenceNote, 117)
                        : null,
                    'status' => PaymentStatus::Succeeded,
                    'paid_at' => $paidAt,
                ]);

                if ($operator !== null) {
                    activity('booking')
                        ->causedBy($operator)
                        ->performedOn($booking)
                        ->event('manual_payment_recorded')
                        ->withProperties([
                            'booking_ref' => $booking->booking_ref,
                            'amount' => $payment->amount,
                            'method' => $method,
                            'payment_id' => $payment->id,
                        ])
                        ->log("Operator {$operator->name} recorded {$method} payment of {$payment->amount} against booking {$booking->booking_ref}.");
                }
            }

            // ── Overpayment surplus → guest wallet ──────────────────
            // Only when the operator collected more than the booking
            // owed AND a Customer is linked to the booking. Standalone
            // guests without a Customer row silently lose the surplus
            // in v1 — CustomerLinker attaches on booking create so
            // this is a rare edge case (imported historical bookings).
            if ($surplus > 0 && $operator !== null && $booking->customer instanceof Customer) {
                $this->wallet->topup(
                    customer: $booking->customer,
                    amount: $surplus,
                    description: sprintf(
                        'Change from %s payment on booking %s',
                        $method,
                        $booking->booking_ref,
                    ),
                    paymentMethod: $method,
                    meta: [
                        'source' => 'overpayment_at_checkin',
                        'booking_ref' => $booking->booking_ref,
                    ],
                    links: ['booking_id' => $booking->id, 'payment_id' => $payment?->id],
                    causer: $operator,
                );

                activity('customer_wallet')
                    ->causedBy($operator)
                    ->performedOn($booking->customer)
                    ->event('overpayment_credited')
                    ->withProperties([
                        'booking_ref' => $booking->booking_ref,
                        'surplus' => $surplus,
                    ])
                    ->log(sprintf(
                        'Overpayment of ₦%s from booking %s credited to %s\'s wallet.',
                        number_format($surplus),
                        $booking->booking_ref,
                        $booking->customer->name,
                    ));
            }

            return $payment?->fresh();
        });
    }
}
