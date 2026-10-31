<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Geocodes a nearby-place label (e.g. "Nnamdi Azikiwe International Airport")
 * into a `[lat, lng]` pair using Nominatim (OpenStreetMap). Results are
 * cached for 30 days so a repeat render doesn't hit the API + a
 * property's map plots instantly on the second visit.
 *
 * Nominatim's usage policy caps requests at 1/second per IP; we honour
 * that with a soft short-circuit (returns null on 429 or timeout) so
 * a rate-limit response doesn't take the whole page down. Operators
 * who care about exact positioning can drop `lat`/`lng` directly into
 * the `nearby_places` JSON and we'll never call the API for that
 * entry.
 */
final class NearbyPlaceGeocoder
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    private const CACHE_TTL_DAYS = 30;
    private const TIMEOUT_SECONDS = 4;

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function coordinates(string $label, ?string $context = null): ?array
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }

        $query = $context !== null && trim($context) !== ''
            ? $label.', '.trim($context)
            : $label;

        $cacheKey = 'nearby_geocode:v1:'.sha1(mb_strtolower($query));

        return Cache::remember(
            $cacheKey,
            now()->addDays(self::CACHE_TTL_DAYS),
            fn () => $this->lookup($query),
        );
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function lookup(string $query): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => config('brand.name').'/1.0 (hello@'.config('keverna.central_domain').')',
                'Accept' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->get(self::NOMINATIM_URL, [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'accept-language' => 'en',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $results = $response->json();
            if (! is_array($results) || $results === []) {
                return null;
            }

            $first = $results[0];
            if (! isset($first['lat'], $first['lon'])) {
                return null;
            }

            return [
                'lat' => (float) $first['lat'],
                'lng' => (float) $first['lon'],
            ];
        } catch (Throwable $e) {
            // Silent fail — the page still renders without the map pin.
            Log::debug('NearbyPlaceGeocoder lookup failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
