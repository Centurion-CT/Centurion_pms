<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single choke-point for every mutation of `products.quantity_on_hand`.
 * Nothing else in the codebase writes to that column — every other
 * caller (receive stock, sell, adjust, waste, transfer) routes here
 * so the ledger + materialised total stay in lock-step inside one
 * transaction.
 *
 * Signature is deliberately noun-y ("record a stock movement of
 * -N with reason=Sold") rather than verb-y ("sellStock") so the
 * caller expresses intent (reason) explicitly.
 */
class RecordStockMovement
{
    /**
     * @param  int  $delta  Signed. Negative for outflows (sold, wasted),
     *                       positive for inflows (received, adjusted-up).
     */
    public function handle(
        Product $product,
        int $delta,
        StockMovementReason $reason,
        ?Operator $operator = null,
        ?int $unitCost = null,
        ?Model $source = null,
        ?string $note = null,
        ?Carbon $occurredAt = null,
    ): StockMovement {
        if (! $product->track_stock) {
            throw new RuntimeException(
                "Cannot record stock movement on '{$product->name}' — track_stock is off.",
            );
        }
        if ($delta === 0) {
            throw new RuntimeException('Stock movement delta cannot be zero.');
        }

        return DB::transaction(function () use (
            $product, $delta, $reason, $operator, $unitCost, $source, $note, $occurredAt
        ): StockMovement {
            // Lock the product row so concurrent sales/adjustments can't
            // race each other into a wrong quantity_after snapshot.
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $newQty = (int) $product->quantity_on_hand + $delta;
            $product->forceFill(['quantity_on_hand' => $newQty])->save();

            $movement = StockMovement::query()->create([
                'tenant_id'      => $product->tenant_id,
                'product_id'     => $product->id,
                'operator_id'    => $operator?->id,
                'reason'         => $reason,
                'delta'          => $delta,
                'quantity_after' => $newQty,
                'unit_cost'      => $unitCost,
                'source_type'    => $source ? $source::class : null,
                'source_id'      => $source?->getKey(),
                'note'           => $note,
                'occurred_at'    => $occurredAt ?? Carbon::now(),
            ]);

            return $movement;
        });
    }
}
