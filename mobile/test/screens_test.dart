import 'dart:convert';

import 'package:centurion_staff/core/auth/session.dart';
import 'package:centurion_staff/demo/demo_backend.dart';
import 'package:centurion_staff/features/auth/login_service.dart';
import 'package:centurion_staff/features/checks/checks_screen.dart';
import 'package:centurion_staff/features/staff/providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'helpers.dart';

void main() {
  group('sign in', () {
    testWidgets('phone -> PIN: wrong PIN shows the message, right PIN lands on My rooms', (tester) async {
      final login = FakeLoginService(
        (req) => req.pin == '246810'
            ? LoginSuccess(demoSession(demoBlessing))
            : const LoginFailure(code: 'invalid_credentials', status: 401),
      );
      final h = Harness(login: login);
      await h.pumpApp(tester);

      expect(find.text('Sign in'), findsOneWidget);
      await tester.enterText(find.byKey(const Key('phone-field')), '0803 123 4567');
      await tester.tap(find.text('Next'));
      await h.settle(tester);
      expect(find.text('Enter your PIN'), findsOneWidget);
      expect(find.text('For 0803 123 4567'), findsOneWidget);

      // 4 digits do not submit by themselves (PINs can be 6 long).
      for (final d in '1111'.split('')) {
        await tester.tap(find.byKey(Key('key-$d')));
      }
      await h.settle(tester);
      expect(login.calls, isEmpty);
      await tester.tap(find.byKey(const Key('key-submit')));
      await h.settle(tester);
      expect(login.calls.single.pin, '1111');
      expect(login.calls.single.phone, '0803 123 4567');
      expect(find.text("That phone number and PIN don't match."), findsOneWidget);

      // A 6th digit submits on its own.
      for (final d in '246810'.split('')) {
        await tester.tap(find.byKey(Key('key-$d')));
      }
      await h.settle(tester);
      expect(login.calls.last.pin, '246810');
      expect(
        find.text('Good morning, Blessing').evaluate().isNotEmpty ||
            find.text('Good afternoon, Blessing').evaluate().isNotEmpty ||
            find.text('Good evening, Blessing').evaluate().isNotEmpty,
        isTrue,
      );
      expect(find.text('My rooms'), findsOneWidget);
      expect(find.text('204'), findsOneWidget);
      await h.dispose(tester);
    });

    testWidgets('a manager gets the friendly "not for you" screen', (tester) async {
      final fake = FakeLoginService((_) => const LoginFailure(code: 'staff_only', status: 403, detail: 'x'));
      final h = Harness(login: fake);
      await h.pumpApp(tester);
      await tester.enterText(find.byKey(const Key('phone-field')), '08031234567');
      await tester.tap(find.text('Next'));
      await h.settle(tester);
      for (final d in '1234'.split('')) {
        await tester.tap(find.byKey(Key('key-$d')));
      }
      await tester.pump();
      await tester.tap(find.byKey(const Key('key-submit')));
      await h.settle(tester);
      expect(fake.calls.single.pin, '1234');
      expect(
        find.text('This app is for housekeepers and porters. Use the Centurion dashboard on the web instead.'),
        findsOneWidget,
      );
      await h.dispose(tester);
    });

    testWidgets('a 300 shows the hotel picker and resends with tenant_id', (tester) async {
      final login = FakeLoginService(
        (req) => req.tenantId == null
            ? const LoginPickTenant([Tenant(id: 1, name: 'Centurion Apartments'), Tenant(id: 2, name: 'Annex')])
            : LoginSuccess(demoSession(demoMusa)),
      );
      final h = Harness(login: login);
      await h.pumpApp(tester);
      await tester.enterText(find.byKey(const Key('phone-field')), '08057654321');
      await tester.tap(find.text('Next'));
      await h.settle(tester);
      for (final d in '135790'.split('')) {
        await tester.tap(find.byKey(Key('key-$d')));
      }
      await h.settle(tester);
      expect(find.text('Pick your hotel'), findsOneWidget);
      await tester.tap(find.text('Annex'));
      await h.settle(tester);
      expect(login.calls.last.tenantId, 2);
      expect(login.calls.last.pin, '135790');
      expect(find.text('To check'), findsOneWidget);
      await h.dispose(tester);
    });
  });

  testWidgets('cleaning: tick updates progress, finish sheet queues a finish', (tester) async {
    final h = Harness(session: demoSession(demoBlessing));
    await h.pumpApp(tester);
    await tester.tap(find.text('204'));
    await h.settle(tester);
    expect(find.text('Room 204'), findsOneWidget);
    expect(find.text('DELUXE KING · GUEST ARRIVING TODAY'), findsOneWidget);

    String progress() => (tester.widget<Text>(find.byKey(const Key('progress-text'))).textSpan!).toPlainText();
    expect(progress(), '0 of 9 done');

    await tester.tap(find.byKey(const Key('tick-101')));
    await h.settle(tester);
    await tester.tap(find.byKey(const Key('tick-102')));
    await h.settle(tester);
    expect(progress(), '2 of 9 done');

    await tester.tap(find.byKey(const Key('tick-102')));
    await h.settle(tester);
    expect(progress(), '1 of 9 done');

    await tester.tap(find.byKey(const Key('finish-room')));
    await h.settle(tester);
    expect(find.text('Anything broken or missing?'), findsOneWidget);
    expect(find.text('8 items not ticked'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('finish-note')), 'Bedside lamp bulb is out.');
    await tester.tap(find.byKey(const Key('send-to-porter')));
    await h.settle(tester);

    final rows = await tester.runAsync(() => h.container.read(outboxDaoProvider).pending());
    expect(rows!.map((r) => r.endpoint), [
      'staff/rooms/1/start',
      'staff/rooms/1/items/101',
      'staff/rooms/1/items/102',
      'staff/rooms/1/items/102',
      'staff/rooms/1/finish',
    ]);
    expect(jsonDecode(rows.last.payload), {'note': 'Bedside lamp bulb is out.'});
    expect(rows.map((r) => r.idempotencyKey).toSet().length, 5);

    // Back on the list, optimistically with the porter.
    expect(find.text('Room 204 sent to the porter.'), findsOneWidget);
    expect(find.text('3 rooms to clean, 2 with the porter'), findsOneWidget);
    expect(find.text('Offline. Your changes will send when you\'re back online.'), findsNothing);
    expect(find.text('Sending your changes.'), findsOneWidget);
    await h.dispose(tester);
  });

  testWidgets('porter: send-back sheet builds the right payload', (tester) async {
    Map<String, dynamic>? result;
    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => Scaffold(
            body: Center(
              child: TextButton(
                onPressed: () async {
                  result = await showModalBottomSheet<Map<String, dynamic>>(
                    context: context,
                    isScrollControlled: true,
                    builder: (_) => const SendBackSheet(cleanerFirstName: 'Blessing'),
                  );
                },
                child: const Text('open'),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    expect(find.text('Send back to Blessing'), findsOneWidget);

    // Nothing picked yet: the button does nothing.
    await tester.tap(find.byKey(const Key('confirm-send-back')));
    await tester.pumpAndSettle();
    expect(result, isNull);

    await tester.tap(find.byKey(const Key('reason-bed')));
    await tester.tap(find.byKey(const Key('reason-towels')));
    await tester.tap(find.byKey(const Key('reason-smell')));
    await tester.tap(find.byKey(const Key('reason-smell'))); // untap
    await tester.pump();
    await tester.enterText(find.byKey(const Key('send-back-note')), ' Hair in the sink ');
    await tester.tap(find.byKey(const Key('confirm-send-back')));
    await tester.pumpAndSettle();
    expect(result, {
      'reasons': ['towels', 'bed'],
      'note': 'Hair in the sink',
    });
  });

  testWidgets('porter: send back from Room 210 queues the request and empties the card', (tester) async {
    final h = Harness(session: demoSession(demoMusa));
    await h.pumpApp(tester);
    expect(find.text('3 rooms to check'), findsOneWidget);
    await tester.tap(find.text('210'));
    await h.settle(tester);
    expect(find.text('Cleaned by Blessing'), findsOneWidget);
    expect(find.text('"Bedside lamp bulb is out."'), findsOneWidget);
    await tester.tap(find.byKey(const Key('send-back')));
    await h.settle(tester);
    await tester.tap(find.byKey(const Key('reason-towels')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('confirm-send-back')));
    await h.settle(tester);
    final rows = await tester.runAsync(() => h.container.read(outboxDaoProvider).pending());
    expect(rows!.single.endpoint, 'staff/checks/5/send-back');
    expect(jsonDecode(rows.single.payload), {
      'reasons': ['towels'],
    });
    expect(find.text('2 rooms to check'), findsOneWidget);
    await h.dispose(tester);
  });

  testWidgets('with a live outbox the change reaches the demo server', (tester) async {
    final h = Harness(session: demoSession(demoBlessing), liveOutbox: true);
    await h.pumpApp(tester);
    await tester.tap(find.text('112'));
    await h.settle(tester);
    await tester.tap(find.byKey(const Key('tick-201')));
    await h.settle(tester);
    expect(h.backend.rooms[2]!['state'], 'cleaning');
    final rows = await tester.runAsync(() => h.container.read(outboxDaoProvider).pending());
    expect(rows, isEmpty);
    await h.dispose(tester);
  });

  for (final screen in ['home', 'room', 'supplies', 'me']) {
    testWidgets('no overflow at 1.3x text on $screen', (tester) async {
      final h = Harness(session: demoSession(demoBlessing));
      await h.pumpApp(tester, textScale: 1.3);
      if (screen == 'room') {
        await tester.scrollUntilVisible(find.text('118'), 200);
        await tester.tap(find.text('118'));
      } else if (screen != 'home') {
        await tester.tap(find.text(screen == 'me' ? 'Me' : 'Supplies'));
      }
      await h.settle(tester);
      expect(tester.takeException(), isNull);
      await h.dispose(tester);
    });
  }

  testWidgets('no overflow at 1.3x text on the PIN pad', (tester) async {
    final h = Harness(login: FakeLoginService((_) => const LoginFailure(code: 'x')));
    await h.pumpApp(tester, textScale: 1.3);
    await tester.enterText(find.byKey(const Key('phone-field')), '08031234567');
    await tester.tap(find.text('Next'));
    await h.settle(tester);
    expect(tester.takeException(), isNull);
    await h.dispose(tester);
  });

  test('role mapping', () {
    expect(roleFrom('house_cleaner'), StaffRole.housekeeper);
    expect(roleFrom('porter'), StaffRole.porter);
    expect(roleFrom('manager'), StaffRole.other);
  });
}
