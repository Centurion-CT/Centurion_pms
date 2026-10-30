# Multi-tenancy plan

Centurion started as a single-hotel install (forked from keverna-solo). As of
30 Oct 2026 it is becoming a SaaS that other apartment and hotel businesses
sign up for. This is Centurion's own, leaner take, not a restore of Keverna's
SaaS layer: no central identity, no SSO handoff between domains, no big admin
panel.

## Decisions

- **Tenants** are other operators: separate companies with their own staff,
  guests and money. Centurion Apartments is business #1.
- **Sign-up** is self-serve on `centurionpms.com`.
- **Addresses:** every business gets `{slug}.centurionpms.com` and can add its
  own domain later. Centurion Apartments keeps `centurionapartments.ng`.
- **Billing** is deferred. Businesses keep a status but nothing is charged.
- **A small console** lets the platform owner list businesses and pause them.
  There is no "log in as this business".

## What each address serves

The host decides the business. The path decides the part of it.

| Address | Serves |
| --- | --- |
| `centurionpms.com` (and `www.`) | Platform: home, sign-up, "find my business" login. No business is bound. |
| `{slug}.centurionpms.com/` | That business's booking site |
| `{slug}.centurionpms.com/login` | That business's staff login, then `/dashboard` |
| Verified own domain, e.g. `centurionapartments.ng` | The same booking site and `/login` |
| `console.centurionpms.com` | Platform owner console |
| Unknown `{x}.centurionpms.com` | "This address isn't in use" (404) |
| A paused business (`locked`) | Booking pages: "not taking online bookings right now" with their contact details. Staff login is refused. |
| An archived business | Treated as unknown (404) |

Rules:

- Sessions are host-only cookies (`SESSION_DOMAIN` stays empty). A login on one
  address never applies to another address or another business.
- Once a business has a verified own domain, booking-site pages on its
  `centurionpms.com` address 301 to the own domain; `/login` and `/dashboard`
  keep working on both. If the own domain stops pointing at us, verification
  lapses and the redirect stops on its own.
- `/` becomes the booking site when the new booking site ships (step 5). Until
  then `/` keeps redirecting to `/login`.

## Rollout switch

`TENANCY_MODE` (config `keverna.tenancy_mode`):

- `single` (default, what production runs today): every request binds the
  first business, whatever the host. Nothing changes for Centurion.
- `domains`: the host decides the business, as above.

Production flips to `domains` only after the wildcard DNS record and
certificate for `*.centurionpms.com` exist on Dokwe and Centurion's business
row has its slug and own domain set. See [subdomain-dns.md](subdomain-dns.md).

## Steps

| # | Step | Status |
| --- | --- | --- |
| 1 | Find the business from the host (`HostTenantFinder`), `TENANCY_MODE` switch, unknown/paused/platform-host handling | Done (placeholder platform home) |
| 2 | Isolation tests: two businesses side by side across pages, staff login, mobile API, webhooks, uploads, queued jobs, cache | Started: pages, staff accounts, mobile tokens, paused, unknown address. To do: uploads, queued jobs, cache, URLs built outside a request |
| 3 | Platform pages on `centurionpms.com`: home, sign-up (reuses `SeedInstallation`), find-my-business login with emailed links | To do |
| 4 | Domains per business: turn the existing domain page on for everyone, own-domain redirect | To do |
| 5 | Booking site from design Option C v2 as each business's `/` | To do |
| 6 | Brand split: "Centurion" = the software; business name, logo and site title move into each business's settings | To do |
| 7 | Console on `console.centurionpms.com`: businesses, needs a look, pause | To do |
| 8 | Production cut-over: DNS, `TENANCY_MODE=domains`, Centurion's slug + own domain | To do |

Designs: `design/platform/` (local mockups) and `design/platform/superdesign/`
(chosen direction, "Product tour").

## Findings while building

- **Payment return address.** `InitiateBookingPayment` and
  `InitiateBalancePayment` fall back to `PAYSTACK_CALLBACK_URL`
  (`{APP_URL}/paystack/callback`), and no route exists at that path. With
  several businesses the return address must be the paying business's own
  host. Fix with the booking site (step 5).
- **URLs built outside a request** (queued mail, scheduled commands) use
  `APP_URL`, which becomes the platform host. Most mail already uses
  `Tenant::primaryHost()`; the rest needs auditing in step 2.
- **`/health`** used to sit in the business-only routes, so in `domains` mode
  an uptime check on a bare host would 404. It is now registered outside them.
- Tests now run with `TENANCY_MODE=domains`, matching where production is
  going. `onBusinessHost('slug')` in `tests/Pest.php` points `route()` at a
  business's address.

## Not mirrored to keverna-solo

Everything in this plan stays in centurion-hms. keverna-solo remains a
single-hotel product. Generic bug fixes found along the way can still be
mirrored.
