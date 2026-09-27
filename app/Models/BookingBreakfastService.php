<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property Carbon $service_date
 * @property Carbon $served_at
 * @property int|null $served_by_operator_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BookingBreakfastService extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'served_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function servedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'served_by_operator_id');
    }
}
