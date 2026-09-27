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
 * @property int|null $property_id
 * @property string $name
 * @property string|null $description
 * @property bool $active
 */
class MembershipBusiness extends Model
{
    use BelongsToTenant;

    protected $table = 'membership_businesses';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'bool',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<MembershipPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(MembershipPlan::class, 'business_id');
    }

    /**
     * @return HasMany<MembershipSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(MembershipSubscription::class, 'business_id');
    }
}
