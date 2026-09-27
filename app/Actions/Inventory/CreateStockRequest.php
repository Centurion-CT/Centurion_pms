<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\StockRequestStatus;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockRequest;
use RuntimeException;

/**
 * Outlet operator files a request for a store product. The request
 * points at the STORE product (source SKU) and the target outlet;
 * the outlet-side product is resolved/auto-provisioned at approval
 * time — the store is the master catalog.
 */
class CreateStockRequest
{
    public function handle(
        Product $storeProduct,
        Outlet $outlet,
        int $quantity,
        ?Operator $requestedBy = null,
        ?string $note = null,
    ): StockRequest {
        if ($quantity <= 0) {
            throw new RuntimeException('Request quantity must be positive.');
        }
        if ($storeProduct->store_id === null) {
            throw new RuntimeException(
                "Product '{$storeProduct->name}' is not a store product — requests source from the central store.",
            );
        }
        if (! $storeProduct->track_stock) {
            throw new RuntimeException(
                "Product '{$storeProduct->name}' is not stock-tracked — no request needed.",
            );
        }
        if ((int) $storeProduct->tenant_id !== (int) $outlet->tenant_id) {
            throw new RuntimeException('Store product and target outlet belong to different tenants.');
        }

        return StockRequest::query()->create([
            'tenant_id'                => $outlet->tenant_id,
            'outlet_id'                => $outlet->id,
            'product_id'               => $storeProduct->id,
            'quantity'                 => $quantity,
            'status'                   => StockRequestStatus::Pending,
            'requested_by_operator_id' => $requestedBy?->id,
            'requested_note'           => $note !== null && $note !== '' ? $note : null,
        ]);
    }
}
