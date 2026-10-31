<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * Resolves the brand context to render at the top of a Markdown email
 * (PRD §17). Reads from the currently-bound business; mails sent with
 * no business (sign-up codes, login links) carry the software's brand.
 *
 * Booking mailables bind their tenant via `makeCurrent()` before render,
 * which means queued workers also see the right brand even though jobs
 * don't carry the bound-tenant context automatically.
 */
final class MailBranding
{
    /** Centurion's blue, for mails sent with no business bound. */
    public const DEFAULT_COLOR = '#336799';

    /**
     * @return array{name: string, color: string, logoUrl: ?string, homeUrl: string}
     */
    public static function current(): array
    {
        $tenant = Tenant::current();
        if (! $tenant instanceof Tenant) {
            return [
                'name' => (string) config('brand.name'),
                'color' => self::DEFAULT_COLOR,
                'logoUrl' => null,
                'homeUrl' => 'https://'.(string) config('keverna.central_domain'),
            ];
        }

        $property = $tenant->property;
        $color = $property->primary_color ?? self::DEFAULT_COLOR;
        $logoUrl = $tenant->logoUrl();

        return [
            'name' => $tenant->name,
            'color' => $color,
            'logoUrl' => $logoUrl,
            'homeUrl' => 'https://'.$tenant->primaryHost(),
        ];
    }
}
