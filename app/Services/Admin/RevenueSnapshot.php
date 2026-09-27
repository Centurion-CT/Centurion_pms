<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;

/**
 * Revenue rollups for the admin dashboard (PRD §18). Plain integers — the
 * dashboard doesn't draw charts in v1 (deferred to v2 alongside Chart.js).
 *
 *   - ARR = active subscriptions × annual price (assumes one-tier pricing,
 *     which is the case in v1 — tiered pricing is a v2 roadmap item).
 *   - MRR = ARR / 12 (truthful because pricing is annual; integer-divide
 *     to keep money in whole NGN).
 *   - Churn (window) = subscriptions that flipped to Expired/Failed in
 *     the last N days, divided by subs that were active at window start.
 *   - Keverna fees = total `keverna_fee` across SUCCEEDED Payments in
 *     the window (the platform's per-transaction take, PRD §4.1).
 */
class RevenueSnapshot
{
    public const CHURN_WINDOW_DAYS = 30;

    /**
     * @return array{
     *     active_subscriptions: int,
     *     arr_ngn: int,
     *     mrr_ngn: int,
     *     churn_window_days: int,
     *     churned_count: int,
     *     churn_pct: float,
     *     keverna_fees_window_ngn: int,
     *     paystack_fees_window_ngn: int,
     *     stays_bookings_count: int,
     *     stays_gmv_window_ngn: int,
     *     stays_keverna_fees_window_ngn: int,
     *     stays_paystack_fees_window_ngn: int,
     *     stays_operator_payouts_window_ngn: int,
     * }
     */
    public function build(): array
    {
        $annualPrice = (int) config('keverna.pricing.subscription_annual_ngn');

        $active = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial])
            ->count();

        $arr = $active * $annualPrice;
        $mrr = $arr > 0 ? intdiv($arr, 12) : 0;

        $windowStart = Carbon::now()->subDays(self::CHURN_WINDOW_DAYS);

        $churned = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('status', [SubscriptionStatus::Expired, SubscriptionStatus::Failed])
            ->where('updated_at', '>=', $windowStart)
            ->count();

        // Active-at-window-start = currently active + those churned during the window.
        $denominator = $active + $churned;
        $churnPct = $denominator > 0 ? round(($churned / $denominator) * 100, 2) : 0.0;

        $kevernaFees = (int) Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('payments.status', PaymentStatus::Succeeded)
            ->where('payments.created_at', '>=', $windowStart)
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->sum('bookings.keverna_fee');

        $paystackFees = (int) Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('payments.status', PaymentStatus::Succeeded)
            ->where('payments.created_at', '>=', $windowStart)
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->sum('bookings.paystack_fee');

        // Marketplace-only rollup - separates Keverna Stays transactions
        // from tenant-direct so we can eyeball the 5% is landing and
        // spot flow anomalies (e.g. a run of bookings with keverna_fee=0
        // would mean the split isn't being taken and we're not getting
        // paid). Uses the same source_note the CreateStaysBooking
        // service stamps on every marketplace booking.
        $staysFilter = fn ($q) => $q
            ->withoutGlobalScope(TenantScope::class)
            ->where('payments.status', PaymentStatus::Succeeded)
            ->where('payments.created_at', '>=', $windowStart)
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->where('bookings.source_note', 'Keverna Stays marketplace');

        $staysBookingsCount = (int) $staysFilter(Payment::query())->distinct('bookings.id')->count('bookings.id');
        $staysGmv           = (int) $staysFilter(Payment::query())->sum('payments.amount');
        $staysKevernaFees   = (int) $staysFilter(Payment::query())->sum('bookings.keverna_fee');
        $staysPaystackFees  = (int) $staysFilter(Payment::query())->sum('bookings.paystack_fee');
        // Operator payout = GMV - Keverna's cut - Paystack's cut.
        // Nets out the two additive fees so the number equals what
        // reached the operator's Paystack subaccount (before Paystack
        // takes their split, which happens at settlement time).
        $staysOperatorPayouts = max(0, $staysGmv - $staysKevernaFees - $staysPaystackFees);

        return [
            'active_subscriptions' => $active,
            'arr_ngn' => $arr,
            'mrr_ngn' => $mrr,
            'churn_window_days' => self::CHURN_WINDOW_DAYS,
            'churned_count' => $churned,
            'churn_pct' => $churnPct,
            'keverna_fees_window_ngn' => $kevernaFees,
            'paystack_fees_window_ngn' => $paystackFees,
            'stays_bookings_count' => $staysBookingsCount,
            'stays_gmv_window_ngn' => $staysGmv,
            'stays_keverna_fees_window_ngn' => $staysKevernaFees,
            'stays_paystack_fees_window_ngn' => $staysPaystackFees,
            'stays_operator_payouts_window_ngn' => $staysOperatorPayouts,
        ];
    }
}
