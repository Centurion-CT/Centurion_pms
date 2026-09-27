<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One product's opening OR closing stock reading for an OutletShift.
 * kind = 'opening' captured when the shift opens; kind = 'closing'
 * when it closes.
 *
 * We denormalise product_name and unit_price onto the snapshot so the
 * CSV export reflects what was in force at snapshot time, even if the
 * product is renamed or repriced afterward.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $outlet_shift_id
 * @property int $product_id
 * @property string $kind             'opening' | 'closing'
 * @property int $quantity_on_hand
 * @property string $product_name
 * @property int|null $unit_price
 * @property Carbon $captured_at
 */
class OutletShiftStockSnapshot extends Model
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
            'quantity_on_hand' => 'int',
            'unit_price' => 'int',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OutletShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(OutletShift::class, 'outlet_shift_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
