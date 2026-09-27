<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A residential rental unit — flat, house, single-room apartment.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property string $slug
 * @property string $name
 * @property string $address
 * @property string|null $city
 * @property string|null $state
 * @property int $bedrooms
 * @property int $bathrooms
 * @property int|null $size_sqm
 * @property int|null $monthly_rent_naira
 * @property int $annual_rent_naira
 * @property int $deposit_naira
 * @property int $service_charge_annual_naira
 * @property string|null $description
 * @property array<int,string>|null $amenities
 * @property string|null $photo_path
 * @property UnitStatus $status
 * @property-read Tenant $tenant
 * @property-read Property $property
 */
class Unit extends Model
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
            'status'    => UnitStatus::class,
            'amenities' => 'array',
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
     * @return HasMany<Lease, $this>
     */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function activeLease(): ?Lease
    {
        return $this->leases()
            ->whereIn('status', ['active', 'pending'])
            ->latest('started_at')
            ->first();
    }

    /**
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', UnitStatus::Available->value);
    }

    /**
     * Availability snapshot for the landing + browse cards.
     *
     * - Available units → status 'available'
     * - Leased units → status 'leased' + the lease's expires_at as
     *   the "next available" date so prospects can plan a viewing
     *   for when the unit reopens
     * - Maintenance / archived → status 'unavailable'
     *
     * @return array{status: string, label: string, available_from: ?Carbon}
     *   status ∈ {'available','leased','unavailable'}
     */
    public function availabilitySummary(): array
    {
        if ($this->status === UnitStatus::Available) {
            return [
                'status'         => 'available',
                'label'          => 'Available now',
                'available_from' => null,
            ];
        }

        if ($this->status === UnitStatus::Leased) {
            $nextFree = $this->leases()
                ->whereIn('status', [LeaseStatus::Active->value, LeaseStatus::Pending->value])
                ->max('expires_at');

            $nextFree = $nextFree !== null ? Carbon::parse($nextFree) : null;

            return [
                'status'         => 'leased',
                'label'          => $nextFree !== null
                    ? 'Leased — next available '.$nextFree->format('M Y')
                    : 'Currently leased',
                'available_from' => $nextFree,
            ];
        }

        return [
            'status'         => 'unavailable',
            'label'          => $this->status->label(),
            'available_from' => null,
        ];
    }
}
