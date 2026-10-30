<?php

declare(strict_types=1);

namespace App\Platform;

use App\Models\SlugRedirect;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Whether `{slug}.centurionpms.com` can be given to a new business, with
 * the sentence the address field shows. Used by the home page's live
 * check and by sign-up, which checks again inside its transaction.
 */
class BusinessAddress
{
    public const MIN = 3;

    public const MAX = 30;

    /** Lowercase letters, digits and single dashes, not at either end. */
    private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** "Grand Palace Suites Ltd" → "grand-palace-suites". */
    public static function suggestFromName(string $name): string
    {
        $name = preg_replace('/\b(the|ltd|limited|nig|nigeria|plc)\b/i', ' ', $name) ?? $name;

        return Str::limit(Str::slug($name), self::MAX, '');
    }

    public static function host(string $slug): string
    {
        return $slug.config('keverna.tenant_domain_suffix');
    }

    /**
     * @return array{slug: string, available: bool, message: string, suggestions: list<string>}
     */
    public function check(string $raw): array
    {
        $slug = strtolower(trim($raw));
        $result = fn (bool $ok, string $message, array $suggestions = []) => [
            'slug' => $slug, 'available' => $ok, 'message' => $message, 'suggestions' => $suggestions,
        ];

        if (strlen($slug) < self::MIN) {
            return $result(false, 'Use at least '.self::MIN.' characters.');
        }
        if (strlen($slug) > self::MAX) {
            return $result(false, 'Use '.self::MAX.' characters or fewer.');
        }
        if (! preg_match(self::PATTERN, $slug)) {
            return $result(false, 'Use letters, numbers and single dashes, with no dash at the start or end.');
        }
        if (in_array($slug, (array) config('keverna.reserved_slugs'), true)) {
            return $result(false, "“{$slug}” is reserved. Try another.");
        }
        if ($this->taken($slug)) {
            $suggestions = $this->suggestions($slug);

            return $result(false, self::host($slug).' is taken.'
                .($suggestions === [] ? '' : ' Try '.implode(' or ', $suggestions).'.'), $suggestions);
        }

        return $result(true, self::host($slug).' is free.');
    }

    /**
     * Taken by a business (including deleted ones, whose address stays
     * reserved) or by a recent rename that still redirects.
     */
    public function taken(string $slug): bool
    {
        return Tenant::query()->withTrashed()->where('slug', $slug)->exists()
            || SlugRedirect::query()->where('from_slug', $slug)->where('expires_at', '>', Carbon::now())->exists();
    }

    /** @return list<string> */
    private function suggestions(string $slug): array
    {
        $out = [];
        foreach (['abuja', 'lagos', 'suites', 'apartments', 'homes', 'stays'] as $word) {
            $candidate = Str::limit($slug, self::MAX - strlen($word) - 1, '').'-'.$word;
            if (! str_ends_with($slug, '-'.$word) && ! $this->taken($candidate)) {
                $out[] = $candidate;
            }
            if (count($out) === 2) {
                break;
            }
        }

        return $out;
    }
}
