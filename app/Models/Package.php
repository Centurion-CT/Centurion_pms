<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $created_by_operator_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_complimentary
 * @property int $price
 * @property int $max_per_booking
 * @property bool $available_during_booking
 * @property bool $available_after_booking
 * @property bool $active
 */
class Package extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_complimentary'         => 'bool',
            'price'                    => 'int',
            'max_per_booking'          => 'int',
            'available_during_booking' => 'bool',
            'available_after_booking'  => 'bool',
            'active'                   => 'bool',
        ];
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'created_by_operator_id');
    }

    /**
     * @return HasMany<BookingPackage, $this>
     */
    public function bookingPackages(): HasMany
    {
        return $this->hasMany(BookingPackage::class);
    }

    /**
     * Effective unit price the guest will be charged. Complimentary
     * packages always charge 0 regardless of the `price` column — the
     * column is preserved so an operator can flip a paid package to
     * complimentary and back without losing the original number.
     */
    public function effectivePrice(): int
    {
        return $this->is_complimentary ? 0 : (int) $this->price;
    }

    /**
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    public function scopeForBookingFlow(Builder $query): Builder
    {
        return $query->where('available_during_booking', true);
    }

    /**
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    public function scopeForPostBookingFlow(Builder $query): Builder
    {
        return $query->where('available_after_booking', true);
    }
}
