<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\BookingCharge;
use App\Models\Payment;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One-shot backfill: SettleCharges (the "Mark paid" click on a charge
 * row) only started writing a Payment row on 2026-09-08 (85ff298).
 * Every settled charge on cash/transfer/card/pos/other settled BEFORE
 * that commit is missing from the payments ledger, which means Finance
 * undercounts and the reservation-detail payments panel + Payments
 * dashboard show only the original booking payment.
 *
 * This command finds every settled non-wallet charge that has no
 * matching Payment row and inserts one shaped exactly like the new
 * SettleCharges::recordPayment() writes.
 *
 * Match heuristic (to avoid double-counting wallet or online settlements
 * that already wrote their own Payment):
 *   - Skip charges where settled_method === 'wallet' - the wallet path
 *     (SettleChargesFromWallet) has always written its own Payment.
 *   - Skip charges that already have a Payment on the same booking with
 *     same amount + same method AND paid_at within 60s of settled_at.
 *   - Everything else gets a fresh Payment (type=Extra, status=Succeeded,
 *     collected_by_operator_id = charge.created_by_operator_id as a
 *     best-effort attribution; the true settler isn't recorded on the
 *     old charge row).
 *
 * Runs cross-tenant. Dry-run by default; --apply writes.
 *
 *   php artisan bookings:backfill-settled-charge-payments        # dry-run
 *   php artisan bookings:backfill-settled-charge-payments --apply
 *   php artisan bookings:backfill-settled-charge-payments --tenant=citistay-apartments --apply
 */
class BackfillSettledChargePayments extends Command
{
    protected $signature = 'bookings:backfill-settled-charge-payments
        {--apply : Actually write rows (default is dry-run)}
        {--tenant= : Restrict to one tenant by slug (default: all tenants)}';

    protected $description = 'Insert missing Payment rows for BookingCharges settled before SettleCharges started writing them itself.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $tenantSlug = (string) ($this->option('tenant') ?? '');

        // Cross-tenant scan. Payments + Charges both carry tenant_id
        // so we set it explicitly on the insert rather than relying
        // on BelongsToTenant.
        $query = BookingCharge::query()->withoutGlobalScope(TenantScope::class)
            ->where('settled', true)
            ->whereNotNull('settled_at')
            ->where(function ($q): void {
                $q->whereNull('settled_method')
                    ->orWhere('settled_method', '!=', 'wallet');
            })
            ->orderBy('id');

        if ($tenantSlug !== '') {
            $query->whereHas('booking.tenant', fn ($q) => $q->where('slug', $tenantSlug));
        }

        $candidates = $query->get();
        $this->line("Scanned {$candidates->count()} settled non-wallet charges.");

        $wouldWrite = 0;
        $alreadyCovered = 0;
        $missingBooking = 0;
        $samples = [];

        foreach ($candidates as $charge) {
            if ((int) $charge->amount <= 0) {
                continue;
            }

            $booking = $charge->booking()->withoutGlobalScope(TenantScope::class)->first();
            if ($booking === null) {
                $missingBooking++;
                continue;
            }

            $method = $charge->settled_method ?: 'cash';
            $paidAt = $charge->settled_at ?: $charge->updated_at;

            // Match heuristic: within 60s of settled_at, same booking,
            // same amount, same method - assume that Payment was the
            // one that covered this charge.
            $window = 60;
            $paidAtCarbon = Carbon::parse($paidAt);
            $existing = Payment::query()->withoutGlobalScope(TenantScope::class)
                ->where('booking_id', $booking->id)
                ->where('amount', (int) $charge->amount)
                ->where('method', $method)
                ->where('status', PaymentStatus::Succeeded->value)
                ->whereBetween('paid_at', [
                    $paidAtCarbon->copy()->subSeconds($window),
                    $paidAtCarbon->copy()->addSeconds($window),
                ])
                ->exists();

            if ($existing) {
                $alreadyCovered++;
                continue;
            }

            $wouldWrite++;
            if (count($samples) < 6) {
                $samples[] = [
                    'charge_id' => $charge->id,
                    'booking'   => $booking->booking_ref,
                    'amount'    => (int) $charge->amount,
                    'method'    => $method,
                    'settled_at'=> (string) $paidAt,
                ];
            }

            if ($apply) {
                Payment::query()->create([
                    'tenant_id'               => $booking->tenant_id,
                    'booking_id'              => $booking->id,
                    'invoice_id'              => null,
                    'paystack_reference'      => 'charge-'.$charge->id.'-'.Str::ulid()->toBase32(),
                    'amount'                  => (int) $charge->amount,
                    'type'                    => PaymentType::Extra->value,
                    'method'                  => $method,
                    'collected_by_operator_id'=> $charge->created_by_operator_id,
                    'reference_note'          => 'Backfilled settled charge: '.Str::limit((string) $charge->description, 120),
                    'status'                  => PaymentStatus::Succeeded->value,
                    'paid_at'                 => $paidAt,
                ]);
            }
        }

        $this->line('');
        $this->table(['bucket', 'count'], [
            ['would-write (missing Payment row)', $wouldWrite],
            ['already covered (skipped)',         $alreadyCovered],
            ['missing booking (skipped)',         $missingBooking],
        ]);

        if (! empty($samples)) {
            $this->line('');
            $this->line('Sample (up to 6 rows):');
            $this->table(['charge_id', 'booking', 'amount', 'method', 'settled_at'], $samples);
        }

        if ($apply) {
            $this->line('');
            $this->info("Wrote {$wouldWrite} Payment row(s).");
        } else {
            $this->line('');
            $this->line('<comment>Dry-run.</comment> Re-run with <info>--apply</info> to write the missing rows.');
        }

        return self::SUCCESS;
    }
}
