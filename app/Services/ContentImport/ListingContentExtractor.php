<?php

declare(strict_types=1);

namespace App\Services\ContentImport;

use App\Models\AiExtraction;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Fetches a public listing page (Booking.com / Airbnb) and asks Claude
 * Sonnet 4.6 to extract structured content into a fixed JSON schema.
 *
 * Design constraints:
 *   1. One-shot per property, per URL. No cron, no re-fetch.
 *   2. HTML is capped at config('services.content_import.max_html_bytes')
 *      (default 250KB) to protect against pathological pages inflating
 *      LLM token spend.
 *   3. The system prompt is CONSTANT across all extractions, so it
 *      lands under a `cache_control` breakpoint and Sonnet's prompt
 *      cache reads it at ~90% off after the first call. The URL and
 *      HTML body are the only per-request variables and go after the
 *      breakpoint. Every subsequent call in the same 5-minute window
 *      hits the cache — see shared/prompt-caching.md.
 *   4. Adaptive thinking with medium effort — Sonnet 4.6's sweet spot
 *      for structured extraction: enough thinking to disambiguate,
 *      not enough to burn tokens.
 *   5. Response format is enforced via json_schema (Structured Outputs)
 *      so the returned shape is guaranteed parseable.
 *   6. Never persists or previews source-page photos (copyright).
 */
class ListingContentExtractor
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'description'    => ['type' => 'string'],
            'amenities'      => ['type' => 'array', 'items' => ['type' => 'string']],
            'house_rules'    => ['type' => 'array', 'items' => ['type' => 'string']],
            'check_in_time'  => ['type' => 'string'],
            'check_out_time' => ['type' => 'string'],
            'rating'         => ['type' => 'number'],
            'review_count'   => ['type' => 'integer'],
        ],
        'required' => ['description', 'amenities', 'house_rules', 'check_in_time', 'check_out_time', 'rating', 'review_count'],
    ];

    private const SYSTEM_PROMPT = <<<TXT
You extract structured hospitality-listing content from raw HTML for a property-management platform. Given the HTML of a Booking.com or Airbnb listing page, return ONLY the fields in the response schema. Rules:

- description: 2-4 sentences summarising the property's positioning and stand-out features, in the property's own voice if quoted, else neutral third-person. Never invent details not present on the page.
- amenities: canonical short strings ("Free Wi-Fi", "Air conditioning", "Free parking"). Deduplicate. Cap at 20.
- house_rules: verbatim short strings from the page's rules section ("No smoking", "No parties", "Pets allowed").
- check_in_time / check_out_time: local 24h format "HH:MM" if the page states it, else empty string.
- rating: numeric overall rating 0-10 scale (Booking) or 0-5 scale (Airbnb), converted to 0-5 by dividing by 2 if source is 0-10. If unclear, 0.
- review_count: integer count of reviews if visible; else 0.

