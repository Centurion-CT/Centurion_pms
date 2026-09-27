<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementReason;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int|null $operator_id
 * @property StockMovementReason $reason
 * @property int $delta
 * @property int $quantity_after
 * @property int|null $unit_cost
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $note
 * @property Carbon $occurred_at
 */
class StockMovement extends Model
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
            'reason'         => StockMovementReason::class,
            'delta'          => 'int',
            'quantity_after' => 'int',
            'unit_cost'      => 'int',
            'occurred_at'    => 'datetime',
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

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
