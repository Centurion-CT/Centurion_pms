<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\StockRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockRequest
 */
class StockRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'outlet_id' => $this->outlet_id,
            'outlet_name' => $this->outlet?->name,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'quantity' => (int) $this->quantity,
            'approved_quantity' => $this->approved_quantity !== null ? (int) $this->approved_quantity : null,
            'status' => $this->status?->value,
            'requested_note' => $this->requested_note,
            'resolved_note' => $this->resolved_note,
            'requested_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
