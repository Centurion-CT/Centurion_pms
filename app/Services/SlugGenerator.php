<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Generates URL-safe tenant slugs from a human-friendly property name and
 * resolves collisions by appending a numeric suffix (PRD §6.1.1).
 *
 *   "Grand Palace Abuja"           -> "grand-palace-abuja"
 *   "Grand Palace Abuja" (taken)   -> "grand-palace-abuja-2"
 *   "..." (and -2 taken)           -> "grand-palace-abuja-3"
 *
 * The check uses a soft-deleted-aware query so an archived tenant's slug
 * cannot be silently reclaimed.
 */
class SlugGenerator
{
    public function fromPropertyName(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'property';
        }

        if (! $this->slugExists($base)) {
            return $base;
        }

        $suffix = 2;
        while ($this->slugExists($base.'-'.$suffix)) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }

    private function slugExists(string $slug): bool
    {
        return Tenant::withTrashed()->where('slug', $slug)->exists();
    }
}
