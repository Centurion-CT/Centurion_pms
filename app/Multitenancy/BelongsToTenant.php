<?php

declare(strict_types=1);

namespace App\Multitenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marker trait for every tenant-owned model.
 *
 * Responsibilities:
 *  - Apply {@see TenantScope} so queries are constrained to the current tenant.
 *  - Auto-populate `tenant_id` on create from the current tenant (so app code
 *    never has to remember).
 *  - Expose the `tenant()` BelongsTo relationship for eager loading.
 *
 * Apply to: Property, Room, RoomPrice, AvailabilityBlock, Booking, Payment,
 * Subscription, Notification, BookingAccessToken, BookingChangeRequest, etc.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenant = Tenant::current();

            if ($tenant === null) {
                throw new TenantNotResolvedException(
                    'Refusing to create '.static::class.' without a current tenant. '
                    .'Set the current tenant before creating, or explicitly assign tenant_id.'
                );
            }

            $model->setAttribute('tenant_id', $tenant->getKey());
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
