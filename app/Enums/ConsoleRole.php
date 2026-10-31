<?php

declare(strict_types=1);

namespace App\Enums;

/** What a console user may do (docs/MULTITENANCY.md, step 7). */
enum ConsoleRole: string
{
    /** Pause and resume businesses, and add, change and remove console users. */
    case Owner = 'owner';

    /** Pause and resume businesses. */
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
        };
    }
}
