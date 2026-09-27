<?php

declare(strict_types=1);

namespace App\Console\Commands\Bookings;

use App\Enums\BookingChargeType;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retroactive fix for bookings that got extended AFTER the system
 * had already auto-added an Overstay charge for the same nights.
 * The extend action now nets those out on the fly (see
 * ExtendBooking::handle), but bookings that were extended BEFORE
 * that fix shipped need a one-time backfill sweep.
 *
 * Match criteria (a booking is affected if):
 *   - it carries at least one Extension charge, AND
 *   - it carries at least one Overstay charge created BEFORE the
 *     latest Extension charge (i.e. the overstay was already on
 *     the folio when the extend ran + should have been waived)
 *
 * Usage:
 *   php artisan bookings:reconcile-overstay-post-extend --dry-run
 *   php artisan bookings:reconcile-overstay-post-extend --booking-ref=KV-MZS7KDUU
 *   php artisan bookings:reconcile-overstay-post-extend --apply
 *
 * Same rules the live fix uses:
 *   * unsettled Overstay -> deleted
 *   * settled Overstay -> amount netted off the trailing Extension
 *     charge so the guest isn't charged twice at the counter for
 *     what already left their wallet
 */
class ReconcileOverstayPostExtend extends Command
{
    protected $signature = 'bookings:reconcile-overstay-post-extend
                            {--booking-ref= : Only reconcile this specific booking (skip the scan)}
                            {--apply       : Actually apply the changes; without this flag runs as a dry-run}';

    protected $description = 'Retroactively waive Overstay charges on bookings that were extended after the auto-charge landed';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $ref = (string) ($this->option('booking-ref') ?? '');
        $isDry = ! $apply;

        if ($isDry) {
            $this->components->warn('Dry-run mode. Pass --apply to actually write changes.');
        }

        $query = Booking::query()->withoutGlobalScope(TenantScope::class);
        if ($ref !== '') {
            $query->where('booking_ref', $ref);
        } else {
            // Only scan bookings that have BOTH types of charge.
            $query->whereHas('charges', fn ($q) => $q->where('type', BookingChargeType::Extension->value))
                  ->whereHas('charges', fn ($q) => $q->where('type', BookingChargeType::Overstay->value));
        }

        $totalScanned = 0;
        $totalAffected = 0;
        $totalWaivedAmount = 0;
        $totalCreditedAmount = 0;
        $totalOverstayRowsDeleted = 0;

        $query->with(['charges'])
            ->cursor()
            ->each(function (Booking $booking) use ($apply, &$totalScanned, &$totalAffected, &$totalWaivedAmount, &$totalCreditedAmount, &$totalOverstayRowsDeleted): void {
                $totalScanned++;

                $charges = $booking->charges->sortBy('created_at');
                $lastExtension = $charges->where('type', BookingChargeType::Extension->value)->last();
                if ($lastExtension === null) return;

                // Only overstay rows created BEFORE the last extension
                // are candidates. Anything newer is legitimately owed
                // (post-extension real overstay).
                $affectedOverstay = $charges
                    ->where('type', BookingChargeType::Overstay->value)
                    ->filter(fn (BookingCharge $c) => $c->created_at !== null
                        && $lastExtension->created_at !== null
                        && $c->created_at->lt($lastExtension->created_at));

                if ($affectedOverstay->isEmpty()) return;

                $waivedAmount = 0;
                $waivedCount = 0;
                $alreadyPaid = 0;
                $waivedIds = [];

                foreach ($affectedOverstay as $osc) {
                    if ($osc->settled) {
                        $alreadyPaid += (int) $osc->amount;
                    } else {
                        $waivedAmount += (int) $osc->amount;
                        $waivedCount++;
                        $waivedIds[] = $osc->id;
                    }
                }

                $extAmountBefore = (int) $lastExtension->amount;
                $extAmountAfter = max(0, $extAmountBefore - $alreadyPaid);
                $creditedAmount = $extAmountBefore - $extAmountAfter;

                $totalAffected++;
                $totalWaivedAmount += $waivedAmount;
                $totalCreditedAmount += $creditedAmount;
                $totalOverstayRowsDeleted += $waivedCount;

                $this->line(sprintf(
                    '  <fg=yellow>%s</> · %d row%s waived (₦%s) · extension ₦%s → ₦%s (₦%s credit for wallet-paid)',
                    $booking->booking_ref,
                    $waivedCount,
                    $waivedCount === 1 ? '' : 's',
                    number_format($waivedAmount),
                    number_format($extAmountBefore),
                    number_format($extAmountAfter),
                    number_format($creditedAmount),
                ));

                if (! $apply) return;

                DB::transaction(function () use ($waivedIds, $lastExtension, $extAmountAfter, $creditedAmount, $booking, $waivedCount, $waivedAmount): void {
                    if ($waivedIds !== []) {
                        BookingCharge::query()->whereIn('id', $waivedIds)->delete();
                    }
                    if ($creditedAmount > 0) {
                        $newDesc = $lastExtension->description
                            .sprintf(' · ₦%s credit applied for wallet-paid overstay (backfill)', number_format($creditedAmount));
                        $lastExtension->forceFill([
                            'amount' => $extAmountAfter,
                            'description' => $newDesc,
                        ])->save();
                    }

                    activity('booking')
                        ->on($booking)
                        ->event('overstay_reconciled')
                        ->withProperties([
                            'via'                     => 'backfill_command',
                            'overstay_waived_count'   => $waivedCount,
                            'overstay_waived_amount'  => $waivedAmount,
                            'extension_credit'        => $creditedAmount,
                            'extension_amount_before' => (int) $lastExtension->getOriginal('amount'),
                            'extension_amount_after'  => $extAmountAfter,
                        ])
                        ->log(sprintf(
                            'Backfill: waived %d Overstay charge%s (₦%s) + credited ₦%s against extension on %s.',
                            $waivedCount, $waivedCount === 1 ? '' : 's',
                            number_format($waivedAmount),
                            number_format($creditedAmount),
                            $booking->booking_ref,
                        ));
                });
            });

        $this->newLine();
        $this->components->info(sprintf(
            '%s. Scanned %d bookings, %s %d, waived %d row%s (₦%s), credited ₦%s.',
            $apply ? 'Applied' : 'Would apply',
            $totalScanned,
            $apply ? 'reconciled' : 'would reconcile',
            $totalAffected,
            $totalOverstayRowsDeleted,
            $totalOverstayRowsDeleted === 1 ? '' : 's',
            number_format($totalWaivedAmount),
            number_format($totalCreditedAmount),
        ));

        return self::SUCCESS;
    }
}
