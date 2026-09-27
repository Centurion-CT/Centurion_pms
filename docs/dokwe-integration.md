# Dokwe Integration Contract

Reference spec for `App\Services\DokweClient`. Pin to this when implementing
the wrapper — the dokwe API surface evolves and any drift should be caught
by the contract tests, not by a production tenant onboarding failure.

Source of truth: `apps/api/src/modules/domains/domains.controller.ts` in the
dokwe repo. Last reviewed against commit `35a405a` on `feat/fleet`.

## Base URL & Auth

- Base: `https://dokwe.com/api`
- Auth: `Authorization: Bearer <DOKWE_API_KEY>`
  - The key is a `dk_pak_…` personal API key issued from
    Dashboard → Settings → API Keys.
  - The dokwe server treats API-key auth as a "something you have" factor,
    so it bypasses 2FA. **Never log or echo the key.**
- Required env vars on keverna's side:
  ```
  DOKWE_API_URL=https://dokwe.com/api
  DOKWE_API_KEY=dk_pak_…
  DOKWE_PROJECT_ID=<uuid of keverna's dokwe project>
  ```

## Endpoints

### `POST /domains/:projectId` — attach a domain

Body (validated by `AddDomainDto`):

```json
{
  "domain":        "acme.keverna.com",   // required; matches /^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/
  "serviceId":     "<uuid|null>",        // optional; targets a specific service in the project
  "environmentId": "<uuid|null>"         // optional; targets a specific environment
}
```

Response (200/201): the inserted `domains` row with `type: "custom"`,
`verified: false`, `sslStatus: "pending"`.

Idempotency:
- A second attach for the same `(projectId, domain)` returns `409 Conflict`
  with `Domain already configured`. `DokweClient::attachDomain` should
  catch this and treat it as a success — return the existing id by calling
  `GET /domains/:projectId` and matching by `domain`.

Notes:
- The regex **rejects wildcards** (`*.keverna.com` will not validate).
  That's expected; we attach one row per tenant subdomain today.
- `domain` must be ≤253 chars.

### `POST /domains/:projectId/:id/verify` — kick the CNAME + SSL pipeline

Empty body. Dokwe resolves the domain's CNAME and expects it to point at
`proxy.<dokwe-base-domain>` (the marketing site / app host). If the CNAME
matches, dokwe writes the nginx route, runs ACME, and flips
`sslStatus` to `provisioned` or `failed`.

Response: the updated row.

Failure modes worth handling client-side:
- `verified: false` with no `sslError` → CNAME isn't pointing at dokwe yet.
  Keverna should keep polling (back-off recommended).
- `sslStatus: "failed"` with `sslError` → SSL provisioning broke. Show the
  message to the tenant ("Your domain isn't pointing at our edge yet" is
  usually what they need to hear). Use `POST /:id/retry-ssl` after the
  tenant fixes their DNS.

### `POST /domains/:projectId/:id/retry-ssl` — retry SSL only

For when the CNAME is correct (`verified: true`) but the cert failed to
issue. Empty body. Returns the updated row.

### `DELETE /domains/:projectId/:id` — detach

Call this when a tenant is archived or changes their custom domain. Empty
body. Returns 200 on success.

### `GET /domains/:projectId` — list

Returns all domains attached to the project. Useful for the idempotency
recovery path on `POST /domains` (matching by `domain` to recover the id).

## Open issues on the dokwe side (flagged from this audit)

1. **Workspace-scoping gap** — `DomainsController` reads `:projectId` from
   the URL and `addCustomDomain` only validates that the service /
   environment belong to the project. It does **not** verify the project
   belongs to the caller's workspace. With JWT auth that's an authenticated
   user, but it means a user in workspace A who knows a UUID in workspace B
   can attach domains there. Tracked in dokwe task #57 (API-key project
   scoping) — must be fixed before keverna goes live.
2. **No wildcard support** — domains regex rejects `*.…`. Per-tenant attach
   only. Acceptable to ~5k tenants; revisit with dokwe wildcard task if we
   cross that.
3. **No webhook on SSL ready** — keverna must poll. Sensible polling cadence:
   every 10s for the first 2 minutes after attach, then back off to 1m for
   another 5 minutes, then mark as `manual_verify_needed` and surface the
   error to the tenant.

## Suggested `DokweClient` shape

```php
final class DokweClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $projectId,
    ) {}

    /**
     * Attach a domain. Returns the dokwe-side id.
     * Idempotent: treats 409 as success and recovers the existing id.
     */
    public function attachDomain(string $domain, ?string $serviceId = null): string { /* … */ }

    /** Triggers CNAME check + ACME. Returns the updated row. */
    public function verify(string $id): DokweDomain { /* … */ }

    public function retrySsl(string $id): DokweDomain { /* … */ }

    public function detach(string $id): void { /* … */ }

    /** Polled by the tenant onboarding UI. */
    public function getDomain(string $id): DokweDomain { /* … */ }
}
```

Treat every HTTP call as failable — 5xx should retry with jittered back-off,
4xx should surface to the tenant with a meaningful message. Log all calls
to a `dokwe` channel so we can audit cross-system issues.
