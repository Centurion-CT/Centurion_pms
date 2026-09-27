<?php

declare(strict_types=1);

namespace App\Services\ContentImport;

use App\Models\AiExtraction;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Extracts structured business data from a Google Business URL via the
 * Google Places API v1.
 *
 * Input: any URL that maps to a Google Place — a `/maps/place/...` URL,
 * a shortened `maps.app.goo.gl/...` link, or a bare Place ID. We use
 * the "Text Search" endpoint with the raw URL text as the query, which
 * resolves any of those forms to the top match. Cheap and legit —
 * Google's official API, ~₦2,800 per lookup (or whatever
 * config('services.content_import.usd_to_kobo') converts $0.017 to).
 *
 * Returns a structured payload the review pane can render field-by-field.
 * Photos are surfaced as URL references only, never persisted; operator
 * has to re-upload their own to avoid copyright landmines.
 *
 * If the API key isn't configured, throws a clear "not configured"
 * error that the job catches and records against the import row.
 */
class GooglePlacesExtractor
{
    public function __construct(private readonly ImportBudget $budget) {}

    /**
     * @return array{name?:string, description?:string, address?:string, city?:string, state?:string, phone?:string, rating?:float, review_count?:int, opening_hours?:list<string>, amenities?:list<string>, photo_refs?:list<string>}
     */
    public function extract(string $url, ?int $importId = null): array
    {
        $this->budget->assertWithinHardCap();

        $apiKey = (string) config('services.content_import.google_places.api_key');
        if ($apiKey === '') {
            $this->logExtraction(
                importId: $importId,
                sourceUrl: $url,
                succeeded: false,
                costKobo: 0,
                error: 'GOOGLE_PLACES_API_KEY not configured.',
            );
            throw new RuntimeException(
                'GOOGLE_PLACES_API_KEY is not set. Configure it in .env before running Google Business imports.',
            );
        }

        $response = Http::withHeaders([
            'X-Goog-Api-Key'   => $apiKey,
            'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.internationalPhoneNumber,'.
                                   'places.rating,places.userRatingCount,places.regularOpeningHours,'.
                                   'places.editorialSummary,places.addressComponents,places.photos',
        ])->timeout(20)
          ->post('https://places.googleapis.com/v1/places:searchText', [
              'textQuery' => $url,
              'pageSize'  => 1,
          ]);

        $costUsd = (float) config('services.content_import.google_places.cost_usd_per_call');
        $usdToKobo = (int) config('services.content_import.usd_to_kobo');
        $costKobo = (int) round($costUsd * $usdToKobo);

        if (! $response->successful()) {
            $this->logExtraction(
                importId: $importId,
                sourceUrl: $url,
                succeeded: false,
                costKobo: $costKobo, // Google bills even on failures
                error: 'Places API HTTP '.$response->status().': '.substr($response->body(), 0, 500),
            );
            throw new RuntimeException('Google Places API failed with HTTP '.$response->status().'.');
        }

        $place = $response->json('places.0');
        if (! is_array($place)) {
            $this->logExtraction(
                importId: $importId,
                sourceUrl: $url,
                succeeded: false,
                costKobo: $costKobo,
                error: 'No place found for URL.',
            );
            throw new RuntimeException('Google Places returned no matches for that URL.');
        }

        $this->logExtraction(
            importId: $importId,
            sourceUrl: $url,
            succeeded: true,
            costKobo: $costKobo,
            error: null,
        );

        return $this->normalise($place);
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    private function normalise(array $place): array
    {
        $out = [];
        if (isset($place['displayName']['text'])) {
            $out['name'] = (string) $place['displayName']['text'];
        }
        if (isset($place['editorialSummary']['text'])) {
            $out['description'] = (string) $place['editorialSummary']['text'];
        }
        if (isset($place['formattedAddress'])) {
            $out['address'] = (string) $place['formattedAddress'];
        }
        if (isset($place['internationalPhoneNumber'])) {
            $out['phone'] = (string) $place['internationalPhoneNumber'];
        }
        if (isset($place['rating'])) {
            $out['rating'] = (float) $place['rating'];
        }
        if (isset($place['userRatingCount'])) {
            $out['review_count'] = (int) $place['userRatingCount'];
        }
        if (! empty($place['regularOpeningHours']['weekdayDescriptions'])) {
            $out['opening_hours'] = array_values(array_map(
                fn ($h) => (string) $h,
                $place['regularOpeningHours']['weekdayDescriptions'],
            ));
        }
        foreach ($place['addressComponents'] ?? [] as $c) {
            $types = $c['types'] ?? [];
            if (in_array('locality', $types, true) && ! isset($out['city'])) {
                $out['city'] = (string) ($c['longText'] ?? '');
            }
            if (in_array('administrative_area_level_1', $types, true) && ! isset($out['state'])) {
                $out['state'] = (string) ($c['longText'] ?? '');
            }
        }
        // Photo refs surfaced as names only; the review pane renders
        // "N photos found on Google — please re-upload your own copies".
        if (! empty($place['photos'])) {
            $out['photo_refs'] = array_values(array_map(
                fn ($p) => (string) ($p['name'] ?? ''),
                array_slice($place['photos'], 0, 8),
            ));
        }

        return $out;
    }

    private function logExtraction(
        ?int $importId,
        string $sourceUrl,
        bool $succeeded,
        int $costKobo,
        ?string $error,
    ): void {
        AiExtraction::query()->create([
            'import_id'  => $importId,
            'provider'   => 'google_places',
            'model'      => null,
            'source_url' => $sourceUrl,
            'tokens_in'  => 0,
            'tokens_out' => 0,
            'cost_kobo'  => $costKobo,
            'succeeded'  => $succeeded,
            'error'      => $error,
            'ran_at'     => now(),
        ]);
    }
}
