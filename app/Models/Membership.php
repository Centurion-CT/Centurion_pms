<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\MembershipStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An active workspace subscription tying a Customer to a Plan + Workspace.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int $workspace_plan_id
 * @property int|null $workspace_id
 * @property MembershipStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $next_renewal_at
 * @property Carbon|null $expires_at
 * @property int $amount_naira
 * @property BillingInterval $billing_interval
 * @property string|null $paystack_subscription_code
 * @property string|null $notes
 * @property-read Customer $customer
 * @property-read WorkspacePlan $plan
 * @property-read Workspace|null $workspace
 */
class Membership extends Model
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
            'status'           => MembershipStatus::class,
            'billing_interval' => BillingInterval::class,
            'started_at'       => 'date',
            'next_renewal_at'  => 'date',
            'expires_at'       => 'date',
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
     * @return BelongsTo<WorkspacePlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkspacePlan::class, 'workspace_plan_id');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
     * @param  Builder<Membership>  $query
     * @return Builder<Membership>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            MembershipStatus::Active->value,
            MembershipStatus::Pending->value,
        ]);
    }
}
