<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomDomainStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Error = 'error';
}
