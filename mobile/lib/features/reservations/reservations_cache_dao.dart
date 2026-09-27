// Read-through cache for reservations — same pattern as
// InspectionsCacheDao.

import 'dart:convert';

import 'package:drift/drift.dart';

import '../../core/storage/local_db.dart';
import 'reservation_models.dart';

class ReservationsCacheDao {
  ReservationsCacheDao(this._db);

  final LocalDb _db;

  Future<void> upsertList(String filter, List<Map<String, dynamic>> payloads) async {
    await _db.transaction(() async {
      await (_db.delete(_db.cachedReservationRows)..where((t) => t.filter.equals(filter))).go();
      for (final p in payloads) {
        await _db.into(_db.cachedReservationRows).insert(CachedReservationRowsCompanion.insert(
              bookingRef: p['booking_ref'] as String,
              filter: filter,
              payload: jsonEncode(p),
            ));
      }
    });
  }

  Stream<List<ReservationSummary>> watchList(String filter) {
    final query = _db.select(_db.cachedReservationRows)
      ..where((t) => t.filter.equals(filter))
      ..orderBy([(t) => OrderingTerm.asc(t.fetchedAt)]);
    return query.watch().map((rows) {
      final parsed = <ReservationSummary>[];
      for (final row in rows) {
        try {
          final payload = jsonDecode(row.payload) as Map<String, dynamic>;
          parsed.add(ReservationSummary.fromJson(payload));
        } catch (_) {}
      }
      return parsed;
    });
  }

  Future<void> upsertDetail(String ref, Map<String, dynamic> payload) async {
    await _db.into(_db.cachedReservationDetails).insertOnConflictUpdate(
          CachedReservationDetailsCompanion.insert(
            bookingRef: ref,
            payload: jsonEncode(payload),
          ),
        );
  }

  Stream<ReservationDetail?> watchDetail(String ref) {
    final query = _db.select(_db.cachedReservationDetails)..where((t) => t.bookingRef.equals(ref));
    return query.watchSingleOrNull().map((row) {
      if (row == null) return null;
      try {
        return ReservationDetail.fromJson(jsonDecode(row.payload) as Map<String, dynamic>);
      } catch (_) {
        return null;
      }
    });
  }
}
