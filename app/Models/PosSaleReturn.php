<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $pos_sale_id
 * @property int|null $operator_id
 * @property int $refund_amount
 * @property string $refund_method
 * @property string $reason
 * @property Carbon|null $refunded_at
 * @property-read PosSale $sale
 * @property-read Operator|null $operator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PosSaleReturnLine> $lines
 */
class PosSaleReturn extends Model
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
            'refund_amount' => 'int',
            'refunded_at'   => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PosSale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /**
     * @return HasMany<PosSaleReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PosSaleReturnLine::class);
    }
}
