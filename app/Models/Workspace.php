<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceType;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bookable workspace unit (hot desk pool / dedicated desk / office / room).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property string $slug
 * @property string $name
 * @property WorkspaceType $type
 * @property int $capacity
 * @property string|null $description
 * @property int|null $hourly_rate_naira
 * @property int|null $daily_rate_naira
 * @property string|null $photo_path
 * @property bool $is_active
 * @property-read Tenant $tenant
 * @property-read Property $property
 */
class Workspace extends Model
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
            'type'      => WorkspaceType::class,
            'is_active' => 'bool',
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
     * @return HasMany<WorkspacePlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(WorkspacePlan::class);
    }

    /**
     * @return HasMany<DeskBooking, $this>
     */
    public function deskBookings(): HasMany
    {
        return $this->hasMany(DeskBooking::class);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @param  Builder<Workspace>  $query
     * @return Builder<Workspace>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Best-effort capacity snapshot for the landing + browse cards.
     *
     * The denominator is the workspace's static `capacity` (how many
     * seats / desks the space holds). The numerator is currently-used
     * inventory — active or pending memberships pinned to *this*
     * workspace.
     *
     * Hot-desk memberships are often floating (no workspace_id), so a
     * Hot Desk pool with floating memberships reports `total=capacity,
     * taken=0` until the operator starts pinning members. That's the
     * least surprising display: it never under-reports capacity, and
     * the "Fully subscribed" badge only fires when explicit pins fill
     * the pool.
     *
     * @return array{total: int, available: int, status: string}
     *   status ∈ {'available','limited','full'}
     */
    public function availabilitySummary(): array
    {
        $total = (int) $this->capacity;

        $taken = $this->memberships()
            ->whereIn('status', [
                MembershipStatus::Active->value,
                MembershipStatus::Pending->value,
            ])
            ->count();

        $available = max(0, $total - $taken);

        $status = match (true) {
            $available === 0     => 'full',
            $available <= 2      => 'limited',
            default              => 'available',
        };

        return [
            'total'     => $total,
            'available' => $available,
            'status'    => $status,
        ];
    }

    /**
     * Human-readable noun for what one unit of inventory is on this
     * workspace — drives the badge ("1 seat left" vs "1 office left").
     */
    public function unitNoun(int $count = 1): string
    {
        $base = match ($this->type) {
            WorkspaceType::HotDesk, WorkspaceType::DedicatedDesk => 'seat',
            WorkspaceType::PrivateOffice => 'office',
            WorkspaceType::MeetingRoom => 'slot',
        };

        return $count === 1 ? $base : $base.'s';
    }
}
