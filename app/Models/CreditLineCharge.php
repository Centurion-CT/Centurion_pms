<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingChargeType;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $credit_line_id
 * @property int|null $room_id
 * @property BookingChargeType $type
 * @property string $description
 * @property int $amount
 * @property int|null $added_by_operator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class CreditLineCharge extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BookingChargeType::class,
        ];
    }

    /**
     * @return BelongsTo<CreditLine, $this>
     */
    public function creditLine(): BelongsTo
    {
        return $this->belongsTo(CreditLine::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
