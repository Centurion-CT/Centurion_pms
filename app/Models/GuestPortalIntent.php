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
 * @property int|null $payment_id
 * @property string $purpose
 * @property string $status
 * @property array<string,mixed>|null $payload
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $error_message
 */
class GuestPortalIntent extends Model
{
    use BelongsToTenant;

    public const PURPOSE_EXTEND = 'extend';
    public const PURPOSE_ORDER = 'order';
    public const PURPOSE_RESERVE = 'reserve';
    public const PURPOSE_BALANCE = 'balance';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
