<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GuestRequestKind;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property int $room_id
 * @property GuestRequestKind $kind
 * @property string $status
 * @property string|null $notes
 * @property array<string,mixed>|null $body
 * @property int|null $resolved_by_operator_id
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class GuestRequest extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => GuestRequestKind::class,
            'body' => 'array',
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

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'resolved_by_operator_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GuestRequestMessage::class);
    }
}
