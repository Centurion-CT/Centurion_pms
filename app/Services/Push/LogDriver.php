<?php

declare(strict_types=1);

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;

/** Local development: writes each push to the log instead of FCM. */
final class LogDriver implements PushDriver
{
    public function available(): bool
    {
        return true;
    }

    public function send(PushMessage $message, string $token, string $platform = ''): PushResult
    {
        Log::info('host push', [
            'token' => substr($token, 0, 12).'...',
            'platform' => $platform,
            'title' => $message->title,
            'body' => $message->body,
            'data' => $message->data(),
            'urgent' => $message->urgent,
        ]);

        return PushResult::Sent;
    }
}
