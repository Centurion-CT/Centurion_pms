<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoomInspectionStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $room_id
 * @property int|null $room_category_id
 * @property int|null $housekeeping_checklist_id
 * @property int|null $triggered_by_booking_id
 * @property RoomInspectionStatus $status
 * @property int|null $assigned_operator_id
 * @property Carbon|null $started_at
 * @property Carbon|null $housekeeper_at
 * @property int|null $housekeeper_by_operator_id
 * @property Carbon|null $porter_at
 * @property int|null $porter_by_operator_id
 * @property Carbon|null $supervisor_at
 * @property int|null $supervisor_by_operator_id
 * @property Carbon|null $sent_back_at
 * @property int|null $sent_back_by_operator_id
 * @property list<string>|null $sent_back_reasons
 * @property string|null $sent_back_note
 * @property int $send_back_count
 * @property int $readiness_score
 * @property string|null $summary_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, RoomInspectionItem> $items
 */
class RoomInspection extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RoomInspectionStatus::class,
            'housekeeper_at' => 'datetime',
            'porter_at' => 'datetime',
            'supervisor_at' => 'datetime',
            'started_at' => 'datetime',
            'sent_back_at' => 'datetime',
            'sent_back_reasons' => 'array',
            'send_back_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function triggeredByBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'triggered_by_booking_id');
    }

    /**
     * @return HasMany<RoomInspectionItem, $this>
     */
    public function assignedOperator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'assigned_operator_id');
    }

    public function housekeeper(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'housekeeper_by_operator_id');
    }

    public function sentBackBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'sent_back_by_operator_id');
    }

    /** @return HasMany<RoomInspectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RoomInspectionItem::class);
    }
}
