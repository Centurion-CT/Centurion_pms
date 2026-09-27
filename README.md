# Keverna Solo

A single-tenant, self-hosted property management system for one hotel
per install. Forked from Keverna SaaS with the platform admin,
subscription/billing, cross-tenant marketplace, and central-identity
flows removed — everything else (bookings, reservations, in-house
guests, housekeeping inspections, POS, inventory, corporate portal,
guest portal, mobile API) is intact.

The install pattern is one PostgreSQL/MySQL database + one PHP app
server + one Redis + one queue worker + one scheduler per hotel. No
shared infrastructure, no per-tenant fees, no external kill-switch.

- [What you get](#what-you-get)
- [Requirements](#requirements)
- [Install](#install)
- [First run — the setup wizard](#first-run--the-setup-wizard)
- [Going live checklist](#going-live-checklist)
- [Multi-branch](#multi-branch)
- [Day-two operations](#day-two-operations)
- [Backups](#backups)
- [Upgrading + upstream sync](#upgrading--upstream-sync)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)

---

## What you get

- **Front-desk console** — reservations, in-house guests, walk-in
  booking, split payments, room reassignment, housekeeping override
  during check-in, extend stay, checkout with early/late fees.
- **Rooms + categories + rates** — per-category pricing, weekly /
  monthly discounts, deposit policies, min-nights, long-stay support.
- **Housekeeping** — per-room inspection chain (cleaner → porter →
  supervisor), damage flags, room flagging by managers, rooms-needing
  attention widget, per-inspection audit trail.
- **Guest QR portal** — per-branch QR that guests scan for extend /
  check-out / rate / complaint / taxi / transfer / order / reserve,
  with Paystack card payment.
- **POS + inventory** — outlets, product catalogue, stock model
  (central store vs per-department), purchase orders, low-stock
  digest, per-shift reconciliation.
- **Corporate portal** — self-service login for corporate accounts,
  wallet top-up via Paystack, member/booking management.
- **Public stays page** — the hotel's own booking site at `/`, with a
  branded landing OR the Keverna Stays marketplace layout (togglable).
- **Mobile API** — bearer-token endpoints for the housekeeper /
  inspector app.
- **Reports** — weekly + monthly summary PDFs emailed to subscribers.

## What you don't get (removed in the fork)

- Platform admin dashboard, subscription/trial billing, cross-tenant
  marketplace discovery, tenant signup + license codes, unified
  central login, domain sharding, custom-domain verification. See
  [`docs/UPSTREAM-SYNC.md`](docs/UPSTREAM-SYNC.md) for the full list.

---

## Requirements

- PHP 8.2 or newer
- Composer 2
- Node 20 + npm (for the frontend build)
- Redis 6+
- One of: SQLite (dev only), MySQL 8+, PostgreSQL 14+
- SMTP mailer credentials (SendPipe, Postmark, SES, or any other)
- Paystack account with a secret key (for card payments — the guest
  QR portal, public stays checkout, and corporate wallet top-up all
  route through Paystack)

## Install

```bash
git clone <your-solo-host> keverna-solo
cd keverna-solo

composer install --no-dev --optimize-autoloader
npm ci
npm run build

cp .env.example .env
php artisan key:generate --force

# Edit .env — see the Configuration Reference below.
# At minimum, set: APP_URL, DB_*, REDIS_HOST, MAIL_*, PAYSTACK_*
$EDITOR .env

php artisan migrate --force
php artisan storage:link
```

Point your webserver at `public/index.php` (nginx sample lives in
[`docs/deployment.md`](docs/deployment.md); ignore the tenant-subdomain
routing sections, they don't apply here).

Now hit the URL in a browser. Any first request lands you at
`/setup` — the one-time install wizard.

---

## First run — the setup wizard

The wizard runs exactly once, when the DB has no `tenants` row yet.
It creates the singleton tenant + first property + first Manager
operator inside a single database transaction, then logs you in.

Fields:

| Section | Field | Notes |
|---|---|---|
| Hotel | Name | The property's public name — appears in emails, receipts, mobile app. |
| First branch | Name | Displayed in the sidebar. If this is your only branch, "Main" is fine. |
| First branch | Type | `hotel`, `serviced apartment`, `short let`, `long stay`, `workspace`, or `residential`. Drives which parts of the operator dashboard show up. |
| First branch | Address, City, State | Address prints on invoices + shows on the public site. |
| Manager account | Full name | Displayed on receipts + audit log. |
| Manager account | Email | You'll sign in with this address. |
| Manager account | Password | Minimum 10 characters. Store it in a password manager. |

The wizard creates a `Manager` role operator, which has access to
every part of the dashboard. Once you're in, use `/staff` to invite
front-desk, housekeeping, POS, and stockkeeper roles.

**Rerunning the wizard**: once a tenant exists, `/setup` returns 404.
To re-seed, `truncate tenants;` on the DB and refresh.

---

## Going live checklist

Post-wizard you land on `/dashboard`. To take a real booking, walk
through this list:

1. **Room categories** — `/dashboard/room-categories` → add at least
   one (e.g. "Deluxe King"). Set the base rate, capacity, deposit
   rules, and min-nights here.
2. **Rooms** — `/dashboard/rooms` → add real inventory (room 101,
   102...). Every room belongs to a category, which is where the
   pricing lives.
3. **Housekeeping checklist** — `/dashboard/housekeeping` → open the
   "Checklist templates" section and attach one to your category so
   inspections know what to check.
4. **Publish the public site** — `/settings/property`:
   - **Site live**: toggle on so `/` renders your branded landing.
   - **Marketplace stays layout** (optional): toggle
     `is_listed_publicly` on if you'd prefer the OTA-style layout with
     room cards + date search over the operator-branded landing. Off
     by default.
5. **Paystack** — `/settings/property` → paste your Paystack secret
   key so card payments (guest QR portal, public checkout, wallet
   top-up) route to your account. Test with a small charge before
   going live.
6. **Email branding** — replace the placeholder `mail/*` templates or
   just set `APP_NAME` in `.env` so the auto-signature reads
   "The <YourHotel> team".
7. **Add staff** — `/staff` → invite housekeeping, front desk, POS
   staff. Each gets a magic-link claim email; when they set a
   password, their role controls which parts of the sidebar they can
   see.
8. **First booking** — from `/dashboard/reservations` click "Reserve"
   and walk a test booking end-to-end. Check the calendar, in-house
   list, and payments page all update.

---

## Multi-branch

Solo installs can run multiple properties on one deployment. Every
booking, room, POS sale, and inspection carries the property_id it
was created against; operators either pick their branch from the
switcher in the sidebar or are pinned to one via
`/staff/{operator}/branches`.

- `/settings/branches` — create a second branch. New branches start
  with `is_live = false` so you can seed them privately before turning
  them public.
- On `/` (the public site) — if there's exactly one branch, `/`
  renders it. With multiple branches, `/` renders a chooser card
  linking to `/branches/{slug}`.
- Booking data + inventory is scoped per branch; wallet balances +
  operator sessions are tenant-wide.

## Day-two operations

Two long-running processes need to stay up alongside the web app:

**Queue worker** — most background work (email sends, review invites,
scheduled reminders, POS sale confirmation callbacks) routes through
Redis:

```bash
php artisan horizon
```

Wire this as a systemd service (or supervisor / pm2 unit) that
restarts on exit. `docs/deployment.md` has the systemd template.

**Scheduler** — the cron-defined jobs (auto-cancel expired holds,
balance-due reminders, low-stock digest, summary reports, corporate
membership renewals) fire from Laravel's scheduler:

```bash
* * * * * cd /path/to/keverna-solo && php artisan schedule:run >> /dev/null 2>&1
```

Exactly one instance. Two schedulers would double-fire every cron
job.

## Backups

Nothing about a solo install is federated — losing the DB loses
everything (reservations, guest data, payment history, POS ledger).
At a minimum, run a nightly logical dump:

```bash
mysqldump --single-transaction --routines keverna_solo > backup.sql
# or
pg_dump keverna_solo > backup.sql
```

Ship the dump off-machine (S3, Backblaze B2, an object store from
Dokwe or similar). The `storage/app/` directory holds uploaded media
(room photos, guest ID scans, receipts) and needs the same treatment.

For daily restore verification, spin up a throwaway DB, load the
dump, boot the app against it. If `/dashboard` renders + a random
booking's payment total matches, the backup is good.

## Upgrading + upstream sync

The fork tracks a copy of the SaaS Keverna repo as `upstream`. When
Keverna ships a bug fix, cherry-pick it in:

```bash
git fetch upstream
git log --oneline origin/main..upstream/main
git cherry-pick <sha>
```

Not every upstream commit belongs here — admin, subscription, central
identity, and cross-tenant marketplace commits should be skipped.
Full playbook: [`docs/UPSTREAM-SYNC.md`](docs/UPSTREAM-SYNC.md).

## Configuration reference

Only the solo-relevant subset of `.env` — everything else in
`.env.example` is either a passthrough to Laravel or a legacy
SaaS-only knob that's a no-op here.

| Key | Required? | Notes |
|---|---|---|
| `APP_NAME` | required | Displayed in email signatures + browser title. Set it to your hotel's short name. |
| `APP_ENV` | required | `production` in real deployments. Anything else enables the /dev/mail preview routes. |
| `APP_KEY` | required | Generated by `php artisan key:generate` on install. Never rotate — it decrypts stored Paystack secrets, Dokwe credentials, and identity tokens. |
| `APP_URL` | required | Full URL with scheme (`https://book.myhotel.com`). Used to build QR codes + email links. |
| `APP_DEBUG` | required | `false` in production. |
| `DB_CONNECTION` | required | `mysql` or `pgsql` for production; `sqlite` is dev-only. |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | required | Standard Laravel DB config. |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | required | Backs cache, sessions, and the Horizon queue. |
| `SESSION_DRIVER` | required | `redis` in production. |
| `QUEUE_CONNECTION` | required | `redis` in production. `sync` for dev. |
| `MAIL_MAILER` | required | `smtp`, `postmark`, `sendpipe`, etc. |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_ENCRYPTION` | required | Only when `MAIL_MAILER=smtp`. |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | required | The `From:` header on outbound mail. |
| `PAYSTACK_MASTER_SECRET` | recommended | Optional platform key used for corporate wallet top-ups. Leave blank to disable that flow; per-property Paystack keys entered in `/settings/property` still power card checkout. |
| `SENDPIPE_API_KEY` | optional | Only if `MAIL_MAILER=sendpipe`. |
| `DOKWE_*` | optional | S3-compatible media storage. If unset, MediaLibrary falls back to `storage/app/public/`. |
| `HORIZON_ENABLED` | optional | Set to `false` if you're running a different queue supervisor. |

Config knobs that no longer do anything (safe to leave in
`.env.example` for now, will get pruned):
`CENTRAL_DOMAIN`, `ADMIN_DOMAIN`, `TENANT_DOMAIN_SUFFIX`,
`CUSTOM_DOMAIN_CNAME_TARGET`, `SUBSCRIPTION_ANNUAL_NGN`, `TRIAL_DAYS`,
`SUBDOMAIN_PROVISIONING_MODE`.

## Troubleshooting

**`/setup` returns 404 but I want to re-run the wizard**  
The wizard is one-shot by design. Truncate the `tenants` table (which
cascades to properties + operators via FK) and refresh:
`echo "delete from tenants;" | mysql keverna_solo`.

**Any URL redirects to `/setup` unexpectedly**  
The `NoCurrentTenant` handler bounces here whenever the tenant
resolver can't find a `tenants` row. Verify with
`select id, name, slug from tenants;` — if empty, the seed didn't
complete. If populated, check application logs for a downstream error
that's masking the real cause.

**`Vite manifest not found`**  
`npm run build` hasn't been run since the last deploy. Rerun it and
`php artisan view:clear`.

**`No application encryption key has been specified`**  
`.env` is missing `APP_KEY`. Run `php artisan key:generate --force`
once, then never rotate it.

**Paystack callbacks 401 or don't fire**  
Confirm the webhook URL in your Paystack dashboard matches
`APP_URL/paystack/booking-webhook` (per-property) or
`APP_URL/paystack/corporate-wallet-topup-webhook` (master key), and
that the signing secret in `/settings/property` matches your Paystack
dashboard's "Test secret" or "Live secret" depending on environment.

**A guest reports the booking didn't confirm even though Paystack
charged them**  
Every payment writes a `payments` row before the redirect. Find it by
reference:  
`select * from payments where paystack_reference = 'kev_...';`  
If `status = 'succeeded'` and no booking flipped to `Confirmed`,
`ConfirmBookingPayment` failed downstream — check the Horizon failed
jobs list (`/horizon/failed`).

**Housekeeper mobile app auth fails**  
Mobile login uses a bearer token minted from the operator's email +
password. The operator must have `role = house_cleaner`, `porter`, or
`supervisor`. Managers + frontdesk can log in too but the app only
exposes housekeeping surfaces.

---

Everything else — support, feature requests, cherry-picking upstream
fixes — routes through the same channel you got this fork from.
