<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $corporate_reservation_id
 * @property int $room_category_id
 * @property int $rooms_count
 */
class CorporateReservationCategory extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rooms_count' => 'int',
        ];
    }

    /**
     * @return BelongsTo<CorporateReservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CorporateReservation::class, 'corporate_reservation_id');
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function roomCategory(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class);
    }
}
