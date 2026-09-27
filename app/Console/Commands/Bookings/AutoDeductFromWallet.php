<?php

declare(strict_types=1);

namespace App\Console\Commands\Bookings;

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Customers\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Nightly ledger sweep for guests carrying money in their wallet:
 *
 *   1. Overstay accrual - every CheckedIn booking past checkout_date
 *      gets billed for whatever overstay nights aren't already on the
 *      ledger. Computes the delta each run so a missed day (or five)
 *      catches up on the next execution rather than being lost. One
 *      aggregate BookingCharge per catch-up. If the guest's wallet
 *      covers the full delta, we spend it immediately and mark the
 *      charge settled; partial cover settles up to what's available
 *      and leaves the remainder unsettled for front-desk collection
 *      at checkout via {@see MarkCheckedOut}.
 *
 *   2. Balance-due auto-collect - every OnHold booking whose
 *      balance_due_at has passed and whose guest wallet has funds
 *      gets its balance drawn from the wallet before
 *      {@see AutoCancelExpiredHolds} sweeps at 08:15. Fully covered
 *      bookings flip to Confirmed.
 *
 * Runs across every tenant. Sets Tenant::current() per iteration so
 * BelongsToTenant hooks find a bound tenant when creating new rows.
 */
class AutoDeductFromWallet extends Command
{
    protected $signature = 'bookings:auto-deduct-from-wallet';

    protected $description = 'Charge overstay + balance-due bookings from guest wallets before overnight sweeps';

