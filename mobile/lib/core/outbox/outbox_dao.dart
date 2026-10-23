import 'package:drift/drift.dart';

import '../storage/local_db.dart';

class OutboxDao {
  OutboxDao(this._db);

  final LocalDb _db;

  Future<int> enqueue({
    required String idempotencyKey,
    required String httpMethod,
    required String endpoint,
    required String payload,
    String label = '',
  }) {
    return _db.into(_db.outboxEntries).insert(
          OutboxEntriesCompanion.insert(
            idempotencyKey: idempotencyKey,
            endpoint: endpoint,
            payload: payload,
            httpMethod: Value(httpMethod),
            label: Value(label),
          ),
        );
  }

  /// Oldest first: the thing tapped first sends first.
  Future<List<OutboxEntry>> pending() {
    return (_db.select(_db.outboxEntries)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();
  }

  Stream<int> countStream() => _db.select(_db.outboxEntries).watch().map((rows) => rows.length);

  Future<void> delete(int id) => (_db.delete(_db.outboxEntries)..where((t) => t.id.equals(id))).go();

  Future<void> bumpRetry(OutboxEntry e, String error) {
    return (_db.update(_db.outboxEntries)..where((t) => t.id.equals(e.id))).write(
      OutboxEntriesCompanion(
        retryCount: Value(e.retryCount + 1),
        lastError: Value(error),
        lastAttemptedAt: Value(DateTime.now()),
      ),
    );
  }
}
