<?php

declare(strict_types=1);

namespace App\Support\Housekeeping;

/**
 * Turns free-text checklist labels into what the staff app draws: a
 * group (Bathroom / Bedroom / Extras) and a picture key, by keyword.
 * Checklists are typed by each hotel, so this is best effort; anything
 * unmatched lands in Extras with the generic picture.
 *
 * Also maps a porter's send-back reasons onto items, so sending a room
 * back for "towels" clears only the towel ticks.
 */
final class ChecklistPictures
{
    /** @var array<string, string> group key => label, in display order */
    public const GROUPS = [
        'bathroom' => 'Bathroom',
        'bedroom' => 'Bedroom',
        'extras' => 'Extras',
    ];

    /** Porter send-back reasons, in display order. */
    public const REASONS = [
        'towels' => 'towels',
        'bathroom' => 'bathroom',
        'bed' => 'bed',
        'floor' => 'floor',
        'smell' => 'smell',
        'missing' => 'something missing',
        'other' => 'other',
    ];

    /** @var array<string, list<string>> icon => word starts, first match wins */
    private const ICONS = [
        'toilet' => ['toilet', 'wc', 'loo'],
        'shower' => ['shower', 'sink', 'basin', 'bath', 'tub', 'mirror', 'tap'],
        'towel' => ['towel', 'bathrobe', 'robe'],
        'soap' => ['soap', 'shampoo', 'gel', 'lotion', 'toiletr', 'amenit'],
        'light' => ['light', 'bulb', 'lamp', 'tv', 'remote', 'ac', 'air con', 'socket'],
        'sheets' => ['sheet', 'pillow', 'bed', 'duvet', 'linen', 'blanket', 'mattress'],
        'dust' => ['dust', 'wipe', 'polish', 'surface', 'furniture', 'table', 'desk', 'wardrobe', 'cupboard', 'cabinet'],
        'floor' => ['floor', 'mop', 'vacuum', 'sweep', 'carpet', 'rug'],
        'water' => ['water', 'bottle', 'kettle', 'cup', 'glass', 'tea', 'coffee'],
        'bin' => ['bin', 'trash', 'rubbish', 'waste', 'garbage'],
        'minibar' => ['minibar', 'mini bar', 'fridge', 'snack'],
    ];

    private const BATHROOM_ICONS = ['toilet', 'shower', 'towel', 'soap'];

    private const BEDROOM_ICONS = ['sheets', 'dust', 'floor', 'light'];

    public static function icon(string $label): string
    {
        $text = mb_strtolower($label);
        // "toilet roll" is a supply, not the toilet itself.
        if (preg_match('/\b(toilet roll|toilet paper|tissue)/u', $text) === 1) {
            return 'soap';
        }
        foreach (self::ICONS as $icon => $words) {
            foreach ($words as $word) {
                // Match at the start of a word, so "bin" isn't found in
                // "cabinet" nor "wc" in "pillowcases".
                if (preg_match('/\b'.preg_quote($word, '/').'/u', $text) === 1) {
                    return $icon;
                }
            }
        }

        return 'generic';
    }

    public static function group(string $label): string
    {
        $text = mb_strtolower($label);
        if (str_contains($text, 'bathroom')) {
            return 'bathroom';
        }
        if (str_contains($text, 'bedroom')) {
            return 'bedroom';
        }
        $icon = self::icon($label);

        return match (true) {
            in_array($icon, self::BATHROOM_ICONS, true) => 'bathroom',
            in_array($icon, self::BEDROOM_ICONS, true) => 'bedroom',
            default => 'extras',
        };
    }

    /**
     * Does a send-back reason concern this item? Reasons that name no
     * item (smell, missing, other) match nothing, so all ticks stay.
     */
    public static function reasonCovers(string $reason, string $label): bool
    {
        $icon = self::icon($label);

        return match ($reason) {
            'towels' => $icon === 'towel',
            'bathroom' => self::group($label) === 'bathroom',
            'bed' => $icon === 'sheets',
            'floor' => $icon === 'floor',
            default => false,
        };
    }

    /** @param  list<string>  $reasons */
    public static function reasonsLabel(array $reasons): string
    {
        return implode(', ', array_map(fn (string $r): string => self::REASONS[$r] ?? $r, $reasons));
    }
}