    public function handle(WalletService $walletService): int
    {
        $today = Carbon::today();
        $now = Carbon::now();
        $overstayCharged = 0;
        $overstaySwept = 0;
        $balanceSwept = 0;

        // ── 1. Overstay accrual + wallet sweep ─────────────────────
        // `cursor()` doesn't eager-load, so we defer relation loads to
        // AFTER makeCurrent() - otherwise BelongsToTenant's scope on
        // Room / Customer fires with no current tenant and returns null.
        Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereDate('checkout_date', '<', $today)
            ->cursor()
            ->each(function (Booking $booking) use (&$overstayCharged, &$overstaySwept, $walletService, $today): void {
                $tenant = Tenant::query()->find($booking->tenant_id);
                if (! $tenant instanceof Tenant) {
                    return;
                }

                $tenant->makeCurrent();
                try {
                    // Load category too - Booking::committedNightlyRate
                    // reads room.category.base_rate / breakfast_included_rate
                    // to pick the tier the guest actually committed to,
                    // so a breakfast-tier guest's overstay night charges
                    // at their tier rather than the cheaper base rate.
                    $booking->load('room.category', 'customer.wallet');
                    $rate = $booking->committedNightlyRate();
                    if ($rate <= 0) {
                        return;
                    }

                    // Delta between nights owed and nights already on
                    // the ledger - resilient to missed cron days: if
                    // the sweep failed to run for a week, this run
                    // catches up in one aggregate charge instead of
                    // leaving the guest permanently under-billed.
                    $nightsToCharge = $booking->unchargedOverstayNights($today);
                    if ($nightsToCharge <= 0) {
                        return;
                    }
                    $chargeAmount = $nightsToCharge * $rate;

                    // Human-readable date range for the ledger row.
                    // The first uncharged night is the day AFTER the
                    // last charged night (or checkout_date if none
                    // charged yet); the last is today.
                    $checkoutDate = $booking->checkout_date->copy()->startOfDay();
                    $alreadyCharged = $booking->overstayNightsCharged();
                    $rangeStart = $checkoutDate->copy()->addDays($alreadyCharged);
                    $rangeEnd   = $today;

                    DB::transaction(function () use ($booking, $baseRate, $chargeAmount, $nightsToCharge, $rangeStart, $rangeEnd, $walletService, &$overstayCharged, &$overstaySwept): void {
                        $charge = BookingCharge::query()->create([
                            'tenant_id' => $booking->tenant_id,
                            'booking_id' => $booking->id,
                            'created_by_operator_id' => null,
                            'type' => BookingChargeType::Overstay->value,
                            'description' => sprintf(
                                '%d night%s overstay (auto) · %s → %s',
                                $nightsToCharge,
                                $nightsToCharge === 1 ? '' : 's',
                                $rangeStart->toFormattedDateString(),
                                $rangeEnd->toFormattedDateString(),
                            ),
                            'amount' => $chargeAmount,
                            'settled' => false,
                        ]);
                        $overstayCharged++;

                        if ($booking->customer instanceof Customer) {
                            $tx = $walletService->spendUpTo(
                                customer: $booking->customer,
                                amount: $chargeAmount,
                                description: sprintf(
                                    '%d night%s overstay on %s',
                                    $nightsToCharge,
                                    $nightsToCharge === 1 ? '' : 's',
                                    $booking->booking_ref,
                                ),
                                meta: ['source' => 'overstay_auto', 'booking_ref' => $booking->booking_ref],
                                links: ['booking_id' => $booking->id, 'booking_charge_id' => $charge->id],
                            );

                            // Only mark the charge fully settled when
                            // the wallet covered the ENTIRE delta -
                            // partial wallet cover leaves the remainder
                            // as an unsettled charge front desk will
                            // collect at checkout.
                            if ($tx !== null && $tx->amount_naira === -$chargeAmount) {
                                $charge->forceFill([
                                    'settled' => true,
                                    'settled_at' => Carbon::now(),
                                    'settled_method' => 'wallet',
                                ])->save();
                                $overstaySwept++;
                            }
                        }
                    });
                } catch (Throwable $e) {
                    $this->components->error(sprintf(
                        'Overstay pass failed for %s: %s',
                        $booking->booking_ref,
                        $e->getMessage(),
                    ));
                } finally {
                    Tenant::forgetCurrent();
                }
            });

        // ── 2. Balance-due auto-collect ────────────────────────────
        Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_status', BookingStatus::OnHold->value)
            ->where('balance_due', '>', 0)
            ->whereNotNull('balance_due_at')
            ->where('balance_due_at', '<', $now)
            ->cursor()
            ->each(function (Booking $booking) use (&$balanceSwept, $walletService): void {
                $tenant = Tenant::query()->find($booking->tenant_id);
                if (! $tenant instanceof Tenant) {
                    return;
                }

                $tenant->makeCurrent();
                try {
                    $booking->load('customer.wallet');
                    if (! $booking->customer instanceof Customer) {
                        return;
                    }
                    $outstanding = (int) $booking->balance_due;
                    $available = $booking->customer->walletBalance();
                    if ($available <= 0) {
                        return;
                    }
                    $toSpend = min($outstanding, $available);

                    DB::transaction(function () use ($booking, $walletService, $outstanding, $toSpend, &$balanceSwept): void {
                        $payment = Payment::query()->create([
                            'tenant_id' => $booking->tenant_id,
                            'booking_id' => $booking->id,
                            'invoice_id' => null,
                            'paystack_reference' => 'wallet-'.Str::ulid()->toBase32(),
                            'amount' => $toSpend,
                            'type' => PaymentType::Balance,
                            'method' => 'wallet',
                            'reference_note' => 'Balance-due auto-collect from wallet',
                            'status' => PaymentStatus::Succeeded,
                            'paid_at' => Carbon::now(),
                        ]);

                        $walletService->spend(
                            customer: $booking->customer,
                            amount: $toSpend,
                            description: sprintf('Auto-collected balance on %s', $booking->booking_ref),
                            meta: ['source' => 'balance_due_auto', 'booking_ref' => $booking->booking_ref],
                            links: ['booking_id' => $booking->id, 'payment_id' => $payment->id],
                        );

                        if ($toSpend >= $outstanding) {
                            $booking->forceFill([
                                'booking_status' => BookingStatus::Confirmed->value,
                                'payment_status' => PaymentStatus::Succeeded->value,
                                'balance_due' => 0,
                            ])->save();
                        } else {
                            $booking->forceFill([
                                'balance_due' => $outstanding - $toSpend,
                            ])->save();
                        }
                        $balanceSwept++;
                    });
                } catch (Throwable $e) {
                    $this->components->error(sprintf(
                        'Balance-due pass failed for %s: %s',
                        $booking->booking_ref,
                        $e->getMessage(),
                    ));
                } finally {
                    Tenant::forgetCurrent();
                }
            });

        $this->components->info(sprintf(
            'Overstay charges added: %d (of which %d settled from wallet). Balance-due bookings collected: %d.',
            $overstayCharged,
            $overstaySwept,
            $balanceSwept,
        ));

        return self::SUCCESS;
    }
}
