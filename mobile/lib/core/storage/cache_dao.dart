import 'dart:convert';

import '../json.dart';
import 'local_db.dart';

class CacheDao {
  CacheDao(this._db);

  final LocalDb _db;

  Future<void> put(String key, Map<String, dynamic> body, {DateTime? at}) {
    return _db.into(_db.cachedResponses).insertOnConflictUpdate(
          CachedResponsesCompanion.insert(cacheKey: key, payload: jsonEncode(body), fetchedAt: at ?? DateTime.now()),
        );
  }

  Future<Map<String, dynamic>?> get(String key) async {
    final row = await (_db.select(_db.cachedResponses)..where((t) => t.cacheKey.equals(key))).getSingleOrNull();
    if (row == null) return null;
    try {
      return jMap(jsonDecode(row.payload));
    } catch (_) {
      return null; // Bad row: the next refresh overwrites it.
    }
  }

  Future<List<String>> keys() async {
    final rows = await _db.select(_db.cachedResponses).get();
    return rows.map((r) => r.cacheKey).toList();
  }
}
