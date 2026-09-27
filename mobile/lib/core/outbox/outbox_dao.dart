// Thin CRUD wrapper around the outbox_entries Drift table. Kept
// separate from LocalDb so tests can substitute an in-memory DAO
// without spinning a full sqlite instance.

import 'package:drift/drift.dart';

import '../storage/local_db.dart';

class OutboxDao {
  OutboxDao(this._db);

  final LocalDb _db;

  Future<int> enqueue({
    required String idempotencyKey,
    required String endpoint,
    required String payload,
    String httpMethod = 'POST',
    String? hint,
  }) {
    return _db.into(_db.outboxEntries).insert(OutboxEntriesCompanion.insert(
          idempotencyKey: idempotencyKey,
          endpoint: endpoint,
          payload: payload,
          httpMethod: Value(httpMethod),
          hint: Value(hint),
        ));
  }

  /// Drainable entries, oldest first (FIFO — the operator's mental
  /// model is "the thing I tapped first should sync first").
  Future<List<OutboxEntry>> pending() {
    return (_db.select(_db.outboxEntries)
          ..where((t) => t.parked.equals(false))
          ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]))
        .get();
  }

  /// Entries the server rejected with 4xx — surfaced to the operator
  /// via the /outbox review screen. Newest first so the freshest
  /// failure sits at the top.
  Future<List<OutboxEntry>> parked() {
    return (_db.select(_db.outboxEntries)
          ..where((t) => t.parked.equals(true))
          ..orderBy([(t) => OrderingTerm.desc(t.createdAt)]))
        .get();
  }

  /// Live stream of the count of NOT-parked entries. Drives the
  /// badge in the queue screen's app bar.
  Stream<int> pendingCountStream() {
    final query = _db.select(_db.outboxEntries)..where((t) => t.parked.equals(false));
    return query.watch().map((rows) => rows.length);
  }

  /// Full pending list stream — powers per-entity "is this one
  /// syncing?" chips on detail screens. Same query as pending()
  /// but reactive.
  Stream<List<OutboxEntry>> pendingStream() {
    final query = _db.select(_db.outboxEntries)
      ..where((t) => t.parked.equals(false))
      ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]);
    return query.watch();
  }

  Stream<List<OutboxEntry>> parkedStream() {
    final query = _db.select(_db.outboxEntries)
      ..where((t) => t.parked.equals(true))
      ..orderBy([(t) => OrderingTerm.desc(t.createdAt)]);
    return query.watch();
  }

  Future<void> delete(int id) {
    return (_db.delete(_db.outboxEntries)..where((t) => t.id.equals(id))).go();
  }

  Future<void> park(int id, String error) {
    return (_db.update(_db.outboxEntries)..where((t) => t.id.equals(id))).write(
      OutboxEntriesCompanion(
        parked: const Value(true),
        lastError: Value(error),
        lastAttemptedAt: Value(DateTime.now()),
      ),
    );
  }

  Future<void> unpark(int id) {
    return (_db.update(_db.outboxEntries)..where((t) => t.id.equals(id))).write(
      const OutboxEntriesCompanion(
        parked: Value(false),
        retryCount: Value(0),
        lastError: Value(null),
      ),
    );
  }

  Future<void> bumpRetry(int id, String error) async {
    // Read-modify-write in one round trip — atomic enough for our
    // single-worker draining model.
    final row = await (_db.select(_db.outboxEntries)..where((t) => t.id.equals(id))).getSingleOrNull();
    if (row == null) return;
    await (_db.update(_db.outboxEntries)..where((t) => t.id.equals(id))).write(
      OutboxEntriesCompanion(
        retryCount: Value(row.retryCount + 1),
        lastError: Value(error),
        lastAttemptedAt: Value(DateTime.now()),
      ),
    );
  }
}
