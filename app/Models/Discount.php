<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\DiscountType;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $created_by_operator_id
 * @property string $name
 * @property string|null $code
 * @property DiscountType $type
 * @property int $value
 * @property int|null $min_nights
 * @property int|null $max_nights
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property array<int, string>|null $channels
 * @property int|null $max_uses
 * @property int $used_count
 * @property bool $active
 * @property string|null $description
 */
class Discount extends Model
{
    use BelongsToTenant;
    use \App\Multitenancy\BelongsToProperty;
    use SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type'        => DiscountType::class,
            'value'       => 'int',
            'min_nights'  => 'int',
            'max_nights'  => 'int',
            'starts_on'   => 'date',
            'ends_on'     => 'date',
            'channels'    => 'array',
            'max_uses'    => 'int',
            'used_count'  => 'int',
            'active'      => 'bool',
        ];
    }

    /**
     * @return BelongsToMany<Room, $this>
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'discount_rooms');
    }

    /**
     * Category-level targeting — useful for "20% off any Deluxe stay"
     * without listing every Deluxe room individually.
     *
     * @return BelongsToMany<RoomCategory, $this>
     */
    public function roomCategories(): BelongsToMany
    {
        return $this->belongsToMany(RoomCategory::class, 'discount_room_categories');
    }

    /**
     * @return HasMany<BookingDiscount, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(BookingDiscount::class);
    }

    /**
     * True if this discount targets specific rooms (vs all rooms).
     * Distinct from `rooms()->exists()` because an empty pivot is the
     * "all rooms" sentinel — there's no row in `discount_rooms` for
     * that case.
     */
    public function targetsSpecificRooms(): bool
    {
        return $this->rooms()->exists();
    }

    /**
     * True if the discount targets specific room categories.
     */
    public function targetsSpecificRoomCategories(): bool
    {
        return $this->roomCategories()->exists();
    }

    /**
     * Apply this discount's rule against a netSubtotal, returning the
     * resolved ₦ amount off. Caps at the subtotal so we never produce
     * a negative remainder.
     */
    public function computeAmountOff(int $netSubtotal): int
    {
        if ($netSubtotal <= 0) {
            return 0;
        }

        $off = match ($this->type) {
            DiscountType::Percent => (int) floor($netSubtotal * min(100, max(0, $this->value)) / 100),
            DiscountType::Fixed   => max(0, $this->value),
        };

        return min($off, $netSubtotal);
    }

    /**
     * @param  Builder<Discount>  $query
     * @return Builder<Discount>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<Discount>  $query
     * @return Builder<Discount>
     */
    public function scopeAutoApply(Builder $query): Builder
    {
        return $query->whereNull('code');
    }

    /**
     * @param  Builder<Discount>  $query
     * @return Builder<Discount>
     */
    public function scopeWithCode(Builder $query, string $code): Builder
    {
        return $query->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($code))]);
    }

    /**
     * Human-friendly summary used in tables + the application pill.
     */
    public function discountSummary(): string
    {
        return $this->type === DiscountType::Percent
            ? $this->value.'% off'
            : '₦'.number_format($this->value).' off';
    }
}
