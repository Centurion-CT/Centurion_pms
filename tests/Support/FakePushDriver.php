<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Push\PushDriver;
use App\Services\Push\PushMessage;
use App\Services\Push\PushResult;

/** Records host pushes instead of sending them. */
final class FakePushDriver implements PushDriver
{
    /** @var list<array{token: string, platform: string, message: PushMessage}> */
    public array $sent = [];

    /** @var array<string, PushResult> token => forced result */
    public array $results = [];

    public bool $on = true;

    public function available(): bool
    {
        return $this->on;
    }

    public function send(PushMessage $message, string $token, string $platform = ''): PushResult
    {
        $this->sent[] = ['token' => $token, 'platform' => $platform, 'message' => $message];

        return $this->results[$token] ?? PushResult::Sent;
    }

    /** @return list<string> */
    public function tokens(?string $kind = null): array
    {
        return array_values(array_map(
            fn (array $s) => $s['token'],
            array_filter($this->sent, fn (array $s) => $kind === null || $s['message']->kind === $kind),
        ));
    }

    /** @return list<PushMessage> */
    public function messages(?string $kind = null): array
    {
        return array_values(array_map(
            fn (array $s) => $s['message'],
            array_filter($this->sent, fn (array $s) => $kind === null || $s['message']->kind === $kind),
        ));
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
