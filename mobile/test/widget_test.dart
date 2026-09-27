// Smoke test — pump KevernaApp inside a ProviderScope + verify it
// paints without throwing. Overrides the outbox worker so its
// Timer.periodic + connectivity subscription don't leave pending
// state that flutter_test complains about ("Timer still pending
// after the widget tree was disposed"). Real coverage lives in
// feature-scoped widget tests.

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:keverna/core/outbox/outbox_providers.dart';
import 'package:keverna/main.dart';

void main() {
  testWidgets('KevernaApp boots without throwing', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          // Skip the worker so no Timer/subscription leaks into the
          // test framework's invariant checks.
          outboxWorkerProvider.overrideWith((ref) => null),
        ],
        child: const KevernaApp(),
      ),
    );
    // One additional frame so the router has a chance to redirect + build.
    await tester.pump();
  });
}
