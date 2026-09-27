<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\CustomerWalletTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Hard-delete a set of bookings for a tenant. Written for the platform
 * admin support case where a business ran self-service test check-ins
 * during onboarding, then went live with real guests, and wants the
 * synthetic ledger rows wiped so their reports are clean.
 *
 * DB cascades handle most children (payments, booking_charges,
 * booking_discounts, booking_change_requests, booking_access_tokens,
 * booking_package, booking_breakfast_services). We manually sweep the
 * rows that use nullOnDelete or polymorphic references:
 *
 *   - invoices (nullOnDelete on booking_id) → deleted; invoice_lines
 *     cascade
 *   - customer_wallet_transactions (nullOnDelete on booking_id) →
 *     deleted so the wallet ledger doesn't keep referring to a booking
 *     that no longer exists
 *   - availability_blocks (nullOnDelete on booking_id) → deleted so we
 *     don't leave phantom holds on those room-nights
 *   - Spatie activity_log rows (polymorphic subject_type/subject_id) →
 *     deleted; no FK, would linger otherwise
 *
 * **We do NOT auto-reverse wallet balances.** If a test booking was
 * paid from wallet, that debit stays put — admins should reconcile the
 * wallet separately if the test payments should be refunded. The
 * preview surfaces the affected wallet amount so the admin can decide.
 *
 * Returns a summary the UI shows in the "done" toast + audit log.
 *
 * @phpstan-type PurgeSummary array{
 *   bookings:int,
 *   payments_count:int,
 *   payments_amount:int,
 *   charges_count:int,
 *   charges_amount:int,
 *   invoices:int,
 *   wallet_transactions:int,
 *   wallet_amount:int,
 *   availability_blocks:int,
 *   activity_log:int,
 * }
 */
class PurgeTenantBookings
{
    /**
     * @param  array<int, int>  $bookingIds
     * @return PurgeSummary
     */
    public function handle(Tenant $tenant, Admin $admin, array $bookingIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $bookingIds))));
        if ($ids === []) {
            throw new RuntimeException('Pick at least one booking to purge.');
        }

        // Run inside the tenant context so BelongsToTenant models resolve
        // without needing withoutGlobalScope on every call. Cross-tenant
        // rows are impossible anyway — we key by booking_id belonging to
        // this tenant (asserted below) and every child table is
        // tenant-scoped.
        return $tenant->execute(function () use ($tenant, $admin, $ids): array {
            // Guard: every booking id must belong to this tenant. Prevents
            // a URL-tampered id from reaching across tenants.
            $realIds = Booking::query()
                ->whereIn('id', $ids)
                ->where('tenant_id', $tenant->id)
                ->pluck('id')
                ->all();

            if ($realIds === []) {
                throw new RuntimeException('None of those bookings belong to this tenant.');
            }
            if (count($realIds) !== count($ids)) {
                // Silent — we simply purge the ids we can prove belong here.
                $ids = $realIds;
            }

            // Snapshot totals BEFORE we start deleting so the returned
            // summary reflects what was removed even after the rows are
            // gone.
            $paymentsAgg = Payment::query()->whereIn('booking_id', $ids)
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as s')
                ->first();
            $chargesAgg = BookingCharge::query()->whereIn('booking_id', $ids)
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as s')
                ->first();
            $walletAgg = CustomerWalletTransaction::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereIn('booking_id', $ids)
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(ABS(amount_naira)), 0) as s')
                ->first();
            $invoicesCount = Invoice::query()->whereIn('booking_id', $ids)->count();
            $blocksCount   = AvailabilityBlock::query()->whereIn('booking_id', $ids)->count();
            $activityCount = Activity::query()
                ->where('subject_type', Booking::class)
                ->whereIn('subject_id', $ids)
                ->count();

            $summary = [
                'bookings'           => count($ids),
                'payments_count'     => (int) ($paymentsAgg->c ?? 0),
                'payments_amount'    => (int) ($paymentsAgg->s ?? 0),
                'charges_count'      => (int) ($chargesAgg->c ?? 0),
                'charges_amount'     => (int) ($chargesAgg->s ?? 0),
                'invoices'           => $invoicesCount,
                'wallet_transactions' => (int) ($walletAgg->c ?? 0),
                'wallet_amount'      => (int) ($walletAgg->s ?? 0),
                'availability_blocks' => $blocksCount,
                'activity_log'       => $activityCount,
            ];

            try {
                DB::transaction(function () use ($ids): void {
                    // Manual sweeps (nullOnDelete / polymorphic).
                    Invoice::query()->whereIn('booking_id', $ids)->delete();
                    CustomerWalletTransaction::query()
                        ->withoutGlobalScope(TenantScope::class)
                        ->whereIn('booking_id', $ids)
                        ->delete();
                    AvailabilityBlock::query()->whereIn('booking_id', $ids)->delete();

                    // Spatie activity_log has no FK — polymorphic clean up.
                    Activity::query()
                        ->where('subject_type', Booking::class)
                        ->whereIn('subject_id', $ids)
                        ->delete();

                    // Cascades handle payments, booking_charges,
                    // booking_discounts, booking_change_requests,
                    // booking_access_tokens, booking_package,
                    // booking_breakfast_services.
                    Booking::query()->whereIn('id', $ids)->delete();
                });
            } catch (Throwable $e) {
                throw new RuntimeException('Purge failed: '.$e->getMessage(), previous: $e);
            }

            activity('admin')
                ->causedBy($admin)
                ->performedOn($tenant)
                ->event('tenant_bookings_purged')
                ->withProperties($summary + ['booking_ids' => $ids])
                ->log(sprintf(
                    'Admin %s purged %d booking(s) from %s (%d payment(s) ₦%s, %d charge(s), %d wallet txn(s) ₦%s).',
                    $admin->name,
                    $summary['bookings'],
                    $tenant->slug,
                    $summary['payments_count'],
                    number_format($summary['payments_amount']),
                    $summary['charges_count'],
                    $summary['wallet_transactions'],
                    number_format($summary['wallet_amount']),
                ));

            return $summary;
        });
    }
}
