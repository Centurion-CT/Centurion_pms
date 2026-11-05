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
- `/` is always the business's booking site, never a redirect to `/login`.
  Until it has a live building with an apartment type, the page shows the
  business name, "Online booking opens soon" and its phone and email.
- The platform home (sign-up, "find my business") exists only with
  `TENANCY_MODE=domains`. In `single` mode every address is the one
  business's booking site, so the central domain shows that, not the
  platform home.

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
| 5 | Booking site from design Option C v2 as each business's `/` | Done |
| 6 | Brand split: "Centurion" = the software; business name, logo and site title move into each business's settings | Done |
| 7 | Console on `console.centurionpms.com`: businesses, needs a look, pause | Done |
| 8 | Production cut-over: DNS, `TENANCY_MODE=domains`, Centurion's slug + own domain (see step 4 notes) | To do |

Designs: `design/platform/` (local mockups) and `design/platform/superdesign/`
(chosen direction, "Product tour").

## Findings while building

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

## Step 7 notes: console

- `console.{CENTRAL_DOMAIN}` (`routes/platform-console.php`, domains mode
  only). Console users live in `console_users` (`App\Models\ConsoleUser`),
  not tied to any business, as **owners** (pause / resume, manage users)
  or **admins** (pause / resume).
- **First owner:** `/setup` (`App\Livewire\Console\Setup`) is open only
  while no console user exists and `CONSOLE_SETUP_KEY` is set. The owner
  enters the key, name, email and password, then a code emailed to them.
  The page then closes for good.
- **Sign-in:** email and password, then a 6-digit code emailed to that
  address (`ConsoleCode`). A wrong email and a wrong password get the
  same answer.
- **Users** (`App\Livewire\Console\Users`, owners): invite by email as
  owner or admin (they choose a password from a 3-day link), change
  role, send a password reset, remove. There is always at least one
  owner, and nobody removes or demotes themselves.
- **Recovery:** `php artisan console:invite {email} --owner` prints a
  password link, creating the user if needed, for when every owner is
  locked out.
- **Businesses** (`App\Livewire\Console\Businesses`): every business
  that isn't archived, with its addresses, own-domain state, contact,
  buildings live, apartment types, staff, bookings in the last 30 days
  and the last booking. "Needs a look" flags: no apartment types, no
  live building, payments going to the platform's Paystack account, an
  own domain that stopped reaching us, paused.
- **Pause / resume** sets the business to Locked / Active (step 1's
  paused page and staff sign-out). Each is recorded in that business's
  activity log with the admin's email.
- No "log in as this business", as decided.

## Step 6 notes: brand split

"Centurion" (`config/brand.php`, `BRAND_NAME`) is the software; each
business has its own identity.

- **Settings → Business** (`App\Livewire\Dashboard\Settings\Business`,
  managers): business name, booking-site title (`tenants.site_title`,
  replacing the install-wide `BRAND_SITE_TITLE`; the first business keeps
  its value on migrate), phone and email. The logo is the one uploaded
  under Photos (`Tenant::logoUrl()`), used on the staff login, sidebar,
  booking site and email header; without one the Centurion mark shows.
- **Email senders:** mail sent for a business goes out under its name
  (`App\Listeners\SendMailAsBusiness`); platform mail (sign-up codes,
  login links) under Centurion. Sign-offs follow the same rule.
  `APP_NAME` should be `Centurion`.
