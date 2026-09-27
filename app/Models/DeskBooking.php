<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeskBookingStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-off hourly / day-pass workspace reservation. The recurring
 * counterpart is {@see Membership}.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $workspace_id
 * @property int|null $customer_id
 * @property string $reference
 * @property Carbon $start_at
 * @property Carbon $end_at
 * @property int $amount_naira
 * @property DeskBookingStatus $status
 * @property string|null $paystack_reference
 * @property string|null $notes
 * @property-read Workspace $workspace
 * @property-read Customer|null $customer
 */
class DeskBooking extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return [
            'status'   => DeskBookingStatus::class,
            'start_at' => 'datetime',
            'end_at'   => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Corporate, $this>
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    /**
     * @return BelongsTo<CorporateMember, $this>
     */
    public function corporateMember(): BelongsTo
    {
        return $this->belongsTo(CorporateMember::class, 'corporate_member_id');
    }

    /**
     * @param  Builder<DeskBooking>  $query
     * @return Builder<DeskBooking>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('end_at', '>=', now())
            ->whereIn('status', [
                DeskBookingStatus::Pending->value,
                DeskBookingStatus::Confirmed->value,
                DeskBookingStatus::InUse->value,
            ]);
    }

    public function duration(): \Carbon\CarbonInterval
    {
        return $this->start_at->diffAsCarbonInterval($this->end_at);
    }
}
