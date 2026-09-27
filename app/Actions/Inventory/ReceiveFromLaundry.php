<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves N rotational units from `qty_at_laundry` back to
 * `quantity_on_hand` (clean shelf) and logs a StockMovement so the
 * audit-log timeline shows the clean-stock increase alongside its
 * source ("6 back from laundry"). Rejects moves greater than
 * qty_at_laundry.
 *
 * The paired SendToLaundry action is a pure state change (no ledger
 * row); this one is a real clean_stock increase, so the ledger row
 * matters — it's what feeds the running-balance column on the
 * inventory audit page.
 */
class ReceiveFromLaundry
{
    public function handle(
        Product $product,
        int $quantity,
        ?Operator $operator = null,
        ?string $note = null,
    ): Product {
        if (! $product->is_rotational) {
            throw new RuntimeException(
                "Cannot receive '{$product->name}' from laundry — it isn't a rotational item.",
            );
        }
        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be positive.');
        }
        if ($quantity > $product->qty_at_laundry) {
            throw new RuntimeException(sprintf(
                "Only %d %s at the laundry, cannot receive %d back.",
                $product->qty_at_laundry,
                $product->qty_at_laundry === 1 ? 'unit' : 'units',
                $quantity,
            ));
        }

        return DB::transaction(function () use ($product, $quantity, $operator, $note): Product {
            $product->qty_at_laundry -= $quantity;
            $product->quantity_on_hand += $quantity;
            $product->save();

            StockMovement::query()->create([
                'tenant_id'      => $product->tenant_id,
                'product_id'     => $product->id,
                'operator_id'    => $operator?->id,
                'reason'         => StockMovementReason::ReturnedFromLaundry,
                'delta'          => $quantity,
                'quantity_after' => $product->quantity_on_hand,
                'note'           => $note !== null && $note !== '' ? $note : null,
                'occurred_at'    => now(),
            ]);

            return $product->fresh();
        });
    }
}
