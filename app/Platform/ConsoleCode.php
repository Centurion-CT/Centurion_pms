<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The 6-digit code a console admin signs in with (docs/MULTITENANCY.md,
 * step 7). Same rules as the sign-up code, kept apart in the cache.
 */
class ConsoleCode extends SignUpCode
{
    protected const PREFIX = 'console-code:';
}