NEVER include prices, availability, photo URLs, or personal reviewer names. NEVER guess data that isn't on the page — prefer empty strings / empty arrays over invention.
TXT;

    public function __construct(private readonly ImportBudget $budget) {}

    /**
     * @return array{description:string, amenities:array<int,string>, house_rules:array<int,string>, check_in_time:string, check_out_time:string, rating:float, review_count:int}
     */
    public function extract(string $url, ?int $importId = null): array
    {
        $this->budget->assertWithinHardCap();

        $apiKey = (string) config('services.content_import.anthropic.api_key');
        if ($apiKey === '') {
            $this->logFailure($importId, $url, 0, 0, 0, 'ANTHROPIC_API_KEY not configured.');
            throw new RuntimeException(
                'ANTHROPIC_API_KEY is not set. Configure it in .env before running Booking / Airbnb imports.',
            );
        }

        $html = $this->fetchHtml($url, $importId);

        return $this->askClaude($url, $html, $importId);
    }

    private function fetchHtml(string $url, ?int $importId): string
    {
        $maxBytes = (int) config('services.content_import.max_html_bytes');
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Keverna-ContentImport/1.0 (+https://keverna.com)'])
                ->get($url);
        } catch (Throwable $e) {
            $this->logFailure($importId, $url, 0, 0, 0, 'HTTP fetch failed: '.$e->getMessage());
            throw new RuntimeException('Could not fetch that URL: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $this->logFailure($importId, $url, 0, 0, 0, 'HTTP '.$response->status().' fetching URL');
            throw new RuntimeException('That URL returned HTTP '.$response->status().'.');
        }

        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            // Truncate rather than fail; the head of the page usually
            // carries the description + amenity block. Log so the admin
            // sees which pages hit this.
            $body = substr($body, 0, $maxBytes);
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function askClaude(string $url, string $html, ?int $importId): array
    {
        $baseUrl = rtrim((string) config('services.content_import.anthropic.base_url'), '/');
        $model = (string) config('services.content_import.anthropic.model');
        $apiKey = (string) config('services.content_import.anthropic.api_key');

        try {
            $response = Http::timeout(90)
                ->withHeaders([
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post($baseUrl.'/v1/messages', [
                    'model'      => $model,
                    'max_tokens' => 4096,
                    'thinking'   => ['type' => 'adaptive'],
                    'output_config' => [
                        'effort' => 'medium',
                        'format' => [
                            'type'   => 'json_schema',
                            'schema' => self::RESPONSE_SCHEMA,
                        ],
                    ],
                    // System prompt lives under a cache breakpoint — the
                    // same 700-token instruction runs on every extraction
                    // and reads at ~10% cost after the first call.
                    'system' => [[
                        'type' => 'text',
                        'text' => self::SYSTEM_PROMPT,
                        'cache_control' => ['type' => 'ephemeral'],
                    ]],
                    'messages' => [[
                        'role' => 'user',
                        'content' => [[
                            'type' => 'text',
                            'text' => "Extract structured content from this listing page.\n\nURL: {$url}\n\nHTML:\n\n".$html,
                        ]],
                    ]],
                ]);
        } catch (Throwable $e) {
            $this->logFailure($importId, $url, 0, 0, 0, 'Anthropic HTTP failed: '.$e->getMessage());
            throw new RuntimeException('Anthropic API request failed: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $errBody = substr($response->body(), 0, 500);
            $this->logFailure($importId, $url, 0, 0, 0, 'Anthropic HTTP '.$response->status().': '.$errBody);
            throw new RuntimeException('Anthropic API failed with HTTP '.$response->status().'.');
        }

        $data = $response->json();
        $tokensIn = (int) ($data['usage']['input_tokens'] ?? 0);
        $tokensOut = (int) ($data['usage']['output_tokens'] ?? 0);
        $cacheRead = (int) ($data['usage']['cache_read_input_tokens'] ?? 0);
        $costKobo = $this->costKoboFor($tokensIn, $tokensOut, $cacheRead);

        // Pull the text of the first output block — with structured
        // outputs enforced above, this is a JSON string matching our
        // schema. Any deviation is a legit error worth logging.
        $rawJson = $data['content'][0]['text'] ?? null;
        if (! is_string($rawJson)) {
            $this->logFailure($importId, $url, $tokensIn, $tokensOut, $costKobo, 'Missing text block in Claude response.');
            throw new RuntimeException('Anthropic returned no text block.');
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rawJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->logFailure($importId, $url, $tokensIn, $tokensOut, $costKobo, 'JSON decode failed: '.$e->getMessage());
            throw new RuntimeException('Anthropic returned invalid JSON.');
        }

        AiExtraction::query()->create([
            'import_id'         => $importId,
            'provider'          => 'anthropic_claude',
            'model'             => $model,
            'source_url'        => $url,
            'tokens_in'         => $tokensIn,
            'tokens_out'        => $tokensOut,
            'cache_read_tokens' => $cacheRead,
            'cost_kobo'         => $costKobo,
            'succeeded'         => true,
            'error'             => null,
            'ran_at'            => now(),
        ]);

        return $decoded;
    }

    /**
     * Sonnet 4.6 pricing: $3/M input, $15/M output, cache reads ~$0.30/M.
     * Kobo = 100ths of a naira. USD → kobo via services.usd_to_kobo.
     */
    private function costKoboFor(int $tokensIn, int $tokensOut, int $cacheRead): int
    {
        $usdToKobo = (int) config('services.content_import.usd_to_kobo');
        $usd = ($tokensIn * 3 / 1_000_000)
             + ($tokensOut * 15 / 1_000_000)
             + ($cacheRead * 0.3 / 1_000_000);

        return (int) round($usd * $usdToKobo);
    }

    private function logFailure(
        ?int $importId,
        string $url,
        int $tokensIn,
        int $tokensOut,
        int $costKobo,
        string $error,
    ): void {
        AiExtraction::query()->create([
            'import_id'  => $importId,
            'provider'   => 'anthropic_claude',
            'model'      => (string) config('services.content_import.anthropic.model'),
            'source_url' => $url,
            'tokens_in'  => $tokensIn,
            'tokens_out' => $tokensOut,
            'cost_kobo'  => $costKobo,
            'succeeded'  => false,
            'error'      => $error,
            'ran_at'     => now(),
        ]);
    }
}
