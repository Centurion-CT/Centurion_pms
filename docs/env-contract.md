# Production Env Contract

Reference for what dokwe (or any other runtime) must inject for keverna to
run in production. Pair with `.env.example` — that file is the **dev**
reference; this file is the **prod** checklist.

The container's entrypoint (`docker/start.sh`) enforces this contract:

- **Tier 1 — required**: missing any of these → container exits at boot.
- **Tier 2 — recommended**: container boots, but the corresponding feature
  is disabled until the var lands. Surfaced as a `WARN:` line in stderr.

Treat this file as the single source of truth — `start.sh` reads from the
same lists. Drift here is a bug.

---

## Tier 1 — required (fail-fast at boot)

| Var | Purpose | Example |
|---|---|---|
| `APP_KEY` | Laravel encryption key. Generate once with `php artisan key:generate --show` and store as a dokwe secret. Rotating it invalidates every active session, cookie, and signed URL. | `base64:…` |
| `APP_URL` | Canonical central host. Used for absolute URL generation everywhere outside the tenant context. | `https://keverna.com` |
| `DB_CONNECTION` | Database driver: `pgsql` or `mysql`. Without it Laravel falls back to `sqlite` and treats `DB_DATABASE` as a file path. | `pgsql` |
| `DB_HOST` | Database host. Wire to the dokwe MySQL service-app's internal hostname. | `keverna-mysql.svc` |
| `DB_DATABASE` | Database name on that host. | `keverna` |
| `DB_USERNAME` | DB user. | `keverna` |
| `REDIS_HOST` | Redis host. Wire to the dokwe Redis service-app. | `keverna-redis.svc` |
| `CENTRAL_DOMAIN` | The root host the marketing/central site lives on. Used by `DomainTenantFinder` to **not** resolve a tenant when the request hits the central host. | `keverna.com` |
| `TENANT_DOMAIN_SUFFIX` | Suffix matched against the incoming host to extract a tenant slug. Must start with `.`. | `.keverna.com` |

## Tier 2 — recommended (boot succeeds, feature disabled)

| Var | Feature affected if missing | Notes |
|---|---|---|
| `DB_PASSWORD` | Writable database access | Leave unset only for local docker-compose with no-password MySQL. |
| `PAYSTACK_SECRET_KEY` | Billing + guest bookings | Without this, charge creation throws. |
| `PAYSTACK_WEBHOOK_SECRET` | Billing webhooks | Without this, signature verification fails. |
| `SENDPIPE_API_KEY` | Outbound email + WhatsApp OTPs | Set `MAIL_MAILER=sendpipe` alongside. |
| `DOKWE_ACCESS_KEY`, `DOKWE_SECRET_KEY`, `DOKWE_BUCKET`, `DOKWE_ENDPOINT`, `DOKWE_PUBLIC_URL` | Media uploads | Checked only when `MEDIA_DISK` or `FILESYSTEM_DISK` is `dokwe`. |
| `BUNNY_STORAGE_ZONE`, `BUNNY_STORAGE_API_KEY`, `BUNNY_PULL_ZONE_URL` | Media uploads | Checked only when `MEDIA_DISK` or `FILESYSTEM_DISK` is `bunny` (deprecated). |

### Opt-in: in-app domain management

Off by default (`DOMAIN_MANAGEMENT_ENABLED=false`) — a solo install attaches
its domain once in the Dokwe dashboard. Set it to `true` to re-enable the
`/domain` page (subdomain rename + custom domain attach). The vars below are
only checked when it's on.

| Var | Feature affected if missing | Notes |
|---|---|---|
| `DOKWE_API_KEY` | Custom domain attach via dokwe | `dk_pak_…` personal API key issued from dokwe Settings → API Keys. Not the same as the storage `DOKWE_ACCESS_KEY`. |
| `DOKWE_PROJECT_ID` | Custom domain attach via dokwe | UUID of this deployment's project inside dokwe. |
| `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ZONE_ID` | Subdomain DNS provisioning | Only if subdomain DNS is managed through Cloudflare. |

## Tier 3 — should be set, but defaults are sane

These have safe defaults but you almost always want them explicit in prod.

