<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingInterval;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pricing tier for a workspace membership or one-off booking.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $workspace_id
 * @property string $name
 * @property BillingInterval $billing_interval
 * @property int $price_naira
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $member_cap
 * @property-read Workspace|null $workspace
 */
class WorkspacePlan extends Model
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
            'billing_interval' => BillingInterval::class,
            'is_active'        => 'bool',
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
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @param  Builder<WorkspacePlan>  $query
     * @return Builder<WorkspacePlan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function priceLabel(): string
    {
        return '₦'.number_format($this->price_naira).' '.$this->billing_interval->suffix();
    }
}
