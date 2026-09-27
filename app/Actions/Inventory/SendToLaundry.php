<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Operator;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves N rotational units from `qty_in_use` to `qty_at_laundry`.
 * Pure state change on the product row, clean_stock (quantity_on_hand)
 * is untouched, so no StockMovement is written. Rotation history is
 * reconstructible from qty_in_use / qty_at_laundry + product
 * updated_at + operator activity logs.
 *
 * Rejects non-rotational products (their linens don't come back
 * clean) and rejects moves greater than qty_in_use so an operator
 * can't over-report what was sent to the laundry.
 */
class SendToLaundry
{
    public function handle(
        Product $product,
        int $quantity,
        ?Operator $operator = null,
        ?string $note = null,
    ): Product {
        if (! $product->is_rotational) {
            throw new RuntimeException(
                "Cannot send '{$product->name}' to laundry — it isn't a rotational item.",
            );
        }
        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be positive.');
        }
        if ($quantity > $product->qty_in_use) {
            throw new RuntimeException(sprintf(
                "Only %d %s in use, cannot send %d to laundry.",
                $product->qty_in_use,
                $product->qty_in_use === 1 ? 'unit' : 'units',
                $quantity,
            ));
        }

        return DB::transaction(function () use ($product, $quantity): Product {
            $product->qty_in_use -= $quantity;
            $product->qty_at_laundry += $quantity;
            $product->save();

            return $product->fresh();
        });
    }
}
