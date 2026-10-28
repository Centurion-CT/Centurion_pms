// Build-time configuration.
//
//   flutter run --dart-define=CENTURION_HOST=centurionpms.com
//   flutter run --dart-define=CENTURION_HOST=http://127.0.0.1:8765   (local)
//   flutter build web --dart-define=STAFF_DEMO=true                  (demo data)
//
// A host without a scheme gets https://. A scheme in the define is kept,
// so plain http works for local testing.

/// Hotel address compiled into the build. Empty means the phone screen
/// asks for it (and stores it on the phone).
const kDefaultHost = String.fromEnvironment('CENTURION_HOST', defaultValue: '');

/// Swap the network for an in-memory hotel with realistic data.
const kDemo = bool.fromEnvironment('STAFF_DEMO');

/// Demo only: sign straight in as `housekeeper` or `porter` (for screenshots).
const kDemoAutoLogin = String.fromEnvironment('STAFF_DEMO_AUTOLOGIN');

/// Turns whatever the user typed (or the define) into a base URL with a
/// scheme and no trailing slash. Returns '' for blank input.
String normalizeHost(String raw) {
  var h = raw.trim();
  if (h.isEmpty) return '';
  if (!h.contains('://')) h = 'https://$h';
  while (h.endsWith('/')) {
    h = h.substring(0, h.length - 1);
  }
  return h;
}
