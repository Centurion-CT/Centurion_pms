<?php

declare(strict_types=1);

namespace App\Services\Push;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Firebase Cloud Messaging HTTP v1 for Android and iOS (APNs through
 * FCM). Auth is a service-account JWT (RS256, signed here with
 * openssl_sign) exchanged for an OAuth2 access token, cached ~50 min.
 *
 * Unconfigured (no project id or unreadable credentials) = not
 * available and no HTTP at all.
 */
final class FcmDriver implements PushDriver
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public const ANDROID_CHANNEL = 'keverna_host';

    /** Kinds where only the latest unsent message matters. */
    private const COLLAPSIBLE = ['daily_summary', 'low_stock'];

    /** @var array<string, mixed>|null|false false = not parsed yet */
    private array|null|false $account = false;

    public function __construct(
        private readonly ?string $projectId,
        private readonly ?string $credentials,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('services.fcm.project_id') ?: null,
            config('services.fcm.credentials') ?: null,
        );
    }

    public function available(): bool
    {
        return $this->projectId !== null && $this->account() !== null;
    }

    public function send(PushMessage $message, string $token, string $platform = ''): PushResult
    {
        if (! $this->available() || $token === '') {
            return PushResult::Skipped;
        }

        try {
            $response = $this->post($message, $token, $this->accessToken());
            if ($response->status() === 401) {
                // Revoked / expired access token: mint a fresh one once.
                Cache::forget($this->cacheKey());
                $response = $this->post($message, $token, $this->accessToken());
            }
        } catch (Throwable $e) {
            report($e);

            return PushResult::Failed;
        }

        if ($response->successful()) {
            return PushResult::Sent;
        }

        if (self::isDeadToken($response)) {
            return PushResult::InvalidToken;
        }

        report(new RuntimeException('FCM send failed: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300)));

        return PushResult::Failed;
    }

    /**
     * The messages:send body.
     *
     * @return array<string, mixed>
     */
    public static function payload(PushMessage $message, string $token): array
    {
        $android = [
            'priority' => 'high',
            'notification' => [
                'channel_id' => self::ANDROID_CHANNEL,
                'sound' => 'default',
            ],
        ];
        $apnsHeaders = ['apns-priority' => '10'];
        if (in_array($message->kind, self::COLLAPSIBLE, true)) {
            $android['collapse_key'] = $message->kind;
            $apnsHeaders['apns-collapse-id'] = $message->kind;
        }

        return [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $message->title,
                    'body' => $message->body,
                ],
                'data' => $message->data(),
                'android' => $android,
                'apns' => [
                    'headers' => $apnsHeaders,
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'thread-id' => $message->kind,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * UNREGISTERED (app uninstalled / token expired) or INVALID_ARGUMENT
     * about the token itself: the token will never work again.
     */
    public static function isDeadToken(Response $response): bool
    {
        $error = (array) ($response->json('error') ?? []);
        $status = (string) ($error['status'] ?? '');
        $codes = collect((array) ($error['details'] ?? []))
            ->map(fn ($d) => is_array($d) ? (string) ($d['errorCode'] ?? '') : '')
            ->filter()
            ->all();

        // Only the FCM error code: a bare NOT_FOUND can also mean a wrong
        // project id, which must not wipe every token.
        if (in_array('UNREGISTERED', $codes, true)) {
            return true;
        }
        if ($status === 'INVALID_ARGUMENT' || in_array('INVALID_ARGUMENT', $codes, true)) {
            $text = mb_strtolower((string) ($error['message'] ?? '').' '.json_encode($error['details'] ?? []));

            return str_contains($text, 'token');
        }

        return false;
    }

    private function post(PushMessage $message, string $token, string $accessToken): Response
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(10)
            ->post(
                'https://fcm.googleapis.com/v1/projects/'.rawurlencode((string) $this->projectId).'/messages:send',
                self::payload($message, $token),
            );
    }

    private function accessToken(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $account = (array) $this->account();
        $tokenUri = (string) ($account['token_uri'] ?? self::DEFAULT_TOKEN_URI);
        $response = Http::asForm()->timeout(10)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->jwt($account, $tokenUri),
        ]);
        $token = (string) $response->json('access_token', '');
        if (! $response->successful() || $token === '') {
            throw new RuntimeException('FCM OAuth token request failed: HTTP '.$response->status());
        }

        // Google tokens live 1 hour; keep ~50 min.
        $ttl = max(60, min(3000, (int) $response->json('expires_in', 3600) - 600));
        Cache::put($this->cacheKey(), $token, $ttl);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function jwt(array $account, string $tokenUri): string
    {
        $now = time();
        $segments = [
            self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            self::b64(json_encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR)),
        ];
        $input = implode('.', $segments);

        $key = openssl_pkey_get_private((string) $account['private_key']);
        if ($key === false || ! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM service-account key could not sign the JWT.');
        }

        return $input.'.'.self::b64($signature);
    }

    /**
     * The service account: FCM_CREDENTIALS as JSON, or a path to it.
     *
     * @return array<string, mixed>|null
     */
    private function account(): ?array
    {
        if ($this->account !== false) {
            return $this->account;
        }

        $raw = trim((string) $this->credentials);
        if ($raw !== '' && ! str_starts_with($raw, '{')) {
            $path = str_starts_with($raw, '/') ? $raw : base_path($raw);
            $raw = is_readable($path) ? (string) file_get_contents($path) : '';
        }
        $json = $raw !== '' ? json_decode($raw, true) : null;

        return $this->account = is_array($json) && ! empty($json['client_email']) && ! empty($json['private_key'])
            ? $json
            : null;
    }

    private function cacheKey(): string
    {
        return 'fcm:access-token:'.sha1((string) ($this->account()['client_email'] ?? '').'|'.$this->projectId);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
