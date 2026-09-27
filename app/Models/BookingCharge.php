<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingChargeType;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single extra charge attached to a booking — extension nights,
 * room-service, damage, etc. The booking's outstanding balance at
 * checkout = balance_due + sum(unsettled charges).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property int|null $created_by_operator_id
 * @property BookingChargeType $type
 * @property string $description
 * @property int $amount
 * @property bool $settled
 * @property Carbon|null $settled_at
 * @property string|null $settled_method
 */
class BookingCharge extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type'        => BookingChargeType::class,
            'settled'     => 'bool',
            'amount'      => 'int',
            'settled_at'  => 'datetime',
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
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'created_by_operator_id');
    }

    /**
     * @param  Builder<BookingCharge>  $query
     * @return Builder<BookingCharge>
     */
    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->where('settled', false);
    }
}
