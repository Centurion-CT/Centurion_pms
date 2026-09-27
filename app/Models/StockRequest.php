<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockRequestStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Outlet-initiated ask for more stock. Lifecycle:
 *   pending → approved | rejected | cancelled
 *
 * On approval the ApproveStockRequest action writes the paired ledger
 * via RecordStockIssuance and stamps `issued_pair_movement_id` with
 * the outlet-side movement so the request → issuance chain is
 * walkable.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $outlet_id
 * @property int $product_id
 * @property int $quantity
 * @property StockRequestStatus $status
 * @property int|null $approved_quantity
 * @property int|null $requested_by_operator_id
 * @property int|null $resolved_by_operator_id
 * @property string|null $requested_note
 * @property string|null $resolved_note
 * @property int|null $issued_pair_movement_id
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Outlet $outlet
 * @property-read Product $product
 * @property-read Operator|null $requestedBy
 * @property-read Operator|null $resolvedBy
 */
class StockRequest extends Model
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
            'status'            => StockRequestStatus::class,
            'quantity'          => 'int',
            'approved_quantity' => 'int',
            'resolved_at'       => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'requested_by_operator_id');
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'resolved_by_operator_id');
    }

    /**
     * @param  Builder<StockRequest>  $query
     * @return Builder<StockRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', StockRequestStatus::Pending);
    }
}
