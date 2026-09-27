<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Tenant admin-side delete for a single POS product. Cascade FKs
 * handle stock_movements / recipe_items / physical_counts / stock_
 * requests / purchase_order_lines / pos_sale_lines (nullOnDelete so
 * historical sales keep the row with product_id=NULL rather than
 * cascade-deleting a real sale). On-hand quantity + wallet history
 * are NOT auto-reversed - stock ledgers stay consistent with what
 * actually happened before the delete.
 *
 * Guards:
 *   - Only Manager can delete a product.
 *   - Product must belong to the operator's tenant.
 */
class DeleteProduct
{
    /**
     * @return array{
     *   product_id: int, product_name: string,
     *   movements: int, current_qty: int,
     * }
     */
    public function handle(Product $product, Operator $operator): array
    {
        if ($operator->role !== OperatorRole::Manager) {
            throw new RuntimeException('Only a Manager can delete a product.');
        }
        if ($product->tenant_id !== $operator->tenant_id) {
            throw new RuntimeException('That product belongs to a different tenant.');
        }

        $movementCount = StockMovement::query()
            ->where('product_id', $product->id)->count();
        $currentQty = (int) ($product->quantity ?? 0);

        try {
            DB::transaction(function () use ($product): void {
                $product->delete();
            });
        } catch (Throwable $e) {
            throw new RuntimeException('Delete failed: '.$e->getMessage(), previous: $e);
        }

        activity('product')
            ->causedBy($operator)
            ->event('product_deleted')
            ->withProperties([
                'product_id'    => $product->id,
                'product_name'  => (string) $product->name,
                'sku'           => (string) ($product->sku ?? ''),
                'outlet_id'     => $product->outlet_id,
                'store_id'      => $product->store_id,
                'cascaded_movements' => $movementCount,
                'quantity_at_delete' => $currentQty,
                'tenant_id'     => $product->tenant_id,
            ])
            ->log(sprintf(
                'Manager %s deleted product "%s" (%d movement(s) cascaded, qty %d at delete).',
                $operator->name,
                $product->name,
                $movementCount,
                $currentQty,
            ));

        return [
            'product_id'   => $product->id,
            'product_name' => (string) $product->name,
            'movements'    => $movementCount,
            'current_qty'  => $currentQty,
        ];
    }
}
