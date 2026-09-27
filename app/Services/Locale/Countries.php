<?php

declare(strict_types=1);

namespace App\Services\Locale;

/**
 * Compact country reference for reviewer / guest surfaces.
 *
 * Not exhaustive — deliberate curation of the countries our host base
 * actually sees in reviews. Add on demand rather than shipping the
 * full ISO-3166 list (which would swell the dropdown to 250 items for
 * two rare picks).
 *
 * Flag emojis are the two regional-indicator characters for the ISO
 * alpha-2 code; the browser's font renders them. If you extend this,
 * follow the same shape: name → ['iso' => 'XX', 'flag' => '🇽🇽'].
 */
class Countries
{
    /** @var array<string, array{iso: string, flag: string}> */
    private const MAP = [
        // West Africa (heaviest concentration of Keverna hosts + guests)
        'Nigeria'                    => ['iso' => 'NG', 'flag' => '🇳🇬'],
        'Ghana'                      => ['iso' => 'GH', 'flag' => '🇬🇭'],
        'Ivory Coast'                => ['iso' => 'CI', 'flag' => '🇨🇮'],
        'Senegal'                    => ['iso' => 'SN', 'flag' => '🇸🇳'],
        'Cameroon'                   => ['iso' => 'CM', 'flag' => '🇨🇲'],
        'Benin'                      => ['iso' => 'BJ', 'flag' => '🇧🇯'],
        'Togo'                       => ['iso' => 'TG', 'flag' => '🇹🇬'],
        // Other Africa
        'South Africa'               => ['iso' => 'ZA', 'flag' => '🇿🇦'],
        'Kenya'                      => ['iso' => 'KE', 'flag' => '🇰🇪'],
        'Rwanda'                     => ['iso' => 'RW', 'flag' => '🇷🇼'],
        'Tanzania'                   => ['iso' => 'TZ', 'flag' => '🇹🇿'],
        'Uganda'                     => ['iso' => 'UG', 'flag' => '🇺🇬'],
        'Ethiopia'                   => ['iso' => 'ET', 'flag' => '🇪🇹'],
        'Egypt'                      => ['iso' => 'EG', 'flag' => '🇪🇬'],
        'Morocco'                    => ['iso' => 'MA', 'flag' => '🇲🇦'],
        'Zimbabwe'                   => ['iso' => 'ZW', 'flag' => '🇿🇼'],
        // Europe
        'United Kingdom'             => ['iso' => 'GB', 'flag' => '🇬🇧'],
        'Ireland'                    => ['iso' => 'IE', 'flag' => '🇮🇪'],
        'France'                     => ['iso' => 'FR', 'flag' => '🇫🇷'],
        'Germany'                    => ['iso' => 'DE', 'flag' => '🇩🇪'],
        'Spain'                      => ['iso' => 'ES', 'flag' => '🇪🇸'],
        'Portugal'                   => ['iso' => 'PT', 'flag' => '🇵🇹'],
        'Italy'                      => ['iso' => 'IT', 'flag' => '🇮🇹'],
        'Netherlands'                => ['iso' => 'NL', 'flag' => '🇳🇱'],
        'Belgium'                    => ['iso' => 'BE', 'flag' => '🇧🇪'],
        'Switzerland'                => ['iso' => 'CH', 'flag' => '🇨🇭'],
        'Sweden'                     => ['iso' => 'SE', 'flag' => '🇸🇪'],
        'Norway'                     => ['iso' => 'NO', 'flag' => '🇳🇴'],
        'Denmark'                    => ['iso' => 'DK', 'flag' => '🇩🇰'],
        'Finland'                    => ['iso' => 'FI', 'flag' => '🇫🇮'],
        'Poland'                     => ['iso' => 'PL', 'flag' => '🇵🇱'],
        'Turkey'                     => ['iso' => 'TR', 'flag' => '🇹🇷'],
        // Americas
        'United States'              => ['iso' => 'US', 'flag' => '🇺🇸'],
        'Canada'                     => ['iso' => 'CA', 'flag' => '🇨🇦'],
        'Mexico'                     => ['iso' => 'MX', 'flag' => '🇲🇽'],
        'Brazil'                     => ['iso' => 'BR', 'flag' => '🇧🇷'],
        'Argentina'                  => ['iso' => 'AR', 'flag' => '🇦🇷'],
        'Colombia'                   => ['iso' => 'CO', 'flag' => '🇨🇴'],
        'Chile'                      => ['iso' => 'CL', 'flag' => '🇨🇱'],
        // Middle East
        'United Arab Emirates'       => ['iso' => 'AE', 'flag' => '🇦🇪'],
        'Saudi Arabia'               => ['iso' => 'SA', 'flag' => '🇸🇦'],
        'Israel'                     => ['iso' => 'IL', 'flag' => '🇮🇱'],
        'Lebanon'                    => ['iso' => 'LB', 'flag' => '🇱🇧'],
        // Asia
        'India'                      => ['iso' => 'IN', 'flag' => '🇮🇳'],
        'China'                      => ['iso' => 'CN', 'flag' => '🇨🇳'],
        'Japan'                      => ['iso' => 'JP', 'flag' => '🇯🇵'],
        'South Korea'                => ['iso' => 'KR', 'flag' => '🇰🇷'],
        'Singapore'                  => ['iso' => 'SG', 'flag' => '🇸🇬'],
        'Malaysia'                   => ['iso' => 'MY', 'flag' => '🇲🇾'],
        'Thailand'                   => ['iso' => 'TH', 'flag' => '🇹🇭'],
        'Indonesia'                  => ['iso' => 'ID', 'flag' => '🇮🇩'],
        'Philippines'                => ['iso' => 'PH', 'flag' => '🇵🇭'],
        'Vietnam'                    => ['iso' => 'VN', 'flag' => '🇻🇳'],
        // Oceania
        'Australia'                  => ['iso' => 'AU', 'flag' => '🇦🇺'],
        'New Zealand'                => ['iso' => 'NZ', 'flag' => '🇳🇿'],
    ];

    /**
     * @return array<string, array{iso: string, flag: string}>
     */
    public static function all(): array
    {
        return self::MAP;
    }

    public static function flagFor(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $key = self::normaliseKey($name);
        foreach (self::MAP as $canonical => $info) {
            if (self::normaliseKey($canonical) === $key) {
                return $info['flag'];
            }
        }

        return null;
    }

    /**
     * "USA" / "u.k." / "  Nigeria " should all resolve to the same
     * canonical entry, even if the operator typed sloppily.
     */
    private static function normaliseKey(string $name): string
    {
        $s = trim($name);
        // Common aliases first — cheaper than fuzzy matching + covers
        // 99% of what operators type.
        $aliases = [
            'usa'         => 'united states',
            'us'          => 'united states',
            'u.s.'        => 'united states',
            'u.s.a.'      => 'united states',
            'america'     => 'united states',
            'uk'          => 'united kingdom',
            'u.k.'        => 'united kingdom',
            'britain'     => 'united kingdom',
            'england'     => 'united kingdom',
            'uae'         => 'united arab emirates',
            'south korea' => 'south korea',
            'korea'       => 'south korea',
            'cote divoire' => 'ivory coast',
            "cote d'ivoire" => 'ivory coast',
        ];
        $lc = mb_strtolower($s);
        if (isset($aliases[$lc])) {
            return $aliases[$lc];
        }

        return $lc;
    }
}
