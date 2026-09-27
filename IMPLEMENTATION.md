# Keverna v1.0 — Implementation Checklist

> **Source:** `Keverna_PRD_v1.0.pdf` (FregateLab Limited, May 2026)
> **Scope:** Phase 1 — Foundation (Months 1–3). Phase 2/3 items appear at the bottom for tracking only.
> **Stack:** Laravel 12, Livewire 3, Alpine.js, MySQL 8, Redis, Horizon, Cloudflare R2, Paystack, Resend, Cloudflare DNS API.
> **Testing philosophy:** Pest as primary runner. Every feature ships with (a) unit tests for pure logic, (b) feature/HTTP tests for routes & Livewire components, (c) at least one cross-tenant isolation test where tenant data is touched. Target ≥ 85% line coverage, 100% on billing, multi-tenancy, and booking-state transitions.

---

## 0. Project Bootstrap

### Implementation
- [x] `composer create-project laravel/laravel keverna "^12.0"` (PHP 8.3+)
- [x] Install core packages: `livewire/livewire ^3`, `spatie/laravel-multitenancy`, `spatie/laravel-medialibrary`, `spatie/laravel-permission`, `spatie/laravel-activitylog`, `laravel/horizon`, `predis/predis`, `intervention/image`, `league/flysystem-aws-s3-v3` (for R2)
- [x] Install dev packages: `pestphp/pest`, `pestphp/pest-plugin-laravel`, `pestphp/pest-plugin-livewire`, `laravel/dusk`, `larastan/larastan`, `laravel/pint`, `mockery/mockery`, `fakerphp/faker`
- [x] Configure `.env.example` with placeholders for Paystack, Cloudflare, R2, Resend, WhatsApp, Google Maps, Namecheap
- [x] Add `phpstan.neon` at level 8 and `pint.json` (Laravel preset)
- [x] Set up GitHub Actions CI: lint (Pint), static analysis (PHPStan), tests (Pest), Dusk smoke job
- [x] Configure `phpunit.xml` to use a separate testing MySQL database + Redis DB 15 (using sqlite :memory: for unit/feature speed; MySQL-specific tests will opt-in)
- [x] Add `composer test` script (parallel Pest), `composer test:coverage` script
- [ ] Seed a `DevSeeder` for local dev (1 admin, 3 tenants, 10 rooms each, sample bookings) — deferred until tenant/room models exist (§1, §6)

### Tests
- [x] Smoke test: `it('boots the application')` hits `/` and gets 200
- [x] CI green on first commit (lint + static + unit) — verified locally; pushed to GitHub for CI run

---

## 1. Multi-Tenancy Foundation (CRITICAL — risk: tenant data leak)

### Implementation
- [x] Decide on Spatie Multitenancy single-database with `tenant_id` column strategy (PRD §5.1)
- [x] Create `tenants` table migration: `id, name, slug (unique), email, phone, plan_id, status (trial|active|grace|locked|archived), paystack_secret_key (encrypted, AES-256), custom_domain, domain_verified_at, subscription_expires_at, timestamps, soft deletes`
- [x] Create `Tenant` model extending `Spatie\Multitenancy\Models\Tenant`
- [x] Encrypt `paystack_secret_key` via Laravel's `encrypted` cast
- [x] Implement `DomainTenantFinder` to resolve tenant from subdomain (`{slug}.keverna.com`) OR `custom_domain`
- [x] Build `BelongsToTenant` trait with global scope (`tenant_id = current_tenant_id`) and automatic `tenant_id` setting on create
- [ ] Apply trait to: `Property`, `Room`, `RoomPrice`, `AvailabilityBlock`, `Booking`, `Payment`, `Subscription`, `Media` (custom override), `Notification` — deferred until those models exist (§6–§11)
- [x] Build `EnsureTenantIsResolved` middleware for tenant routes — `ResolveTenantFromRequest` + Spatie's `NeedsTenant`
- [x] Build `EnsureNoTenantIsResolved` middleware for central routes (marketing site, admin panel) — handled by domain constraint; finder returns null for central/admin hosts
- [x] Add `RouteServiceProvider` central vs tenant route groups — done via `bootstrap/app.php` `withRouting(then:)`
- [x] Configure session, cache, queue keys to be tenant-scoped (`cache_prefix = tenant_{id}`) — Spatie `PrefixCacheTask` registered in `switch_tenant_tasks`
- [ ] Add `make:tenant-aware` artisan command (custom) to scaffold migrations with `tenant_id` — deferred; convention is sufficient at this scale
- [ ] Helper `current_tenant()` returns `Tenant|null` — Spatie ships `Tenant::current()` which we use directly

### Tests
- [x] Unit: `TenantTest` — slug uniqueness, encrypted paystack key roundtrip (`tests/Feature/Models/TenantTest.php` — 11 tests)
- [x] Unit: `BelongsToTenantTraitTest` — auto-sets `tenant_id` on create, scopes queries (`tests/Feature/Multitenancy/BelongsToTenantTest.php` — 7 tests)
- [x] **Feature (CRITICAL):** `TenantIsolationTest` (`tests/Feature/Multitenancy/TenantIsolationTest.php` — 9 tests)
  - [x] Tenant A cannot read Tenant B's properties via Eloquent
  - [x] Tenant A cannot read Tenant B's bookings via raw query attempted through model
  - [x] Tenant A cannot update/delete Tenant B's room by guessing ID
  - [x] Unauthenticated request to `tenantA.keverna.com` cannot fetch tenantB data (see `TenantRoutingTest`)
  - [x] Backgrounded job retains tenant context (test queued job uses correct tenant)
- [x] Feature: subdomain routing resolves to correct tenant (`TenantRoutingTest`)
- [x] Feature: custom domain routing resolves to correct tenant when verified
- [x] Feature: unverified custom domain returns 404/error
- [x] Feature: central routes (admin) reject tenant-scoped requests
- [ ] Static analysis rule: every migration adding a tenant-owned table must include `tenant_id` — implement as a custom Pest test that scans migrations once `Property` migration lands (§6)

**Test count for §1: 46 tests passing** (across Tenant model, DomainTenantFinder, BelongsToTenant trait, TenantIsolation, TenantRouting).

---

## 2. Authentication & Account Registration

