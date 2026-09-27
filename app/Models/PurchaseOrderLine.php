<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property int $quantity_ordered
 * @property int $quantity_received
 * @property int $unit_cost
 * @property int $subtotal
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Product $product
 */
class PurchaseOrderLine extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_ordered'  => 'int',
            'quantity_received' => 'int',
            'unit_cost'         => 'int',
            'subtotal'          => 'int',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function outstanding(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }
}
