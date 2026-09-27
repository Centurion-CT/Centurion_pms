<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Events\PlatformPaymentPulse;
use App\Models\Payment;
use App\Models\PlatformStats;

/**
 * Fires PlatformPaymentPulse whenever a Payment either lands as
 * Succeeded or transitions from Pending to Succeeded. Filters out
 * refunds, failed states, corporate-AR mirrors (they duplicate the
 * underlying CorporatePayment which itself never routes through
 * this observer), and micro-txns below the floor so guest wallet
 * top-ups + kobo-scale receipts don't spam the marketing surface.
 */
class PaymentPulseObserver
{
    /** Amounts below this floor (in naira) do not broadcast. */
    private const FLOOR_NAIRA = 5_000;

    /** Round the toast-display amount to the nearest ₦X for anonymity. */
    private const ROUND_TO_NAIRA = 5_000;

    public function created(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::Succeeded) {
            $this->emit($payment);
        }
    }

    public function updated(Payment $payment): void
    {
        if (! $payment->wasChanged('status')) {
            return;
        }
        if ($payment->status !== PaymentStatus::Succeeded) {
            return;
        }
        $this->emit($payment);
    }

    private function emit(Payment $payment): void
    {
        $amount = (int) $payment->amount;
        if ($amount < self::FLOOR_NAIRA) {
            return;
        }
        // Corporate-AR rows mirror a CorporatePayment; broadcasting both
        // would double-count. The canonical CorporatePayment lives on a
        // separate table without this observer, so skipping the mirror
        // gives us exactly one pulse per real inflow.
        if ($payment->type === PaymentType::CorporateAr) {
            return;
        }

        $pad = PlatformStats::PUBLIC_PAD_FACTOR;
        $padded = (int) round($amount * $pad);
        $rounded = (int) round($padded / self::ROUND_TO_NAIRA) * self::ROUND_TO_NAIRA;
        if ($rounded < self::ROUND_TO_NAIRA) {
            $rounded = self::ROUND_TO_NAIRA;
        }

        event(new PlatformPaymentPulse(
            paddedAmount: $padded,
            roundedAmount: $rounded,
            category: self::categoryFor($payment->type),
        ));
    }

    private static function categoryFor(PaymentType $type): string
    {
        return match ($type) {
            PaymentType::Full,
            PaymentType::Deposit,
            PaymentType::Balance,
            PaymentType::Invoice => 'stays',
            PaymentType::PosSale => 'outlet',
            PaymentType::Extra => 'extras',
            PaymentType::CorporateAr => 'ar',
        };
    }
}
