<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\StockRequestStatus;
use App\Models\Operator;
use App\Models\Product;
use App\Models\StockRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Store keeper approves an outlet request. Composes:
 *   1. resolve the outlet-side product — if one already exists in the
 *      target outlet with the same name (case-insensitive) as the
 *      requested store product, reuse it; otherwise auto-provision a
 *      new outlet product mirroring the store product's name / SKU /
 *      cost / price.
 *   2. RecordStockIssuance to write the paired -N / +N ledger rows.
 *   3. stamp the request row as approved with resolved_at / by /
 *      approved_quantity / issued_pair_movement_id (the outlet-side
 *      movement id).
 *
 * `approvedQuantity` defaults to the requested qty; keeper can pass
 * a lower or higher number to revise before approving.
 */
class ApproveStockRequest
{
    public function __construct(
        private readonly RecordStockIssuance $issue,
    ) {}

    public function handle(
        StockRequest $request,
        Operator $approvedBy,
        ?int $approvedQuantity = null,
        ?string $note = null,
    ): StockRequest {
        if ($request->status !== StockRequestStatus::Pending) {
            throw new RuntimeException(
                "Cannot approve request #{$request->id} — status is {$request->status->value}.",
            );
        }

        $qty = $approvedQuantity ?? $request->quantity;
        if ($qty <= 0) {
            throw new RuntimeException('Approved quantity must be positive.');
        }

        $storeProduct = Product::query()->findOrFail($request->product_id);
        if ($storeProduct->store_id === null) {
            throw new RuntimeException(
                "Request #{$request->id} points at a non-store product — cannot resolve source.",
            );
        }

        return DB::transaction(function () use ($request, $storeProduct, $qty, $approvedBy, $note): StockRequest {
            $outletProduct = $this->findOrCreateOutletProduct($request, $storeProduct);

            $pair = $this->issue->handle(
                storeProduct:  $storeProduct,
                outletProduct: $outletProduct,
                quantity:      $qty,
                operator:      $approvedBy,
                note:          "Issued via stock request #{$request->id}",
            );

            $request->forceFill([
                'status'                    => StockRequestStatus::Approved,
                'approved_quantity'         => $qty,
                'resolved_by_operator_id'   => $approvedBy->id,
                'resolved_note'             => $note !== null && $note !== '' ? $note : null,
                'issued_pair_movement_id'   => $pair['outlet']->id,
                'resolved_at'               => now(),
            ])->save();

            return $request->fresh();
        });
    }

    /**
     * Find the outlet-side twin of the store product by case-insensitive
     * name match within the target outlet. Auto-provision if it doesn't
     * exist — the store is the master catalog, so a request against a
     * new SKU brings that SKU into the outlet.
     *
     * Provisioned outlet product mirrors the store's name / sku / cost
     * and adopts the store's `price` as the default sell price. Manager
     * can revise via the store product later (all outlet products for
     * that SKU could inherit — future concern).
     */
    private function findOrCreateOutletProduct(StockRequest $request, Product $storeProduct): Product
    {
        $existing = Product::query()
            ->where('tenant_id', $request->tenant_id)
            ->where('outlet_id', $request->outlet_id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($storeProduct->name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Product::query()->create([
            'tenant_id'           => $request->tenant_id,
            'outlet_id'           => $request->outlet_id,
            'store_id'            => null,
            'name'                => $storeProduct->name,
            'sku'                 => $storeProduct->sku,
            'barcode'             => $storeProduct->barcode,
            'description'         => $storeProduct->description,
            'price'               => (int) $storeProduct->price,
            'cost'                => (int) $storeProduct->cost,
            'track_stock'         => true,
            'quantity_on_hand'    => 0,
            'low_stock_threshold' => (int) $storeProduct->low_stock_threshold,
            'active'              => true,
        ]);
    }
}
