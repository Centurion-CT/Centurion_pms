<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * Which of a business's own colour the staff sign-in pages can use.
 * Sign-up stores UNSET, so that counts as no choice, and a colour too pale
 * to carry white text is refused: the pages fall back to Centurion's.
 */
final class BusinessColor
{
    public const UNSET = '#1a1a1a';

    private const MIN_CONTRAST_ON_WHITE = 4.5;

    public static function forSignIn(?Tenant $tenant): ?string
    {
        $color = strtolower((string) $tenant?->property?->primary_color);

        return $color !== self::UNSET && self::carriesWhiteText($color) ? $color : null;
    }

    public static function carriesWhiteText(string $color): bool
    {
        if (preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            return false;
        }

        $channels = array_map(function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($color, 1), 2));

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return 1.05 / ($luminance + 0.05) >= self::MIN_CONTRAST_ON_WHITE;
    }
}
