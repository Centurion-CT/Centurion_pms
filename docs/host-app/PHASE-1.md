# Host app, phase 1: foundation (contract shared by the Flutter app and the Laravel API)

New Flutter app `mobile-host/` ("Keverna for Hosts", Android + iOS, bundle/app id `com.keverna.host`) for owners,
managers, front desk and reservation officers. Designs: `design/host-app/a/*.html|png` (direction A, approved).
The existing `mobile/` staff app is NOT touched (it will later be rebuilt for housekeepers and porters).

## Roles
- Host app users: `manager`, `frontdesk`, `reservation_officer`. Any other role gets 403 `role_not_supported`
  on host endpoints and the app shows "This app is for owners, managers and front desk. Housekeeping uses the Keverna staff app."
- Owner = `manager` (owner-level), or any host role with the `finance.view` permission. Exposed as `capabilities.owner`.

## Existing endpoints reused as-is (base `https://{tenant}.{central}/api/mobile/v1/`)
- Central `POST https://{central}/api/mobile/v1/login` {email, password, tenant_id?, platform, device_name, app_version}
  -> 200 {token, operator, tenant, device} | 300 {status:"pick_tenant", tenants:[{id,slug,name,role,last_login_at}]} | 401/403/429.
- `GET me`, `POST logout`. All authed calls: `Authorization: Bearer`, non-GET need `Idempotency-Key` (8-128 chars, ULID).
- Errors: `{error: code, detail?}`. 401 codes: unauthenticated | invalid_token_scope | device_not_found | device_revoked
  -> app signs out locally.

## New in phase 1
### Central `POST login/pin` (routes/api-mobile-central.php)
{phone, pin (4-6 digits), tenant_id?, platform, device_name, app_version} -> same responses as `login`.
Uses the same phone + PIN check as the web staff PIN login (App\Livewire\Central\PinLogin / Operator::verifyPin),
same rate limit (6/min per IP+phone), same generic 401 `invalid_credentials`.

### Host group `/api/mobile/v1/host/*` (tenant subdomain, middleware: auth:sanctum, mobile.device, mobile.idempotency,
plus `host.role` that allows manager|frontdesk|reservation_officer and binds the branch, see below)
Branch binding: every host request resolves the operator's current branch through PropertyContext (operators.current_property_id,
falling back to the first accessible property). Optional header `X-Keverna-Property: {id}` overrides for that request if the
operator can access it. Host endpoints scope everything to that branch.

- `GET host/session` -> {data: {
    operator: {id, name, email, phone, role, role_label, initials},
    tenant: {id, name, slug},
    property: {id, name, city},                       // current branch
    capabilities: {owner: bool, create_booking: bool, take_payment: bool, approve: bool, finance: bool},
    properties_count: int }}
- `GET host/properties` -> {data: [{id, name, city, state, rooms_total, occupied_tonight, occupancy_pct, is_current}]}
  (branches this operator can access in this tenant)
- `POST host/properties/{id}/switch` -> {status:"ok", property:{...}} (sets operators.current_property_id; 403 if no access)
- `GET host/today` -> {data: {
    date: "2025-10-23", greeting_name,
    counts: {arriving, arriving_unassigned, leaving, leaving_overdue, in_house, rooms_total, requests_open, requests_urgent},
    money: {collected_today, owed_total, owed_stays},           // NGN integers
    arrivals: [{ref, guest_name, room_number|null, category, eta|null, balance_due, status, status_label, chip:{label, tone}}],  // max 8, by time
    departures: [{ref, guest_name, room_number, balance_due, overdue: bool}],                                                   // max 8
    owner: null | {revenue_week, revenue_week_change_pct, series_7d:[{date, amount}], occupancy_tonight_pct, adr_week, approvals_waiting}
  }}
  Values come from the same services the web Today/Summary use (no new maths): DashboardSnapshot / FinanceSnapshot / OccupancyCalculator.

## Flutter app (phase 1 scope)
- Project: `flutter create --org com.keverna --project-name keverna_host --platforms android,ios mobile-host`.
- Packages: flutter_riverpod, go_router, dio, flutter_secure_storage, local_auth, google_fonts (or bundled Fraunces/Inter),
  lucide_icons_flutter, intl, ulid, connectivity_plus, url_launcher, drift + drift_flutter (outbox + cache), package_info_plus, device_info_plus.
- Copy (not import) from `mobile/lib/core`: session store, Dio client with Idempotency-Key interceptor, outbox (MutationService,
  worker, dao). Fix while copying: parse action responses unwrapped (`{status, reservation}`), add a 401 interceptor that clears
  the session, add Android INTERNET permission to main manifest, iOS NSFaceIDUsageDescription.
- Theme (direction A tokens): ink #14171A, lime #ADCB52, cream #F4F1EA, page #F4F1EA, white cards radius 22-26 with
  1px rgba(20,23,26,.08) border, pill buttons (52 tall primary ink, lime arrow dot), tone pairs late #9A3F27/#FBE3DC,
  ok #3F4F12/#EEF4DC, due #7A5200/#FCEFD4, info #2E5788/#E3ECF7, purple #4A3A6E/#E8DDF5. Fraunces for titles, Inter body.
  Components: KCard, KPillButton (primary/secondary/lime), KChip(tone), KStatTile, KMoneyCard (ink), KSegment, KSheet, KListRow.
- Screens in phase 1 (match the PNGs): 01 splash (session restore), 02 sign in (email + password, "Use staff PIN" switches to
  phone + PIN), tenant picker (300 response), 03 unlock (local 4-digit PIN set after first sign-in + fingerprint/Face ID via
  local_auth; auto-lock after 5 min in background), 04 pick property (when properties_count > 1), shell with 5 tabs
  (Today, Bookings, Calendar, Inbox, More) where only Today and More are real; others show a "Coming in the next update" state,
  05 Today (manager) and 06 Today (owner header when capabilities.owner) from `GET host/today`, 27 More (profile, branch switch,
  sign out calling POST logout, "Open full dashboard" via existing GET /api/mobile/v1/auth/web-handoff), 30 offline banner.
- Copy: sentence case, no em-dashes, ₦ with thousands separators.
