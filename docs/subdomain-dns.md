# Tenant subdomain DNS

**Model:** wildcard DNS at Namecheap, direct-to-Dokwe origin.

Every tenant lives at `{slug}.keverna.com`. The whole subdomain space is
served by a single wildcard record — new signups appear on their
subdomain the moment the row is written, with no per-tenant DNS work
and no external API calls at signup.

## Why we moved

The earlier design created one CNAME per tenant via the Cloudflare API.
That path had two ceilings we would have hit well before 1M tenants:

1. **DNS record limits** — Cloudflare Enterprise zones cap out under 1M
   records.
2. **API rate limits** — 1,200 req/5min on the Free tier is a signup
   bottleneck.

The wildcard removes both. It also removes the Cloudflare API
dependency from the signup path entirely — one less external system
whose blip stalls onboarding.

## Actual current setup

- **DNS provider:** Namecheap. `keverna.com` is registered and its
  authoritative DNS is served from Namecheap.
- **Traffic path:** browser → Dokwe ingress → app. No Cloudflare or
  other CDN in front today. TLS terminates at Dokwe.

## Required one-time setup

- **Wildcard DNS record** at Namecheap:
  - Type: `A` if you want to point at a fixed IP for Dokwe's ingress,
    or `CNAME` if Dokwe gives you a hostname (`ingress-*.dokwe.app`
    style). Note: some registrars refuse a `CNAME` on a wildcard label —
    if Namecheap does, use `A` records.
  - Host: `*` (the wildcard label).
  - Target: whatever Dokwe advertises for the app's ingress.
- **Wildcard TLS cert** `*.keverna.com` at Dokwe:
  - **Let's Encrypt DNS-01 (recommended).** Requires an ACME client
    with Namecheap API access so it can write the
    `_acme-challenge.keverna.com` TXT record and pass verification.
    Namecheap's API needs whitelisted source IPs and an API key —
    generate one from Namecheap → Profile → Tools → API Access.
  - **Paid wildcard cert.** Upload once, replace at renewal.
- **Fallback for the apex.** `keverna.com` itself (not a subdomain) is
  served by whichever A record you have at Namecheap. The wildcard
  covers subdomains but not the apex.

## Custom domains

Unchanged. Operators point their own domain (`booking.grand-palace.com`
etc.) via CNAME at the value in
`config/keverna.php → custom_domain_cname_target` (env
`CUSTOM_DOMAIN_CNAME_TARGET`, default `cname.keverna.com`). That
hostname resolves to Dokwe's ingress and is served the same way. The
`VerifyCustomDomain` + `AttachCustomDomainToDokwe` jobs continue to
handle these; they use `Services\Dns\DnsResolver` for verification, not
any provider API.

## What the application does now

`App\Jobs\Domains\ProvisionTenantSubdomain` stamps
`tenants.subdomain_provisioned_at` and dispatches
`App\Jobs\Domains\AttachTenantDomainToDokwe`. That second job asks
Dokwe to route traffic for the tenant's `{slug}.keverna.com` to the
correct tenant context — Dokwe already accepts the hostname via the
wildcard, the attach is really a routing registration + TLS SNI hookup.

There is no external DNS API call in this path. The
`CloudflareDnsService` and its Exception class have been removed. The
`CLOUDFLARE_*` env vars are no longer read anywhere and should be
dropped from `.env`/deployment manifests.

## If we later want an edge CDN in front

Nothing here needs to change to add one. Point a wildcard CNAME at a
CDN target (Cloudflare, Fastly, whatever), configure the CDN to
origin-connect to Dokwe with a valid wildcard cert, and traffic will
flow through it. The application does not care whether Dokwe is
directly reachable or fronted.

## Rollback

If wildcard-DNS misbehaves and you need per-tenant records back, the
application change is contained to `ProvisionTenantSubdomain`. Restore
the previous implementation from git
(`git log --follow app/Jobs/Domains/ProvisionTenantSubdomain.php`),
put the `CloudflareDnsService` back, and re-add the `CLOUDFLARE_*` env
vars. Nothing else in the app depends on the removed pieces.
