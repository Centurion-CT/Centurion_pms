<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AvailabilityBlockType;
use App\Multitenancy\BelongsToTenant;
use Database\Factories\AvailabilityBlockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $room_id
 * @property Carbon $date
 * @property AvailabilityBlockType $type
 * @property string|null $reason
 * @property int|null $booking_id
 * @property-read Tenant $tenant
 * @property-read Room $room
 */
class AvailabilityBlock extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AvailabilityBlockFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => AvailabilityBlockType::class,
        ];
    }

    protected static function newFactory(): AvailabilityBlockFactory
    {
        return AvailabilityBlockFactory::new();
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
