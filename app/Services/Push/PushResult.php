<?php

declare(strict_types=1);

namespace App\Services\Push;

enum PushResult: string
{
    case Sent = 'sent';
    /** The token is dead (uninstalled app, bad token): clear it. */
    case InvalidToken = 'invalid_token';
    case Failed = 'failed';
    /** Driver switched off: nothing was attempted. */
    case Skipped = 'skipped';
}
