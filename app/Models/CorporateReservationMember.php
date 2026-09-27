<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $corporate_reservation_id
 * @property int $corporate_member_id
 * @property int|null $assigned_booking_id
 * @property Carbon|null $assigned_at
 */
class CorporateReservationMember extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
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
     * @return BelongsTo<CorporateMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(CorporateMember::class, 'corporate_member_id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'assigned_booking_id');
    }
}
