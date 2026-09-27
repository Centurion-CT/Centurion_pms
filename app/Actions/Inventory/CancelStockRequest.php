<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\StockRequestStatus;
use App\Models\Operator;
use App\Models\StockRequest;
use RuntimeException;

/**
 * Outlet withdraws a request before the store keeper has acted on it.
 * No-op if it's already been resolved.
 */
class CancelStockRequest
{
    public function handle(
        StockRequest $request,
        ?Operator $cancelledBy = null,
    ): StockRequest {
        if ($request->status !== StockRequestStatus::Pending) {
            throw new RuntimeException(
                "Cannot cancel request #{$request->id} — status is {$request->status->value}.",
            );
        }

        $request->forceFill([
            'status'                  => StockRequestStatus::Cancelled,
            'resolved_by_operator_id' => $cancelledBy?->id,
            'resolved_at'             => now(),
        ])->save();

        return $request->fresh();
    }
}
