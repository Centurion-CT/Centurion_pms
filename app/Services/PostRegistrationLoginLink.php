<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

/**
 * Builds a one-shot, encrypted token that lets a freshly-registered operator
 * land on their tenant subdomain already authenticated.
 *
 * We use Crypt (HMAC-authenticated AES-256-CBC under APP_KEY) instead of
 * Laravel's signed-URL helpers because those use the central request host
 * when generating, while the redirect target lives on a different host
 * (the tenant subdomain). The token carries its own expiry; the receiving
 * route verifies that the operator belongs to the resolved tenant.
 *
 * Lifetime: 5 minutes. Single-use: redeem() records each token's id for as
 * long as it could still verify, so the link can't be replayed from browser
 * history after logging out.
 */
class PostRegistrationLoginLink
{
    private const LIFETIME_MINUTES = 5;

    public function generate(Tenant $tenant, Operator $operator): string
    {
        $token = Crypt::encryptString(json_encode([
            'id' => Str::random(32),
            'operator_id' => $operator->id,
            'tenant_id' => $tenant->id,
            'expires_at' => Carbon::now()->addMinutes(self::LIFETIME_MINUTES)->timestamp,
        ], JSON_THROW_ON_ERROR));

        // Inherit scheme + port from APP_URL so the redirect uses the
        // same protocol/port the app is actually served on. Two bugs
        // this fixes:
        //   1. Local dev on `php artisan serve --port=8081` — without
        //      the port, the browser hits port 80 (dead).
        //   2. Prod hardcoded `http://` — every redirect had to go via
        //      ForceHttpsInProduction's 301, and some clients drop
        //      query params on that hop, breaking the token round-trip.
        $parts = parse_url((string) config('app.url'));
        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$tenant->primaryHost()}{$port}/auth/post-registration?token=".urlencode($token);
    }

    /**
     * verify(), and spend the token so a second use fails.
     *
     * @return array{id: string, operator_id: int, tenant_id: int}|null
     */
    public function redeem(string $token, Tenant $tenant): ?array
    {
        $payload = $this->verify($token, $tenant);
        if ($payload === null) {
            return null;
        }

        $firstUse = Cache::add('post-registration-login:'.$payload['id'], true, Carbon::now()->addMinutes(self::LIFETIME_MINUTES + 1));

        return $firstUse ? $payload : null;
    }

    /**
     * @return array{id: string, operator_id: int, tenant_id: int}|null
     */
    public function verify(string $token, Tenant $tenant): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ! isset($payload['id'], $payload['operator_id'], $payload['tenant_id'], $payload['expires_at'])
        ) {
            return null;
        }

        if ((int) $payload['tenant_id'] !== $tenant->id) {
            return null;
        }

        if (Carbon::now()->timestamp > (int) $payload['expires_at']) {
            return null;
        }

        return [
            'id' => (string) $payload['id'],
            'operator_id' => (int) $payload['operator_id'],
            'tenant_id' => (int) $payload['tenant_id'],
        ];
    }
}
