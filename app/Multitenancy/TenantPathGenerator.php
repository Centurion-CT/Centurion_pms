<?php

declare(strict_types=1);

namespace App\Multitenancy;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Stamps `tenant_{id}/` into every media path on R2 (PRD §10 isolation).
 *
 *   tenant_42/19/photo.jpg
 *   tenant_42/19/conversions/photo-thumb.jpg
 *   tenant_42/19/responsive-images/photo-medium.jpg
 *
 * Falls back to `central/{id}/` for media owned directly by non-tenant
 * entities (admin uploads, system assets) — never seen in v1 but keeps the
 * path generator total.
 */
class TenantPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->prefix($media).$media->id.'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->prefix($media).$media->id.'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->prefix($media).$media->id.'/responsive-images/';
    }

    private function prefix(Media $media): string
    {
        $tenantId = $media->tenant_id ?? null;

        return $tenantId === null ? 'central/' : "tenant_{$tenantId}/";
    }
}
