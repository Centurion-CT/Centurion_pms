// Riverpod wiring for storage, outbox, API and every screen's data.
//
//   localDb -> cacheDao / outboxDao
//   session -> staffApi (or the demo hotel) -> staffRepository
//   outboxWorker drains in the background while signed in
//   Resource<T> per GET path: paints the cache, refetches, and recomputes
//   whenever the outbox changes (localRevision) or settles (serverRevision)

import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_error.dart';
import '../../core/api/staff_api.dart';
import '../../core/auth/session.dart';
import '../../core/config.dart';
import '../../core/outbox/outbox_dao.dart';
import '../../core/outbox/outbox_worker.dart';
import '../../core/storage/cache_dao.dart';
import '../../core/storage/local_db.dart';
import '../../demo/demo_backend.dart';
import 'models.dart';
import 'staff_repository.dart';

final localDbProvider = Provider<LocalDb>((ref) {
  final db = LocalDb();
  ref.onDispose(db.close);
  return db;
});

final cacheDaoProvider = Provider<CacheDao>((ref) => CacheDao(ref.watch(localDbProvider)));
final outboxDaoProvider = Provider<OutboxDao>((ref) => OutboxDao(ref.watch(localDbProvider)));

/// The in-memory hotel used when built with STAFF_DEMO=true.
final demoBackendProvider = Provider<DemoBackend>((ref) => DemoBackend());

final staffApiProvider = Provider<StaffApi?>((ref) {
  final session = ref.watch(sessionProvider.select((s) => s.session));
  if (session == null) return null;
  if (kDemo) return DemoStaffApi(ref.watch(demoBackendProvider), session.operator);
  return DioStaffApi(
    baseUrl: session.baseUrl,
    token: session.token,
    onUnauthorized: () => ref.read(sessionProvider.notifier).signOut(kickedOut: true),
  );
});

/// Bumped when the outbox changes: screens recompute from the cache.
final localRevisionProvider = StateProvider<int>((ref) => 0);

/// Bumped when the outbox has emptied: screens refetch from the server.
final serverRevisionProvider = StateProvider<int>((ref) => 0);

/// Messages for changes the server refused (shown as a snackbar).
final outboxMessagesProvider = Provider<StreamController<String>>((ref) {
  final c = StreamController<String>.broadcast();
  ref.onDispose(c.close);
  return c;
});

final Provider<StaffRepository?> staffRepositoryProvider = Provider<StaffRepository?>((ref) {
  final api = ref.watch(staffApiProvider);
  if (api == null) return null;
  return StaffRepository(
    api: api,
    cache: ref.watch(cacheDaoProvider),
    outbox: ref.watch(outboxDaoProvider),
    onEnqueued: () {
      ref.read(localRevisionProvider.notifier).state++;
      ref.read(outboxWorkerProvider)?.drain(force: true);
    },
  );
});

/// Off in widget tests so no timers or platform channels run.
final outboxAutoStartProvider = Provider<bool>((ref) => true);

final Provider<OutboxWorker?> outboxWorkerProvider = Provider<OutboxWorker?>((ref) {
  final api = ref.watch(staffApiProvider);
  if (api == null) return null;
  String? lastMsg;
  Timer? refetch;
  ref.onDispose(() => refetch?.cancel());
  DateTime lastAt = DateTime(2000);
  final worker = OutboxWorker(
    dao: ref.watch(outboxDaoProvider),
    api: api,
    onSent: (e) async {
      await ref.read(staffRepositoryProvider)?.bake(e);
      ref.read(localRevisionProvider.notifier).state++;
    },
    onDropped: (e, err) {
      ref.read(localRevisionProvider.notifier).state++;
      final msg = dropMessage(e, err);
      final now = DateTime.now();
      if (msg != lastMsg || now.difference(lastAt) > const Duration(seconds: 5)) {
        ref.read(outboxMessagesProvider).add(msg);
      }
      lastMsg = msg;
      lastAt = now;
    },
    onSettled: (empty) {
      // Refetch once things go quiet, not after every tick.
      refetch?.cancel();
      if (empty) {
        refetch = Timer(const Duration(seconds: 2), () => ref.read(serverRevisionProvider.notifier).state++);
      }
    },
  );
  if (ref.watch(outboxAutoStartProvider)) worker.start();
  ref.onDispose(worker.stop);
  return worker;
});

