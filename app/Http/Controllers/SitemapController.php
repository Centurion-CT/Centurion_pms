<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Central marketing sitemap. Tenant-facing landing pages live on
 * per-tenant subdomains and are not included here (each tenant
 * would have its own sitemap under its own host if we ever ship
 * that surface).
 */
class SitemapController
{
    public function __invoke(): Response
    {
        $now = now()->toIso8601String();
        $base = url('/');

        $urls = [
            ['loc' => $base.'/',            'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => $base.'/stays',       'priority' => '0.95','changefreq' => 'daily'],
            ['loc' => $base.'/pricing',     'priority' => '0.9', 'changefreq' => 'monthly'],
            ['loc' => $base.'/demo',        'priority' => '0.9', 'changefreq' => 'monthly'],
            ['loc' => $base.'/api-access',  'priority' => '0.7', 'changefreq' => 'monthly'],
            ['loc' => $base.'/for-hosts',   'priority' => '0.7', 'changefreq' => 'monthly'],
        ];

        // Per-property canonical URLs. Every property that opted into
        // Keverna Stays and has active rooms gets a /stays/{slug} entry
        // so search engines can index the detail pages directly.
        // Cross-tenant read so central sitemap sees every listing.
        $properties = \App\Models\Property::query()
            ->withoutGlobalScope(\App\Multitenancy\TenantScope::class)
            ->where('is_listed_publicly', true)
            ->whereNotNull('public_slug')
            ->whereHas('rooms', fn ($r) => $r
                ->withoutGlobalScope(\App\Multitenancy\TenantScope::class)
                ->where('status', \App\Enums\RoomStatus::Active->value))
            ->get(['id', 'public_slug', 'updated_at']);

        foreach ($properties as $property) {
            $urls[] = [
                'loc'        => $base.'/stays/'.$property->public_slug,
                'priority'   => '0.85',
                'changefreq' => 'daily',
                'lastmod'    => $property->updated_at?->toIso8601String() ?? $now,
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.htmlspecialchars($u['loc']).'</loc>'."\n";
            $xml .= '    <lastmod>'.($u['lastmod'] ?? $now).'</lastmod>'."\n";
            $xml .= '    <changefreq>'.$u['changefreq'].'</changefreq>'."\n";
            $xml .= '    <priority>'.$u['priority'].'</priority>'."\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
