<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;

/**
 * Receipt facts for one Payment row, used by the desk's 80 mm receipt
 * (host/payments/{id}/receipt). Same rules as Keverna's
 * PaymentReceiptController helpers; Centurion has no web receipt page.
 */
final class PaymentReceipt
{
    /**
     * Balance after this payment: booking total minus every succeeded
     * payment taken on or before this one (paid_at, then id as
     * tiebreak). Refunded rows don't count as money received.
     */
    public static function balanceAfter(Payment $payment): ?int
    {
        $booking = $payment->booking;
        if ($booking === null) {
            return null;
        }
        $cutAt = $payment->paid_at;
        $paidSoFar = (int) $booking->payments
            ->filter(fn (Payment $p) => $p->status === PaymentStatus::Succeeded)
            ->filter(function (Payment $p) use ($payment, $cutAt): bool {
                if ($p->id === $payment->id) {
                    return true;
                }
                if ($cutAt === null || $p->paid_at === null) {
                    return $p->id < $payment->id;
                }

                return $p->paid_at->lt($cutAt)
                    || ($p->paid_at->eq($cutAt) && $p->id < $payment->id);
            })
            ->sum('amount');

        return max(0, (int) $booking->total_amount - $paidSoFar);
    }

    public static function typeLabel(Payment $payment): string
    {
        return match ($payment->type->value) {
            'corporate_ar' => 'Corporate account payment',
            'pos_sale' => 'POS sale',
            'full' => 'Full payment',
            'deposit' => 'Deposit',
            'balance' => 'Balance payment',
            'invoice' => 'Invoice payment',
            'extra' => 'Extra',
            default => ucfirst(str_replace('_', ' ', $payment->type->value)),
        };
    }

    public static function number(Payment $payment): string
    {
        return (string) ($payment->paystack_reference ?: ('PAY-'.$payment->id));
    }
}
