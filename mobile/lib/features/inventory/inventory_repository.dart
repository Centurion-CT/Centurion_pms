// Inventory repository + Riverpod providers. Store catalogue + my
// stock requests. Writes (createRequest) go through MutationService
// so a raised request queues if the phone is offline.

import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/outbox/mutation_service.dart';
import '../../core/outbox/outbox_providers.dart';
import '../pos/pos_models.dart' show Product;
import 'inventory_models.dart';

class InventoryRepository {
  InventoryRepository({required Dio dio, required MutationService mutations})
      : _dio = dio,
        _mutations = mutations;

  final Dio _dio;
  final MutationService _mutations;

  Future<List<Product>> storeProducts({String? q}) async {
    final resp = await _dio.get<Map<String, dynamic>>(
      'inventory/store-products',
      queryParameters: {if (q != null && q.isNotEmpty) 'q': q},
    );
    return (resp.data!['data'] as List)
        .map((e) => Product.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<List<StockRequestSummary>> requests({String filter = 'mine'}) async {
    final resp = await _dio.get<Map<String, dynamic>>(
      'stock-requests',
      queryParameters: {'filter': filter},
    );
    return (resp.data!['data'] as List)
        .map((e) => StockRequestSummary.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<StockRequestSummary> createRequest({
    required int outletId,
    required int productId,
    required int quantity,
    required String productLabel,
    String? note,
  }) {
    return _mutations.perform<StockRequestSummary>(
      endpoint: 'stock-requests',
      body: {
        'outlet_id': outletId,
        'product_id': productId,
        'quantity': quantity,
        if (note != null) 'note': note,
      },
      hint: 'Request $quantity × $productLabel',
      parse: _parseEnvelope,
    );
  }

  Future<StockRequestSummary> approveRequest({
    required int id,
    int? approvedQuantity,
    String? note,
    required String productLabel,
  }) {
    return _mutations.perform<StockRequestSummary>(
      endpoint: 'stock-requests/$id/approve',
      body: {
        if (approvedQuantity != null) 'approved_quantity': approvedQuantity,
        if (note != null) 'note': note,
      },
      hint: 'Approve request for $productLabel',
      parse: _parseEnvelope,
    );
  }

  Future<StockRequestSummary> rejectRequest({
    required int id,
    required String note,
    required String productLabel,
  }) {
    return _mutations.perform<StockRequestSummary>(
      endpoint: 'stock-requests/$id/reject',
      body: {'note': note},
      hint: 'Reject request for $productLabel',
      parse: _parseEnvelope,
    );
  }

  StockRequestSummary _parseEnvelope(Response<dynamic> resp) {
    final data = resp.data as Map<String, dynamic>;
    return StockRequestSummary.fromJson(
        (data['stock_request'] as Map).cast<String, dynamic>());
  }
}

// ── Providers ──

final inventoryRepositoryProvider = Provider<InventoryRepository?>((ref) {
  final client = ref.watch(apiClientProvider);
  final mutations = ref.watch(mutationServiceProvider);
  if (client == null || mutations == null) return null;
  return InventoryRepository(dio: client.raw, mutations: mutations);
});

/// Straight network fetch — the catalogue is small enough per
/// property that we don't need a Drift cache for it. Cached
/// implicitly via Riverpod's autoDispose+keepAlive-on-listen
/// semantics during the operator's session.
final storeProductsProvider =
    FutureProvider.autoDispose.family<List<Product>, String?>((ref, q) async {
  final repo = ref.watch(inventoryRepositoryProvider);
  if (repo == null) return const [];
  return repo.storeProducts(q: q);
});

final myStockRequestsProvider =
    FutureProvider.autoDispose<List<StockRequestSummary>>((ref) async {
  final repo = ref.watch(inventoryRepositoryProvider);
  if (repo == null) return const [];
  return repo.requests(filter: 'mine');
});

/// Pending review queue for managers. Non-managers get an empty
/// list back from the server (403 → catch → []), so UI can render
/// the same widget for both roles without special-casing here.
final pendingStockRequestsProvider =
    FutureProvider.autoDispose<List<StockRequestSummary>>((ref) async {
  final repo = ref.watch(inventoryRepositoryProvider);
  if (repo == null) return const [];
  return repo.requests(filter: 'pending');
});
