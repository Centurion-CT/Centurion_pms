# Syncing bug fixes from Keverna (SaaS) into keverna-solo

`keverna-solo` was forked from the SaaS Keverna repo. It shares 90% of
its code but has deleted the entire admin panel, subscription/billing,
central identity, and cross-tenant marketplace surface. When a bug is
fixed upstream, cherry-picking the change into this fork is usually a
one-liner — sometimes it takes a small conflict resolution.

The `upstream` remote points at the SaaS repo at fork time:

```bash
git remote -v
# origin    <your solo host>                 (fetch)
# origin    <your solo host>                 (push)
# upstream  /path/to/keverna                 (fetch)
# upstream  /path/to/keverna                 (push)
```

If it isn't set, add it:

```bash
git remote add upstream /path/to/keverna
git fetch upstream
```

## Cherry-picking a single fix

Say Keverna SaaS ships commit `abc1234` — a booking-flow fix that
belongs in solo too:

```bash
git fetch upstream
git cherry-pick abc1234
```

If the paths still exist in the fork (bookings, housekeeping,
reservations code stayed identical), the pick lands clean.

### When a conflict shows up

You'll hit conflicts when the SaaS commit touches files the fork
deleted (admin, subscription, central identity) or edits a file the
fork already rewrote for solo (`bootstrap/app.php`, `routes/web.php`,
`config/auth.php`, `resources/views/layouts/tenant-auth.blade.php`,
`app/Models/Tenant.php`).

Common resolutions:

- **The commit adds a new feature to admin panel code**  
  Skip the pick entirely — that feature has no home in solo:  
  `git cherry-pick --abort`

- **The commit edits a file the fork deleted**  
  Skip that file, take the rest:  
  `git rm <deleted-path>` for each `both added` conflict, then  
  `git cherry-pick --continue`

- **The commit edits `bootstrap/app.php` or `routes/web.php`**  
  These are heavily rewritten in solo. Open the file, take the
  functional change (usually a middleware add or a route add), skip
  the SaaS-only chunks (admin route group, central-domain routing,
  subscription webhook exclusions).

- **The commit edits `app/Models/Tenant.php`**  
  Solo dropped `subscriptions()`, `currentSubscription()`,
  `isReadOnlyForBilling()` (now returns false stub) and the
  `SubscriptionStatus`/`TenantScope` imports. If the upstream change
  touches those methods, skip that hunk. If it touches
  `hasOwnPaystackPayouts()`, `hasDokweStorage()`, or
  `primaryHost()`, keep it.

- **The commit edits the tenant-auth layout**  
  Solo removed the impersonation banner, trial banner, subscription
  sidebar item, and read-only dimmer. If the upstream change touches
  those blocks, skip. Everything else (nav palette, topbar, layout
  chrome) still lives here.

## Batch-syncing a whole day of upstream fixes

If Keverna ships a range like `abc1234..def5678`:

```bash
git fetch upstream
git log --oneline abc1234^..def5678
git cherry-pick abc1234^..def5678
```

Resolve conflicts commit-by-commit with the same rules as above.

## What NOT to sync

Never cherry-pick these upstream categories — they conflict with
solo's design intent and will pile up dead code:

- **Any admin panel commit** — `app/Livewire/Admin/*`,
  `app/Actions/Admin/*`, `app/Services/Admin/*`,
  `routes/admin.php`, admin tests
- **Any subscription/billing commit** — `app/Models/Subscription`,
  `EnforceSubscriptionAccess`, trial banners, subscription webhook,
  `ProcessExpiredSubscriptions` job, `SendRenewalReminders`
- **Central identity flows** — `app/Livewire/Central/*`,
  `IdentityHandoffController`, `IdentityClaimController`,
  `SsoConsume`, `PostRegistrationLoginController`,
  `App\Livewire\Auth\Register`
- **Cross-tenant marketplace index** — `StaysIndexController`,
  `StaysListingQuery::search*`, marketing landing
  (`welcome.blade.php`, `pricing.blade.php`, `hosts.blade.php`)
- **Domain provisioning** — `DispatchSubdomainProvisioning`,
  `ProvisionTenantSubdomain`, `AttachTenantDomainToDokwe`,
  `DomainTenantFinder`, `RedirectOldSlug`

## Skimming upstream weekly

A short workflow that catches drift without buying dead code:

```bash
git fetch upstream
git log --oneline --no-merges origin/main..upstream/main | tee /tmp/upstream-new.txt
```

Scan the subjects for bugs/features that belong here (bookings,
housekeeping, POS, reservations, pricing, guest portal). Cherry-pick
those. Ignore the rest.

Rule of thumb: if the commit subject starts with `admin —`,
`subscription —`, `marketing —`, `identity —`, `central —`, or
`stays index —`, don't pick it.

## What the SaaS build has that solo doesn't

Kept as a running list so triage stays fast:

- Multi-tenant tenant switcher + subdomain sharding (solo uses
  `SoloTenantFinder`, one row)
- Platform admin dashboard, admin auth guard, impersonation
- Subscription rows + trial + read-only enforcement + renewal
  reminders
- Central identity flows (unified `/login`, `/register`, `/choose`,
  `/handoff`, `/reservations`, magic-link `/claim`)
- Cross-tenant marketplace index (`/stays`) and search
- Marketing site (`welcome.blade.php`, `pricing.blade.php`,
  `hosts.blade.php`, `/demo`, `/api-access`)
- Custom-domain verification + `RedirectOldSlug`

Everything else — bookings, reservations, housekeeping, POS,
inventory, calendar, corporate portal, guest portal, per-property
stays page, mobile API — is shared with upstream and cherry-picks
apply as-is.
