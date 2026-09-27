<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeasePaymentStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One scheduled rent / deposit / service-charge invoice on a Lease.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lease_id
 * @property Carbon $due_at
 * @property string $label
 * @property int $amount_naira
 * @property LeasePaymentStatus $status
 * @property Carbon|null $paid_at
 * @property string|null $paystack_reference
 * @property string|null $notes
 * @property-read Lease $lease
 */
class LeasePayment extends Model
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
            'status'  => LeasePaymentStatus::class,
            'due_at'  => 'date',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * @param  Builder<LeasePayment>  $query
     * @return Builder<LeasePayment>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LeasePaymentStatus::Upcoming->value,
            LeasePaymentStatus::Due->value,
            LeasePaymentStatus::Overdue->value,
        ]);
    }
}
