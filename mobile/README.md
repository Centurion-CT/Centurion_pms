# Keverna — mobile app

Flutter app for Keverna staff. Ships with the housekeeping chain
(housekeeper → porter → supervisor) as the Phase 1 vertical slice;
reservation-manager flows land next.

## First run

Platform folders (`android/`, `ios/`) and the generated Drift +
launcher-icon + native-splash assets are committed, so cloning +
building is one command:

```sh
cd mobile
flutter pub get
dart run build_runner build --delete-conflicting-outputs
flutter run
```

**iOS prerequisites** — building for iOS needs Xcode + CocoaPods on
the machine. `flutter doctor` will complain if either is missing:

```sh
xcode-select --install
sudo gem install cocoapods
# Or, from a fresh Mac: install Xcode from the App Store, then:
sudo xcode-select --switch /Applications/Xcode.app/Contents/Developer
sudo xcodebuild -runFirstLaunch
```

Android has no such prereq beyond the Android SDK that `flutter
doctor` will pick up automatically.

On the web dashboard: **Staff → Linked devices → Generate pairing
code**. Scan the QR from the phone (or type the 8-char code in the
manual entry screen). The app posts to `/api/mobile/v1/pair`, gets
a Sanctum bearer token, and lands on the housekeeping queue.

### Local dev over TLS (HTTPS-only)

The app runs HTTPS-only — neither Android's `usesCleartextTraffic`
nor iOS's `NSAppTransportSecurity` are opened. That matches
production, but it means the local dev backend also needs to be on
`https://`. Two easy paths:

- **Laravel Herd**: every `.test` domain is auto-secured on port
  443. `chidi.keverna.test` → `https://chidi.keverna.test` works
  out of the box.
- **Tunnel**: `cloudflared tunnel --url http://chidi.keverna.test`
  gives you a public `https://<random>.trycloudflare.com` you can
  scan a QR against from any phone on any network.

If you *must* run against plain HTTP for a one-off (e.g. an
Android emulator hitting the host machine directly), the escape
hatch is a `network_security_config.xml` exception scoped to just
`10.0.2.2` — but don't commit that; the whole point of the
strict default is that a phone paired to a hostile network can't
be MITM'd.

### Pointing the app at prod vs dev

The central auth URL is baked in at build time via
`--dart-define`:

```sh
# Local dev (Herd on keverna.test)
flutter run --dart-define=KEVERNA_CENTRAL=keverna.test

# Production (default — no flag needed)
flutter run   # equivalent to --dart-define=KEVERNA_CENTRAL=keverna.com
flutter build web        # ships the prod URL
flutter build apk        # ships the prod URL
```

Only the `/login` endpoint uses the central URL. Every other
mobile API call goes to the tenant subdomain returned inside the
login response, so switching central switches the whole target
environment.

### CORS

The mobile web build (`flutter run -d chrome`) makes cross-origin
requests from `http://localhost:*` (dev-server) to the backend's
tenant subdomains. `config/cors.php` allows all origins on
`api/*` (Bearer-token flow, no cookies — safe to leave open).
Rate limiting on `/login` (6/min per ip+email) blunts credential
stuffing from random origins.

Native mobile builds (iOS/Android) don't hit CORS — it's a
browser policy.

### Regenerating brand assets

Both launcher icons + native splash are generated from
`assets/branding/*.png`. If you swap those source images:

```sh
dart run flutter_launcher_icons
dart run flutter_native_splash:create
```

The generated files land in `android/app/src/main/res/mipmap-*`
and `ios/Runner/Assets.xcassets/AppIcon.appiconset/` for icons,
and matching drawable/asset directories for the splash.

## Layout

