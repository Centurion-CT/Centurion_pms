# Host app, phase 5: push notifications, settings, release (contract)

Read first: DECISIONS.md, PHASE-1..4.md. Designs: design/host-app/a/21-notifications, 27-more, 28-settings (PNG + HTML).
Today nothing sends push: `mobile_devices.push_token` exists (filled at login/pair) but no sender, no app flag, no prefs.

## Decisions
- Transport: Firebase Cloud Messaging HTTP v1 for Android AND iOS (APNs through FCM). No Firebase project exists yet, so
  everything must work with push switched off: server driver `log` (writes to the log) or `null` when unconfigured; the
  app builds, runs and passes tests without google-services.json / GoogleService-Info.plist and simply hides push.
- Only host-app devices get host pushes (the staff app `mobile/` shares mobile_devices). Mark them `app = 'host'`.
- Preferences are per device (the Settings screen is on that phone). Defaults all on.
- All push copy: short, plain, no em-dashes, money as ₦85,000. Never include guest phone numbers or card details.
- Recipients always pass the same gates the app uses to show the thing: branch access (operator can access the
  property), role/permission (ApprovalInbox::gates for approvals; owner = capabilities.owner for payments/summary;
  host roles for bookings/requests). The actor who did it never gets a push for their own action.
- Quiet hours 22:00 to 07:00 Africa/Lagos: only urgent guest requests (and approvals waiting on a guest) push; the rest
  are skipped (they still show in the in-app Notifications list as today).

## Server
1. Migration: mobile_devices + `app` string(16) nullable (host|staff), `push_platform` nullable, `push_prefs` json
   nullable, `push_token_updated_at` nullable. Host login (LoginController via host routes or `X-Keverna-App: host`
   header, pick whichever the host app already sends; add the header to the app if needed) sets app=host.
