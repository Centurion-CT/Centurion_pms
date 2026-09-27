<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerInteractionDirection;
use App\Enums\CustomerInteractionType;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int|null $created_by_operator_id
 * @property int|null $booking_id
 * @property CustomerInteractionType $type
 * @property CustomerInteractionDirection $direction
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $system_event
 * @property Carbon $happened_at
 */
class CustomerInteraction extends Model
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
            'type'        => CustomerInteractionType::class,
            'direction'   => CustomerInteractionDirection::class,
            'happened_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
}
