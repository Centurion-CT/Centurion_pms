// Riverpod wiring for the outbox stack. Ordering matters:
//   localDb        — app-lifetime singleton (SQLite handle)
//   outboxDao      — trivially derived from localDb
//   outboxWorker   — rebuilt whenever the Dio changes (i.e. sign-in
//                    / sign-out); old worker's timer + connectivity
//                    subscription cancelled via ref.onDispose
//   mutationService — depends on all three; null while unpaired
//
// Deliberately does NOT import feature repositories — the drained-
// mutation → provider-invalidation wiring lives in main.dart via
// outboxDrainEventProvider. Any repository can listen to that
// stream to invalidate itself. This inversion breaks what was
// previously a circular import (outbox → reservations_repository →
// outbox) that confused the Dart analyzer's type resolution.

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../storage/local_db.dart';
import 'mutation_service.dart';
import 'outbox_dao.dart';
import 'outbox_worker.dart';

final localDbProvider = Provider<LocalDb>((ref) {
  final db = LocalDb();
  ref.onDispose(db.close);
  return db;
});

final outboxDaoProvider = Provider<OutboxDao>((ref) {
  return OutboxDao(ref.watch(localDbProvider));
});

/// Broadcast channel — the worker writes to this every time it
/// successfully drains an entry. Repositories listen for their
/// endpoint patterns to invalidate themselves. Nullable so the
/// stream can seed as null before any drain has happened.
final outboxDrainEventProvider = StateProvider<OutboxEntry?>((ref) => null);

final outboxWorkerProvider = Provider<OutboxWorker?>((ref) {
  final client = ref.watch(apiClientProvider);
  final dao = ref.watch(outboxDaoProvider);
  if (client == null) return null;

  final worker = OutboxWorker(
    dao: dao,
    dio: client.raw,
    onDrained: (entry) {
      ref.read(outboxDrainEventProvider.notifier).state = entry;
    },
  );
  worker.start();
  ref.onDispose(worker.stop);
  return worker;
});

final mutationServiceProvider = Provider<MutationService?>((ref) {
  final client = ref.watch(apiClientProvider);
  final dao = ref.watch(outboxDaoProvider);
  final worker = ref.watch(outboxWorkerProvider);
  if (client == null || worker == null) return null;
  return MutationService(dio: client.raw, dao: dao, worker: worker);
});

/// Live count of NOT-parked entries. Powers the "N queued" badge in
/// the queue screen app bar.
final outboxPendingCountProvider = StreamProvider<int>((ref) {
  final dao = ref.watch(outboxDaoProvider);
  return dao.pendingCountStream();
});

/// Parked entries (server-rejected). Powers the /outbox review screen.
final outboxParkedProvider = StreamProvider<List<OutboxEntry>>((ref) {
  final dao = ref.watch(outboxDaoProvider);
  return dao.parkedStream();
});

/// True while any not-parked outbox entry targets this inspection.
/// Powers a "Syncing…" chip on the inspection detail screen.
final inspectionHasPendingWriteProvider =
    StreamProvider.autoDispose.family<bool, int>((ref, id) {
  final dao = ref.watch(outboxDaoProvider);
  final needle = 'inspections/$id/';
  return dao.pendingStream().map((rows) => rows.any((r) => r.endpoint.startsWith(needle)));
});

/// True while any not-parked outbox entry targets this reservation.
/// Powers a "Syncing…" chip on the reservation detail screen.
final reservationHasPendingWriteProvider =
    StreamProvider.autoDispose.family<bool, String>((ref, bookingRef) {
  final dao = ref.watch(outboxDaoProvider);
  final needle = 'reservations/$bookingRef/';
  return dao.pendingStream().map((rows) => rows.any((r) => r.endpoint.startsWith(needle)));
});
