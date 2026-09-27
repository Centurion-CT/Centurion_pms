<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Mail\Subscriptions\SubscriptionInvoiceMail;
use App\Mail\Subscriptions\SubscriptionReceiptMail;
use App\Models\Admin;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Records a follow-up payment against an existing tenant subscription
 * (e.g. the tenant paid a first instalment at activation, then wired
 * the balance a month later). Increments amount_received without
 * touching the contract term itself, keeping legacy `amount` in
 * sync so downstream reports stay consistent.
 *
 * Queues a fresh receipt every time, and a fresh invoice if there's
 * still an outstanding balance after the payment. Fully-paid rows
 * skip the invoice.
 */
class RecordSubscriptionPayment
{
    public function handle(
        Subscription $subscription,
        Admin $admin,
        int $amount,
        ?string $notes = null,
    ): Subscription {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $outstandingBefore = $subscription->outstanding();
        if ($amount > $outstandingBefore) {
            throw new \InvalidArgumentException(
                'Payment amount (₦'.number_format($amount).') exceeds outstanding (₦'.number_format($outstandingBefore).').'
            );
        }

        $tenant = $subscription->tenant;

        $subscription = DB::transaction(function () use (
            $subscription, $admin, $amount, $notes, $outstandingBefore, $tenant,
        ): Subscription {
            $subscription->forceFill([
                'amount_received' => (int) $subscription->amount_received + $amount,
                'amount'          => (int) $subscription->amount + $amount,
                'paid_at'         => $subscription->paid_at ?? Carbon::now(),
            ]);
            if ($notes !== null && trim($notes) !== '') {
                $existing = (string) ($subscription->agreement_notes ?? '');
                $stamp = Carbon::now()->format('j M Y');
                $line = "[{$stamp}] Payment ₦".number_format($amount).': '.trim($notes);
                $subscription->agreement_notes = $existing === '' ? $line : $existing."\n".$line;
            }
            $subscription->save();

            $outstandingAfter = max(0, $outstandingBefore - $amount);

            activity('subscription')
                ->causedBy($admin)
                ->performedOn($subscription)
                ->event('payment_recorded')
                ->withProperties([
                    'amount'             => $amount,
                    'outstanding_before' => $outstandingBefore,
                    'outstanding_after'  => $outstandingAfter,
                ])
                ->log('Recorded ₦'.number_format($amount).' payment on '.$tenant->slug.' subscription');

            return $subscription;
        });

        $outstandingAfter = $subscription->outstanding();

        // Always confirm the payment received.
        Mail::to($tenant->email)->queue(new SubscriptionReceiptMail($subscription->refresh(), $amount));

        // Still money owed? Send an updated invoice with the new balance.
        if ($outstandingAfter > 0) {
            Mail::to($tenant->email)->queue(new SubscriptionInvoiceMail($subscription->refresh(), $outstandingAfter));
        }

        return $subscription;
    }
}
