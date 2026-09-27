<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property int|null $room_category_id
 * @property string|null $label
 * @property int $rate_ngn
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property list<string>|null $devices
 * @property list<string>|null $referrers
 * @property string|null $time_start
 * @property string|null $time_end
 * @property int $priority
 * @property bool $is_active
 * @property int|null $created_by_operator_id
 * @property-read Property $property
 * @property-read RoomCategory|null $category
 */
class RateRule extends Model
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
            'starts_on' => 'date',
            'ends_on'   => 'date',
            'devices'   => 'array',
            'referrers' => 'array',
            'is_active' => 'boolean',
            'priority'  => 'integer',
            'rate_ngn'  => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    /**
     * Active rules on a given calendar day (inclusive on both ends).
     *
     * @param  Builder<RateRule>  $query
     * @return Builder<RateRule>
     */
    public function scopeActiveOn(Builder $query, Carbon $day): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', $day->toDateString())
            ->whereDate('ends_on', '>=', $day->toDateString());
    }
}
