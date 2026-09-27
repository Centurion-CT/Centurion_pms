<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Models\BookingCharge;
use App\Models\CreditLineCharge;
use App\Models\CustomerWalletTransaction;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Sibling to {@see PurgeTenantBookings}, for the POS-sale side of the
 * onboarding-test-cleanup story: a property runs a handful of dummy
 * sales while learning the POS, then wants those synthetic ledger
 * rows wiped before their real reports start.
 *
 * DB cascades sweep pos_sale_lines + pos_sale_returns +
 * pos_sale_return_lines. Everything else in the POS blast radius uses
 * nullOnDelete FKs or polymorphic references, so we drop them by
 * hand:
 *
 *   - payments (nullOnDelete on pos_sale_id) → delete the whole row
 *     for test data, otherwise you'd orphan the tender ledger
 *   - customer_wallet_transactions (nullOnDelete on pos_sale_id) →
 *     delete
 *   - stock_movements (polymorphic source_type=PosSale) → delete
 *   - booking_charges tied to this sale (matched by booking_id +
 *     description LIKE reference; the "charge to room" path writes
 *     a BookingCharge that has no FK back to the sale) → delete
 *   - credit_line_charges (same pattern for credit-line tender) →
 *     delete
 *   - Spatie activity_log rows (polymorphic subject_type=PosSale) →
 *     delete
 *
 * **Stock levels + wallet balances are NOT auto-reversed.** We
 * delete the movement / txn rows, but the derived on-hand quantity
 * and wallet balance stay as they are — reconciliation is up to the
 * admin. The preview in the modal surfaces the amounts so the
 * decision is explicit.
 *
 * @phpstan-type PurgePosSummary array{
 *   sales:int,
 *   sales_amount:int,
 *   lines:int,
 *   payments:int,
 *   payments_amount:int,
 *   wallet_transactions:int,
 *   wallet_amount:int,
 *   stock_movements:int,
 *   booking_charges:int,
 *   credit_line_charges:int,
 *   activity_log:int,
 * }
 */
class PurgeTenantPosSales
{
    /**
     * @param  array<int, int>  $saleIds
     * @return PurgePosSummary
     */
    public function handle(Tenant $tenant, Admin $admin, array $saleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $saleIds))));
        if ($ids === []) {
            throw new RuntimeException('Pick at least one POS sale to purge.');
        }

        return $tenant->execute(function () use ($tenant, $admin, $ids): array {
            // Guard: every id must belong to this tenant.
            $sales = PosSale::query()
                ->whereIn('id', $ids)
                ->where('tenant_id', $tenant->id)
                ->get();

            if ($sales->isEmpty()) {
                throw new RuntimeException('None of those sales belong to this tenant.');
            }
            $ids = $sales->pluck('id')->all();

            // Snapshot before delete so the summary reflects reality.
            $paymentsAgg = Payment::query()->whereIn('pos_sale_id', $ids)
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as s')
                ->first();
            $walletAgg = CustomerWalletTransaction::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereIn('pos_sale_id', $ids)
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(ABS(amount_naira)), 0) as s')
                ->first();
            $stockCount = StockMovement::query()
                ->where('source_type', PosSale::class)
                ->whereIn('source_id', $ids)
                ->count();
            $linesCount = \App\Models\PosSaleLine::query()
                ->whereIn('pos_sale_id', $ids)
                ->count();
            $activityCount = Activity::query()
                ->where('subject_type', PosSale::class)
                ->whereIn('subject_id', $ids)
                ->count();

            // Booking + credit-line charges are matched by reference
            // string appearing in the description (there's no FK back
            // from the charge to the sale). Only touch charges whose
            // parent record matches the sale's routing so we don't
            // stomp on someone else's row with a similar description.
            $bookingChargeIds = [];
            $creditLineChargeIds = [];
            foreach ($sales as $s) {
                $ref = (string) $s->reference;
                if ($ref === '') {
                    continue;
                }
                if ($s->booking_id) {
                    $bookingChargeIds = array_merge($bookingChargeIds, BookingCharge::query()
                        ->where('booking_id', $s->booking_id)
                        ->where('description', 'like', '%'.$ref.'%')
                        ->pluck('id')->all());
                }
                if ($s->credit_line_id) {
                    $creditLineChargeIds = array_merge($creditLineChargeIds, CreditLineCharge::query()
                        ->where('credit_line_id', $s->credit_line_id)
                        ->where('description', 'like', '%'.$ref.'%')
                        ->pluck('id')->all());
                }
            }
            $bookingChargeIds = array_values(array_unique($bookingChargeIds));
            $creditLineChargeIds = array_values(array_unique($creditLineChargeIds));

            $summary = [
                'sales'               => count($ids),
                'sales_amount'        => (int) $sales->sum('total'),
                'lines'               => $linesCount,
                'payments'            => (int) ($paymentsAgg->c ?? 0),
                'payments_amount'     => (int) ($paymentsAgg->s ?? 0),
                'wallet_transactions' => (int) ($walletAgg->c ?? 0),
                'wallet_amount'       => (int) ($walletAgg->s ?? 0),
                'stock_movements'     => $stockCount,
                'booking_charges'     => count($bookingChargeIds),
                'credit_line_charges' => count($creditLineChargeIds),
                'activity_log'        => $activityCount,
            ];

            try {
                DB::transaction(function () use ($ids, $bookingChargeIds, $creditLineChargeIds): void {
                    Payment::query()->whereIn('pos_sale_id', $ids)->delete();
                    CustomerWalletTransaction::query()
                        ->withoutGlobalScope(TenantScope::class)
                        ->whereIn('pos_sale_id', $ids)
                        ->delete();
                    StockMovement::query()
                        ->where('source_type', PosSale::class)
                        ->whereIn('source_id', $ids)
                        ->delete();
                    if ($bookingChargeIds !== []) {
                        BookingCharge::query()->whereIn('id', $bookingChargeIds)->delete();
                    }
                    if ($creditLineChargeIds !== []) {
                        CreditLineCharge::query()->whereIn('id', $creditLineChargeIds)->delete();
                    }
                    Activity::query()
                        ->where('subject_type', PosSale::class)
                        ->whereIn('subject_id', $ids)
                        ->delete();
                    // Cascades: pos_sale_lines, pos_sale_returns,
                    // pos_sale_return_lines follow via FK.
                    PosSale::query()->whereIn('id', $ids)->delete();
                });
            } catch (Throwable $e) {
                throw new RuntimeException('POS purge failed: '.$e->getMessage(), previous: $e);
            }

            activity('admin')
                ->causedBy($admin)
                ->performedOn($tenant)
                ->event('tenant_pos_sales_purged')
                ->withProperties($summary + ['sale_ids' => $ids])
                ->log(sprintf(
                    'Admin %s purged %d POS sale(s) from %s (₦%s, %d payment(s), %d wallet txn(s), %d stock movement(s)).',
                    $admin->name,
                    $summary['sales'],
                    $tenant->slug,
                    number_format($summary['sales_amount']),
                    $summary['payments'],
                    $summary['wallet_transactions'],
                    $summary['stock_movements'],
                ));

            return $summary;
        });
    }
}
