// Read-through cache for /manager/glance. Payload is a single JSON
// row keyed by id=1 (per-tenant, per-installation).

import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/outbox/outbox_providers.dart';
import '../../core/storage/local_db.dart';
import 'glance_models.dart';

class GlanceRepository {
  GlanceRepository({required Dio dio, required LocalDb db})
      : _dio = dio,
        _db = db;

  final Dio _dio;
  final LocalDb _db;

  Future<void> refresh() async {
    final resp = await _dio.get<Map<String, dynamic>>('manager/glance');
    await _db.into(_db.cachedManagerGlance).insertOnConflictUpdate(
          CachedManagerGlanceCompanion.insert(
            payload: jsonEncode(resp.data!),
          ),
        );
  }

  Stream<ManagerGlance?> watch() {
    return (_db.select(_db.cachedManagerGlance)..limit(1)).watchSingleOrNull().map((row) {
      if (row == null) return null;
      try {
        return ManagerGlance.fromJson(jsonDecode(row.payload) as Map<String, dynamic>);
      } catch (_) {
        return null;
      }
    });
  }

  /// Convenience: peek at the last-fetched timestamp so the UI can
  /// paint "as of Xm ago" without hydrating the whole payload.
  Future<DateTime?> lastFetchedAt() async {
    final row =
        await (_db.select(_db.cachedManagerGlance)..limit(1)).getSingleOrNull();
    return row?.fetchedAt;
  }
}

// ── Providers ──

final glanceRepositoryProvider = Provider<GlanceRepository?>((ref) {
  final client = ref.watch(apiClientProvider);
  final db = ref.watch(localDbProvider);
  if (client == null) return null;
  return GlanceRepository(dio: client.raw, db: db);
});

/// Stream cached glance + kick a background refresh on subscribe.
/// Errors from the refresh don't affect the stream (cache stays
/// visible). UI's pull-to-refresh calls repo.refresh() for explicit
/// retry + snackbar feedback.
final managerGlanceProvider = StreamProvider.autoDispose<ManagerGlance?>((ref) {
  final repo = ref.watch(glanceRepositoryProvider);
  if (repo == null) return Stream.value(null);
  Future.microtask(() async {
    try {
      await repo.refresh();
    } catch (_) {}
  });
  return repo.watch();
});
