<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-shot correction for bookings whose manual_discount_amount got
 * inflated by the extend-then-cancel bug. Walks the activity log for
 * a single booking_ref, finds the sequences of:
 *
 *   extended → post_checkin_discount_applied(*) → extension_cancelled
 *
 * ...and sums the post_checkin_discount_applied amounts that landed
 * between each extend/cancel pair. That sum is what should have been
 * reversed by CancelBookingExtension but wasn't (the fix landed
 * 2026-09-08). Applies the reversal to total_amount +
 * manual_discount_amount + balance_due in one transaction and drops
 * a discount_correction_after_extension_cancel activity row.
 *
 * Bounded to a single booking_ref by design - not a bulk backfill
 * (the pattern is rare + benign in most tenants, no reason to sweep).
 *
 *   php artisan bookings:correct-discount-after-cancel KV-XJZWA3WU
 *   php artisan bookings:correct-discount-after-cancel KV-XJZWA3WU --apply
 */
class CorrectDiscountAfterExtensionCancel extends Command
{
    protected $signature = 'bookings:correct-discount-after-cancel
        {ref : Booking reference (e.g. KV-XJZWA3WU)}
        {--apply : Actually write the correction (default is dry-run)}
        {--undo : Roll back the most recent correction on this booking using the stored before-snapshot}';

    protected $description = 'Reverse post-checkin discounts that leaked past a subsequent extension cancel on one booking.';

    public function handle(): int
    {
        $ref = (string) $this->argument('ref');
        $apply = (bool) $this->option('apply');
        $undo = (bool) $this->option('undo');

        // Cross-tenant lookup - correction commands run from the
        // central shell, no tenant is bound. Load via the booking_ref
        // (globally unique in practice) + set the tenant for the
        // activity-log read + write.
        $booking = Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('booking_ref', $ref)
            ->first();

        if ($booking === null) {
            $this->error("Booking {$ref} not found.");

            return self::FAILURE;
        }

        $tenant = $booking->tenant;
        if ($tenant === null) {
            $this->error("Booking {$ref} has no tenant - refusing to touch.");

            return self::FAILURE;
        }
        $tenant->makeCurrent();

        if ($undo) {
            return $this->undo($booking, $apply);
        }

        $activities = \App\Models\Activity::query()
            ->where('subject_type', $booking->getMorphClass())
            ->where('subject_id', $booking->id)
            ->whereIn('event', ['extended', 'post_checkin_discount_applied', 'extension_cancelled'])
            ->orderBy('id')
            ->get(['id', 'event', 'properties', 'created_at']);

        // Walk the sequence. Pairs of (extended, extension_cancelled)
        // that never reversed an interleaved post_checkin_discount_applied
        // are the leak sites; sum those amounts.
        $leaked = 0;
        $pendingDiscounts = [];
        $insideExtension = false;
        $pairsProcessed = 0;

        foreach ($activities as $act) {
            $event = (string) $act->event;
            if ($event === 'extended') {
                $insideExtension = true;
                $pendingDiscounts = [];
                continue;
            }
            if ($event === 'post_checkin_discount_applied' && $insideExtension) {
                $amt = $this->propInt($act, 'amount');
                if ($amt > 0) {
                    $pendingDiscounts[] = $amt;
                }
                continue;
            }
            if ($event === 'extension_cancelled' && $insideExtension) {
                $alreadyReversed = $this->propInt($act, 'post_extend_discount_reversed');
                foreach ($pendingDiscounts as $amt) {
                    // If the cancel already handled it (post-fix
                    // cancels stamp this) leave it alone; else it
                    // leaked past the old cancel and needs the fix.
                    if ($alreadyReversed <= 0) {
                        $leaked += $amt;
                    }
                }
                $pairsProcessed++;
                $insideExtension = false;
                $pendingDiscounts = [];
            }
        }

        $this->line('');
        $this->line("Booking: <info>{$booking->booking_ref}</info>");
        $this->line("Tenant : <info>{$tenant->slug}</info>");
        $this->line("Extend/cancel pairs walked: <info>{$pairsProcessed}</info>");
        $this->line('');
        $this->line('Current state:');
        $this->table(['field', 'value'], [
            ['total_amount', number_format((int) $booking->total_amount)],
            ['manual_discount_amount', number_format((int) $booking->manual_discount_amount)],
            ['balance_due', number_format((int) $booking->balance_due)],
            ['room_total', number_format((int) $booking->room_total)],
            ['nights', $booking->nights],
        ]);

        if ($leaked <= 0) {
            $this->line('No leaked post-checkin discount found. Nothing to correct.');

            return self::SUCCESS;
        }

        $newTotal = (int) $booking->total_amount + $leaked;
        $newDiscount = max(0, (int) $booking->manual_discount_amount - $leaked);
        // Deliberately DO NOT touch balance_due. ApplyPostCheckinDiscount
        // subtracted `min(balance_due, amount)` from balance_due -
        // capped at 0. Without a snapshot of the pre-discount balance
        // for each event we can't tell how much was actually taken
        // off, so adding `$leaked` blindly would over-restore for
        // already-paid bookings (the KV-XJZWA3WU incident). Leave it
        // for the operator to reconcile manually if needed.
        $newBalance = (int) $booking->balance_due;

        $this->line("Leaked ₦<comment>".number_format($leaked)."</comment> in post-checkin discount would be reversed:");
        $this->table(['field', 'before', 'after'], [
            ['total_amount', number_format((int) $booking->total_amount), number_format($newTotal)],
            ['manual_discount_amount', number_format((int) $booking->manual_discount_amount), number_format($newDiscount)],
            ['balance_due', number_format((int) $booking->balance_due), '(unchanged)'],
        ]);

        if (! $apply) {
            $this->line('');
            $this->line('<comment>Dry-run.</comment> Re-run with <info>--apply</info> to write the correction.');

            return self::SUCCESS;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $leaked, $newTotal, $newDiscount, $newBalance): void {
            $before = [
                'total_amount' => (int) $booking->total_amount,
                'manual_discount_amount' => (int) $booking->manual_discount_amount,
                'balance_due' => (int) $booking->balance_due,
            ];

            $booking->forceFill([
                'total_amount' => $newTotal,
                'manual_discount_amount' => $newDiscount,
                'balance_due' => $newBalance,
            ])->save();

            activity('booking')
                ->performedOn($booking)
                ->event('discount_correction_after_extension_cancel')
                ->withProperties([
                    'reversed_amount' => $leaked,
                    'before' => $before,
                    'after' => [
                        'total_amount' => $newTotal,
                        'manual_discount_amount' => $newDiscount,
                        'balance_due' => $newBalance,
                    ],
                    'applied_at' => Carbon::now()->toIso8601String(),
                    'command' => 'bookings:correct-discount-after-cancel',
                ])
                ->log("One-shot correction: reversed ₦".number_format($leaked)." post-checkin discount that leaked past extension cancel.");
        });

