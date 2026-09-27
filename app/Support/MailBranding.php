<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * Resolves the brand context to render at the top of a Markdown email
 * (PRD §17). Reads from the currently-bound tenant; falls back to
 * Keverna defaults so central / cross-tenant mails still render with a
 * recognisable header.
 *
 * Booking mailables bind their tenant via `makeCurrent()` before render,
 * which means queued workers also see the right brand even though jobs
 * don't carry the bound-tenant context automatically.
 */
final class MailBranding
{
    public const DEFAULT_NAME = 'Keverna';

    public const DEFAULT_COLOR = '#ADCB52';

    /**
     * @return array{name: string, color: string, logoUrl: ?string, homeUrl: string}
     */
    public static function current(): array
    {
        $tenant = Tenant::current();
        if (! $tenant instanceof Tenant) {
            return [
                'name' => self::DEFAULT_NAME,
                'color' => self::DEFAULT_COLOR,
                'logoUrl' => null,
                'homeUrl' => 'https://'.(string) config('keverna.central_domain', 'keverna.com'),
            ];
        }

        $property = $tenant->property;
        $color = $property->primary_color ?? self::DEFAULT_COLOR;
        $logoUrl = $property?->getFirstMedia('logo')?->getUrl();

        return [
            'name' => $tenant->name,
            'color' => $color,
            'logoUrl' => $logoUrl,
            'homeUrl' => 'https://'.$tenant->primaryHost(),
        ];
    }
}
