<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $pos_sale_return_id
 * @property int $pos_sale_line_id
 * @property int $quantity
 * @property int $unit_price
 * @property int $subtotal
 * @property-read PosSaleReturn $return
 * @property-read PosSaleLine $saleLine
 */
class PosSaleReturnLine extends Model
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
            'quantity'   => 'int',
            'unit_price' => 'int',
            'subtotal'   => 'int',
        ];
    }

    /**
     * @return BelongsTo<PosSaleReturn, $this>
     */
    public function return(): BelongsTo
    {
        return $this->belongsTo(PosSaleReturn::class, 'pos_sale_return_id');
    }

    /**
     * @return BelongsTo<PosSaleLine, $this>
     */
    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(PosSaleLine::class, 'pos_sale_line_id');
    }
}
