<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Database\Factories\RoomPriceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Seasonal nightly rate override (PRD §6.3.2 / §9). Active for every date in
 * the inclusive range [start_date, end_date].
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $room_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $rate
 * @property string|null $label
 * @property-read Tenant $tenant
 * @property-read Room $room
 */
class RoomPrice extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RoomPriceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    protected static function newFactory(): RoomPriceFactory
    {
        return RoomPriceFactory::new();
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Overlap predicate per the standard inclusive-range overlap rule:
     *   (existing.start <= new.end) AND (existing.end >= new.start)
     *
     * @param  Builder<RoomPrice>  $query
     * @return Builder<RoomPrice>
     */
    public function scopeOverlapping(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString());
    }
}
