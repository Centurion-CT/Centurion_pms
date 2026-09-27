// Read-through cache for the POS surface — outlets + per-outlet
// product catalogue. Sales aren't cached (each is one-off + the
// receipt returns from the create-sale POST directly).

import 'dart:convert';

import 'package:drift/drift.dart';

import '../../core/storage/local_db.dart';
import 'pos_models.dart';

class PosCacheDao {
  PosCacheDao(this._db);

  final LocalDb _db;

  Future<void> upsertOutlets(List<Map<String, dynamic>> payloads) async {
    await _db.transaction(() async {
      await _db.delete(_db.cachedOutlets).go();
      for (final p in payloads) {
        await _db.into(_db.cachedOutlets).insert(CachedOutletsCompanion.insert(
              outletId: Value(p['id'] as int),
              payload: jsonEncode(p),
            ));
      }
    });
  }

  Stream<List<Outlet>> watchOutlets() {
    return _db.select(_db.cachedOutlets).watch().map((rows) {
      return rows.map((r) {
        try {
          return Outlet.fromJson(jsonDecode(r.payload) as Map<String, dynamic>);
        } catch (_) {
          return null;
        }
      }).whereType<Outlet>().toList();
    });
  }

  Future<void> upsertProducts(int outletId, List<Map<String, dynamic>> payloads) async {
    await _db.transaction(() async {
      await (_db.delete(_db.cachedProducts)..where((t) => t.outletId.equals(outletId))).go();
      for (final p in payloads) {
        await _db.into(_db.cachedProducts).insert(CachedProductsCompanion.insert(
              outletId: outletId,
              productId: p['id'] as int,
              payload: jsonEncode(p),
            ));
      }
    });
  }

  Stream<List<Product>> watchProducts(int outletId) {
    final q = _db.select(_db.cachedProducts)..where((t) => t.outletId.equals(outletId));
    return q.watch().map((rows) {
      return rows.map((r) {
        try {
          return Product.fromJson(jsonDecode(r.payload) as Map<String, dynamic>);
        } catch (_) {
          return null;
        }
      }).whereType<Product>().toList();
    });
  }
}
