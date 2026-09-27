<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditLineStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property string $name
 * @property CreditLineStatus $status
 * @property int|null $spending_limit
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class CreditLine extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CreditLineStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Rooms attached to this credit line — the "which rooms count for
     * charges on this tab" list.
     *
     * @return BelongsToMany<Room, $this>
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'credit_line_room')
            ->withPivot('attached_at');
    }

    /**
     * @return HasMany<CreditLineCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(CreditLineCharge::class);
    }

    /**
     * @return HasMany<CreditLineSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(CreditLineSettlement::class);
    }

    /**
     * Running totals — settled from summed rows so a single query
     * covers the report card at the top of the detail view. Both
     * charges + settlements need to be loaded (or summable) for these
     * to be cheap; call ->loadSum() upstream when rendering lists.
     */
    public function chargedTotal(): int
    {
        return (int) $this->charges()->sum('amount');
    }

    public function settledTotal(): int
    {
        return (int) $this->settlements()->sum('amount');
    }

    public function outstandingTotal(): int
    {
        return max(0, $this->chargedTotal() - $this->settledTotal());
    }

    /**
     * True when the running charged total (before settlement) exceeds
     * the operator-set soft cap. The cap only warns; it doesn't block
     * new charges — the operator decides.
     */
    public function isOverLimit(): bool
    {
        if ($this->spending_limit === null) {
            return false;
        }

        return $this->chargedTotal() > (int) $this->spending_limit;
    }

    /**
     * @param  Builder<CreditLine>  $q
     * @return Builder<CreditLine>
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', CreditLineStatus::Active->value);
    }
}
