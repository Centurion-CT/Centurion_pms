<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Signs the magic-link claim URL sent to guests after a booking
 * confirmation (docs/unified-login.md §3, Phase 2).
 *
 * Same signing shape as TenantSsoTokenSigner (HMAC over scheme + host +
 * path + sorted query params) but with a longer default TTL — guests
 * often don't check email immediately. Nonce lives in guest_claim_tokens
 * for replay protection and audit.
 */
final class GuestClaimTokenSigner
{
    /** 7 days — enough for a guest to notice the email and click. */
    private const DEFAULT_TTL_SECONDS = 60 * 60 * 24 * 7;

    private const CLAIM_PATH = '/claim';

    public function __construct(private readonly string $appKey)
    {
    }

    /**
     * @return array{url: string, nonce: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function issue(string $email, ?int $bookingId = null, ?int $ttlSeconds = null): array
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;
        $nonce = (string) Str::uuid();
        $expiresAt = now()->addSeconds($ttl);

        $host = (string) config('keverna.central_domain');
        $scheme = request()?->getScheme() ?? 'https';

        $params = [
            'email' => strtolower(trim($email)),
            'nonce' => $nonce,
            'expires' => $expiresAt->getTimestamp(),
        ];
        if ($bookingId !== null) {
            $params['booking'] = $bookingId;
        }

        $urlToSign = $this->canonicalUrl($scheme, $host, self::CLAIM_PATH, $params);
        $signature = hash_hmac('sha256', $urlToSign, $this->appKey);
        $params['signature'] = $signature;

        return [
            'url' => "{$scheme}://{$host}".self::CLAIM_PATH.'?'.http_build_query($params),
            'nonce' => $nonce,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{email: string, nonce: string, expires: int, booking: int|null}
     */
    public function verify(Request $request): array
    {
        $required = ['email', 'nonce', 'expires', 'signature'];
        foreach ($required as $key) {
            if ($request->query($key) === null) {
                throw new SsoTokenException(
                    SsoTokenException::KIND_MISSING,
                    "Missing claim parameter: {$key}",
                );
            }
        }

        $expires = (int) $request->query('expires');
        if ($expires <= 0 || $expires < time()) {
            throw new SsoTokenException(
                SsoTokenException::KIND_EXPIRED,
                'Claim link has expired.',
            );
        }

        $signature = (string) $request->query('signature');

        $verifyParams = [
            'email' => strtolower(trim((string) $request->query('email'))),
            'nonce' => (string) $request->query('nonce'),
            'expires' => $expires,
        ];
        $booking = $request->query('booking');
        if ($booking !== null) {
            $verifyParams['booking'] = (int) $booking;
        }

        $urlToVerify = $this->canonicalUrl(
            $request->getScheme(),
            $request->getHttpHost(),
            self::CLAIM_PATH,
            $verifyParams,
        );

        $expected = hash_hmac('sha256', $urlToVerify, $this->appKey);
        if (! hash_equals($expected, $signature)) {
            throw new SsoTokenException(
                SsoTokenException::KIND_BAD_SIGNATURE,
                'Claim signature does not match.',
            );
        }

        return $verifyParams + ['booking' => $verifyParams['booking'] ?? null];
    }

    /**
     * @param  array<string, int|string>  $params
     */
    private function canonicalUrl(string $scheme, string $host, string $path, array $params): string
    {
        ksort($params);

        return "{$scheme}://{$host}{$path}?".http_build_query($params);
    }
}
