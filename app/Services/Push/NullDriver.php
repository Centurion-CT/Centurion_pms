<?php

declare(strict_types=1);

namespace App\Services\Push;

/** Push switched off (no FCM project configured). */
final class NullDriver implements PushDriver
{
    public function available(): bool
    {
        return false;
    }

    public function send(PushMessage $message, string $token, string $platform = ''): PushResult
    {
        return PushResult::Skipped;
    }
}