- **Keverna left the visible text:** dashboard labels, fee lines
  ("Centurion fee"), the staff app and POS names, email wording,
  `/llms.txt` (now describes the business's own site), the corporate
  portal, the Paystack subaccount label. Keverna's marketplace listing
  toggle is gone from property settings; `/stays` 301s to the
  apartments and `/stays/{slug}` to the building on the booking site. Corporates once "onboarded centrally"
  belong to, and are editable by, their business.
- **Support WhatsApp** on the staff sign-in pages pointed at Keverna's
  support numbers. It now uses `SUPPORT_WHATSAPP` and is hidden when
  that's empty.
- **Legal pages** (`/terms`, `/privacy`, `/cookies`) are written for
  the business whose address they're on: it is the party guests book
  with, its name and contact come from Settings → Business, and
  Centurion is named only as the software. `/ndpr` (Keverna's NDPR
  trustmark page) is removed. The owner should still review the
  wording.
- Left alone: Keverna-era code that nothing routes to any more (central
  identity pages, marketing pages, the stays index view), and internal names
  (`window.Keverna*`, `X-Keverna-Property`, the `kevernaFee` field).

## Step 5 notes: booking site

Built from design Option C v2 ("Map Desk", `design/website/c-map`), on every
business's address, then redesigned on 5 Nov 2026 so one shell runs from home
page to the guest's own booking and still reads well for a business with one
apartment and no photos:

| Path | Page |
| --- | --- |
| `/` | Home: search, apartments free for the default stay, locations, long-stay rates, good to know (`SiteController::home`) |
| `/apartments` | Apartments free for a stay, filters by building and type, the map when buildings have positions (`SiteController::results`) |
| `/apartments/{building}/{type}` | One apartment: photos, the stay on a calendar, price and Book (`SiteController::apartment`). Old `?open={type id}` links 301 here |
| `/book/{building}/{type}` | Checkout: who's staying, pay in full or the deposit, then Paystack (`App\Livewire\Site\Checkout`) |
| `/stays/pay/success/{ref}` | Booked (or "waiting for your payment" while unpaid) |
| `/stays/pay/failed` | Payment didn't go through |
| `/manage` | Find a booking by reference + email or phone, then the existing guest booking page (`App\Livewire\Site\ManageBooking`) |
| `/guest/booking/{ref}` | The guest's booking and self-service pages, on the same header and footer (`site.layouts.guest`) |

- Everything comes from the business's data (`App\Site\BookingSite`): live
  buildings, active apartment types, free counts per night (same rule as
  `AvailabilityService::categoryFreeUnits`, read in two queries), prices
  from `PricingCalculator` with fees, so the card, panel and checkout
  agree. Booking goes through RoomAllocator, CreateBooking and
  InitiateBookingPayment, as the category page does.
- What a business needs for a good site: buildings marked live, each with
  a latitude/longitude (for the map), photos on its apartment types, its
  phone and email, check-in times and a cancellation policy in house
  rules, and optionally monthly rates and nearby places.
- The site takes the business's logo and colour (Settings → Business), as the
  staff sign-in does. With no photo the three bars stand in; a business that
  can't take payments gets "Book by phone" at checkout instead of a form.
- Reviews the business shows (dashboard → Reviews) appear on the apartment's
  page: its rooms' and the whole building's, newest first, first names only.
- Public pages carry search and link-preview tags
  (`site.partials.meta`); checkout, find my booking, the guest's booking and
  the review pages are noindex with no preview.
- Two booking paths remain. `/rooms`, `/categories/{slug}` and `/rooms/{id}`
  sit in the same shell but have extras at booking, discount codes and
  corporate rates, which `/book/{building}/{type}` doesn't yet. Merge them
  once the new checkout has those. Nothing on the site links to them.
- `/stays/{slug}/book` 301s to the building's apartments, and
  `/branches/{slug}` with nothing bookable shows `site.branch`. The in-room
  portal keeps its own app-like layout, in the business's colour and logo.
- Map tiles default to Esri's street map, as in the design
  (`SITE_MAP_TILES`); CARTO's free tiles now need a key. Check the tile
  provider's terms before heavy production traffic.
- Fixed along the way: a payment that couldn't start because Paystack
  isn't set up left the room held; unpaid bookings showed as paid on the
  confirmation and guest booking pages.

## Step 5 notes: payments

- **One rule for which Paystack account a business's payments use**
  (`App\Services\Paystack\BusinessPaystack`): its subaccount on the
  platform account, else its own secret key, else the platform account
  with no split (paid out by hand). Bookings, balances, the payment
  return page, both webhooks and corporate top-ups all ask it. Top-ups
  used to go to the platform account unsplit, whatever the business.
- **Payment return address.** Bookings from the room and category pages
  and balance payments returned to `PAYSTACK_CALLBACK_URL`
  (`/paystack/callback`), which had no route. Every booking payment now
  returns to `/stays/pay/callback` on the address the guest booked on,
  which only looks at that business's payments. `PAYSTACK_CALLBACK_URL`
  is gone.
- **Platform webhook.** Payments through the platform account notify the
  one webhook address set on that account. It is now
  `https://{CENTRAL_DOMAIN}/paystack/webhook`
  (`PlatformPaystackWebhookController`), which finds the business by the
  reference and hands the event to its booking or top-up webhook. A
  business with its own Paystack account sets
  `https://{its address}/paystack/booking-webhook` in its own dashboard.

- **`/stays` pages were cross-business.** Keverna's marketplace looked up
  properties and bookings across every business, so on one business's
  address another's property page opened, could be booked, and
  `/stays/pay/success/{ref}` showed any business's confirmation. Those
  lookups now only see the current business. (The `/stays/{slug}` page
  itself has been broken since the port: it links to a removed route. The
  new booking site replaces it.)

## Stays marketplace

`/stays` on the central domain lists every business that opted in
(Settings → Business, `tenants.listed_on_stays_at`; empty by default).

- `App\Platform\StaysDirectory` is the only guest-facing reader across
  businesses. It shows live buildings with a bookable apartment type,
  for businesses in Trial or Active, so pausing one removes it at once.
  `properties.is_listed_publicly` is Keverna's opt-out, which defaults to
  on, so it isn't treated as consent.
- With dates it asks each business's own `BookingSite`, in that business's
  context, so availability and totals match its site.
- Booking stays on the business's address: cards link to `/apartments`
  there, carrying the search. That needs the wildcard DNS (step 8).
- `/stays` on a business's own address still redirects to its apartments.

## Sign-up switch

`SIGNUP_OPEN` (config `keverna.signup_open`, off by default) lets the
platform go live for existing businesses before sign-up opens. While off,
/signup shows "Sign-up isn't open yet" with the help email, the home page
swaps the address picker for the same, and every sign-up step refuses on
the server. Tests run with it on.

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
  address with the one-time `PostRegistrationLoginLink`. The email is
  already confirmed, so they land straight on the dashboard. The phone
  number is stored unverified.
- `/login` on the platform finds a business by address, or emails a link to
  each business's staff login. It never says whether an email exists.
- Staff emails are now unique per business (`operators.tenant_id, email`),
  so one person can work at several businesses. Sign-up also links the
  manager to a login identity so the mobile apps can sign them in.
- Not built: "used on this device" on the platform login (sessions don't
  cross addresses, so it needs its own cookie), and a "What does it cost?"
  answer, left out until pricing is decided.

## Branch records

`php artisan branches:check` is read-only. Forms once filed everything under
a business's first branch, so run it before cut-over: it lists rooms, rate
rules and reviews that disagree about their branch, and branches with no
rooms. A room filed under the first branch with its category looks normal,
so the business has to confirm those.

## Not mirrored to keverna-solo

Everything in this plan stays in centurion-hms. keverna-solo remains a
single-hotel product. Generic bug fixes found along the way can still be
mirrored.
