<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One-shot backfill: for every historical booking that was marked
 * `payment_status = Succeeded` before 2026-07-24 (when RecordManualPayment
 * landed) but has NO corresponding Succeeded Payment row, insert a
 * synthetic manual receipt so Finance's "Collected" and Reports'
 * revenue reflect reality.
 *
 * Ran once per tenant on deploy; idempotent — bookings that already
 * have a Succeeded Payment covering the total are skipped.
 *
 *   php artisan bookings:backfill-manual-payments        # dry-run
 *   php artisan bookings:backfill-manual-payments --apply
 */
class BackfillManualPayments extends Command
{
    protected $signature = 'bookings:backfill-manual-payments {--apply : Actually write rows (default is dry-run)}';

    protected $description = 'Insert missing Payment rows for bookings marked Succeeded before the RecordManualPayment action shipped.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // Cross-tenant scan — bypass the TenantScope so we can walk
        // every tenant's bookings in one command. Each row's tenant_id
        // rides along on the Payment we insert.
        $bookings = Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('payment_status', PaymentStatus::Succeeded->value)
            ->whereIn('booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::CheckedIn->value,
                BookingStatus::CheckedOut->value,
            ])
            ->where('total_amount', '>', 0)
            ->orderBy('id');

        $total = (clone $bookings)->count();
        $this->info(sprintf('Scanning %d candidate booking%s…', $total, $total === 1 ? '' : 's'));

        $inserted = 0;
        $skipped = 0;

        $bookings->chunkById(200, function ($rows) use (&$inserted, &$skipped, $apply): void {
            foreach ($rows as $booking) {
                $paid = (int) Payment::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->where('booking_id', $booking->id)
                    ->where('status', PaymentStatus::Succeeded->value)
                    ->sum('amount');

                $outstanding = (int) $booking->total_amount - $paid;

                if ($outstanding <= 0) {
                    $skipped++;
                    continue;
                }

                if ($apply) {
                    Payment::query()->create([
                        'tenant_id'          => $booking->tenant_id,
                        'booking_id'         => $booking->id,
                        'invoice_id'         => null,
                        'paystack_reference' => 'manual-backfill-'.Str::ulid()->toBase32(),
                        'amount'             => $outstanding,
                        'type'               => $paid > 0 ? PaymentType::Balance : PaymentType::Full,
                        'method'             => 'cash',
                        'reference_note'     => 'Backfilled from booking.payment_status — pre-RecordManualPayment history',
                        'status'             => PaymentStatus::Succeeded,
                        // Best-guess timestamp: the booking's last update
                        // typically corresponds to when the operator flipped
                        // it Confirmed/Succeeded.
                        'paid_at'            => $booking->updated_at ?? $booking->created_at,
                    ]);
                }

                $inserted++;
            }
        });

        $this->line('');
        $this->info(sprintf(
            '%s %d Payment row%s would be inserted (%d bookings already had cover).',
            $apply ? 'Inserted' : 'Would insert',
            $inserted,
            $inserted === 1 ? '' : 's',
            $skipped,
        ));

        if (! $apply) {
            $this->comment('Dry-run only. Re-run with --apply to write the rows.');
        }

        return self::SUCCESS;
    }
}
