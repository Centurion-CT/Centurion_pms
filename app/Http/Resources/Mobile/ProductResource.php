<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (int) $this->price,
            'track_stock' => (bool) $this->track_stock,
            'quantity_on_hand' => (int) $this->quantity_on_hand,
            'low_stock' => $this->track_stock
                && $this->quantity_on_hand <= $this->low_stock_threshold,
            'active' => (bool) $this->active,
        ];
    }
}