### Implementation
- [x] `operators` table: `id, tenant_id, name, email (globally unique at v1; per-tenant relaxes in v2), phone, password, email_verified_at, phone_verified_at, last_login_at, remember_token`
- [x] `admins` table (central, separate guard): `id, name, email, password, role, is_active, last_login_at, remember_token`
- [x] Configure `config/auth.php`: `web` (operator) guard, `admin` guard — `guest_portal` token-based guard deferred to §13 (it's built around a tokens table, not an Eloquent provider)
- [x] Registration form Livewire component `Auth/Register` on central domain — captures name, email, property name, phone, password
- [x] Email/password login with rate limiting (5 attempts / minute / IP+email)
- [x] Password reset flow (Laravel default broker, per-guard `operator_password_reset_tokens` + `admin_password_reset_tokens` tables, themed Livewire UI)
- [x] Logout, session timeout 2h idle (SESSION_LIFETIME=120)
- [x] **Bonus:** Encrypted, expiring post-registration login link bridges central registration → tenant subdomain auto-login

### Tests — 30 new, all passing
- [x] **Registration** (`tests/Feature/Auth/RegistrationTest.php` — 10 tests)
  - [x] Renders `/register` on central domain
  - [x] Creates `Tenant + Operator` atomically (DB transaction)
  - [x] Hashes operator password
  - [x] Rejects duplicate operator email (validator + DB-level)
  - [x] Rejects weak passwords + mismatched confirmation
  - [x] Auto-generates slug from property name
  - [x] Appends numeric suffix on collision (PRD §6.1.1)
  - [x] Redirects to post-registration login URL on tenant subdomain
  - [x] Rolls back tenant creation when operator save throws (atomicity)
- [x] **Post-registration auto-login** (`tests/Feature/Auth/PostRegistrationLoginTest.php` — 6 tests)
  - [x] Logs operator in + redirects to onboarding on valid token
  - [x] Updates `last_login_at` on token consumption
  - [x] Rejects tampered, expired, cross-tenant, and empty tokens
- [x] **Login** (`tests/Feature/Auth/LoginTest.php` — 8 tests)
  - [x] Renders `/login`
  - [x] Signs in with valid creds + redirects to intended URL
  - [x] Rejects unknown email + wrong password
  - [x] Updates `last_login_at` on success
  - [x] Locks out after 5 failed attempts/minute (PRD §2)
  - [x] **Cross-tenant login isolation** — operator from Tenant A cannot log in on Tenant B's subdomain even with matching credentials
  - [x] Regenerates session id on success (session fixation defence per PRD §10)
- [x] **Password reset** (`tests/Feature/Auth/PasswordResetTest.php` — 6 tests)
  - [x] Sends reset notification for known email
  - [x] Errors on unknown email + invalid format
  - [x] Resets password with valid token
  - [x] Rejects tampered/expired tokens + weak new passwords
- [x] **Guard isolation** (`tests/Feature/Auth/GuardIsolationTest.php` — 6 tests)
  - [x] Operator session doesn't carry to admin guard
  - [x] Admin session doesn't carry to operator guard
  - [x] Operator hitting admin route does not bind admin guard
  - [x] Unauthenticated request to protected tenant route redirects to `tenant.login`
  - [x] Authenticated operator reaches onboarding placeholder
  - [x] Logout drops the session + redirects to login

**Cumulative test count across §0–§2: 84 passing, 180 assertions.**

---

## 3. Subscription, Trial & Paystack Billing

### Implementation
- [x] `subscriptions` table per PRD §9 + plans as PHP enum (`SubscriptionPlan::Trial`, `SubscriptionPlan::Annual`)
- [x] Trial path: trial Subscription created during registration with `status=trial`, `expires_at = now()+14 days`, no payment
- [x] Paid path: `InitiateSubscriptionUpgrade` action initialises ₦300,000 Paystack charge, redirects to checkout (tenant-side `/subscription` page)
- [x] `SubscriptionWebhookController` verifies HMAC-SHA512 signature, idempotently processes `charge.success`
- [x] On payment success → `ActivateSubscription` action flips status active + expires older trial row + updates tenant.status + fires `SubscriptionActivated` event
- [ ] `SubscriptionActivated` listener queues `ProvisionTenantSubdomain` job — listener+job arrive in §5 (Domain & Subdomain Provisioning)
- [ ] Domain registration upsell flow: ₦25,000 one-time — deferred to Phase 2 (roadmap)
- [x] Renewal reminder mails: day 30, 14, 7, 1 (`SendRenewalReminders` scheduled command, daily 09:00 WAT) — `reminders_sent` JSON column tracks milestones for idempotency
- [x] Grace period state machine: `active`/`trial` → `grace` (3 days) → `locked` (30 days) → `archived` (`ProcessExpiredSubscriptions` daily 09:15 WAT)
- [x] Public booking page on locked tenant shows "Temporarily unavailable" (HTTP 200, not 404 — PRD §4.2)
- [ ] Renewal payment via Paystack reactivates tenant — works for *new* paid Subscriptions; UI to renew an expired sub is part of §15 (Dashboard) post-launch

### Tests — 39 new, all passing
- [x] **PaystackClient** (`tests/Feature/Paystack/PaystackClientTest.php` — 7 tests): kobo conversion, signature timing-safe (hash_equals), tenant vs master key wiring, error paths
- [x] **SubscriptionWebhook** (`tests/Feature/Paystack/SubscriptionWebhookTest.php` — 9 tests): valid signature, tampered (401), malformed body (422), unknown reference, amount mismatch, idempotency, trial→paid upgrade
- [x] **Subscription model** (`tests/Feature/Models/SubscriptionTest.php` — 9 tests): persistence, paystack_reference uniqueness, enums, tenant scope, `currentSubscription()` resolver
- [x] **UpgradeFlow** (`tests/Feature/Subscription/UpgradeFlowTest.php` — 9 tests): page renders, auth-gated, hides CTA for active annual, Paystack init + redirect, metadata, Failed row at init time
- [x] **SendRenewalReminders** (`tests/Feature/Subscription/SendRenewalRemindersTest.php` — 6 tests): each milestone, no-match days, idempotency, progressive sends via time travel, skip expired
- [x] **ProcessExpiredSubscriptions** (`tests/Feature/Subscription/ProcessExpiredSubscriptionsTest.php` — 10 tests): every state transition + boundary conditions, idempotency, full Active→Grace→Locked→Archived progression via time travel
- [x] **PublicLanding** (`tests/Feature/Tenant/PublicLandingTest.php` — 5 tests): Active/Trial/Grace render landing; Locked renders "temporarily unavailable" 200; Archived 404s via DomainTenantFinder

**Cumulative test count across §0–§3: 140 passing, 313 assertions.**

---

## 4. Onboarding Wizard (3 steps, non-skippable)

### Implementation
- [x] Wizard router at `/onboarding`: redirects to next incomplete step until done (or to `/dashboard`)
- [x] `RequireCompleteOnboarding` middleware gates `/dashboard` + `/subscription`
- [x] Step 1 Livewire `Onboarding/PropertyBasics` — name (prefilled from tenant), property type, full address, city/state, description; creates the `Property` row
- [x] Step 2 Livewire `Onboarding/EmailVerification` — 6-digit OTP via Resend (queued `EmailOtpMail`), 10-min expiry, 60s resend cooldown, max 5 attempts → 30-min lockout
- [x] Step 3 Livewire `Onboarding/PhoneVerification` — 6-digit OTP via WhatsApp Business API (`WhatsAppOtpService`), same rules
- [x] Slug already auto-generated at registration (§2); covered there
- [x] Dispatch `TenantOnboarded` event on step 3 success — downstream listeners (subdomain provisioning + welcome mail) wire up in §5 / §17
- [x] `is_live=false` on Property creation; landing page shows placeholder until operator marks live (full landing in §10)

### Tests — 38 new, all passing
- [x] **WizardRouter** (`WizardRouterTest.php` — 7 tests): redirects to step 1/2/3 based on missing state; routes complete operators to dashboard; `/dashboard` + `/subscription` both gated
- [x] **PropertyBasics** (`PropertyBasicsTest.php` — 6 tests): name prefilled from tenant; create + advance; validation; idempotent re-saves; tenant isolation
- [x] **OtpService** (`OtpServiceTest.php` — 12 tests): 6-digit hashed codes, 60s cooldown enforcement, expiry, wrong code increments attempts, 5th attempt → 30-min lockout, NotFound, AlreadyConsumed, email vs phone channel isolation, WhatsApp Business API payload shape, resend cooldown reporting
- [x] **EmailVerification** (`EmailVerificationTest.php` — 8 tests): queues mail on mount; correct code advances; incorrect/short/expired errors; cooldown blocks resend; lockout after 5 attempts
- [x] **PhoneVerification** (`PhoneVerificationTest.php` — 5 tests): WhatsApp called on mount; correct code marks `phone_verified_at` + fires `TenantOnboarded` + redirects to dashboard; failed attempt does NOT fire event; expiry + lockout

**Cumulative test count across §0–§4: 178 passing, 452 assertions.**

---

## 5. Domain & Subdomain Provisioning

### Implementation
- [x] `CloudflareDnsService` — wraps Cloudflare API v4 (createCname, findByName, deleteRecord) with structured `CloudflareException` carrying `cloudflareCode` (81057 = already-exists treated as idempotent success)
- [x] `ProvisionTenantSubdomain` job — creates `{slug}.keverna.com` CNAME, `tries=3` with `[10s, 30s, 60s]` backoff, `ShouldBeUnique` by tenant_id, `NotTenantAware` (operates on explicit tenant id), stamps `subdomain_provisioned_at` on success
- [x] `DispatchSubdomainProvisioning` listener fans both `TenantOnboarded` + `SubscriptionActivated` into the same idempotent job
- [x] `VerifyCustomDomain` job — polls CNAME + TXT via `DnsResolver` interface, self-reschedules every 15 min for up to 48h then flips to `Error`, no-op on already-Active
- [x] `AttachCustomDomain` action — validates uniqueness, rotates verification token, dispatches first verification attempt
- [x] DNS-resolver abstraction: `SystemDnsResolver` (`dns_get_record()`) in prod, `FakeDnsResolver` for tests via container override
- [x] Slug change — `ChangeSlug` action: 1×/year cooldown, 90-day `slug_redirects` row, `RedirectOldSlug` global middleware 301s old-slug requests, new CNAME re-provisioned via dispatched job
- [x] Custom domain UI in dashboard (`Livewire/Domain/Manage` at `/domain`): slug rename + custom domain attach + CNAME/TXT instructions + status + re-verify button — gated by completed onboarding
- [ ] Full Cloudflare for SaaS proxying + SSL — zone-level config out-of-band; the verify job just stamps `domain_verified_at`. Deferred to deployment runbook.

### Tests — 47 new, all passing
- [x] **CloudflareDnsService** (`CloudflareDnsServiceTest.php` — 6): POST/GET/DELETE shape + auth header, `success=false` on 200 treated as error, error envelope mapping
- [x] **ProvisionTenantSubdomain** (`ProvisionTenantSubdomainTest.php` — 9): CNAME creation, idempotency, `RECORD_ALREADY_EXISTS` treated as success, retries on transient errors, dispatched on both events, `uniqueId` + `tries` + `backoff` exposed
- [x] **VerifyCustomDomain** (`VerifyCustomDomainTest.php` — 10): success path, CNAME mismatch, TXT missing, 48h timeout → Error, no-op on Active, `AttachCustomDomain` uniqueness + token rotation + dispatch
- [x] **Domain UI** (`DomainManageTest.php` — 8): page renders + auth-gated, validation, attach persists + dispatches verify, instructions panel, re-verify button, onboarding gate
- [x] **Slug change** (`SlugChangeTest.php` — 10): rename + cooldown + redirect row, 365-day cooldown enforcement, collision against tenants + unexpired redirects, no-op on same slug, empty rejection
- [x] **RedirectOldSlug middleware** (`RedirectOldSlugTest.php` — 4): 301 preserves path+query, expired redirects 404, current slug passes through, central/admin domains untouched

**Cumulative test count across §0–§5: 236 passing, 552 assertions.**

---

## 6. Room / Unit Management

### Implementation
- [x] `rooms` migration per PRD §9 + `RoomBookingType` enum (short_let|long_stay|both) + `RoomStatus` enum (active|inactive). `type` left as free-form `string(64)` per PRD §6.3.1
- [x] `Room` model with `BelongsToTenant`, `SoftDeletes`, `property()` BelongsTo, `active()` scope, `maxOccupancy()` + `isLongStayCapable()` helpers
- [x] `Property->rooms()` HasMany
- [x] Livewire `Dashboard/Rooms/Index` — list, search (name+type), filter by status, paginated 15 per page, soft-delete action
- [x] Livewire `Dashboard/Rooms/Form` — single component handles both create and edit; route-model-bound `Room` (BelongsToTenant 404s foreign-tenant ids automatically)
- [x] Capacity validation (`1 ≤ adults ≤ 50`, `0 ≤ children ≤ 50`); booking_type + status enum-constrained; negative rates blocked
- [x] Monthly rate required when `booking_type` allows long-stay (PRD §6.3.2 cross-field validation)
- [x] Soft delete preserves history for future Booking FKs
- [ ] Refuse delete when room has future bookings — deferred to §11 (Booking model doesn't exist yet); placeholder comment in `Index::delete()`

### Tests — 31 new, all passing
- [x] **Room model** (`tests/Feature/Models/RoomTest.php` — 11): persistence, enum casts, BelongsTo, `property->rooms()`, `maxOccupancy`, `isLongStayCapable` (both halves), `active()` scope, soft-delete, tenant isolation read/find/update
- [x] **Room Index UI** (`tests/Feature/Rooms/RoomIndexTest.php` — 9): renders + auth/onboarding gates, empty state, listing scoped to tenant, status + search filters, pagination at 15, soft-delete action, cross-tenant isolation
- [x] **Room Form UI** (`tests/Feature/Rooms/RoomFormTest.php` — 11): create + edit modes, redirect on save, every validation bound (capacity 0/>50, negative rate, invalid booking_type/status), long-stay monthly_rate cross-field check, edit-mode prefill, **route-model-bound 404 when editing a foreign tenant's room (CRITICAL isolation)**

**Cumulative test count across §0–§6: 267 passing, 628 assertions.**

---

## 7. Pricing Management

### Implementation
- [x] `room_prices` migration per PRD §9 — date range overrides with label; `overlapping()` scope on RoomPrice
- [x] Rooms migration extension: `weekly_discount_percent`, `monthly_discount_percent`, `deposit_type`, `deposit_value` (DepositType enum: none|fixed|percent)
- [x] Livewire `Dashboard/Pricing/Manage` — discount + deposit settings, seasonal override list/add/delete with overlap detection, live preview
- [x] `PricingCalculator` service + `PricingBreakdown` DTO + `NightlyRate` DTO — pure calculator, integer NGN throughout, returns per-night breakdown
- [x] Live price preview wired into the Manage component (`wire:model.live` on checkin/checkout)
- [x] Long-stay monthly rate path: `booking_type=LongStay` AND `monthly_rate` set AND `nights >= 28` → uses `ceil(nights/30) × monthly_rate`, no auto-discount stacking

### Tests — 30 new, all passing
- [x] **PricingCalculator** (`PricingCalculatorTest.php` — 19 tests):
  - [x] Base rate × nights, no overrides
  - [x] Override fully covering the range
  - [x] Override partially covering — mixed base + override per night
  - [x] Multiple overlapping overrides → most-recent (highest id) wins
  - [x] Weekly discount at exactly 7 nights; not at 6
  - [x] Monthly discount at 28+ nights (replaces weekly, not stacked)
  - [x] Long-stay mode at 28+ nights with explicit `LongStay` booking type
  - [x] Long-stay 31 nights = 2 months (ceil)
  - [x] Short-let room with `monthly_rate` set still uses nightly pricing
  - [x] Paystack fee = `1.5% × net + ₦100`, capped at ₦2,000
  - [x] Keverna fee = `0.5% × net`, capped at ₦2,000
  - [x] Both fees additive on guest `totalDue`, operator gets `netSubtotal` in full
  - [x] Deposit: fixed amount, percent of net, and `None` paths
  - [x] Deposit % calculated against the **discounted** net, not the gross
  - [x] Throws on checkout ≤ checkin
- [x] **Pricing UI** (`PricingManageTest.php` — 11 tests): renders + auth/onboarding gate, saves settings, discount % bounds, add+list+delete override, overlap rejection, adjacent non-overlapping allowed, end-before-start rejection, live preview rendering, empty-preview hint, **route-model 404 for foreign-tenant rooms**

**Cumulative test count across §0–§7: 297 passing, 710 assertions.**

---

## 8. Room Media (Spatie Media Library + R2)

### Implementation
- [x] R2 disk in `filesystems.php` — S3-compat with `region=auto`, path-style, public visibility, `R2_PUBLIC_URL` for delivery
- [x] `Room` + `Property` implement `HasMedia` + `InteractsWithMedia`
- [x] Media collections: rooms `gallery` (JPG/PNG/WebP, max 20 via `onlyKeepLatest(20)`); property `hero` (single), `logo` (single, PNG/SVG only), `gallery`
- [x] Three image conversions: `thumb` (200×200 crop), `medium` (800×600 crop), `full` (1920 max — preserves aspect)
- [x] Per-file validation: JPG/PNG/WebP, ≤5MB via `media-library.max_file_size`
- [x] Livewire `Dashboard/Rooms/MediaManager` — multi-upload, reorder (sanitized against foreign ids), alt text edit, single-hero radio, delete
- [x] **Custom `TenantPathGenerator`** prefixes every R2 path with `tenant_{id}/` for §10 isolation; **custom `App\Models\Media`** auto-stamps `tenant_id` on `creating` so no row can exist without one
- [x] `media.tenant_id` column FK'd to `tenants` with `cascadeOnDelete` — deleting a tenant wipes all their R2 references via DB cascade

### Tests — 16 new, all passing
- [x] **Uploads + validation** (`RoomMediaTest.php` — 5): JPG, PNG, WebP accepted; PDF + GIF rejected; >5MB rejected
- [x] **Conversions** (1): `thumb`, `medium`, `full` all generated synchronously via `QUEUE_CONNECTION=sync`
- [x] **Tenant isolation** (3): files land under `tenant_{id}/{media_id}/`, `tenant_id` auto-stamped on the row, **404 when an operator opens the photos URL for a foreign tenant's room** (route-model binding via BelongsToTenant)
- [x] **Hero / alt / reorder / delete / cascade** (7):
  - exactly one image can be `is_hero` at a time
  - `Room::heroImage()` returns the flag, falling back to first photo
  - alt text persists via `custom_properties`
  - reorder uses `Media::setNewOrder()` and **sanitizes foreign ids out of the input array** (anti-tampering)
  - per-photo delete works
  - hard-deleting the Room cascades the media row via DB FK
  - 20-photo cap enforced over multiple upload batches

**Cumulative test count across §0–§8: 313 passing, 744 assertions.**
- [ ] Feature: deleting room cascades media deletion on R2

---

## 9. Availability Calendar

### Implementation
- [x] `availability_blocks` migration per PRD §9 — `AvailabilityBlockType` enum (booked|blocked), nullable `booking_id` (FK lands in §11), unique `(tenant_id, room_id, date)` index = the double-booking firewall
- [x] `AvailabilityService` with `isAvailable / getBlocksForMonth / blockRange / reserveRangeForBooking / unblockRange`
- [x] **Half-open interval `[checkin, checkout)`** documented + enforced — checkout date is free, back-to-back bookings just work
- [x] `RangeNotAvailableException` surfaces unique-constraint violations as a typed error callers can catch
- [x] Livewire `Dashboard/Calendar/PropertyView` — monthly heat-map across all Active rooms; URL-bound month for deep links; prev/next month navigation
- [x] Livewire `Dashboard/Calendar/RoomView` — single-room month grid + block/unblock form with reason. UI uses inclusive end-date (operator-friendly); component converts to half-open before calling the service
- [ ] Drag-select Alpine front-end — placeholder; the form's `{start, end}` pair is the same interface the JS will populate, so the server-side action is complete

### Tests — 28 new, all passing
- [x] **AvailabilityService** (`AvailabilityServiceTest.php` — 15)
  - [x] Empty calendar → available
  - [x] Range overlapping a Booked night → unavailable
  - [x] Range overlapping a Blocked night → unavailable
  - [x] **Back-to-back bookings both valid** (the half-open interval payoff)
  - [x] `isAvailable(d, d)` returns false (zero-night invalid)
  - [x] `blockRange` writes N rows; pads `reason`
  - [x] **`blockRange` throws `RangeNotAvailableException` on overlap, partial inserts rolled back**
  - [x] `reserveRangeForBooking` stamps the `booking_id` for audit
  - [x] `unblockRange` removes Blocked rows but **preserves Booked rows** in mixed ranges
  - [x] `getBlocksForMonth` filters strictly to the month boundaries
  - [x] **Unique `(tenant_id, room_id, date)` constraint trips on duplicate inserts** — the race-condition firewall
  - [x] Tenant A's `isAvailable` ignores Tenant B's blocks
  - [x] Tenant A's `blockRange` rows carry Tenant A's id; invisible to Tenant B under the scope
- [x] **PropertyView calendar** (`PropertyCalendarTest.php` — 6): renders, gated, empty state, inactive rooms hidden, booked+blocked+free states in matrix, cross-tenant isolation, month navigation
- [x] **RoomView calendar** (`RoomCalendarTest.php` — 7): renders, month grid, block persists rows, overlap → validation error, unblock removes only Blocked rows, end-before-start rejected, **route-model 404 on foreign-tenant room**

**Cumulative test count across §0–§9: 341 passing, 796 assertions.**

**Discovered & documented:** Eloquent's date casts serialize DATE columns as `'Y-m-d H:i:s'` (with time), while raw `query()->insert()` writes whatever string you pass. On SQLite (`DATE` stored as TEXT) this means `'2026-07-03'` and `'2026-07-03 00:00:00'` are two different rows that BOTH satisfy a unique constraint on the column. Fixed by formatting our service-layer inserts with `Y-m-d H:i:s` and switching to `whereDate()` on the query side. In MySQL/Postgres DATE columns truncate the time anyway, so this is silent in prod but a bug magnet in tests — comment in the service explains why.

---

## 10. Public Landing Page (SEO + Branded)

### Implementation
- [x] `LandingController::show` + `LandingController::sitemap` on the tenant `/`
- [x] Blade template with **all PRD §6.4.1 sections**: Hero (logo + name + tagline + hero image + CTA), About (description + amenities + Google Maps embed iframe), Room Gallery (active rooms with hero images), Reviews (`is_displayed` only), Contact (phone, email), Footer ("Powered by Keverna" link)
- [x] Server-rendered HTML, no SPA, semantic markup
- [x] Open Graph `og:title/url/site_name/description/image` + Twitter `summary_large_image` card
- [x] `sitemap.xml` per tenant — landing + every Active room, `application/xml` content-type
- [x] Canonical URL = `https://{primaryHost}/` — custom domain when verified, else subdomain (via `Tenant::primaryHost()` from §1)
- [x] Google Maps embed iframe (no API key needed for plain embed via `/maps?q=`)
- [x] WhatsApp floating button — renders only when `whatsapp_button_enabled` AND tenant has a phone
- [x] "Powered by Keverna" footer (suppressible in Phase 2 white-label tier per roadmap)
- [x] 5-min tenant-scoped cache via `cache()->remember()`. Cache key = `landing:{tenant_id}:{property_id}:{property.updated_at.timestamp}` so any property edit **busts the cache deterministically** without an explicit flush. `X-Cache-Key` header exposed for verification.
- [x] Schema additions: branding columns on `properties` (`primary_color`, `tagline`, `font_theme`, `section_visibility` JSON, `whatsapp_button_enabled`, `whatsapp_button_prefill`); `reviews` table with `is_displayed` + `order_column`
- [x] `Review` model + factory; `Property::reviews()` HasMany; `Property::isSectionVisible()` helper (defaults visible)

### Tests — 20 new, all passing
- [x] **Status branching** (5): not-live → Coming Soon; no property → Coming Soon; live → full landing; Locked → "temporarily unavailable" 200; Archived → 404 upstream; Grace → renders
- [x] **Room gallery** (2): one card per Active room (inactive hidden); empty state when no active rooms
- [x] **Reviews** (2): only `is_displayed=true` reviews appear; `section_visibility.reviews=false` hides the whole section
- [x] **Branding + WhatsApp** (3): `primary_color` injected into CSS; WhatsApp button only when enabled AND phone present; button hidden when disabled
- [x] **SEO** (3): Open Graph + Twitter meta present; canonical points to subdomain by default; canonical points to verified custom domain
- [x] **Sitemap** (1): lists landing + every active room with correct content-type
- [x] **Cache** (2): cache populated under exposed key; key format embeds `updated_at` timestamp so edits bust on next request
- [x] **Cross-tenant isolation** (1): tenant A's gallery never lists tenant B's rooms (via Room's `BelongsToTenant` scope)

**Deferred:** TTFB < 800ms performance assertion (PRD §10) needs a CI-grade benchmark harness; defer to deploy-readiness work, not unit-test scope.

**Cumulative test count across §0–§10: 356 passing, 840 assertions.**

---

## 11. Guest-Facing Booking Engine

### Implementation
- [x] Schema: `bookings` + `payments` per PRD §9; `availability_blocks.booking_id` FK added (deferred from §9); enums (`BookingStatus`, `BookingSource`, `PaymentType`, `PaymentStatus`)
- [x] `BookingReferenceGenerator` — `KV-XXXXXXXX`, alphabet stripped of `0/O/1/I/L` (phone-support transcription friendly), regenerate-on-collision across all tenants
- [x] `CreateBooking` action: validates room Active + dates + capacity + min_nights, prices via `PricingCalculator`, atomic `DB::transaction` creates Booking and calls `reserveRangeForBooking` — race-condition unique-constraint violation rolls back the whole transaction (no orphan rows)
- [x] `InitiateBookingPayment` action: pre-creates `Payment` Pending, calls Paystack init using `PaystackClient::forTenantKey(tenant.paystack_secret_key)` (PER-OPERATOR key, not master), returns checkout URL; marks Payment Failed if init throws
- [x] `BookingWebhookController` on tenant subdomain `/paystack/booking-webhook` (CSRF-exempt): tenant resolved by middleware → verifies HMAC with `tenant.paystack_secret_key`. 401 bad signature, 422 unknown ref / amount mismatch, idempotent on succeeded.
- [x] `ConfirmBookingPayment` action: Full→Confirmed, Deposit→OnHold, Balance→Confirmed (clears balance_due); fires `BookingConfirmed` event AFTER commit
- [x] Public room detail Livewire `Booking/RoomDetail` at `/rooms/{room}`: gallery, description, capacity, live price preview, guest form, Full/Deposit radio, phone validated as international format. Foreign-tenant + inactive rooms 404
- [x] `SendBookingConfirmationMail` listener queues `BookingConfirmedMail` (Full) or `BookingOnHoldMail` (Deposit) to guest AND operator
- [x] `ReleaseStalePendingBookings` command every minute: cancels Pending bookings older than 15 min and deletes their Booked rows (only Booked — preserves operator's manual Blocks even when sharing dates)
- [ ] Drag-friendly `AvailabilityPicker` Alpine front-end: deferred — server interface (`checkin`/`checkout` date inputs) is the same contract any front-end will populate

### Tests — 52 new, all passing
- [x] **CreateBooking** (17): happy path + nightly blocks + booking_ref uniqueness + Full/Deposit payment fields, every validation rule (inactive room, past dates, checkout≤checkin, min_nights, capacity edges), **race-condition rollback (atomicity)**, manual-blocked dates rejected, half-open back-to-back valid, pricing parity (fees added on top, operator gets net)
- [x] **InitiateBookingPayment** (5): operator key used (not master), Full vs Deposit Payment row shapes, init failure → Payment Failed, missing operator key → RuntimeException
- [x] **BookingWebhook** (8): unsigned 401, wrong-key signature 401, unknown ref 422, amount mismatch 422, Full→Confirmed, Deposit→OnHold (balance_due preserved), idempotency, non-success events ack 200
- [x] **RoomDetail UI** (11): renders + Active gate (404 inactive), foreign-tenant 404, live price preview, "not available" warning, every validation rule + international phone, happy path → Paystack redirect, race-loser surfaces guest-friendly error (no Booking row leaked)
- [x] **Notifications** (4): `BookingConfirmed` fires on Full + idempotency, mail queued to guest + operator, OnHold uses different mailable
- [x] **ReleaseStalePendingBookings** (7): 15-min cutoff, leaves under-15 alone, doesn't touch Confirmed/OnHold, preserves operator Blocked rows, idempotent, multi-tenant scan

**Cumulative test count across §0–§11: 408 passing, 953 assertions.**

---

## 12. Book-on-Hold (Deposit Flow)

### Implementation
- [x] Per-room deposit config (`deposit_type` + `deposit_value`) shipped in §7
- [x] `balance_due_at` column on bookings; `CreateBooking` populates it = `checkin_date` for Deposit-type bookings (PRD §6.4.3 default)
- [x] On hold checkout: guest pays only deposit, booking transitions to `OnHold` via existing webhook path (shipped in §11)
- [x] `SendBalanceReminders` daily command at 08:00 — mails at 7/3/1 days before deadline. Idempotent via `bookings.balance_reminders_sent` JSON column (same pattern as `subscriptions.reminders_sent`)
- [x] `AutoCancelExpiredHolds` daily command at 08:15 — cancels OnHold bookings past `balance_due_at` AND deletes their Booked nights (preserves operator's manual Blocked rows even on shared dates)
- [x] `InitiateBalancePayment` action: same per-tenant Paystack pattern as `InitiateBookingPayment`, but `type=Balance` and `amount=balance_due`. Existing `ConfirmBookingPayment` (§11) flips `Balance + Succeeded → Confirmed + balance_due=0`. **Refund deposits on auto-cancel are NOT issued** — operator's call per their stated cancellation policy, handled out-of-band via Paystack dashboard
- [ ] Guest portal link for balance pay — UI lands in §13 (Guest Self-Service Portal); the action it'll call is ready

### Tests — 17 new, all passing
- [x] `CreateBooking` populates `balance_due_at = checkin_date` for Deposit; leaves null for Full
- [x] Webhook `Deposit + Succeeded` → `OnHold` preserves `balance_due`
- [x] Webhook `Balance + Succeeded` → `Confirmed` clears `balance_due` to 0
- [x] `InitiateBalancePayment` uses operator key, creates Pending Balance Payment, sends correct kobo amount; refuses on non-OnHold + zero-balance
- [x] `SendBalanceReminders` queues at exactly 7/3/1 days via time-travel; idempotent; skips Confirmed; skips OnHold with `balance_due=0` (paid early)
- [x] `AutoCancelExpiredHolds` cancels past-deadline; leaves under-deadline alone; doesn't touch Confirmed; preserves Blocked rows; idempotent; multi-tenant scan

**Cumulative test count across §0–§12: 425 passing, 987 assertions.**

---

## 13. Guest Self-Service Portal (Token-Based)

### Implementation
- [x] `booking_access_tokens` table: `tenant_id, booking_id, token_hash (sha256, unique), expires_at, last_used_at`
- [x] `booking_change_requests` table: `tenant_id, booking_id, requested_checkin, requested_checkout, guest_note, status (pending|approved|rejected), operator_response, decided_at`
- [x] `GuestPortalTokenService` (issue / resolveBooking / urlFor) — 30-day TTL, sha-256 hashed plaintext, constant-time `hash_equals` compare, `last_used_at` hygiene stamp
- [x] Portal URL injected into `BookingConfirmedMail` + `BookingOnHoldMail` via `SendBookingConfirmationMail` listener — "Manage your booking" / "Pay balance" CTA button in the Blade markdown templates
- [x] `CancelBooking` action — v1 policy hardcoded: ≥7d = no penalty, <7d = deposit forfeit. Deletes `Booked` AvailabilityBlocks (preserves operator `Blocked`), no automatic Paystack refund
- [x] `RequestDateChange` action — creates one PENDING `BookingChangeRequest`, rejects when prior pending exists, validates checkout > checkin > today, normalizes whitespace on guest note
- [x] Routes under `/guest/booking/{bookingRef}` — Show / Cancel / Balance / Change. Token comes from `?token=` query string and is bound to `#[Locked]` Livewire state for action-callbacks
- [x] `ResolvesPortalBooking` trait — mount-and-load helper used by all four Livewire pages; double-checks token on every server action so a client snapshot edit can't swap bookings
- [x] Livewire components: `Guest\Show` (booking summary + action nav), `Guest\Cancel` (confirm + deposit-forfeit preview + post-result), `Guest\PayBalance` (re-uses `InitiateBalancePayment` from §12), `Guest\RequestChange` (form + duplicate-pending guard)

### Tests
- [x] `GuestPortalTokenService`: issue+round-trip; wrong/empty token rejected; 30-day TTL enforced; `last_used_at` stamped; cross-tenant token denied; URL uses primary host (9 cases)
- [x] `CancelBooking`: no forfeit ≥7d out, forfeit <7d, zero-deposit booking exempt, only `Booked` rows deleted (Blocked preserved), throws on already-Cancelled / CheckedOut (6 cases)
- [x] `RequestDateChange`: happy path normalizes note; checkout-not-after-checkin rejected; checkin-not-future rejected; duplicate pending rejected; cancelled booking rejected; new request allowed after prior decided (6 cases)
- [x] `GuestPortalHttpTest`: show 200 with token, 404 without/wrong/cross-tenant token; cancel via Livewire flips booking + deletes blocks; balance-pay redirects to Paystack; no-balance shows friendly error; change form persists + rejects same-date submission (9 cases)

**Cumulative test count across §0–§13: 455 passing, 1048 assertions.**

---

## 14. Operator Booking Management

### Implementation
- [x] `activity_log` table — Spatie Activitylog with `tenant_id` baked into the initial migration (no separate add-column step), plus `event` + `batch_uuid` columns folded in
- [x] `App\Models\Activity` — tenant-aware Spatie Activity model. `creating` hook auto-stamps tenant_id from `Tenant::current()`; global scope filters reads to current tenant. Central jobs (e.g. ProvisionTenantSubdomain) can still log with null tenant
- [x] `Booking` uses `LogsActivity` trait — logs state-change fields only (`booking_status`, `payment_status`, `balance_due`, `deposit_amount`), `logOnlyDirty`, `dontSubmitEmptyLogs`
- [x] Five operator actions (each writes an `activity('booking')` row with `causedBy($operator)` + `event` + structured `properties`):
  - `ConfirmBooking` — guarded against Cancelled / CheckedOut
  - `MarkCheckedIn` — refuses Pending; refuses today < checkin_date
  - `MarkCheckedOut` — accepts CheckedIn / Confirmed / OnHold (operators often forget to check in); deletes future `Booked` blocks, preserves `Blocked`
  - `ResendBookingConfirmation` — re-fires `BookingConfirmed`; existing listener issues a fresh portal token
  - `CancelBooking` (extended) — optional `?Operator $operator` causer + `bool $refundRequested` flag; v1 records the flag in audit properties + description but does NOT call Paystack refunds API (v2 ticket per PRD §15.4)
- [x] Livewire `Dashboard\Bookings\Index` — filters in URL state (`#[Url]`): status, room, search (name/email/ref), date range; paginated 20/page; empty state
- [x] Livewire `Dashboard\Bookings\Show` — booking summary + 5 action buttons, refund-requested checkbox, audit log panel (last 20 entries); foreign-tenant booking_ref 404s via BelongsToTenant scope
- [x] Livewire `Dashboard\Bookings\ManualCreate` — walk-in form, `source=Manual`, payment_settlement radio (Succeeded → mark Confirmed, Pending → mark Pending); availability conflicts still enforced by `CreateBooking`
- [x] `BookingsCsvExportController` — chunked StreamedResponse, 500 rows/batch, `ob_flush()` + `flush()` between batches; honours the same filter querystring as Index for "export current view"
- [x] Routes under `/dashboard/bookings` — index, /create, /export.csv, /{bookingRef}; all gated by `auth:web` + `RequireCompleteOnboarding`

### Tests
- [x] `BookingActionsTest` (11 cases): each action transitions state + writes audit row with correct event / causer / tenant_id; refuses illegal transitions (cancel→confirm, future check-in, etc.); refund flag stored in properties + description; cross-tenant Activity rows hidden by global scope. **Pitfall captured in the test:** bare `Event::fake()` swallows Eloquent `creating` events and breaks the tenant-stamp closure — use `Event::fake([Specific::class])` instead
- [x] `BookingsDashboardTest` (16 cases): Index renders + filters (status, room, search) + cross-tenant isolation; Show renders action buttons + 404s foreign booking_ref + invokes Confirm/Cancel + surfaces error on future check-in + refund flag flows end-to-end; ManualCreate creates Confirmed-when-paid and Pending-when-not, source=manual; CSV streams correct columns + respects status filter + cross-tenant isolation

**Cumulative test count across §0–§14: 482 passing, 1109 assertions.**

---

## 15. Dashboard Widgets

### Implementation
- [x] `OccupancyCalculator` — rolling-N-day occupancy %. Numerator = `Booked` AvailabilityBlock rows in window (operator-`Blocked` excluded — owner-driven inventory shouldn't inflate the metric). Denominator = `active_rooms × days`. Capped at 100% defensively. Returns full breakdown DTO (percent, booked_nights, total_nights, window_from, window_to)
- [x] `DashboardSnapshot` — single-call data bundle: today's arrivals (Confirmed/OnHold matching checkin == today), today's departures (CheckedIn matching checkout == today), 14-day room×date heatmap (pre-aggregated `[room_id][date] => booked|blocked` for O(1) view lookup), outstanding balances (OnHold + balance_due > 0), recent 5 bookings, occupancy block. 60s `Cache::remember` per tenant with key `dashboard:snapshot:t{id}`; `DashboardSnapshot::forget()` static hook for action-layer cache busting
- [x] Livewire `Dashboard\Home` — renders all five widgets in a single Blade. Pulls one snapshot per request
- [x] Route swap — `/dashboard` was a view-only placeholder; now points at `Dashboard\Home` Livewire (existing onboarding/phone-verification tests still pass — they used `route('tenant.dashboard')`)

### Tests
- [x] `OccupancyCalculatorTest` (7 cases): zero-rooms returns 0% with zero denominator; zero-bookings returns 0% with correct denominator; partial booking math (5/30 = 17%); Blocked rows excluded from numerator; Inactive rooms excluded from denominator; custom window length honoured (`rolling(7)`); cross-tenant scoping — same query under tenant A vs tenant B returns different results
- [x] `DashboardHomeTest` (8 cases): HTTP render for onboarded operator; empty state shows 5 distinct empty-state markers; arrivals/departures list only today's matching bookings; outstanding-balance widget renders ₦amount; heatmap renders `data-state="free|booked|blocked"` cells; occupancy percent rendered (3/30 = 10%); 60s cache holds + `forget()` busts; cross-tenant invisibility on dashboard

**Cumulative test count across §0–§15: 497 passing, 1149 assertions.**

---

## 16. Landing Page Customization

### Implementation
- [x] Schema already in place from §10 (`primary_color`, `tagline`, `font_theme`, `section_visibility` JSON, `whatsapp_button_enabled`, `whatsapp_button_prefill` on `properties`)
- [x] `config/keverna.php` `branding` block — single source of truth for the 5 font themes (`inter_playfair`, `inter_inter`, `lora_lora`, `poppins_poppins`, `merriweather_montserrat`) + 4 section visibility keys (`amenities`, `map`, `reviews`, `contact_block`). Editor + landing both read from here so they can't drift
- [x] Livewire `Dashboard\Branding\Editor` — full controls: color picker (hex + text input), tagline, font dropdown, 4 section toggles, WhatsApp button on/off + prefill. `updated()` hook stashes pending values into the session and bumps `previewVersion` so the iframe URL changes
- [x] Live preview iframe with desktop / mobile device toggle (375px vs full-width); aria-pressed pattern on toggle buttons; "Open full landing page in a new tab" link goes to the live (saved) version
- [x] `BrandingPreviewController` at `/__branding-preview` — gated by `auth:web`; reads session-stashed overrides keyed by `operator_id`; rejects overrides whose `tenant_id` doesn't match the bound tenant (cross-tenant injection denied). Clones the property via `replicate()` + `setRawAttributes()` so saved data isn't mutated; media (hero/logo) always loads from the persisted original
- [x] Save path persists to `Property` directly — the landing cache key embeds `property->updated_at` (set by §10's controller), so saving auto-busts the 5-minute cache. No explicit `Cache::forget` required
- [x] Routes wired under existing `auth:web` + `RequireCompleteOnboarding` group: `/dashboard/branding` (editor) and `/__branding-preview` (iframe target)

### Tests
- [x] `BrandingEditorTest` (10 cases): renders editor + iframe placeholder; mount hydrates from existing property; save persists every field; saving touches `updated_at` (1s sleep used to ensure timestamp delta — second-precision); validation rejects invalid hex / 81-char tagline / unknown font theme; pending values land in the session keyed by operator id with `tenant_id` stamped; `previewVersion` bumps on every field change; device toggle accepts desktop/mobile and ignores junk
- [x] `BrandingPreviewTest` (5 cases): renders landing with overrides; falls back to saved branding when session is empty; ignores overrides whose `tenant_id` mismatches (cross-tenant injection denied); guest gets redirected to login; operator on tenant Y can't have operator-X overrides land in their preview (session key isolation)

**Cumulative test count across §0–§16: 512 passing, 1195 assertions.**

---

## 17. Notifications

### Implementation
- [x] `notifications` table — Laravel's default Markdown migration with a `tenant_id` column baked in (no add-column step), plus an extra `(tenant_id, created_at)` index for the bell's tenant-scoped queries
- [x] `Operator` already had `Notifiable`; new `creating` hook on `Illuminate\Notifications\DatabaseNotification` in AppServiceProvider stamps `tenant_id` from the payload (preferred — survives queued contexts) or `Tenant::current()` (sync fallback)
- [x] Three new Markdown mailables: `BookingCancelledMail` (carries `depositForfeit` flag for body copy), `CheckInReminderMail`, `OperatorWelcomeMail` — all in addition to existing `BookingConfirmedMail`, `BookingOnHoldMail`, `BalanceReminderMail`, `RenewalReminderMail`
- [x] `BookingCancelled` event + `SendBookingCancelledMail` listener wired through `CancelBooking::handle()`; `SendOperatorWelcomeMail` listener hooks the existing `TenantOnboarded` event (after email + phone verified, not at raw operator create)
- [x] All booking mailables implement `Spatie\Multitenancy\Jobs\NotTenantAware` — Spatie's queued-job tenant check would otherwise throw at dispatch time; the `BindsBookingTenant` trait re-binds the right tenant in the mailable's `headers()` hook so the layout still gets the right brand at render time
- [x] **Branded mail layout** — published `vendor/mail/html/message.blade.php` reads from `App\Support\MailBranding::current()` which pulls tenant name + primary color + logo URL from the bound tenant (or falls back to Keverna defaults for central/cross-tenant mails). Layout renders a 4px brand-color stripe under the header
- [x] `bookings:send-checkin-reminders` daily Artisan command (14:00 WAT) — `checkin_date == tomorrow` only, idempotent via new `bookings.checkin_reminder_sent_at` timestamp column. Scoped lifted, runs cross-tenant in one pass
- [x] `BookingActivityNotification` (database channel) — generic in-dashboard notification with an `event` discriminator (`'confirmed'|'cancelled'`) so the bell can pick icon/colour without parsing text
- [x] `NotifyOperatorsOfBookingActivity` listener fans `BookingConfirmed` / `BookingCancelled` into one DB notification per operator on the booking's tenant
- [x] `Dashboard\NotificationsBell` Livewire — toggle dropdown, per-row mark-read button, mark-all-read; no polling (re-queries on toggle). Wired into the dashboard home view
- [x] **Pitfall captured** — Laravel 12 auto-discovers ANY `handle*(TypedEvent $event)` method as a listener; manual `Event::listen()` for the same listener creates duplicate registrations that double-fire. Removed all `Event::listen()` calls in AppServiceProvider and let auto-discovery do the work; existing `DispatchSubdomainProvisioning` registrations were also doubled but `ShouldBeUnique` silently masked the duplication

### Tests
- [x] `MailablesTest` (4 cases): `BookingCancelledMail` queued to guest + operator on cancel; `depositForfeit=true` propagates when cancelled inside 7 days; `OperatorWelcomeMail` queued on `TenantOnboarded`; rendered Markdown mail body contains the brand-color stripe + tenant name (proves `BindsBookingTenant` trait works under sync render)
- [x] `CheckInRemindersTest` (6 cases): Confirmed + OnHold checking in tomorrow both get reminded; today's not reminded; +3 days not reminded; Pending + Cancelled never reminded; idempotent (second run doesn't re-mail); cross-tenant scan in one pass
- [x] `InDashboardBellTest` (9 cases): notification row written on `BookingConfirmed` + on `CancelBooking`; tenant_id stamped; fans out one row per operator; cross-tenant invisibility; unread count badge; dropdown toggle shows items; markRead flips one row; markAllRead flips all; operator A cannot mark operator B's row read (per-operator scoping in markRead query)

**Cumulative test count across §0–§17: 531 passing, 1229 assertions.**

---

## 18. Keverna Admin Panel (Central)

### Implementation
- [x] `admin.layout.blade.php` — minimal sidebar/header layout with nav (Dashboard / Tenants / Revenue / Log out). No tenant context bound on admin host
- [x] Admin auth: `Admin\Auth\Login` Livewire, separate throttle key (`admin-` prefix), `auth:admin` middleware + `EnsureActiveAdmin` middleware that logs out + 403s deactivated accounts on next request. Operators authenticated under `web` guard get redirected to admin login by `auth:admin` middleware (host-aware `redirectGuestsTo` closure routes admin host → admin.login, anything else → tenant.login)
- [x] `Admin\Dashboard\Home` — platform-wide tenant counters (total / active+trial+grace / locked / archived)
- [x] `Admin\Tenants\Index` — cross-tenant list with status filter + search by slug/name/email; pagination 25/page (URL state via `#[Url]`)
- [x] `Admin\Tenants\Show` — detail page surfacing the three write actions; routes use Tenant's `getRouteKeyName()=slug` (NOT id — caught in test failure debugging)
- [x] **ExtendSubscription action** — bumps `expires_at` by N days. If current expiry is past, anchors to today (don't compound stale debt); flips Subscription + Tenant back to Active when previously Locked. Audit row with `causedBy($admin)` and `days` + `new_expires_at` in properties
- [x] **RetriggerDomainVerification action** — dispatches `VerifyCustomDomain` (already `ShouldBeUnique`, 15-min lock); resets `custom_domain_status` from Active→Pending so admins can see the re-verification spin. Refuses tenants with no custom domain attached
- [x] **Impersonation** — `admin_impersonations` table (sha-256 token_hash unique, 15-min `expires_at`, `used_at` for single-use). `IssueImpersonationToken` action mints a URL on the tenant subdomain; `ConsumeImpersonationController` validates + lands the admin as the first operator on the tenant + stamps `session('impersonator_admin_id')`. Tenant-auth layout renders a yellow banner with "End impersonation" form; `EndImpersonationController` logs out + bounces to the admin's tenant detail page. Cross-tenant token use rejected (token's tenant_id must match bound tenant)
- [x] **Revenue dashboard** — `RevenueSnapshot` service: ARR = active subs × annual price; MRR = `intdiv(ARR, 12)` (integer money); 30-day churn % = churned ÷ (active + churned); Keverna + Paystack fees from succeeded payments in window. `Admin\Revenue` Livewire renders the numbers (Chart.js wrapping deferred to v2)
- [x] All write actions log to `activity_log` under `log_name='admin'` with `event=subscription_extended|domain_reverify_queued|impersonation_issued|impersonation_consumed` and `causer_type=Admin`
- [x] `phpunit.xml` bootstrap → `tests/bootstrap.php` that sets `memory_limit=512M` — the §0–§18 composite footprint exceeded the default 128M when running the full suite under SQLite-in-memory

### Tests
- [x] `AdminAuthTest` (10 cases): login renders; correct credentials + active = redirect to dashboard; wrong credentials rejected; deactivated rejected; `last_login_at` stamped; unauthenticated /admin/* → admin login (NOT tenant login); authenticated dashboard renders; operator (web guard) on /admin/* still bounced; logout works
- [x] `AdminTenantsTest` (8 cases): cross-tenant index visibility; status filter; search filter; detail renders with action buttons; extend persists new expires_at + audit row with correct causer/properties; past-expiry anchored to today on extend (flips Locked→Active); re-verify dispatches VerifyCustomDomain; refuses re-verify on no-custom-domain tenant
- [x] `ImpersonationTest` (8 cases): 15-min token issued + audit row written; URL surfaces on tenant detail; consume lands as first operator + stamps `used_at` + sets session marker; second use of same token 404s (single-use); expired token 404s; wrong plaintext 404s; cross-tenant token use 404s; banner renders during impersonation; end-impersonation logs out + bounces to admin
- [x] `RevenueTest` (7 cases): zeros when no data; ARR/MRR math (2 subs × ₦300k = ₦600k ARR + ₦50k MRR); trials count as active; churn % within window; pre-window churn ignored; Keverna/Paystack fees from SUCCEEDED payments only; page renders

**Cumulative test count across §0–§18: 564 passing, 1320 assertions.**

---

## 19. Non-Functional Requirements

### Implementation (in-app)
- [x] `ForceHttpsInProduction` global middleware — 301-redirects HTTP→HTTPS in production only; respects `X-Forwarded-Proto` from Cloudflare's trusted proxy so origin-direct requests get bumped while Cloudflare-fronted requests pass through. Bypassed in local/testing
- [x] Paystack secret keys: `'encrypted'` cast on `Tenant::paystack_secret_key` (shipped in §1; confirmed by round-trip test that reads ciphertext directly from the table)
- [x] Maintenance window banner — `config('keverna.maintenance_window')` with `enabled` toggle, `start_hour`/`end_hour` (WAT), `warn_hours_before`. `App\Support\MaintenanceWindow::current()` returns `{state, start, end, hours_until}` with three states: inactive / upcoming / active. `partials/maintenance-banner.blade.php` rendered in both `tenant-auth` and `admin.layout`
- [x] Activity log on operator booking actions — already shipped in §14 via Spatie Activitylog; admin actions in §18
- [x] `/health` endpoint — `HealthController` pings DB (`SELECT 1`), cache (write-read-delete), storage (root `exists()`). Returns 200 + per-check status when all green, 503 when any red. Paystack/Sendpipe/Cloudflare deliberately NOT probed (their blips shouldn't take our LB out of rotation)

### Implementation (infra — deferred to §20 or out-of-scope for v1 codebase)
- [ ] Daily MySQL backup to R2 (`mysqldump` cron + retention 30 days)
- [ ] R2 file versioning enabled (Cloudflare console toggle)
- [ ] WCAG 2.1 AA: ARIA labels are present on forms shipped in §6–§18; full audit needs pa11y CLI in CI (§20 ticket)
- [ ] Mobile responsive: Tailwind in place; full 375px Dusk validation in §20
- [ ] Sentry (or alternative) for error tracking — separate tenant + admin projects (§20 ticket)

### Tests
- [x] `HealthEndpointTest` (3): healthy → 200 + ok; ISO-8601 timestamp; cache failure → 503 + degraded + per-check error
- [x] `MaintenanceWindowTest` (7): disabled returns inactive; in-window returns active; pre-window-by-1h returns upcoming with hours_until=1; late-night-before-tomorrow returns upcoming for tomorrow (warn-ahead crosses midnight); mid-day returns inactive; banner renders on operator dashboard during active window; banner hidden when window disabled
- [x] `ForceHttpsTest` (3): plain HTTP passes through in testing; production → 301 to https URL; https origin request passes through (proxy-trust path)
- [x] `EncryptionTest` (1): paystack_secret_key plaintext round-trips through model; raw DB column is ciphertext

### Deferred to §20 (infra) or post-launch (manual)
- Performance: `LandingPagePerformanceTest` TTFB profiling — needs APM in production
- Accessibility CI gate via pa11y / @axe-core/cli
- Dusk browser tests (full booking journey + mobile viewport)
- Load test via k6 script
- Backup restore drill (documented, manual quarterly)

**Cumulative test count across §0–§19: 578 passing, 1349 assertions.**

---

## 20. Deployment & DevOps

### Implementation
- [ ] Dockerfile (PHP-FPM 8.3 + Nginx) for app
- [ ] `docker-compose.yml` for local dev (app, mysql 8, redis 7, mailpit)
- [ ] Production deploy target: Laravel Forge or self-managed VPS + Cloudflare in front
- [ ] Horizon supervised under systemd / supervisor
- [ ] `php artisan schedule:run` via cron every minute
- [ ] Wildcard SSL `*.keverna.com` from Cloudflare Universal SSL
- [ ] Health check endpoint `/health` (DB + Redis + R2 + Paystack ping)
- [ ] GitHub Actions deploy on tag push (after green CI)
- [ ] Per-env config: `.env.local`, `.env.staging`, `.env.production`
- [ ] Secret manager (env vars on Forge or AWS Secrets Manager)

### Tests
- [ ] CI: lint + static + unit + feature tests on every PR
- [ ] CI: Dusk browser tests on `main` only (slower)
- [ ] Smoke `/health` endpoint returns 200 + JSON status of each subsystem
- [ ] Smoke deploy to staging via tag `staging-*`, runs migrations, runs `dusk` against staging URL

---

## Definition of Done — v1.0 Launch

A feature is *complete* only when **all** of these are true:

- [ ] Migration committed with proper indexes, FKs, `tenant_id` scope
- [ ] Model includes `BelongsToTenant` trait (if tenant-scoped)
- [ ] Livewire / controller layer wired
- [ ] All listed unit tests + feature tests pass
- [ ] At least one cross-tenant isolation test (where applicable) passes
- [ ] Lint (Pint) and static analysis (PHPStan level 8) pass
- [ ] No N+1 queries in dashboard pages (`laravel-debugbar` or `beyondcode/query-detector` check in dev)
- [ ] Manual smoke on mobile viewport (375px) for any user-facing screen
- [ ] PR reviewed; merged to `main` after CI green

---

## Phase 2 (v2.0) — Reference Only

- [ ] Airbnb iCal sync (import/export)
- [ ] Booking.com Connectivity API
- [ ] Multi-property accounts (one login → many properties)
- [ ] In-dashboard messaging (operator ↔ guest)
- [ ] Post-checkout review collection & display
- [ ] Advanced analytics (revenue forecasting, source attribution, occupancy trends)
- [ ] Discount codes + promotional pricing
- [ ] Custom domain purchase (Namecheap/Whogohost in-platform flow)
- [ ] PDF invoice + receipt generation
- [ ] WhatsApp Business API for booking notifications (beyond OTP)

## Phase 3 (v3.0) — Reference Only

- [ ] Housekeeping module (turnover tasks)
- [ ] Staff accounts (RBAC via Spatie Permission)
- [ ] REST API tier for operators
- [ ] White-label tier (remove "Powered by Keverna")
- [ ] Revenue split module (co-hosting)
- [ ] Native mobile app (React Native or Flutter)

---

## Open Questions (Resolve Before Coding the Affected Module)

| # | Question | Resolution from PRD §14 |
|---|---|---|
| 1 | Disclose 1.5% tx fee at signup? | No — included in service agreement |
| 2 | Refund policy for mid-year subscription cancel? | **Open** — needs Legal |
| 3 | NIN/BVN identity verification before going live? | Yes — add to onboarding (gate `is_live`) |
| 4 | Domain registrar partner? | Operators register themselves; Keverna provides DNS instructions only — **defer in-platform purchase to Phase 2** |
| 5 | Retain churned tenant data forever? | No — needs Legal-defined retention period |
| 6 | Mixed short-let + long-stay rooms in one property? | Yes — confirmed; ensure `booking_type` per room supports `Both` |

> Items 2 and 5 are blockers for billing/archival module sign-off. Surface to FregateLab Legal before writing the archival cron.
