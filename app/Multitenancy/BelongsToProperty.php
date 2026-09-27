<?php

declare(strict_types=1);

namespace App\Multitenancy;

use App\Models\Property;
use App\Services\Properties\PropertyContext;

/**
 * Per-property scoping for tenant-wide "relationships" tables
 * (Customers, Wallets, Discounts, Corporates). Mirrors BelongsToTenant
 * but at the branch layer.
 *
 * Auto-stamps `property_id` on create using the operator's current
 * branch via PropertyContext. Adds `scopeForCurrentProperty()` +
 * `scopeForProperty()` so Index components can filter without
 * repeating the same where() everywhere.
 *
 * Does NOT install a global query scope (unlike BelongsToTenant's
 * TenantScope) because operators often need to look ACROSS branches
 * (portfolio-wide aggregate reports, corporate accounts shared across
 * properties, wallet balance owed by a guest who's stayed at multiple
 * branches). Index components opt in via forCurrentProperty() when
 * per-branch scoping is what the operator asked for.
 */
trait BelongsToProperty
{
    public static function bootBelongsToProperty(): void
    {
        static::creating(function ($model): void {
            if (! empty($model->property_id)) return;
            $property = app(PropertyContext::class)->current();
            if ($property !== null) {
                $model->property_id = $property->getKey();
            }
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForProperty($query, Property|int|null $property)
    {
        if ($property === null) return $query;
        $id = $property instanceof Property ? $property->getKey() : (int) $property;
        return $query->where($this->getTable().'.property_id', $id);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForCurrentProperty($query)
    {
        $current = app(PropertyContext::class)->current();
        return $current === null ? $query : $this->scopeForProperty($query, $current);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Property, $this>
     */
    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
