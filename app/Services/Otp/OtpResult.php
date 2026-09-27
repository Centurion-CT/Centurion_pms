<?php

declare(strict_types=1);

namespace App\Services\Otp;

enum OtpResult: string
{
    case Success = 'success';
    case IncorrectCode = 'incorrect_code';
    case Expired = 'expired';
    case Locked = 'locked';
    case NotFound = 'not_found';
    case AlreadyConsumed = 'already_consumed';
}
