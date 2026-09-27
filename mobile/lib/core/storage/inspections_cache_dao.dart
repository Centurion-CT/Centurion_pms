// Read-through cache for the inspections queue + details. Repository
// upserts on each successful network fetch; Riverpod streams from
// here so the UI reconciles automatically without re-parsing.
//
// Payloads round-trip as JSON strings — the Dart model parses on
// read. Silent bad-payload rows return null from the stream so a
// server-side schema drift never crashes the app.

import 'dart:convert';

import 'package:drift/drift.dart';

import '../../features/inspections/inspection_models.dart';
import 'local_db.dart';

class InspectionsCacheDao {
  InspectionsCacheDao(this._db);

  final LocalDb _db;

  // ── Queue rows ──

  /// Replace-all for a given filter, transactionally so the UI never
  /// sees a partial state (empty rows during the swap-over).
  Future<void> upsertQueue(String filter, List<Map<String, dynamic>> payloads) async {
    await _db.transaction(() async {
      await (_db.delete(_db.cachedQueueRows)..where((t) => t.filter.equals(filter))).go();
      for (final p in payloads) {
        await _db.into(_db.cachedQueueRows).insert(CachedQueueRowsCompanion.insert(
              inspectionId: p['id'] as int,
              filter: filter,
              payload: jsonEncode(p),
            ));
      }
    });
  }

  Stream<List<InspectionSummary>> watchQueue(String filter) {
    final query = _db.select(_db.cachedQueueRows)
      ..where((t) => t.filter.equals(filter))
      ..orderBy([(t) => OrderingTerm.asc(t.fetchedAt)]);
    return query.watch().map((rows) {
      final parsed = <InspectionSummary>[];
      for (final row in rows) {
        try {
          final payload = jsonDecode(row.payload) as Map<String, dynamic>;
          parsed.add(InspectionSummary.fromJson(payload));
        } catch (_) {
          // Skip bad rows — next refresh will overwrite.
        }
      }
      return parsed;
    });
  }

  Future<DateTime?> queueLastFetchedAt(String filter) async {
    final row = await (_db.select(_db.cachedQueueRows)
          ..where((t) => t.filter.equals(filter))
          ..orderBy([(t) => OrderingTerm.desc(t.fetchedAt)])
          ..limit(1))
        .getSingleOrNull();
    return row?.fetchedAt;
  }

  // ── Details ──

  Future<void> upsertDetail(int id, Map<String, dynamic> payload) async {
    await _db.into(_db.cachedInspectionDetails).insertOnConflictUpdate(
          CachedInspectionDetailsCompanion.insert(
            inspectionId: Value(id),
            payload: jsonEncode(payload),
          ),
        );
  }

  Stream<InspectionDetail?> watchDetail(int id) {
    final query = _db.select(_db.cachedInspectionDetails)..where((t) => t.inspectionId.equals(id));
    return query.watchSingleOrNull().map((row) {
      if (row == null) return null;
      try {
        return InspectionDetail.fromJson(jsonDecode(row.payload) as Map<String, dynamic>);
      } catch (_) {
        return null;
      }
    });
  }

  Future<DateTime?> detailLastFetchedAt(int id) async {
    final row = await (_db.select(_db.cachedInspectionDetails)
          ..where((t) => t.inspectionId.equals(id)))
        .getSingleOrNull();
    return row?.fetchedAt;
  }
}
