// Local SQLite via Drift.
//
// Three roles:
//   1. Outbox — pending mutations queued while offline. Worker
//      drains when connectivity returns. Live.
//   2. Read cache for the inspections queue — one row per queued
//      inspection, upserted each network fetch, watched by
//      Riverpod so the UI reconciles automatically.
//   3. Read cache for full inspection details — one row per open
//      inspection the operator has looked at. Same shape as (2),
//      keyed by inspection id.
//
// Payloads are stored as opaque JSON (`payload` column) so schema
// evolutions on the server don't force a client migration — the
// Dart model parses at read time. If the server changes shape
// incompatibly, the old cache row simply fails to parse and the
// stream returns nothing until the next network refresh overwrites.

import 'package:drift/drift.dart';
import 'package:drift_flutter/drift_flutter.dart';

part 'local_db.g.dart';

class OutboxEntries extends Table {
  IntColumn get id => integer().autoIncrement()();

  TextColumn get idempotencyKey => text().withLength(min: 8, max: 128)();
  TextColumn get httpMethod => text().withDefault(const Constant('POST'))();
  TextColumn get endpoint => text()();
  TextColumn get payload => text()();
  TextColumn get hint => text().nullable()();

  IntColumn get retryCount => integer().withDefault(const Constant(0))();
  DateTimeColumn get lastAttemptedAt => dateTime().nullable()();
  DateTimeColumn get createdAt => dateTime().withDefault(currentDateAndTime)();

  BoolColumn get parked => boolean().withDefault(const Constant(false))();
  TextColumn get lastError => text().nullable()();
}

class CachedQueueRows extends Table {
  // The (filter, id) pair is the natural key. Duplicating id across
  // filters lets a manager on filter=open see every row without
  // stealing the housekeeper's filter=queue rows.
  IntColumn get inspectionId => integer()();
  TextColumn get filter => text()();
  TextColumn get payload => text()();
  DateTimeColumn get fetchedAt => dateTime().withDefault(currentDateAndTime)();

  @override
  Set<Column<Object>> get primaryKey => {inspectionId, filter};
}

class CachedInspectionDetails extends Table {
  IntColumn get inspectionId => integer()();
  TextColumn get payload => text()();
  DateTimeColumn get fetchedAt => dateTime().withDefault(currentDateAndTime)();

  @override
  Set<Column<Object>> get primaryKey => {inspectionId};
}

class CachedReservationRows extends Table {
  // (filter, booking_ref) — bookingRef is the natural key (stable
  // across renames, human-typeable). Storing multiple filters lets
  // the arrivals + in_house + departures tabs coexist in one table.
  TextColumn get bookingRef => text().withLength(min: 3, max: 32)();
  TextColumn get filter => text()();
  TextColumn get payload => text()();
  DateTimeColumn get fetchedAt => dateTime().withDefault(currentDateAndTime)();

  @override
  Set<Column<Object>> get primaryKey => {bookingRef, filter};
}

class CachedReservationDetails extends Table {
  TextColumn get bookingRef => text().withLength(min: 3, max: 32)();
  TextColumn get payload => text()();
  DateTimeColumn get fetchedAt => dateTime().withDefault(currentDateAndTime)();

  @override
  Set<Column<Object>> get primaryKey => {bookingRef};
}

class CachedOutlets extends Table {
  IntColumn get outletId => integer()();
  TextColumn get payload => text()();
  DateTimeColumn get fetchedAt => dateTime().withDefault(currentDateAndTime)();

  @override
  Set<Column<Object>> get primaryKey => {outletId};
}

class CachedProducts extends Table {
  // (outlet_id, product_id) — one row per outlet-product pair so
  // the same product visible in two outlets caches independently.
  IntColumn get outletId => integer()();
  IntColumn get productId => integer()();
  TextColumn get payload => text()();
  DateTimeColumn get fetchedAt => dateTime().withDefault(currentDateAndTime)();

  @override
  Set<Column<Object>> get primaryKey => {outletId, productId};
}

class CachedManagerGlance extends Table {
  // One row per tenant. Kept as a JSON blob so schema evolutions on
  // the server don't force a client migration.
  IntColumn get id => integer().withDefault(const Constant(1))();
  TextColumn get payload => text()();
  DateTimeColumn get fetchedAt => dateTime().withDefault(currentDateAndTime)();

  @override
  Set<Column<Object>> get primaryKey => {id};
}

@DriftDatabase(tables: [
  OutboxEntries,
  CachedQueueRows,
  CachedInspectionDetails,
  CachedReservationRows,
  CachedReservationDetails,
  CachedOutlets,
  CachedProducts,
  CachedManagerGlance,
])
class LocalDb extends _$LocalDb {
  LocalDb() : super(_open());

  @override
  int get schemaVersion => 6;

  @override
  MigrationStrategy get migration => MigrationStrategy(
        onCreate: (m) => m.createAll(),
        onUpgrade: (m, from, to) async {
          // Scaffold — no real user data yet. Drop + recreate is
          // simpler than an ALTER TABLE dance. Replace with proper
          // migrations once the app has shipped to real users.
          for (final table in allTables) {
            await m.deleteTable(table.actualTableName);
          }
          await m.createAll();
        },
      );

  /// Called on sign-out — nuke cache so the next operator on the
  /// same device doesn't see stale rows from the previous session.
  /// Outbox is NOT cleared because in-flight writes still belong to
  /// the signed-out operator (the token is bound to them server-side).
  Future<void> clearCache() async {
    await transaction(() async {
      await delete(cachedQueueRows).go();
      await delete(cachedInspectionDetails).go();
      await delete(cachedReservationRows).go();
      await delete(cachedReservationDetails).go();
      await delete(cachedOutlets).go();
      await delete(cachedProducts).go();
      await delete(cachedManagerGlance).go();
    });
  }

  static QueryExecutor _open() {
    return driftDatabase(
      name: 'keverna',
      // Web build only — mobile targets ignore this parameter.
      // sqlite3.wasm + drift_worker.js are served from web/ (see
      // README bootstrap notes). WASM runs the sqlite engine
      // in-browser; the worker isolates it off the UI thread.
      web: DriftWebOptions(
        sqlite3Wasm: Uri.parse('sqlite3.wasm'),
        driftWorker: Uri.parse('drift_worker.js'),
      ),
    );
  }
}