| Var | Recommended prod value | Default if unset |
|---|---|---|
| `APP_ENV` | `production` | `production` (from config/app.php) |
| `APP_DEBUG` | `false` | `false` |
| `LOG_CHANNEL` | `stderr` | `stack` — log to file, swallowed by container ephemeral storage |
| `LOG_LEVEL` | `info` | `debug` |
| `DB_PORT` | `5432` (pgsql) / `3306` (mysql) | `5432` / `3306` per driver |
| `FORCE_HTTPS` | `true`, or `false` if the proxy chain drops `X-Forwarded-Proto: https` and the app 301-loops | `true` |
| `SESSION_SECURE_COOKIE` | `true` | unset (cookies not marked Secure) |
| `REDIS_CLIENT` | `predis` | `predis` |
| `REDIS_PORT` | `6379` | `6379` |
| `SESSION_DRIVER` | `redis` | `database` |
| `CACHE_STORE` | `redis` | `database` |
| `QUEUE_CONNECTION` | `redis` | `sync` (runs jobs inline — never in prod) |
| `FILESYSTEM_DISK` | `bunny` | `local` |
| `MEDIA_DISK` | `bunny` | `public` |
| `MAIL_MAILER` | `sendpipe` | `log` |
| `BCRYPT_ROUNDS` | `12` | `12` |
| `APP_TIMEZONE` | `Africa/Lagos` | `UTC` |
| `KEVERNA_ROLE` | `web` / `horizon` / `scheduler` / `migrate` | `web` — used by `docker/start.sh` to pick the runtime. The `migrate` role runs `php artisan migrate --force` and exits; treat it as a pre-deploy one-shot. |
| `RUN_MIGRATIONS_ON_BOOT` | `false` in dokwe-orchestrated deploys (a dedicated `migrate` job is cleaner). `true` for single-container/local setups so the web role runs migrations before serving. | `false` |

## Tier 4 — optional / future

These don't affect Tier 1/2 launches.

| Var | Notes |
|---|---|
| `GOOGLE_MAPS_API_KEY` | Landing-page embed — gracefully hidden if unset. |
| `NAMECHEAP_*` / `WHOGOHOST_*` | Phase 2 domain registrar integration (stubbed). |
| `SUBSCRIPTION_ANNUAL_NGN`, `TRIAL_DAYS`, `PAYSTACK_FEE_*`, `KEVERNA_FEE_*`, `GRACE_PERIOD_DAYS`, `LOCK_TO_ARCHIVE_DAYS` | Pricing/billing constants. Hard-coded defaults live in `config/billing.php` — only override when product wants to change them. |
| `VITE_APP_NAME` | Compiled into the vite bundle at build time, not read at runtime. |

---

## Where these come from

| Var family | Source |
|---|---|
| `APP_KEY` | Generated once with `php artisan key:generate --show`, stored as a dokwe secret. |
| `DB_*` | Auto-wired by dokwe when you attach the MySQL service-app to the project. |
| `REDIS_*` | Auto-wired by dokwe when you attach the Redis service-app. |
| `PAYSTACK_*` | Paystack dashboard → Developers → API Keys. Use the **live** keys in prod, **test** keys in staging. |
| `SENDPIPE_API_KEY` | sendpipe.fregatelab.com — operator-issued. |
| `BUNNY_*` | dash.bunny.net → Storage → zone settings. |
| `DOKWE_API_KEY` | dokwe Dashboard → Settings → API Keys. Generate a key scoped to the keverna project (pending dokwe task #57). |
| `DOKWE_PROJECT_ID` | dokwe Dashboard → keverna project → URL bar (or `dokwe projects` via CLI). |

## What NOT to set in prod

These belong only in local dev:

- `APP_DEBUG=true` — leaks stack traces.
- `APP_ENV=local` or `staging` — flips Laravel into debug helpers and disables some prod safety rails.
- `MAIL_MAILER=log` — silently swallows every outbound message.
- `BROADCAST_CONNECTION=log` — fine for dev, but if you ever add real-time features, switch to `pusher`/`reverb`.
- Any `*_TEST_*` key — prod uses live keys only.

## Verification

After the first deploy:

```sh
# Confirm no Tier 2 WARN lines in the container logs.
dokwe logs web --tail 200 | grep -E '^(FATAL|WARN):'

# Smoke-test the health endpoints.
curl -fsS https://keverna.com/healthz   # nginx — should return "ok"
curl -fsS https://keverna.com/up        # Laravel — should return 200 JSON
```
