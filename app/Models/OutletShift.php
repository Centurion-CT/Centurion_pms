<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One salesperson's shift at one outlet.
 *
 * A shift bounds a window of time — opened_at when the salesperson
 * clocked in, closed_at when they clocked out (or a manager
 * force-closed them). Sales and stock movements that happened inside
 * the window get rolled up on the shift report so the accountant /
 * owner / salesperson can reconcile the till.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $outlet_id
 * @property int $operator_id
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property int|null $closed_by_operator_id
 * @property string|null $close_reason
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Outlet $outlet
 * @property-read Operator $operator
 * @property-read Operator|null $closedBy
 * @property-read Collection<int, OutletShiftStockSnapshot> $snapshots
 */
class OutletShift extends Model
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
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'closed_by_operator_id');
    }

    /**
     * @return HasMany<OutletShiftStockSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(OutletShiftStockSnapshot::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Scope: only open (unclosed) shifts.
     *
     * @param  Builder<OutletShift>  $query
     * @return Builder<OutletShift>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }
}
