<?php

declare(strict_types=1);

namespace App\Services\Dokwe;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP wrapper around dokwe's `/domains/:projectId` endpoints. Used by
 * tenant signup + custom-domain flows to attach DNS hostnames to keverna's
 * dokwe project so dokwe issues a cert and routes traffic.
 *
 * Contract pinned in docs/dokwe-integration.md — keep this class and the
 * doc in lockstep. The dokwe API rarely changes but this is a cross-system
 * boundary, so we trust ONLY what's documented there.
 *
 * Lifecycle of an attached domain:
 *
 *   1. attachDomain()           → row { verified:false, sslStatus:pending }
 *   2. (tenant points DNS)
 *   3. verify()                 → dokwe checks CNAME → fires ACME
 *   4. getDomain() polled until → { verified:true, sslStatus:provisioned }
 *
 * Failure surfaces as DokweException, with `statusCode` set for HTTP
 * failures and `code` populated for dokwe's structured error codes
 * (WORKSPACE_REQUIRES_2FA, FLEET_NOT_ACTIVATED, etc.). Callers should
 * branch on `code` rather than the free-form message.
 */
class DokweClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $projectId,
        private readonly int $connectTimeout = 10,
        private readonly int $requestTimeout = 30,
    ) {
        if ($this->apiKey === '') {
            throw new DokweException('DokweClient requires a non-empty DOKWE_API_KEY');
        }
        if ($this->projectId === '') {
            throw new DokweException('DokweClient requires a non-empty DOKWE_PROJECT_ID');
        }
    }

    public static function default(): self
    {
        return new self(
            baseUrl: (string) config('dokwe.base_url'),
            apiKey: (string) config('dokwe.api_key'),
            projectId: (string) config('dokwe.project_id'),
            connectTimeout: (int) config('dokwe.connect_timeout', 10),
            requestTimeout: (int) config('dokwe.request_timeout', 30),
        );
    }

    // ── Mutations ───────────────────────────────────────────────────────────

    /**
     * Attach a new domain to keverna's dokwe project. Returns the dokwe-side
     * row id, which the caller MUST persist (tenants.{subdomain,custom_domain}
     * _dokwe_id) so future verify/detach calls can find it.
     *
     * Idempotent: if dokwe returns 409 "Domain already configured", we recover
     * the existing id by listing and matching by hostname rather than
     * surfacing a false failure to the tenant onboarding flow.
     */
    public function attachDomain(string $domain): string
    {
        $response = $this->http()->post("/domains/{$this->projectId}", [
            'domain' => $domain,
        ]);

        if ($response->status() === 409) {
            return $this->recoverExistingId($domain)
                ?? throw $this->failure($response, "Domain {$domain} reported as already configured but couldn't be located");
        }

        $this->ensureOk($response, "attachDomain({$domain})");

        $row = $response->json();
        if (! is_array($row) || empty($row['id'])) {
            throw $this->failure($response, 'attachDomain returned an unexpected body');
        }

        return (string) $row['id'];
    }

    /**
     * Tell dokwe to run the CNAME check + ACME issuance. The returned domain
     * reflects the immediate outcome — call again (or poll via getDomain)
     * if it isn't yet provisioned.
     */
    public function verify(string $id): DokweDomain
    {
        $response = $this->http()->post("/domains/{$this->projectId}/{$id}/verify");
        $this->ensureOk($response, "verify({$id})");

        return DokweDomain::fromArray((array) $response->json());
    }

    /**
     * Re-attempt SSL provisioning. Only valid when verified=true but
     * sslStatus=failed — dokwe rejects this call otherwise.
     */
    public function retrySsl(string $id): DokweDomain
    {
        $response = $this->http()->post("/domains/{$this->projectId}/{$id}/retry-ssl");
        $this->ensureOk($response, "retrySsl({$id})");

        return DokweDomain::fromArray((array) $response->json());
    }

    /**
     * Permanently detach a domain. Called on tenant archive / custom domain
     * removal. Idempotent: a 404 from dokwe (already gone) is silently
     * swallowed — anything else surfaces.
     */
    public function detach(string $id): void
    {
        $response = $this->http()->delete("/domains/{$this->projectId}/{$id}");
        if ($response->status() === 404) {
            return;
        }
        $this->ensureOk($response, "detach({$id})");
    }

    // ── Reads ───────────────────────────────────────────────────────────────

    public function getDomain(string $id): DokweDomain
    {
        // Dokwe doesn't expose a single-row GET — list and filter. Cheap
        // enough at our scale (one project = a few thousand rows max).
        foreach ($this->listDomains() as $row) {
            if ($row->id === $id) {
                return $row;
            }
        }
        throw new DokweException("Domain {$id} not found in project {$this->projectId}", 404);
    }

    /**
     * @return array<int,DokweDomain>
     */
    public function listDomains(): array
    {
        $response = $this->http()->get("/domains/{$this->projectId}");
        $this->ensureOk($response, 'listDomains');

        $rows = $response->json();
        if (! is_array($rows)) {
            throw $this->failure($response, 'listDomains returned a non-array body');
        }

        return array_values(array_map(
            static fn (array $row): DokweDomain => DokweDomain::fromArray($row),
            $rows,
        ));
    }

    /**
     * Block until the domain reaches `isProvisioned()` or we hit a terminal
     * failure / the max attempts. Returns the last known state regardless —
     * callers persist whatever they get so the tenant UI can show progress.
     *
     * Wall-clock budget = `$attempts × $interval` seconds. Default is roughly
     * 5 minutes — long enough for ACME's HTTP-01 round-trip plus DNS TTL
     * variance, short enough that a queued job stays under most queue
     * supervisors' soft timeout.
     */
    public function pollUntilProvisioned(string $id, ?int $attempts = null, ?int $interval = null): DokweDomain
    {
        $attempts = $attempts ?? (int) config('dokwe.poll_max_attempts', 30);
        $interval = $interval ?? (int) config('dokwe.poll_initial_interval', 10);

        $last = $this->getDomain($id);
        for ($i = 0; $i < $attempts; $i++) {
            if ($last->isProvisioned() || $last->isFailed()) {
                return $last;
            }
            sleep($interval);
            $last = $this->getDomain($id);
        }

        return $last;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->requestTimeout)
            ->withUserAgent('keverna/dokwe-client');
    }

    /**
     * Recovery path for the 409 idempotency case. Returns the existing id
     * when a row with the same domain exists in the project, or null when
     * dokwe's 409 was about something else (rare — usually means the
     * project itself is misconfigured).
     */
    private function recoverExistingId(string $domain): ?string
    {
        try {
            foreach ($this->listDomains() as $row) {
                if (strcasecmp($row->domain, $domain) === 0) {
                    return $row->id;
                }
            }
        } catch (DokweException $e) {
            // Surface the recovery failure as an info log so on-call has a
            // breadcrumb when the 409 turns out to be a different conflict.
            Log::channel(config('logging.default'))->info('DokweClient recovery failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function ensureOk(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }
        throw $this->failure($response, "DokweClient::{$context} failed");
    }

    private function failure(Response $response, string $message): DokweException
    {
        $body = $response->json();
        $remoteMessage = is_array($body) && isset($body['message']) ? (string) $body['message'] : null;
        $remoteCode = is_array($body) && isset($body['code']) ? (string) $body['code'] : null;

        $full = $remoteMessage !== null
            ? "{$message}: [{$response->status()}] {$remoteMessage}"
            : "{$message}: [{$response->status()}] {$response->body()}";

        return new DokweException(
            message: $full,
            statusCode: $response->status(),
            errorCode: $remoteCode,
        );
    }
}

/*
 * Throwable convenience — alias `throw` expressions need a Throwable, and
 * PHP 8.3 still rejects `?? throw $foo` when $foo is a DokweException
 * inside a coalesce chain. The bare `throw` above sidesteps that lint.
 */
class_exists(ConnectionException::class);
