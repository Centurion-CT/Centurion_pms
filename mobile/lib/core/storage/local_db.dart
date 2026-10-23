// Local SQLite via Drift. Two tables:
//   1. Outbox: every write (start, tick, finish, ready, send back,
//      supplies), replayed in order by the worker.
//   2. Cache: the last good JSON for each GET path, so screens paint
//      straight away and keep working offline. Payloads stay opaque JSON
//      so server shape changes never need a client migration.

import 'package:drift/drift.dart';
import 'package:drift_flutter/drift_flutter.dart';

part 'local_db.g.dart';

class OutboxEntries extends Table {
  IntColumn get id => integer().autoIncrement()();

  TextColumn get idempotencyKey => text().withLength(min: 8, max: 128)();
  TextColumn get httpMethod => text().withDefault(const Constant('POST'))();
  TextColumn get endpoint => text()();
  TextColumn get payload => text()();

  /// What the change is about, e.g. "Room 204". Used in messages.
  TextColumn get label => text().withDefault(const Constant(''))();

  IntColumn get retryCount => integer().withDefault(const Constant(0))();
  DateTimeColumn get lastAttemptedAt => dateTime().nullable()();
  DateTimeColumn get createdAt => dateTime().withDefault(currentDateAndTime)();
  TextColumn get lastError => text().nullable()();
}

class CachedResponses extends Table {
  TextColumn get cacheKey => text()();
  TextColumn get payload => text()();
  DateTimeColumn get fetchedAt => dateTime()();

  @override
  Set<Column<Object>> get primaryKey => {cacheKey};
}

@DriftDatabase(tables: [OutboxEntries, CachedResponses])
class LocalDb extends _$LocalDb {
  LocalDb() : super(_open());

  LocalDb.forTesting(super.executor);

  @override
  int get schemaVersion => 10;

  @override
  MigrationStrategy get migration => MigrationStrategy(
        onCreate: (m) => m.createAll(),
        onUpgrade: (m, from, to) async {
          // v10 is the Centurion Staff rebuild. Nothing from the old app is
          // worth keeping, so drop every table (old and new) and start fresh.
          final rows = await customSelect(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
          ).get();
          for (final r in rows) {
            await customStatement('DROP TABLE IF EXISTS "${r.read<String>('name')}"');
          }
          await m.createAll();
        },
      );

  /// Sign out: drop cached reads so the next person never sees them.
  Future<void> clearCache() => delete(cachedResponses).go();

  Future<void> clearOutbox() => delete(outboxEntries).go();

  static QueryExecutor _open() {
    return driftDatabase(
      name: 'centurion_staff',
      web: DriftWebOptions(
        sqlite3Wasm: Uri.parse('sqlite3.wasm'),
        driftWorker: Uri.parse('drift_worker.js'),
      ),
    );
  }
}
