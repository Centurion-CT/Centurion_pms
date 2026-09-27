<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\StockRequestStatus;
use App\Models\Operator;
use App\Models\StockRequest;
use RuntimeException;

/**
 * Store keeper declines the request with an optional note explaining
 * why (out of stock, wrong outlet, etc). No ledger side-effects.
 */
class RejectStockRequest
{
    public function handle(
        StockRequest $request,
        Operator $rejectedBy,
        ?string $note = null,
    ): StockRequest {
        if ($request->status !== StockRequestStatus::Pending) {
            throw new RuntimeException(
                "Cannot reject request #{$request->id} — status is {$request->status->value}.",
            );
        }

        $request->forceFill([
            'status'                  => StockRequestStatus::Rejected,
            'resolved_by_operator_id' => $rejectedBy->id,
            'resolved_note'           => $note !== null && $note !== '' ? $note : null,
            'resolved_at'             => now(),
        ])->save();

        return $request->fresh();
    }
}
