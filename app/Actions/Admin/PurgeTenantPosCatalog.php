<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Nuclear-option cleanup for a tenant's POS onboarding test data:
 * wipes every outlet + every product (both outlet-side and store-
 * side catalog) + everything that references them, so the property
 * can start their real reports from a blank slate.
 *
 * Sibling to {@see PurgeTenantPosSales}. Where PurgeTenantPosSales
 * targets a subset of sale ids, this action treats the entire POS
 * catalog for the tenant as the blast radius. Also includes any
 * Store rows so the master catalog is gone too (usually what you
 * want during onboarding when the store was also test data).
 *
 * Cascade shape (from the migration FKs):
 *   Outlet delete →
 *     outlet_operators, stock_requests, purchase_orders, pos_sales
 *     (→ pos_sale_lines, pos_sale_returns → return_lines),
 *     products (with outlet_id)
 *   Product delete →
 *     stock_movements, recipe_items, physical_counts,
 *     purchase_order_lines, pos_sale_lines (nullOnDelete)
 *   Store delete →
 *     products with store_id set (via cascadeOnDelete OR set null,
 *     depending on migration; safest to null them first ourselves)
 *
 * Things the FK cascade DOES NOT cover (we clean by hand):
 *   - Payment rows with pos_sale_id nullOnDelete → we DELETE outright
 *     so the tender ledger doesn't carry orphaned rows for test sales.
 *   - customer_wallet_transactions with pos_sale_id → same, DELETE.
 *   - Spatie activity_log rows for subjects we're about to delete →
 *     DELETE so the audit trail matches the DB state.
 *   - booking_charges / credit_line_charges that reference a POS sale
 *     via description match → DELETE.
 *
 * Wallet balances + booking totals are NOT auto-recomputed. The
 * summary the caller renders makes that explicit.
 *
 * @phpstan-type PurgeCatalogSummary array{
 *   outlets: int, products: int, stores: int,
 *   sales: int, sales_amount: int,
 *   lines: int, payments: int, payments_amount: int,
 *   wallet_transactions: int, wallet_amount: int,
 *   stock_movements: int,
 *   booking_charges: int, credit_line_charges: int,
 *   activity_log: int,
 * }
 */
