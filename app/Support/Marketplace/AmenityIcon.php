<?php

declare(strict_types=1);

namespace App\Support\Marketplace;

/**
 * Fuzzy amenity-name to inline-SVG mapping for the Keverna Stays
 * marketplace amenities grid.
 *
 * Amenities are free-text strings the operator types into Property
 * Settings ("Wi-Fi", "WiFi", "Free WiFi", "wifi", ...) - so we
 * lowercase + substring-match against a keyword table rather than
 * demanding an exact enum. Unmatched amenities fall back to a
 * generic checkmark; the label copy is still shown verbatim so
 * an operator-written amenity like "Ocean view" still renders,
 * just with the generic icon.
 *
 * SVG paths are 24x24 viewBox, stroke-based (currentColor) so a
 * single colour token drives every amenity. Add a new match by
 * appending to the `map()` array below - order matters, first hit
 * wins so put more specific keywords ("swimming pool") before
 * short ones ("pool") if they'd otherwise collide.
 */
final class AmenityIcon
{
    /**
     * @return array{icon: string, matched: bool}
     */
    public static function iconFor(string $amenity): array
    {
        $needle = strtolower(trim($amenity));
        foreach (self::map() as [$keywords, $icon]) {
            foreach ($keywords as $kw) {
                if (str_contains($needle, $kw)) {
                    return ['icon' => $icon, 'matched' => true];
                }
            }
        }

        return ['icon' => self::fallbackIcon(), 'matched' => false];
    }

    /**
     * @return list<array{0: list<string>, 1: string}>
     */
    private static function map(): array
    {
        return [
            [['wifi', 'wi-fi', 'internet'],           self::path('M5 12.5a10 10 0 0 1 14 0M8 15.5a6 6 0 0 1 8 0M11 18.5a2 2 0 0 1 2 0')],
            [['pool', 'swim'],                        self::path('M3 18c1.5 0 1.5-1 3-1s1.5 1 3 1 1.5-1 3-1 1.5 1 3 1 1.5-1 3-1M6 15V5.5A2.5 2.5 0 0 1 8.5 3M18 15V5.5A2.5 2.5 0 0 0 15.5 3M6 10h12')],
            [['parking', 'garage', 'car park'],       self::path('M6 4h6a4 4 0 0 1 0 8H9v8M9 12v-8')],
            [['air condition', 'air-cond', 'a/c'],    self::path('M3 12h18M12 3v18M5 5l14 14M19 5L5 19')],
            [['kitchen', 'kitchenette'],              self::path('M4 8h16M6 8V4h12v4M8 8v13M16 8v13M4 21h16')],
            [['breakfast'],                           self::path('M4 12a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v0a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4zM16 8V4M12 8V4M8 8V4M4 16h16v2H4z')],
            [['restaurant', 'dining'],                self::path('M6 3v18M10 3v18M6 12h4M18 3v18M18 12s-3-1-3-4 3-5 3-5z')],
            [['bar', 'lounge'],                       self::path('M5 4h14L15 12v6h2M9 18h6M5 4l7 8')],
            [['gym', 'fitness', 'workout'],           self::path('M2 12h2M4 8v8M6 6v12M8 4v16M16 4v16M18 6v12M20 8v8M22 12h-2')],
            [['spa', 'sauna', 'massage'],             self::path('M12 2a10 10 0 0 0 0 20 10 10 0 0 0 0-20zM12 8v8M8 12h8')],
            [['room service'],                        self::path('M4 20h16M6 20V10a6 6 0 0 1 12 0v10M12 4v4M10 4h4')],
            [['laundry', 'washer', 'dryer'],          self::path('M5 4h14v16H5zM5 4v3h14V4M9 13a3 3 0 1 0 6 0 3 3 0 0 0-6 0zM8 6h.01M11 6h.01')],
            [['shuttle', 'airport pickup', 'airport transfer'], self::path('M4 12l2-6a3 3 0 0 1 3-2h6a3 3 0 0 1 3 2l2 6v6H4zM4 15h16M7 18v2M17 18v2')],
            [['tv', 'television', 'cable', 'netflix'], self::path('M3 5h18v11H3zM8 20h8M12 16v4')],
            [['balcony', 'terrace', 'patio'],         self::path('M3 21h18M3 21V10l9-6 9 6v11M8 21v-6h8v6M8 15V9M16 15V9')],
            [['sea view', 'ocean view', 'beach'],     self::path('M3 15c1.5 0 1.5 1 3 1s1.5-1 3-1 1.5 1 3 1 1.5-1 3-1 1.5 1 3 1 1.5-1 3-1M3 19c1.5 0 1.5 1 3 1s1.5-1 3-1 1.5 1 3 1 1.5-1 3-1 1.5 1 3 1 1.5-1 3-1M8 8l4-4 4 4M12 4v10')],
            [['bath', 'jacuzzi', 'hot tub'],          self::path('M4 12h16v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zM6 12V6a2 2 0 0 1 2-2h1a2 2 0 0 1 2 2M6 20l-1 2M18 20l1 2')],
            [['hot water', 'water heater'],           self::path('M12 3c0 4-4 5-4 9a4 4 0 1 0 8 0c0-4-4-5-4-9z')],
            [['generator', 'backup power', 'inverter'], self::path('M13 2L4 14h7l-1 8 9-12h-7z')],
            [['security', 'cctv', '24/7'],            self::path('M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4zM12 8v4l3 2')],
            [['elevator', 'lift'],                    self::path('M6 3h12v18H6zM10 3v18M14 3v18M9 8l1.5-1.5L12 8M9 16l1.5 1.5L12 16')],
            [['wheelchair', 'accessible', 'disability'], self::path('M12 5a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM10 7v6h6l3 7M10 13a5 5 0 1 0 5 5')],
            [['garden', 'yard', 'outdoor'],           self::path('M12 20V8M8 20V12M16 20V12M4 20h16M8 8a4 4 0 0 1 8 0M12 4v4')],
            [['workspace', 'work desk', 'workstation'], self::path('M3 12h18M5 12V6h14v6M7 12v6M17 12v6M3 20h18')],
            [['smoke', 'smoking allowed'],            self::path('M2 18h20M6 18v-3M2 15h20M18 12s0-3 2-3v9M14 6a2 2 0 0 1 2-2c1 0 1 1 2 1s2-1 2-1')],
            [['pet', 'dog', 'cat'],                   self::path('M5 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM19 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM8 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM16 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM12 21a4 4 0 0 1-4-4c0-3 4-6 4-6s4 3 4 6a4 4 0 0 1-4 4z')],
        ];
    }

    private static function path(string $d): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$d.'"/></svg>';
    }

    private static function fallbackIcon(): string
    {
        return self::path('M4 12l4 4L20 4');
    }
}
