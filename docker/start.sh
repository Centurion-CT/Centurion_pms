#!/bin/sh
# Container entrypoint. Used by every keverna service — picks the right
# runtime based on the KEVERNA_ROLE env var.
#
#   KEVERNA_ROLE=migrate   → runs `php artisan migrate --force` and exits
#   KEVERNA_ROLE=web       → php-fpm + nginx
#   KEVERNA_ROLE=horizon   → php artisan horizon
#   KEVERNA_ROLE=scheduler → loops `php artisan schedule:run` every minute
#
# Setting RUN_MIGRATIONS_ON_BOOT=true on the web role runs migrations
# inline before nginx starts — convenient for single-container setups but
# you'll usually prefer a dedicated `migrate` one-shot in CI/CD instead.
#
# Defaults to "web" if unset so a misconfigured deploy still serves traffic.

set -eu

ROLE="${KEVERNA_ROLE:-web}"

# ── Env contract: tiered validation ──────────────────────────────────────
# Tier 1 — REQUIRED. Laravel can't boot meaningfully without these. Hard
# fail so a misconfigured deploy crashes loudly at boot instead of
# half-running with broken sessions or DNS-less tenant resolution.
# Tier 2 — RECOMMENDED. The container boots, but listed integrations stay
# disabled until the matching vars land. Surfaced as warnings so an early
# canary deploy isn't blocked by, say, a missing Paystack key.
#
# Source of truth: docs/env-contract.md. Keep the two lists in sync.

REQUIRED_VARS="
APP_KEY
APP_URL
DB_HOST
DB_DATABASE
DB_USERNAME
REDIS_HOST
CENTRAL_DOMAIN
TENANT_DOMAIN_SUFFIX
"

# Each line: VAR=feature-description (used in the warning text only).
RECOMMENDED_VARS="
DB_PASSWORD=writable database access
CLOUDFLARE_API_TOKEN=tenant subdomain DNS provisioning
CLOUDFLARE_ZONE_ID=tenant subdomain DNS provisioning
PAYSTACK_SECRET_KEY=billing + guest bookings
PAYSTACK_WEBHOOK_SECRET=billing webhooks
SENDPIPE_API_KEY=outbound email + WhatsApp OTPs
BUNNY_STORAGE_ZONE=tenant media uploads
BUNNY_STORAGE_API_KEY=tenant media uploads
BUNNY_PULL_ZONE_URL=CDN delivery for media
DOKWE_API_KEY=custom domain attach via dokwe
DOKWE_PROJECT_ID=custom domain attach via dokwe
"

missing_required=""
for var in $REQUIRED_VARS; do
    eval "val=\${$var:-}"
    if [ -z "$val" ]; then
        missing_required="$missing_required $var"
    fi
done
if [ -n "$missing_required" ]; then
    echo "FATAL: required env vars not set:$missing_required" >&2
    echo "       see docs/env-contract.md for the full list" >&2
    exit 1
fi

for line in $RECOMMENDED_VARS; do
    var="${line%%=*}"
    feature="${line#*=}"
    eval "val=\${$var:-}"
    if [ -z "$val" ]; then
        echo "WARN: $var unset — $feature will be disabled" >&2
    fi
done

cd /var/www/html

# ── Helpers ──────────────────────────────────────────────────────────────

# Run migrations idempotently. Laravel 11's migrator takes an advisory lock
# on `migrations` so racing containers behave: the winner runs the missing
# files, the losers no-op out. Exit on failure so a broken migration takes
# the deploy down loudly rather than serving stale code.
run_migrations() {
    echo "[start] running php artisan migrate --force"
    php artisan migrate --force --no-interaction
}

# ── Warm Laravel caches (config/route/view/event) ────────────────────────
# Done at boot, not build, because some config values come from runtime env.
# `optimize` is idempotent, so running it on every container start is fine.
# Skipped in the `migrate` role — the one-shot doesn't serve traffic so it
# doesn't need the route/view caches, and the smaller boot footprint cuts
# the time a deploy spends in "migrating" state.
if [ "$ROLE" != "migrate" ]; then
    php artisan optimize
fi

case "$ROLE" in
    migrate)
        # One-shot migrator. Drop this in as a pre-deploy job (dokwe job /
        # k8s init container / a CI step) — it exits with the artisan exit
        # code so a failing migration aborts the rollout.
        run_migrations
        echo "[start] migrations applied — exiting"
        exit 0
        ;;
    web)
        # Optional convenience: run migrations inline before serving. Lets a
        # single-container deploy come up without a separate migrate step,
        # at the cost of slowing every cold start by ~1-2s.
        if [ "${RUN_MIGRATIONS_ON_BOOT:-false}" = "true" ]; then
            run_migrations
        fi
        echo "[start] role=web — launching php-fpm + nginx"
        # Run FPM in the background; exec nginx so it inherits PID 1 and
        # signal handling works (SIGTERM → graceful shutdown).
        php-fpm -F &
        FPM_PID=$!
        trap "kill -TERM $FPM_PID 2>/dev/null || true; wait $FPM_PID 2>/dev/null || true" TERM INT
        exec nginx -g 'daemon off;'
        ;;
    horizon)
        echo "[start] role=horizon — launching queue supervisor"
        # Horizon traps SIGTERM and finishes in-flight jobs, so a plain exec
        # is enough for graceful shutdown.
        exec php artisan horizon
        ;;
    scheduler)
        echo "[start] role=scheduler — invoking schedule:run every minute"
        # No real cron daemon needed; the loop below is the same shape and
        # plays nicer with PID 1 signal handling in a single-purpose container.
        while true; do
            php artisan schedule:run --verbose --no-interaction >> /dev/stdout 2>&1 || true
            sleep 60
        done
        ;;
    *)
        echo "FATAL: unknown KEVERNA_ROLE=$ROLE (expected migrate|web|horizon|scheduler)" >&2
        exit 1
        ;;
esac
