<?php

declare(strict_types=1);

namespace App\Services\Push;

/**
 * Transport for host pushes. Implementations never throw: failures come
 * back as {@see PushResult::Failed} (and are reported by the caller).
 */
interface PushDriver
{
    /** True when this driver actually delivers (or logs) messages. */
    public function available(): bool;

    /**
     * @param  string  $platform  ios | android | '' (unknown)
     */
    public function send(PushMessage $message, string $token, string $platform = ''): PushResult;
}
