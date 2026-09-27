<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $business_id
 * @property int $plan_id
 * @property int $customer_id
 * @property string $member_ref
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property string $status
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property int|null $created_by_operator_id
 */
class MembershipSubscription extends Model
{
    use BelongsToTenant;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_EXPIRED = 'expired';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $table = 'membership_subscriptions';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at'   => 'datetime',
            'expires_at'   => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MembershipBusiness, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(MembershipBusiness::class, 'business_id');
    }

    /**
     * @return BelongsTo<MembershipPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'membership_subscription_id');
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function createdByOperator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'created_by_operator_id');
    }

    /**
     * Whether the subscription is presently live. Prefers the
     * persisted status but falls back to the date window so we
     * catch subscriptions whose expiry has passed without a cron
     * flip.
     */
    public function isCurrentlyActive(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();
        if ($this->status === self::STATUS_CANCELLED) {
            return false;
        }
        return $this->expires_at !== null && $this->expires_at->greaterThan($now);
    }

    public function daysRemaining(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        if ($this->expires_at === null || $this->expires_at->lessThanOrEqualTo($now)) {
            return 0;
        }
        return (int) ceil($now->diffInDays($this->expires_at, absolute: false));
    }

    public function displayStatus(?Carbon $now = null): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return self::STATUS_CANCELLED;
        }
        return $this->isCurrentlyActive($now)
            ? self::STATUS_ACTIVE
            : self::STATUS_EXPIRED;
    }
}
