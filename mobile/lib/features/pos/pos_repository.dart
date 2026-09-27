// POS repository + providers. Reads stream from the cache; writes
// (createSale) go through MutationService so an offline sale queues
// with its client_key preserved across retries.

import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:ulid/ulid.dart';

import '../../core/api/api_client.dart';
import '../../core/outbox/mutation_service.dart';
import '../../core/outbox/outbox_providers.dart';
import '../../core/storage/local_db.dart';
import 'pos_cache_dao.dart';
import 'pos_models.dart';

class PosRepository {
  PosRepository({
    required Dio dio,
    required MutationService mutations,
    required PosCacheDao cache,
  })  : _dio = dio,
        _mutations = mutations,
        _cache = cache;

  final Dio _dio;
  final MutationService _mutations;
  final PosCacheDao _cache;

  // ── Refresh (network → cache) ──

  Future<void> refreshOutlets() async {
    final resp = await _dio.get<Map<String, dynamic>>('outlets');
    final rows = (resp.data!['data'] as List)
        .map((e) => (e as Map).cast<String, dynamic>())
        .toList();
    await _cache.upsertOutlets(rows);
  }

  Future<void> refreshProducts(int outletId) async {
    final resp = await _dio.get<Map<String, dynamic>>('outlets/$outletId/products');
    final rows = (resp.data!['data'] as List)
        .map((e) => (e as Map).cast<String, dynamic>())
        .toList();
    await _cache.upsertProducts(outletId, rows);
  }

  /// Barcode lookup — hits network directly. Not cached because a
  /// scan is a discrete moment: we want the freshest server truth,
  /// not a stale cached row that might be inactive.
  Future<Product?> findByBarcode(int outletId, String barcode) async {
    try {
      final resp = await _dio.get<Map<String, dynamic>>(
        'outlets/$outletId/products',
        queryParameters: {'barcode': barcode},
      );
      final rows = (resp.data!['data'] as List);
      if (rows.isEmpty) return null;
      return Product.fromJson((rows.first as Map).cast<String, dynamic>());
    } on DioException {
      // Fall back to cache lookup — offline barcode scan should still
      // work when the operator's already loaded the catalogue.
      final all = await _cache.watchProducts(outletId).first;
      try {
        return all.firstWhere((p) => p.barcode == barcode);
      } catch (_) {
        return null;
      }
    }
  }

  // ── Writes ──

  /// One-shot: cart + tenders → completed sale. Uses a ULID as the
  /// client_key so retries via the outbox worker (same key preserved
  /// on the outbox row) hit the controller's client_key dedup and
  /// return the same PosSale instead of double-recording the sale.
  Future<PosSaleReceipt> createSale({
    required int outletId,
    required List<CartLine> cart,
    required List<Tender> tenders,
    String? bookingRef,
    String? notes,
  }) async {
    final clientKey = Ulid().toString();
    return _mutations.perform<PosSaleReceipt>(
      endpoint: 'outlets/$outletId/sales',
      body: {
        'client_key': clientKey,
        if (bookingRef != null) 'booking_ref': bookingRef,
        if (notes != null) 'notes': notes,
        'cart': cart
            .map((l) => {'product_id': l.product.id, 'quantity': l.quantity})
            .toList(),
        'tenders': tenders.map((t) => t.toJson()).toList(),
      },
      hint: 'Sale of ${cart.length} ${cart.length == 1 ? 'item' : 'items'}',
      parse: (resp) {
        final data = resp.data as Map<String, dynamic>;
        return PosSaleReceipt.fromJson((data['sale'] as Map).cast<String, dynamic>());
      },
    );
  }
}

// ── Providers ──

final posCacheDaoProvider = Provider<PosCacheDao>((ref) {
  return PosCacheDao(ref.watch(localDbProvider));
});

final posRepositoryProvider = Provider<PosRepository?>((ref) {
  final client = ref.watch(apiClientProvider);
  final mutations = ref.watch(mutationServiceProvider);
  final cache = ref.watch(posCacheDaoProvider);
  if (client == null || mutations == null) return null;
  return PosRepository(dio: client.raw, mutations: mutations, cache: cache);
});

final outletsProvider = StreamProvider.autoDispose<List<Outlet>>((ref) {
  final repo = ref.watch(posRepositoryProvider);
  final cache = ref.watch(posCacheDaoProvider);
  if (repo == null) return Stream.value(const []);
  Future.microtask(() async {
    try {
      await repo.refreshOutlets();
    } catch (_) {}
  });
  return cache.watchOutlets();
});

final productsProvider =
    StreamProvider.autoDispose.family<List<Product>, int>((ref, outletId) {
  final repo = ref.watch(posRepositoryProvider);
  final cache = ref.watch(posCacheDaoProvider);
  if (repo == null) return Stream.value(const []);
  Future.microtask(() async {
    try {
      await repo.refreshProducts(outletId);
    } catch (_) {}
  });
  return cache.watchProducts(outletId);
});

/// Selected outlet lives in memory only — the operator picks it on
/// each session. If they leave and come back to the POS tab the
/// picker is presented again (fine for a tap or two).
final selectedOutletProvider = StateProvider<Outlet?>((ref) => null);

/// Cart state — one CartLine per product. Add/remove/change quantity
/// via CartNotifier methods. Cleared on successful sale.
final cartProvider = StateNotifierProvider<CartNotifier, List<CartLine>>((ref) {
  return CartNotifier();
});

class CartNotifier extends StateNotifier<List<CartLine>> {
  CartNotifier() : super(const []);

  void add(Product p) {
    final existing = state.indexWhere((l) => l.product.id == p.id);
    if (existing >= 0) {
      final line = state[existing];
      line.quantity++;
      state = [...state]; // notify listeners
    } else {
      state = [...state, CartLine(product: p)];
    }
  }

  void setQuantity(int productId, int quantity) {
    if (quantity <= 0) {
      remove(productId);
      return;
    }
    final idx = state.indexWhere((l) => l.product.id == productId);
    if (idx < 0) return;
    state[idx].quantity = quantity;
    state = [...state];
  }

  void remove(int productId) {
    state = state.where((l) => l.product.id != productId).toList();
  }

  void clear() {
    state = const [];
  }

  int get total => state.fold(0, (sum, l) => sum + l.lineTotal);
}
