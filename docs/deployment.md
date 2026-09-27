# Deployment

How a keverna build moves from a commit to running tenants. Pair with
[`env-contract.md`](./env-contract.md) (what to inject) and
[`dokwe-integration.md`](./dokwe-integration.md) (how we talk to dokwe).

## Service topology

One image, four roles (picked via `KEVERNA_ROLE`):

| Role | Why | Replicas | What you'd scale on |
|---|---|---|---|
| `migrate` | One-shot — runs `php artisan migrate --force`, exits. | 1 per deploy | n/a |
| `web` | PHP-FPM + nginx serves every host (central, admin, tenant). | ≥ 2 | request latency / requests-per-second |
| `horizon` | Queue supervisor — runs every job from every tenant. | 1–N | queue length, oldest pending job age |
| `scheduler` | Loops `php artisan schedule:run` every 60s. | exactly 1 | n/a |

All four read the same env (`docs/env-contract.md`). The scheduler must
stay at 1 replica — running two would double-fire any cron-marked job.

## Deploy sequence

The migrate role MUST finish cleanly before web/horizon start, otherwise
they'll boot against a schema that doesn't match the code. Standard
sequence per deploy:

```text
1. dokwe builds the new image from the latest commit
2. dokwe runs:    KEVERNA_ROLE=migrate <image>
                  ─ exits 0 → schema is now at HEAD
                  ─ exits non-zero → ROLLBACK, do not bump web/horizon
3. dokwe bumps:   web → horizon → scheduler   (rolling, in that order)
```

If your dokwe project doesn't expose a "pre-deploy job" primitive yet:

```text
   RUN_MIGRATIONS_ON_BOOT=true    on the web role
```

The web role will then run migrations before each web container boots.
Laravel 11's advisory lock on `migrations` keeps two replicas from racing
— the winner runs, the losers no-op out — but cold start gains ~1–2s and
deploys aren't atomic if the migration fails (some web replicas may
already be serving the new code). Prefer the dedicated migrate role
whenever the orchestrator supports it.

## Rolling back

`migrate` is **forward-only** — Laravel runs migrations up but does not
auto-roll them down. To revert a deploy:

1. Bump web/horizon/scheduler back to the previous image. Service comes
   back; the new column / table the migration added remains harmless
   (older code ignores it).
2. If the migration is destructive and the new column must be removed,
   run `php artisan migrate:rollback --step=1` against the *previous*
   image inside a migrate-shaped container. Manual; do not script it.
3. The reverse is true for forward-incompatible migrations (column rename,
   dropped table). Coordinate by:
   - shipping the rename in two deploys (add new, dual-write, switch reads,
     drop old) rather than one,
   - or accepting that the old code can't run against the new schema and
     skipping rollback entirely.

## Horizon queue strategy

`config/horizon.php` runs three independent supervisors so a noisy queue
can't starve the others:

| Queue | Supervisor budget | What goes here |
|---|---|---|
| `default` | 8 workers (prod) | Webhooks, OTPs, transactional mail, anything platform-critical. |
| `tenants` | 6 workers (prod) | Tenant-scoped work that may backlog under a single noisy tenant — DNS provisioning, media conversions, large availability recalcs. |
| `slow` | 2 workers (prod) | Known long-running batch work (reports, large imports). Timeout 600s. |

Routing rules:
- Tenant-scoped jobs use `->onQueue('tenants')`. The dokwe-attach jobs
  (`AttachTenantDomainToDokwe`, `AttachCustomDomainToDokwe`) already do
  this in their constructors. New tenant-bound jobs should follow the
  same pattern.
- Anything platform-critical defaults to `default`. Don't override.
- Use the `App\Jobs\Concerns\OnTenantQueue` trait if you'd rather
  declare intent at the class level than remember `->onQueue()` in the ctor.

The `/horizon` dashboard is gated to authenticated platform admins by
`HorizonServiceProvider::gate()` — operators can't see queues that
contain other tenants' data. In prod, also set
`HORIZON_PATH=horizon-<random-suffix>` so the URL isn't trivially
discoverable.

Running Horizon as a service-app or sidecar:
```sh
KEVERNA_ROLE=horizon   # see docker/start.sh — runs `php artisan horizon`
```

## Smoke-testing a deploy

```sh
# nginx — should always be 200 even before Laravel finishes optimize
curl -fsS https://keverna.com/healthz

# Laravel — confirms FPM, OPcache, and the route table are loaded
curl -fsS https://keverna.com/up

# Tenant resolution — should 404 cleanly when no tenant is bound
curl -fsS -o /dev/null -w "%{http_code}\n" https://unknown.keverna.com/  # 404
```

Then a real-tenant smoke test (login as a known operator, hit the
dashboard, place a booking). Pest's `composer test` is the
ground truth — green CI on master is the gating signal for the migrate
step in the first place.
