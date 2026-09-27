<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Instantiate a purchase order + its lines in one transaction.
 *
 * Draft POs commit nothing to stock — they're just a promise to buy.
 * ReceivePurchaseOrder is the action that writes stock movements
 * once product actually arrives.
 */
class CreatePurchaseOrder
{
    /**
     * @param  list<array{product_id: int, quantity_ordered: int, unit_cost: int}>  $lines
     */
    public function handle(
        Supplier $supplier,
        Outlet $outlet,
        array $lines,
        ?Operator $operator = null,
        ?\DateTimeInterface $expectedAt = null,
        ?string $notes = null,
        PurchaseOrderStatus $initialStatus = PurchaseOrderStatus::Draft,
    ): PurchaseOrder {
        if ($lines === []) {
            throw new InvalidArgumentException('A purchase order needs at least one line.');
        }

        return DB::transaction(function () use ($supplier, $outlet, $lines, $operator, $expectedAt, $notes, $initialStatus): PurchaseOrder {
            $po = new PurchaseOrder;
            $po->tenant_id   = (int) $supplier->tenant_id;
            $po->supplier_id = (int) $supplier->id;
            $po->outlet_id   = (int) $outlet->id;
            $po->operator_id = $operator?->id;
            $po->status      = $initialStatus;
            $po->expected_at = $expectedAt;
            $po->notes       = $notes;
            $po->placed_at   = $initialStatus === PurchaseOrderStatus::Sent ? now() : null;
            $po->save();

            $subtotal = 0;
            foreach ($lines as $line) {
                $qty = (int) ($line['quantity_ordered'] ?? 0);
                $cost = (int) ($line['unit_cost'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $product = Product::query()->findOrFail((int) $line['product_id']);
                if ((int) $product->outlet_id !== (int) $outlet->id) {
                    throw new InvalidArgumentException("Product {$product->id} doesn't belong to outlet {$outlet->id}.");
                }

                $lineSubtotal = $qty * $cost;
                $subtotal += $lineSubtotal;

                $po->lines()->create([
                    'tenant_id'         => $po->tenant_id,
                    'product_id'        => $product->id,
                    'quantity_ordered'  => $qty,
                    'quantity_received' => 0,
                    'unit_cost'         => $cost,
                    'subtotal'          => $lineSubtotal,
                ]);
            }

            $po->subtotal = $subtotal;
            $po->total    = $subtotal + $po->tax_amount;
            $po->save();

            return $po->fresh(['lines', 'supplier', 'outlet']);
        });
    }
}
