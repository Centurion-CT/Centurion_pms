<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $business_id
 * @property string $name
 * @property string|null $description
 * @property int $price
 * @property int $validity_days
 * @property bool $active
 */
class MembershipPlan extends Model
{
    use BelongsToTenant;

    protected $table = 'membership_plans';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price'         => 'int',
            'validity_days' => 'int',
            'active'        => 'bool',
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
     * @return HasMany<MembershipSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(MembershipSubscription::class, 'plan_id');
    }
}