class PurgeTenantPosCatalog
{
    /**
     * @return PurgeCatalogSummary
     */
    public function handle(Tenant $tenant, Admin $admin): array
    {
        return $tenant->execute(function () use ($tenant, $admin): array {
            $outletIds = Outlet::query()->pluck('id')->all();
            $storeIds = Store::query()->pluck('id')->all();
            $productIds = Product::query()->pluck('id')->all();
            $saleIds = PosSale::query()->pluck('id')->all();

            if ($outletIds === [] && $productIds === [] && $storeIds === []) {
                throw new RuntimeException('Tenant has no POS catalog to purge.');
            }

            // Snapshot the shape before delete so the summary is
            // truthful. Aggregates are lifted from the same tables
            // PurgeTenantPosSales uses so the two sibling actions
            // report in the same shape.
            $paymentsAgg = Payment::query()->whereIn('pos_sale_id', $saleIds)
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as s')
                ->first();
            $walletAgg = \App\Models\CustomerWalletTransaction::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereIn('pos_sale_id', $saleIds)
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(ABS(amount_naira)), 0) as s')
                ->first();
            $stockMovementCount = \App\Models\StockMovement::query()
                ->whereIn('product_id', $productIds)
                ->count();
            $lineCount = \App\Models\PosSaleLine::query()
                ->whereIn('pos_sale_id', $saleIds)
                ->count();
            $activityCount = Activity::query()
                ->where(function ($q) use ($saleIds, $outletIds, $productIds): void {
                    $q->where(fn ($w) => $w->where('subject_type', PosSale::class)
                        ->whereIn('subject_id', $saleIds))
                    ->orWhere(fn ($w) => $w->where('subject_type', Outlet::class)
                        ->whereIn('subject_id', $outletIds))
                    ->orWhere(fn ($w) => $w->where('subject_type', Product::class)
                        ->whereIn('subject_id', $productIds));
                })
                ->count();

            // Booking / credit-line charges reference sales by
            // description substring only (there's no FK), so we
            // walk each sale to build the delete set.
            $bookingChargeIds = [];
            $creditLineChargeIds = [];
            foreach (PosSale::query()->whereIn('id', $saleIds)->get() as $s) {
                $ref = (string) $s->reference;
                if ($ref === '') continue;
                if ($s->booking_id) {
                    $bookingChargeIds = array_merge($bookingChargeIds, \App\Models\BookingCharge::query()
                        ->where('booking_id', $s->booking_id)
                        ->where('description', 'like', '%'.$ref.'%')
                        ->pluck('id')->all());
                }
                if ($s->credit_line_id) {
                    $creditLineChargeIds = array_merge($creditLineChargeIds, \App\Models\CreditLineCharge::query()
                        ->where('credit_line_id', $s->credit_line_id)
                        ->where('description', 'like', '%'.$ref.'%')
                        ->pluck('id')->all());
                }
            }
            $bookingChargeIds = array_values(array_unique($bookingChargeIds));
            $creditLineChargeIds = array_values(array_unique($creditLineChargeIds));

            $summary = [
                'outlets'             => count($outletIds),
                'products'            => count($productIds),
                'stores'              => count($storeIds),
                'sales'               => count($saleIds),
                'sales_amount'        => (int) PosSale::query()->whereIn('id', $saleIds)->sum('total'),
                'lines'               => $lineCount,
                'payments'            => (int) ($paymentsAgg->c ?? 0),
                'payments_amount'     => (int) ($paymentsAgg->s ?? 0),
                'wallet_transactions' => (int) ($walletAgg->c ?? 0),
                'wallet_amount'       => (int) ($walletAgg->s ?? 0),
                'stock_movements'     => $stockMovementCount,
                'booking_charges'     => count($bookingChargeIds),
                'credit_line_charges' => count($creditLineChargeIds),
                'activity_log'        => $activityCount,
            ];

            try {
                DB::transaction(function () use (
                    $saleIds, $outletIds, $productIds, $storeIds,
                    $bookingChargeIds, $creditLineChargeIds,
                ): void {
                    // Tender ledger + wallet history first (nullOnDelete
                    // FKs, so we have to clean before the sale rows
                    // disappear or they'd become tenant-orphans).
                    Payment::query()->whereIn('pos_sale_id', $saleIds)->delete();
                    \App\Models\CustomerWalletTransaction::query()
                        ->withoutGlobalScope(TenantScope::class)
                        ->whereIn('pos_sale_id', $saleIds)
                        ->delete();
                    if ($bookingChargeIds !== []) {
                        \App\Models\BookingCharge::query()->whereIn('id', $bookingChargeIds)->delete();
                    }
                    if ($creditLineChargeIds !== []) {
                        \App\Models\CreditLineCharge::query()->whereIn('id', $creditLineChargeIds)->delete();
                    }

                    // Activity log for subjects we're wiping.
                    Activity::query()
                        ->where(function ($q) use ($saleIds, $outletIds, $productIds): void {
                            $q->where(fn ($w) => $w->where('subject_type', PosSale::class)
                                ->whereIn('subject_id', $saleIds))
                            ->orWhere(fn ($w) => $w->where('subject_type', Outlet::class)
                                ->whereIn('subject_id', $outletIds))
                            ->orWhere(fn ($w) => $w->where('subject_type', Product::class)
                                ->whereIn('subject_id', $productIds));
                        })
                        ->delete();

                    // Delete stores + outlets. Cascades pick up
                    // products / stock_movements / etc.
                    if ($outletIds !== []) {
                        Outlet::query()->whereIn('id', $outletIds)->delete();
                    }
                    if ($storeIds !== []) {
                        Store::query()->whereIn('id', $storeIds)->delete();
                    }
                    // Any orphan products with neither outlet_id nor
                    // store_id (shouldn't happen but defence in depth).
                    Product::query()->whereIn('id', $productIds)->delete();
                });
            } catch (Throwable $e) {
                throw new RuntimeException('POS catalog purge failed: '.$e->getMessage(), previous: $e);
            }

            activity('admin')
                ->causedBy($admin)
                ->performedOn($tenant)
                ->event('tenant_pos_catalog_purged')
                ->withProperties($summary)
                ->log(sprintf(
                    'Admin %s purged POS catalog for %s: %d outlet(s), %d product(s), %d store(s), %d sale(s) (₦%s).',
                    $admin->name,
                    $tenant->slug,
                    $summary['outlets'],
                    $summary['products'],
                    $summary['stores'],
                    $summary['sales'],
                    number_format($summary['sales_amount']),
                ));

            return $summary;
        });
    }
}