        $this->line('');
        $this->info('Correction applied.');

        return self::SUCCESS;
    }

    private function propInt(\App\Models\Activity $activity, string $key): int
    {
        $props = $activity->properties ?? collect();
        if (is_array($props)) {
            return (int) ($props[$key] ?? 0);
        }

        return (int) $props->get($key, 0);
    }

    /**
     * Roll back the most recent discount_correction_after_extension_cancel
     * on this booking using its stored `before` snapshot. Used when
     * an earlier run miscomputed the correction (e.g., the initial
     * balance_due handling that landed 2026-09-08 over-restored on
     * already-paid bookings).
     */
    private function undo(\App\Models\Booking $booking, bool $apply): int
    {
        $stamp = \App\Models\Activity::query()
            ->where('subject_type', $booking->getMorphClass())
            ->where('subject_id', $booking->id)
            ->where('event', 'discount_correction_after_extension_cancel')
            ->latest('id')
            ->first();

        if ($stamp === null) {
            $this->error('No previous correction on this booking to undo.');

            return self::FAILURE;
        }

        $props = $stamp->properties ?? collect();
        $before = is_array($props) ? ($props['before'] ?? null) : $props->get('before');
        if (! is_array($before) || $before === []) {
            $this->error('The correction stamp has no `before` snapshot - can\'t safely undo.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line("Undoing the last correction on <info>{$booking->booking_ref}</info>");
        $this->table(['field', 'current', 'restore-to'], [
            ['total_amount', number_format((int) $booking->total_amount), number_format((int) ($before['total_amount'] ?? $booking->total_amount))],
            ['manual_discount_amount', number_format((int) $booking->manual_discount_amount), number_format((int) ($before['manual_discount_amount'] ?? $booking->manual_discount_amount))],
            ['balance_due', number_format((int) $booking->balance_due), number_format((int) ($before['balance_due'] ?? $booking->balance_due))],
        ]);

        if (! $apply) {
            $this->line('');
            $this->line('<comment>Dry-run.</comment> Re-run with <info>--apply</info> to write the undo.');

            return self::SUCCESS;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $stamp, $before): void {
            $booking->forceFill([
                'total_amount'           => (int) ($before['total_amount'] ?? $booking->total_amount),
                'manual_discount_amount' => (int) ($before['manual_discount_amount'] ?? $booking->manual_discount_amount),
                'balance_due'            => (int) ($before['balance_due'] ?? $booking->balance_due),
            ])->save();

            activity('booking')
                ->performedOn($booking)
                ->event('discount_correction_undone')
                ->withProperties([
                    'undone_activity_id' => (int) $stamp->id,
                    'restored_to' => $before,
                    'applied_at' => \Illuminate\Support\Carbon::now()->toIso8601String(),
                    'command' => 'bookings:correct-discount-after-cancel --undo',
                ])
                ->log('One-shot correction undone; booking restored to pre-correction snapshot.');
        });

        $this->line('');
        $this->info('Undo applied.');

        return self::SUCCESS;
    }
}
