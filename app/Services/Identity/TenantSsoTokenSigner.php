<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\IdentityOperatorLink;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Signs a short-lived URL that the tenant-subdomain SSO consumer
 * exchanges for a session (docs/unified-login.md §3 "SSO handoff").
 *
 * Not using Laravel's URL::temporarySignedRoute because the tenant
 * subdomain is dynamic and the target route lives under a domain
 * resolved by DomainTenantFinder at request time. Manual HMAC keeps
 * the signing surface small and its rules obvious.
 *
 * The signature covers scheme + host + path + sorted query params.
 * Sorting is essential — an attacker who reorders params must not
 * produce a valid signature.
 */
final class TenantSsoTokenSigner
{
    /** Default TTL: enough to redirect + consume, no more. */
    private const DEFAULT_TTL_SECONDS = 60;

    private const CONSUME_PATH = '/sso/consume';

    public function __construct(private readonly string $appKey)
    {
    }

    /**
     * Build a signed URL that redirects into `{tenant}.keverna.com/sso/consume`.
     *
     * @return array{url: string, nonce: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function issue(IdentityOperatorLink $link, ?int $ttlSeconds = null): array
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;
        $nonce = (string) Str::uuid();
        $expiresAt = now()->addSeconds($ttl);

        $host = $link->tenant->slug.config('keverna.tenant_domain_suffix');
        // Match the scheme the browser is currently on. In tests this is
        // http; in prod (behind TLS-terminating proxy) this is https as
        // long as TrustProxies is wired. Either way the consume request
        // lands on the same scheme, so signatures match.
        $scheme = request()->getScheme();

        $params = [
            'operator' => (int) $link->operator_id,
            'identity' => (int) $link->identity_account_id,
            'nonce' => $nonce,
            'expires' => $expiresAt->getTimestamp(),
        ];

        $urlToSign = $this->canonicalUrl($scheme, $host, self::CONSUME_PATH, $params);
        $signature = hash_hmac('sha256', $urlToSign, $this->appKey);
        $params['signature'] = $signature;

        // http_build_query with the signature appended — key order
        // doesn't matter for the produced URL because the verifier
        // sorts before hashing.
        $query = http_build_query($params);

        return [
            'url' => "{$scheme}://{$host}".self::CONSUME_PATH."?{$query}",
            'nonce' => $nonce,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Verify an incoming request's signature + expiry. Returns the
     * decoded payload on success; throws SsoTokenException on failure.
     *
     * Does NOT check the nonce table — replay protection is the
     * consumer controller's job so it can atomically mark the nonce
     * consumed in the same transaction as the session login.
     *
     * @return array{operator: int, identity: int, nonce: string, expires: int}
     */
    public function verify(Request $request): array
    {
        $required = ['operator', 'identity', 'nonce', 'expires', 'signature'];
        foreach ($required as $key) {
            if ($request->query($key) === null) {
                throw new SsoTokenException(
                    SsoTokenException::KIND_MISSING,
                    "Missing SSO parameter: {$key}",
                );
            }
        }

        $expires = (int) $request->query('expires');
        if ($expires <= 0 || $expires < time()) {
            throw new SsoTokenException(
                SsoTokenException::KIND_EXPIRED,
                'SSO token has expired.',
            );
        }

        $signature = (string) $request->query('signature');

        $verifyParams = [
            'operator' => (int) $request->query('operator'),
            'identity' => (int) $request->query('identity'),
            'nonce' => (string) $request->query('nonce'),
            'expires' => $expires,
        ];

        $urlToVerify = $this->canonicalUrl(
            $request->getScheme(),
            // getHttpHost preserves the port. Signer uses the same
            // (config-driven) host string so the two match in tests
            // where the port might be present.
            $request->getHttpHost(),
            self::CONSUME_PATH,
            $verifyParams,
        );

        $expected = hash_hmac('sha256', $urlToVerify, $this->appKey);
        if (! hash_equals($expected, $signature)) {
            throw new SsoTokenException(
                SsoTokenException::KIND_BAD_SIGNATURE,
                'SSO signature does not match this URL.',
            );
        }

        return $verifyParams + ['nonce' => (string) $verifyParams['nonce']];
    }

    /**
     * Build the canonical string that gets HMAC'd. Sorting keys before
     * hashing is what stops an attacker from producing a valid URL by
     * reordering the query string.
     *
     * @param  array<string, int|string>  $params
     */
    private function canonicalUrl(string $scheme, string $host, string $path, array $params): string
    {
        ksort($params);

        return "{$scheme}://{$host}{$path}?".http_build_query($params);
    }
}
