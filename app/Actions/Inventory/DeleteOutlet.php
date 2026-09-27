<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PosSale;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Tenant admin-side delete for a POS outlet the property no longer
 * wants (usually a test outlet created during onboarding). The
 * cascade FKs in the schema sweep everything owned by the outlet:
 * products, outlet_operators, stock_requests, purchase_orders, and
 * pos_sales (which in turn cascade their lines + returns). Payments
 * on those sales use nullOnDelete on pos_sale_id, so tender rows
 * survive by design.
 *
 * Guards:
 *   - Only Manager may delete an outlet.
 *   - The outlet must belong to the operator's current tenant (the
 *     Outlet query is tenant-scoped by BelongsToTenant, so this is
 *     enforced upstream; we double-check here for defence in depth).
 *
 * The delete stamps an activity_log entry with the summary of what
 * was about to be cascaded so an accountant can audit the sweep
 * against the raw DB state before it happened.
 */
class DeleteOutlet
{
    /**
     * @return array{outlet_id: int, outlet_name: string, products: int, sales: int}
     */
    public function handle(Outlet $outlet, Operator $operator): array
    {
        if ($operator->role !== OperatorRole::Manager) {
            throw new RuntimeException('Only a Manager can delete an outlet.');
        }
        if ($outlet->tenant_id !== $operator->tenant_id) {
            throw new RuntimeException('That outlet belongs to a different tenant.');
        }

        // Snapshot the cascade shape for the audit trail.
        $productCount = \App\Models\Product::query()
            ->where('outlet_id', $outlet->id)->count();
        $saleCount = PosSale::query()
            ->where('outlet_id', $outlet->id)->count();

        try {
            DB::transaction(function () use ($outlet): void {
                // FK cascade handles products / stock_requests /
                // purchase_orders / outlet_operators / pos_sales +
                // their lines + returns.
                $outlet->delete();
            });
        } catch (Throwable $e) {
            throw new RuntimeException('Delete failed: '.$e->getMessage(), previous: $e);
        }

        activity('outlet')
            ->causedBy($operator)
            ->event('outlet_deleted')
            ->withProperties([
                'outlet_id'    => $outlet->id,
                'outlet_name'  => (string) $outlet->name,
                'cascaded_products' => $productCount,
                'cascaded_sales'    => $saleCount,
                'tenant_id'    => $outlet->tenant_id,
            ])
            ->log(sprintf(
                'Manager %s deleted outlet "%s" (%d product(s), %d sale(s) cascaded).',
                $operator->name,
                $outlet->name,
                $productCount,
                $saleCount,
            ));

        return [
            'outlet_id'   => $outlet->id,
            'outlet_name' => (string) $outlet->name,
            'products'    => $productCount,
            'sales'       => $saleCount,
        ];
    }
}