```
lib/
  main.dart                          -- ProviderScope + MaterialApp.router
  core/
    router.dart                      -- go_router (StatefulShellRoute)
    shell/main_shell.dart            -- bottom nav (Housekeeping/Reservations/Outbox)
    theme/keverna_theme.dart         -- brand tokens (mirror web)
    auth/session_store.dart          -- Keychain/Keystore session
    api/api_client.dart              -- Dio + Bearer + Idempotency-Key
    outbox/                          -- direct-first w/ offline queue
      outbox_dao.dart
      outbox_worker.dart             -- backoff, connectivity, retry
      mutation_service.dart          -- MutationService.perform()
    storage/
      local_db.dart                  -- Drift schema (outbox + caches)
      inspections_cache_dao.dart     -- read-through cache
  features/
    pair/
      pair_service.dart              -- POST /pair; device fingerprint
      pair_screen.dart               -- QR scanner + manual entry
    inspections/
      inspection_models.dart
      inspections_repository.dart    -- refresh + writes → MutationService
      inspections_queue_screen.dart  -- role-filtered list, cache-stream
      inspection_detail_screen.dart  -- tick + damage + submit/verify/sign-off
    reservations/
      reservation_models.dart
      reservations_cache_dao.dart
      reservations_repository.dart   -- refresh + writes → MutationService
      reservations_screen.dart       -- tabs: Arrivals / In-house / Departures
      reservation_detail_screen.dart -- ledger + check-in/out/no-show/cancel
      take_payment_sheet.dart        -- manual cash/transfer/card/pos
    outbox/
      outbox_screen.dart             -- pending + parked (Retry / Discard)
```

## Brand tokens (must stay in sync with web)

See `lib/core/theme/keverna_theme.dart` — every colour is aliased to
a `KevernaColors` constant that mirrors the CSS custom property on
web (`--color-accent`, `--color-card`, etc.). If the web palette
changes, mirror the constant here so both surfaces stay visually
identical.

## Offline model

Live. Every mutation on the housekeeping chain (submit / verify /
sign-off) goes through `MutationService.perform()`:

1. Generate an `Idempotency-Key` (ULID) upfront.
2. Try the write directly through Dio.
3. **Success** → parse + return.
4. **Client error (4xx)** → bubble as `DioException`. Business-rule
   violations must surface immediately.
5. **Network error / 5xx** → persist into the `outbox_entries` Drift
   table with the SAME key, kick the drainer, throw `QueuedForRetry`.
   The UI treats this as a soft success ("Saved locally — will sync
   when back online" snackbar).

The `OutboxWorker` drains on three triggers:

- **connectivity_plus** — when wifi/mobile comes back.
- **Timer.periodic(30s)** — safety net for OEM ROMs that swallow
  connectivity events.
- **Explicit `drain()`** call from `MutationService` right after
  enqueue (the "back online 200ms later" case).

Retry policy:

- **Network / 5xx** → `bumpRetry`, exponential backoff
  (`30s × 2^retryCount`, capped at 30min).
- **4xx** → **park**. Server refused; retrying won't help. Operator
  reviews in `/outbox`, taps **Retry** (unpark + kick worker) or
  **Discard** (delete the row).

The badge in the queue screen's app bar shows the live count of
NOT-parked entries. Tap it → `/outbox` for the review screen.

Idempotency contract with the server: every retry with the same key
hits `MobileIdempotency` middleware, which returns the cached
response (24h TTL) with `Idempotent-Replay: 1`. So a phone can retry
a submit ten times and only the first will actually re-run
`SubmitHousekeeperCleaning`.

## Auth flow

1. **First launch**: no session → `/pair` screen.
2. **Scan / enter code**: `POST /api/mobile/v1/pair` → server returns
   `{token, operator, tenant, device}`.
3. **Session stored**: bearer token + `{operator, tenant}` snapshot
   in Keychain/Keystore.
4. **Every subsequent request**: Dio interceptor stamps
   `Authorization: Bearer <token>`.
5. **Sign out**: `POST /api/mobile/v1/logout` → clears secure
   storage + soft-revokes the device row server-side.

Revoked-server-side scenario: any request from the phone after
revoke gets a 401 with `{"error":"device_revoked"}`. The app catches
this and drops the local session (implementation next iteration).

## Testing on-device against local Herd / Valet

Emulators can't reach `chidi.keverna.test` on the host by default.
Two options:

- **Wired iPhone**: point your phone at the Mac's local network IP
  (`ipconfig getifaddr en0`) and add a `[hostname → IP]` entry to
  your phone's `/etc/hosts` — or run [ngrok](https://ngrok.com/) and
  paste the ngrok URL as the "Property URL" in the manual entry
  screen.
- **Android emulator**: `10.0.2.2` proxies to the host, so a base
  URL of `http://chidi.keverna.test:8000` becomes
  `http://10.0.2.2:8000` in-emulator. `--dart-define` a base override
  is the cleanest way to switch.

## Next up

- Push notifications (FCM registration in the pair flow).
- Manual booking create sheet (quick-reserve walk-in).
- Physical stock count / receive-stock (currently web-only).
