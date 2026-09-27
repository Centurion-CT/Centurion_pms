// CheckoutSheet balance test — mount the sheet with a cart of
// known total, then verify the "Balanced / Under / Over" copy +
// Complete-sale button enable state track the sum of the tender
// rows. The rule is dead simple but it's the wall between an
// operator hitting Complete with a mismatched split and a server
// 422 they can't easily diagnose from a phone.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:keverna/core/theme/keverna_theme.dart';
import 'package:keverna/features/pos/checkout_sheet.dart';
import 'package:keverna/features/pos/pos_models.dart';
import 'package:keverna/features/pos/pos_repository.dart';

Outlet _fixtureOutlet() => const Outlet(
      id: 1,
      name: 'Bar',
      slug: 'bar',
      type: 'bar',
      active: true,
      taxRate: '0',
    );

Product _fixtureProduct({int price = 1500}) => Product(
      id: 42,
      name: 'Guinness',
      price: price,
      active: true,
      trackStock: false,
      quantityOnHand: 0,
      lowStock: false,
    );

Future<void> _pumpSheet(WidgetTester tester, ProviderContainer container) {
  return tester.pumpWidget(
    UncontrolledProviderScope(
      container: container,
      child: MaterialApp(
        theme: KevernaTheme.light(),
        home: Scaffold(body: CheckoutSheet(outlet: _fixtureOutlet())),
      ),
    ),
  );
}

void main() {
  testWidgets('starts Balanced when the single seed tender matches cart total',
      (tester) async {
    final container = ProviderContainer();
    // Cart: 2 × Guinness @ ₦1500 = ₦3000. CheckoutSheet seeds one
    // cash tender pre-filled to the total.
    container.read(cartProvider.notifier)
      ..add(_fixtureProduct())
      ..add(_fixtureProduct());

    await _pumpSheet(tester, container);
    await tester.pump();

    expect(find.text('Balanced'), findsOneWidget);
    expect(find.text('Under'), findsNothing);
    expect(find.text('Over'), findsNothing);

    final button = tester.widget<ElevatedButton>(
      find.widgetWithText(ElevatedButton, 'Complete sale'),
    );
    expect(button.onPressed, isNotNull, reason: 'Complete should be enabled when balanced.');
  });

  testWidgets('Under when tender amount is less than cart total', (tester) async {
    final container = ProviderContainer();
    container.read(cartProvider.notifier)
      ..add(_fixtureProduct())
      ..add(_fixtureProduct());   // total: ₦3000, seed tender: ₦3000

    await _pumpSheet(tester, container);
    await tester.pump();

    // Clear + retype the amount field to ₦1000 (under).
    final amountField = find.widgetWithText(TextField, '₦').first;
    await tester.enterText(amountField, '1000');
    await tester.pump();

    expect(find.text('Under'), findsOneWidget);
    final button = tester.widget<ElevatedButton>(
      find.widgetWithText(ElevatedButton, 'Complete sale'),
    );
    expect(button.onPressed, isNull, reason: 'Complete disabled when under.');
  });

  testWidgets('Over when tender amount exceeds cart total', (tester) async {
    final container = ProviderContainer();
    container.read(cartProvider.notifier).add(_fixtureProduct());   // ₦1500 total

    await _pumpSheet(tester, container);
    await tester.pump();

    final amountField = find.widgetWithText(TextField, '₦').first;
    await tester.enterText(amountField, '5000');
    await tester.pump();

    expect(find.text('Over'), findsOneWidget);
    final button = tester.widget<ElevatedButton>(
      find.widgetWithText(ElevatedButton, 'Complete sale'),
    );
    expect(button.onPressed, isNull, reason: 'Complete disabled when over.');
  });
}