final outboxCountProvider = StreamProvider<int>((ref) => ref.watch(outboxDaoProvider).countStream());

final connectivityProvider = StreamProvider<bool>((ref) async* {
  final c = Connectivity();
  bool online(List<ConnectivityResult> r) => r.any((x) => x != ConnectivityResult.none);
  yield online(await c.checkConnectivity());
  yield* c.onConnectivityChanged.map(online);
});

// ------------------------------------------------------------ resources

class Resource<T> extends StateNotifier<AsyncValue<T>> {
  Resource(this._repo, this.key, this._parse) : super(const AsyncLoading()) {
    _load();
  }

  final StaffRepository? _repo;
  final String key;
  final T Function(Map<String, dynamic>) _parse;

  void _set(Map<String, dynamic> json) {
    if (!mounted) return;
    try {
      state = AsyncData(_parse(json));
    } catch (e, st) {
      if (!state.hasValue) state = AsyncError(e, st);
    }
  }

  Future<void> _load() async {
    final repo = _repo;
    if (repo == null) return;
    final cached = await repo.read(key);
    if (cached != null) _set(cached);
    await refresh();
  }

  /// Returns false when the server could not be reached (the cache stays).
  Future<bool> refresh() async {
    final repo = _repo;
    if (repo == null) return false;
    try {
      _set(await repo.fetch(key));
      return true;
    } catch (e, st) {
      if (mounted && !state.hasValue) state = AsyncError(ApiError.from(e), st);
      return false;
    }
  }

  Future<void> recompute() async {
    final cached = await _repo?.read(key);
    if (cached != null) _set(cached);
  }
}

Resource<T> _resource<T>(
  Ref<Object?> ref,
  String key,
  T Function(Map<String, dynamic>) parse, {
  bool refetchAfterWrites = true,
}) {
  final r = Resource<T>(ref.watch(staffRepositoryProvider), key, parse);
  ref.listen<int>(localRevisionProvider, (_, __) => r.recompute());
  if (refetchAfterWrites) ref.listen<int>(serverRevisionProvider, (_, __) => r.refresh());
  return r;
}

typedef ResourceProvider<T> = AutoDisposeStateNotifierProvider<Resource<T>, AsyncValue<T>>;
typedef ResourceFamily<T> = AutoDisposeStateNotifierProviderFamily<Resource<T>, AsyncValue<T>, int>;

final staffSessionProvider = StateNotifierProvider<Resource<StaffSessionInfo>, AsyncValue<StaffSessionInfo>>(
  (ref) => _resource(ref, 'staff/session', StaffSessionInfo.fromJson, refetchAfterWrites: false),
);

final ResourceProvider<RoomsData> roomsProvider =
    StateNotifierProvider.autoDispose((ref) => _resource(ref, 'staff/rooms', RoomsData.fromJson));

final ResourceFamily<RoomDetail> roomDetailProvider =
    StateNotifierProvider.autoDispose.family((ref, int id) => _resource(ref, 'staff/rooms/$id', RoomDetail.fromJson));

final ResourceProvider<ChecksData> checksProvider =
    StateNotifierProvider.autoDispose((ref) => _resource(ref, 'staff/checks', ChecksData.fromJson));

final ResourceFamily<CheckDetail> checkDetailProvider =
    StateNotifierProvider.autoDispose.family((ref, int id) => _resource(ref, 'staff/checks/$id', CheckDetail.fromJson));

final ResourceProvider<SuppliesData> suppliesProvider =
    StateNotifierProvider.autoDispose((ref) => _resource(ref, 'staff/supplies', SuppliesData.fromJson));

final ResourceProvider<MeData> meProvider =
    StateNotifierProvider.autoDispose((ref) => _resource(ref, 'staff/me', MeData.fromJson));
