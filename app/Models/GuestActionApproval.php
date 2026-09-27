<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property int $room_id
 * @property string $kind
 * @property array<string,mixed>|null $payload
 * @property int $coord_score          0-100 at request time
 * @property float|null $guest_latitude
 * @property float|null $guest_longitude
 * @property int|null $distance_meters
 * @property string $status            pending | approved | rejected | expired
 * @property int|null $approved_by_operator_id
 * @property string|null $operator_note
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class GuestActionApproval extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'coord_score' => 'integer',
            'guest_latitude' => 'float',
            'guest_longitude' => 'float',
            'distance_meters' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'approved_by_operator_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