2. Endpoints (/api/mobile/v1/host/..., host.role, branch header not required):
   - `PUT device/push` {token|null, platform: ios|android} -> stores/clears token on the CURRENT device (from the Sanctum
     token's mobile-device-{id}); same token on another device row is cleared there (one token, one device).
   - `GET device/notifications` -> {data:{push_available: bool (server configured), prefs:{bookings, payments, approvals,
     guest_requests, daily_summary, low_stock}, allowed:{...same keys: bool}}} where allowed hides what this operator can
     never receive (e.g. payments/daily_summary for non-owners, low_stock without stock access).
   - `PUT device/notifications` {prefs:{...partial}} -> same shape. Idempotency as other host writes.
3. `App\Services\Push\HostPush` (+ `PushDriver` interface, `FcmDriver`, `LogDriver`, `NullDriver`; config
   `services.fcm.{project_id, credentials}` from env FCM_PROJECT_ID + FCM_CREDENTIALS (path or JSON), `PUSH_DRIVER`).
   FcmDriver: OAuth2 service-account JWT -> access token (cache ~50 min), POST messages:send; on UNREGISTERED /
   INVALID_ARGUMENT for the token, null the token. Never throw into the caller: queue a job, report() failures.
   Sends happen after commit (DB::afterCommit), queued.
   Message: notification {title, body}; data {kind, target, property_id, tenant (slug), id}; Android channel
   `keverna_host`; iOS sound default; collapse/thread by kind.
4. Triggers (hook the existing single source of truth; read the code, do not double-send when two paths call each other):
   - bookings: new booking created from website/guest app, walk-in/manual, OTA/channel (not pending-payment holds that
     haven't confirmed). "New booking from your website" / "Zainab Yusuf · Exec Suite · 24 to 28 Oct · ₦240,000".
     target booking:REF.
   - payments (owners only): a guest payment recorded (desk or Paystack), not wallet moves or refunds.
     "Payment received: ₦85,000" / "Chiamaka Obi paid by transfer · Room 203". target booking:REF.
   - approvals: a new item lands in the Inbox approvals (portal hold, held money, corporate request/rate/transfer).
     target approvals.
   - guest_requests: a new guest request (and complaints). target matching what the app's Inbox requests route uses.
   - low_stock: piggyback on SendLowStockDigest's daily result (one push per branch per day, only when something is low).
   - daily_summary: new command `host:push-daily-summary` scheduled daily 08:00 ->timezone('Africa/Lagos'), per tenant,
     per owner device with daily_summary on: "Yesterday at Wuse 2" / "₦1,180,000 collected · 69% occupancy" using the
     SAME numbers as GET owner/summary (call the service). Multi-branch owners: one push for all branches they can access
     ("Yesterday at your 3 branches"). target summary:YYYY-MM-DD. Skip tenants with no activity. Also stores a database
     notification so it appears in the in-app Notifications list.
5. Tests (Pest, fake driver bound in the container): registration + one-token-one-device, prefs + allowed, each trigger
   sends to the right operators only (branch, role, own action excluded, staff-app devices excluded, revoked excluded,
   prefs off excluded), quiet hours, daily summary numbers equal owner/summary, FcmDriver request shape + token
   cleanup with Http::fake, unconfigured = no HTTP. Full suite: no new failures (baseline list in the lead's scratchpad).

## App (mobile-host/)
1. firebase_core + firebase_messaging + flutter_local_notifications. Guarded init: if Firebase isn't configured
   (missing native config / initializeApp throws), push is off for the session, no crash, Settings shows
   "Push notifications aren't switched on for this version yet." Android: google-services plugin applied only when
   android/app/google-services.json exists; POST_NOTIFICATIONS permission; channel keverna_host; monochrome small icon.
   iOS: aps-environment entitlement + remote-notification background mode; works without the plist.
2. After sign-in + unlock: ask permission once (explain first with a K sheet, design tone), get token, PUT device/push;
   on onTokenRefresh re-send; on sign-out PUT null (best effort, before the token is dropped).
3. Taps: getInitialMessage (cold start), onMessageOpenedApp, local-notification tap in foreground. Route through the
   existing target router (owner_nav / For you targets: booking:REF, approvals, summary:DATE, request targets, ...).
   If the property_id differs from the current branch, switch branch first (existing switch flow). If the app is
   locked, open after unlock. Foreground message: show a local notification AND refresh unread counts / inbox badge.
4. Settings screen (28-settings) reached from More: Notifications toggles from GET/PUT device/notifications (only the
   `allowed` ones, subtitles as in the design adapted to truth: "Every payment received", "Every day at 8:00 AM",
   guest requests "Only urgent ones at night"); Security (PIN unlock, fingerprint, auto-lock, change PIN: move the
   existing More controls here, keep More short); General (Language English, Currency ₦ Naira, read-only); About
   (version + build from package_info); footer "Staff, rates and property settings live on the web dashboard."
   Optimistic toggles via the outbox pattern with rollback on error.
5. Release config: Android release signing from android/key.properties when present (gitignored, key.properties.example
   committed), else debug signing so local release builds still work; versionCode/name from pubspec; R8 default.
   Generate launcher icons + native splash from assets/branding (flutter_launcher_icons / flutter_native_splash already
   in dev deps). iOS display name "Keverna for Hosts", bundle com.keverna.host.
6. docs/host-app/RELEASE.md: exact steps the owner of the accounts must do (Firebase project + add Android/iOS apps +
   APNs key upload + service account -> FCM_* env; keystore generation + key.properties; Play Console internal testing
   track; App Store Connect + TestFlight), plus store listing copy (short + full description, no em-dashes), data-safety /
   privacy answers matching what the app actually collects, and a release checklist.
7. Tests: target routing from push data (incl. branch switch + locked), Settings toggles + rollback, unconfigured
   Firebase path. `flutter analyze` clean, `flutter test` green, `flutter build apk --release` and
   `flutter build ios --no-codesign` (if Xcode is available) succeed.
