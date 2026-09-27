<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * Custom Media model that auto-stamps `tenant_id` from the parent (PRD §10).
 *
 * The parent (Room, Property, Tenant) supplies the tenant via:
 *  - $model->tenant_id when the parent itself uses BelongsToTenant
 *  - $model->id when the parent IS a Tenant
 *
 * Stamping here (not in TenantPathGenerator) keeps the database authoritative
 * — even raw SQL inserts hit the column via DEFAULT NULL + foreign key,
 * preventing un-tagged rows from existing.
 *
 * @property int|null $tenant_id
 */
class Media extends SpatieMedia
{
    protected static function booted(): void
    {
        static::creating(function (self $media): void {
            if ($media->getAttribute('tenant_id') !== null) {
                return;
            }

            $parent = $media->model;
            if ($parent instanceof Tenant) {
                $media->setAttribute('tenant_id', $parent->getKey());

                return;
            }
            if ($parent !== null && isset($parent->tenant_id)) {
                $media->setAttribute('tenant_id', (int) $parent->tenant_id);
            }
        });
    }
}
