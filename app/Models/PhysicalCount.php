<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The "balance" and "remark" cells on the daily stock sheet — one row
 * per product per day. Everything else on the sheet is derived from
 * stock_movements + Product on read.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property Carbon $count_date
 * @property int $physical_qty
 * @property string|null $remark
 * @property string|null $note
 * @property int|null $operator_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Product $product
 * @property-read Operator|null $operator
 */
class PhysicalCount extends Model
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
            'count_date'   => 'date',
            'physical_qty' => 'int',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }
}
