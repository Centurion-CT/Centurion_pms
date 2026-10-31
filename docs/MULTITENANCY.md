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
| 2 | Isolation tests: two businesses side by side across pages, staff login, mobile API, webhooks, uploads, queued jobs, cache | Done (one money issue left for step 5, see below) |
| 3 | Platform pages on `centurionpms.com`: home, sign-up, find-my-business login with emailed links | Done |
| 4 | Domains per business: turn the existing domain page on for everyone, own-domain redirect | Done |
| 5 | Booking site from design Option C v2 as each business's `/` | To do |
| 6 | Brand split: "Centurion" = the software; business name, logo and site title move into each business's settings | To do |
| 7 | Console on `console.centurionpms.com`: businesses, needs a look, pause | To do |
| 8 | Production cut-over: DNS, `TENANCY_MODE=domains`, Centurion's slug + own domain (see step 4 notes) | To do |

Designs: `design/platform/` (local mockups) and `design/platform/superdesign/`
(chosen direction, "Product tour").

## Findings while building

- **Payment return address.** `InitiateBookingPayment` and
  `InitiateBalancePayment` fall back to `PAYSTACK_CALLBACK_URL`
  (`{APP_URL}/paystack/callback`), and no route exists at that path. With
  several businesses the return address must be the paying business's own
  host. Fix with the booking site (step 5).
- **Corporate wallet top-ups go to the platform's Paystack account.**
  `TopupService` and its webhook use `PaystackClient::forSubscriptions()`
  with no subaccount, unlike booking payments, which use the business's
  own key or subaccount. That is fine while Centurion is the only
  business. Fix it with the payment work in step 5, before another
  business's corporate clients top up.
- **`/health`** used to sit in the business-only routes, so in `domains` mode
  an uptime check on a bare host would 404. It is now registered outside them.
- Tests now run with `TENANCY_MODE=domains`, matching where production is
  going. `onBusinessHost('slug')` in `tests/Pest.php` points `route()` at a
  business's address.

## Step 2 notes

Checked away from page requests, with tests in
`tests/Feature/Tenancy/BackgroundIsolationTest.php`:

- **Corporate clients** were shared across the install (from Keverna's
  marketplace): every business's forms listed every corporate and its
  staff, guest lookup matched other businesses' corporate staff, and a
  booking could be billed to another business's corporate wallet.
  Corporates, their staff and their email domains now belong to one
  business (`tenant_id`, migration `2026_10_31_100000`). Existing rows go
  to the business that created them, else the one they have bookings
  with, else the first business. The same corporate address and email
  domain can now exist at two businesses.
- **Corporate membership renewals** never ran: the command ran with no
  business bound, so the scoped query found nothing. It now runs once
  per business.
- **Queued jobs** carry the business that queued them (Spatie's
  tenant-aware queue); mails marked `NotTenantAware` run with none bound.
  Corporate mails now bind the corporate's business, like booking mails,
  and link to its own address instead of `APP_URL`.
- **Uploads:** a business's own Dokwe Storage space is now applied by a
  switch task (`UseTenantMediaDisk`) instead of web middleware, so queue
  workers use it too and never reuse the previous business's disk. Lease
  documents and corporate payment receipts are stored under the
  business's folder, like guest IDs and review photos already were.
- **Cache** keys are prefixed per business (`PrefixCacheTask`); the
  dashboard and payout caches also carry the business id in the key.
- **Webhooks** arrive on the business's own address and only find that
  business's bookings and top-ups.
- Removed the guest "claim your Keverna account" email: it linked to a
  `/claim` page that doesn't exist here.

## Step 4 notes

- `/domain` is on by default in `domains` mode (`DOMAIN_MANAGEMENT_ENABLED`
  still overrides). A manager changes the business's address there, or
  adds its own domain.
- **Address change:** same rules as sign-up, once a year. The old address
  301s for 90 days on any path (`RedirectOldSlug`, now global middleware;
  it was never registered before), and the manager is carried to the new
  address signed in.
- **Own domain, three stages:**
  1. *Ownership:* a TXT record on `_centurion-verify.{domain}` with the
     token (`VerifyCustomDomain`, every 15 minutes for 48 hours). It used
     to sit on the domain itself next to a CNAME, which DNS doesn't allow.
  2. *Pointing at us:* a CNAME to `CUSTOM_DOMAIN_CNAME_TARGET` for a
     subdomain, or A records to `CUSTOM_DOMAIN_A_RECORDS` for a bare
     domain like `grandpalace.ng`. With `DOKWE_API_KEY` set, the
     certificate is requested from Dokwe automatically; without it, attach
     the domain in the Dokwe dashboard by hand.
  3. *Live:* `CheckOwnDomain` asks `https://{domain}/__tenant-ping` which
     business it serves. A right answer makes it live; three misses in a
     row (hourly, `domains:check-own`, `domains` mode only) stop it being
     live until it answers again.
- **While live**, the booking site's public pages on the platform address
  301 to the own domain (`RedirectToOwnDomain`, a list of route names).
  Staff pages, payment returns, signed links, webhooks and the API stay
  on both addresses. Links in emails use the own domain only while live.
- Not done: `www.` of an own domain (needs its own DNS record and
  certificate), and removing a domain from Dokwe when it's removed here.
- **For step 8:** create `cname.centurionpms.com` pointing at Dokwe's
  ingress, set `CUSTOM_DOMAIN_CNAME_TARGET` and `CUSTOM_DOMAIN_A_RECORDS`,
  and give Centurion's business row `custom_domain = centurionapartments.ng`
  with `domain_verified_at` and `custom_domain_live_at` set. Rows already
  verified when the step 4 migration runs are taken as live.

## Step 3 notes

- Sign-up: `/signup` (`App\Livewire\Platform\SignUp`): business, then
  account, then a 6-digit email code (`App\Platform\SignUpCode`, in the
  cache), then `CreateBusiness`. Nothing is created before the code is
  right. The password is hashed into the session at step 2 and never sent
  back to the browser.
- "Open my dashboard" (`/signup/open`) hands the manager to their own
  address with the one-time `PostRegistrationLoginLink`. They land on the
  existing onboarding, which asks them to verify their phone (skippable).
- `/login` on the platform finds a business by address, or emails a link to
  each business's staff login. It never says whether an email exists.
- Staff emails are now unique per business (`operators.tenant_id, email`),
  so one person can work at several businesses. Sign-up also links the
  manager to a login identity so the mobile apps can sign them in.
- Not built: "used on this device" on the platform login (sessions don't
  cross addresses, so it needs its own cookie), and a "What does it cost?"
  answer, left out until pricing is decided.

## Not mirrored to keverna-solo

Everything in this plan stays in centurion-hms. keverna-solo remains a
single-hotel product. Generic bug fixes found along the way can still be
mirrored.
