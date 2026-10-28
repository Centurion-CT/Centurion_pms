# Centurion Staff

Flutter app for Centurion Apartments housekeepers and porters. Contract and
design decisions: `../docs/staff-app/PLAN.md`.

## Run

```sh
cd mobile
flutter pub get
dart run build_runner build --delete-conflicting-outputs   # Drift

# Real hotel (https is added when no scheme is given)
flutter run --dart-define=CENTURION_HOST=centurionpms.com
# Local server (debug Android builds allow plain http; emulator host is 10.0.2.2)
flutter run --dart-define=CENTURION_HOST=http://127.0.0.1:8765

# Demo hotel, no server needed. Any phone, then PIN
# 1234 Blessing (housekeeper), 5678 Musa (porter), 0000 manager, 9999 hotel pick
flutter run --dart-define=STAFF_DEMO=true
# Skip sign in (screenshots): add --dart-define=STAFF_DEMO_AUTOLOGIN=housekeeper|porter
```

With no host compiled in, the phone screen asks for the hotel address. A long
press on the logo shows that field at any time. After sign in the app uses the
`base_url` the server returns.

## Layout

- `lib/core/`: config, strings, theme tokens, API client, session (secure
  storage), Drift db (outbox + JSON cache), outbox worker, shared widgets.
- `lib/features/staff/`: models, optimistic overlay, repository, providers.
- `lib/features/{auth,rooms,checks,supplies,me,shell}/`: screens.
- `lib/demo/`: in-memory hotel used by STAFF_DEMO and the widget tests.

## Offline

Every write goes into the Drift outbox with a ULID Idempotency-Key and shows
at once (screens read cached JSON with pending changes applied). The worker
sends entries in order; network trouble, 5xx, 408, 429 and 401 keep the entry,
any other 4xx (such as 409 wrong_state) drops it and shows a message.

## Checks

```sh
flutter analyze
flutter test
flutter build apk --debug
```

Brand assets: `dart run flutter_launcher_icons` and
`dart run flutter_native_splash:create` regenerate icons and splash from
`assets/branding/`.
