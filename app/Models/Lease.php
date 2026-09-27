<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A signed residential lease contract.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $customer_id
 * @property string $reference
 * @property LeaseStatus $status
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property int $term_months
 * @property int $annual_rent_naira
 * @property int|null $monthly_rent_naira
 * @property int $deposit_naira
 * @property int $service_charge_naira
 * @property int $renewal_reminder_days
 * @property string|null $document_path
 * @property Carbon|null $signed_at
 * @property string|null $notes
 * @property-read Unit $unit
 * @property-read Customer $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LeasePayment> $payments
 */
class Lease extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return [
            'status'     => LeaseStatus::class,
            'started_at' => 'date',
            'expires_at' => 'date',
            'signed_at'  => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<LeasePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(LeasePayment::class)->orderBy('due_at');
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
     * @param  Builder<Lease>  $query
     * @return Builder<Lease>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LeaseStatus::Active->value,
            LeaseStatus::Pending->value,
        ]);
    }

    /**
     * @param  Builder<Lease>  $query
     * @return Builder<Lease>
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->where('status', LeaseStatus::Active->value)
            ->whereBetween('expires_at', [now()->startOfDay(), now()->addDays($days)]);
    }
}
