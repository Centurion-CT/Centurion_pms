<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\PosSale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PosSale
 */
class PosSaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : collect();
        $payments = $this->relationLoaded('payments') ? $this->payments : collect();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'client_key' => $this->client_key,
            'status' => $this->status?->value,
            'outlet_id' => $this->outlet_id,
            'booking_id' => $this->booking_id,
            'customer_id' => $this->customer_id,
            'subtotal' => (int) $this->subtotal,
            'tax_amount' => (int) $this->tax_amount,
            'total' => (int) $this->total,
            'notes' => $this->notes,
            'sold_at' => $this->sold_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'lines' => $lines->map(fn ($l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'name' => $l->name,
                'quantity' => (int) $l->quantity,
                'unit_price' => (int) $l->unit_price,
                'line_total' => (int) ($l->quantity * $l->unit_price),
            ])->values(),
            'payments' => $payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => (int) $p->amount,
                'status' => $p->status?->value,
                'reference_note' => $p->reference_note,
            ])->values(),
        ];
    }
}
