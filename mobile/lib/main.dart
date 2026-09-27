import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/outbox/outbox_providers.dart';
import 'core/router.dart';
import 'core/storage/local_db.dart';
import 'core/theme/keverna_theme.dart';
import 'features/inspections/inspections_repository.dart';
import 'features/inventory/inventory_repository.dart';
import 'features/pos/pos_repository.dart';
import 'features/reservations/reservations_repository.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]);
  runApp(const ProviderScope(child: KevernaApp()));
}

class KevernaApp extends ConsumerWidget {
  const KevernaApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Force the outbox worker to instantiate the moment a session
    // exists — otherwise it wouldn't spin up until a screen happened
    // to watch it. This read is a no-op when session is null (the
    // provider itself returns null), so nothing wastes cycles on the
    // pair screen.
    ref.watch(outboxWorkerProvider);

    // Bridge drained-mutation events → provider invalidation. Lives
    // here (not in outbox_providers.dart) so the outbox layer
    // doesn't need to know about feature repositories. Each drained
    // entry's endpoint is matched against the endpoint families we
    // ship; unrecognised patterns are skipped.
    ref.listen<OutboxEntry?>(outboxDrainEventProvider, (_, entry) {
      if (entry == null) return;

      final inspection = RegExp(r'inspections/(\d+)').firstMatch(entry.endpoint);
      if (inspection != null) {
        ref.invalidate(inspectionsQueueProvider);
        ref.invalidate(inspectionDetailProvider(int.parse(inspection.group(1)!)));
        return;
      }

      final reservation = RegExp(r'reservations/([A-Z0-9\-]+)').firstMatch(entry.endpoint);
      if (reservation != null) {
        final ref_ = reservation.group(1)!;
        ref.invalidate(reservationsListProvider('arrivals'));
        ref.invalidate(reservationsListProvider('in_house'));
        ref.invalidate(reservationsListProvider('departures'));
        ref.invalidate(reservationDetailProvider(ref_));
        return;
      }

      final sale = RegExp(r'outlets/(\d+)/sales').firstMatch(entry.endpoint);
      if (sale != null) {
        // Refresh the product catalogue for the outlet so stock
        // levels reflect the decrement from the drained sale.
        ref.invalidate(productsProvider(int.parse(sale.group(1)!)));
        return;
      }

      if (entry.endpoint == 'stock-requests'
          || RegExp(r'stock-requests/\d+/(approve|reject)$').hasMatch(entry.endpoint)) {
        // Any stock-request mutation drained — refresh mine + pending.
        ref.invalidate(myStockRequestsProvider);
        ref.invalidate(pendingStockRequestsProvider);
        return;
      }

      if (entry.endpoint == 'bookings') {
        // Walk-in booking — surface it on all three reservation tabs.
        ref.invalidate(reservationsListProvider('arrivals'));
        ref.invalidate(reservationsListProvider('in_house'));
        ref.invalidate(reservationsListProvider('departures'));
      }
    });

    final router = ref.watch(routerProvider);
    return MaterialApp.router(
      title: 'Keverna',
      debugShowCheckedModeBanner: false,
      theme: KevernaTheme.light(),
      routerConfig: router,
    );
  }
}
