// Read-through cache pattern.
//
//   Providers stream from the Drift cache — cold-start renders the
//   last-known payload instantly. On subscribe, a background refresh
//   fetches network + upserts (cache change re-fires the stream).
//   Refresh errors are the UI's responsibility — pull-to-refresh
//   awaits the future directly and surfaces failures as a snackbar
//   so the cached rows stay visible underneath.
//
//   Writes go through MutationService. On successful direct write
//   we upsert the fresh detail into the cache + kick a queue
//   refresh; on QueuedForRetry (offline enqueue) the outbox worker
//   will fire refresh via provider invalidation once it drains.

import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/outbox/mutation_service.dart';
import '../../core/outbox/outbox_providers.dart';
import '../../core/storage/inspections_cache_dao.dart';
import 'inspection_models.dart';

class InspectionsRepository {
  InspectionsRepository({
    required Dio dio,
    required MutationService mutations,
    required InspectionsCacheDao cache,
  })  : _dio = dio,
        _mutations = mutations,
        _cache = cache;

  final Dio _dio;
  final MutationService _mutations;
  final InspectionsCacheDao _cache;

  // ── Refresh (network → cache) ──

  Future<void> refreshQueue({String filter = 'queue'}) async {
    final resp = await _dio.get<Map<String, dynamic>>(
      'inspections',
      queryParameters: {'filter': filter},
    );
    final rows = (resp.data!['data'] as List)
        .map((e) => (e as Map).cast<String, dynamic>())
        .toList();
    await _cache.upsertQueue(filter, rows);
  }

  Future<void> refreshDetail(int id) async {
    final resp = await _dio.get<Map<String, dynamic>>('inspections/$id');
    final data = (resp.data!['data'] as Map).cast<String, dynamic>();
    await _cache.upsertDetail(id, data);
  }

  Future<DateTime?> queueLastFetched({String filter = 'queue'}) {
    return _cache.queueLastFetchedAt(filter);
  }

  // ── Writes ── (delegated to MutationService; refresh cache after direct success)

  Future<InspectionDetail> submit(
    int id, {
    required List<InspectionItem> items,
    String? summaryNote,
    required String roomLabel,
  }) async {
    final fresh = await _mutations.perform<InspectionDetail>(
      endpoint: 'inspections/$id/submit',
      body: {
        'items': items
            .map((i) => {
                  'id': i.id,
                  'checked': i.checked,
                  if (i.damageNote != null && i.damageNote!.isNotEmpty) 'damage_note': i.damageNote,
                })
            .toList(),
        if (summaryNote != null) 'summary_note': summaryNote,
      },
      hint: 'Submit $roomLabel',
      parse: _parseInspectionEnvelope,
    );
    await _cache.upsertDetail(id, _detailToJson(fresh));
    unawaited(refreshQueue());
    return fresh;
  }

  Future<InspectionDetail> verify(
    int id, {
    required bool accept,
    String? summaryNote,
    required String roomLabel,
  }) async {
    final fresh = await _mutations.perform<InspectionDetail>(
      endpoint: 'inspections/$id/verify',
      body: {'accept': accept, if (summaryNote != null) 'summary_note': summaryNote},
      hint: '${accept ? 'Verify' : 'Reject'} $roomLabel',
      parse: _parseInspectionEnvelope,
    );
    await _cache.upsertDetail(id, _detailToJson(fresh));
    unawaited(refreshQueue());
    return fresh;
  }

  Future<InspectionDetail> signOff(
    int id, {
    required bool accept,
    String? summaryNote,
    required String roomLabel,
  }) async {
    final fresh = await _mutations.perform<InspectionDetail>(
      endpoint: 'inspections/$id/sign-off',
      body: {'accept': accept, if (summaryNote != null) 'summary_note': summaryNote},
      hint: '${accept ? 'Sign off' : 'Reject'} $roomLabel',
      parse: _parseInspectionEnvelope,
    );
    await _cache.upsertDetail(id, _detailToJson(fresh));
    unawaited(refreshQueue());
    return fresh;
  }

  InspectionDetail _parseInspectionEnvelope(Response<dynamic> resp) {
    final data = resp.data as Map<String, dynamic>;
    final fresh = (data['inspection']['data'] as Map).cast<String, dynamic>();
    return InspectionDetail.fromJson(fresh);
  }

  /// Serialise the parsed InspectionDetail back into the server's
  /// shape so the cache is parse-symmetric with fresh network payloads.
  Map<String, dynamic> _detailToJson(InspectionDetail d) {
    return {
      'id': d.id,
      'status': d.status,
      'status_label': d.statusLabel,
      'waiting_on': '',
      'readiness_score': d.readinessScore,
      'summary_note': d.summaryNote,
      'room': {
        'id': null,
        'room_number': d.roomNumber,
        'category_name': d.categoryName,
      },
      'items': d.items
          .map((i) => {
                'id': i.id,
                'label': i.label,
                'checked': i.checked,
                'damage_note': i.damageNote,
                'sort_order': i.sortOrder,
              })
          .toList(),
    };
  }
}

// ── Providers ──

final inspectionsCacheDaoProvider = Provider<InspectionsCacheDao>((ref) {
  return InspectionsCacheDao(ref.watch(localDbProvider));
});

final inspectionsRepositoryProvider = Provider<InspectionsRepository?>((ref) {
  final client = ref.watch(apiClientProvider);
  final mutations = ref.watch(mutationServiceProvider);
  final cache = ref.watch(inspectionsCacheDaoProvider);
  if (client == null || mutations == null) return null;
  return InspectionsRepository(dio: client.raw, mutations: mutations, cache: cache);
});

/// Stream the cached queue. Kicks a background refresh on subscribe;
/// errors from the refresh don't affect the stream (cached rows stay
/// visible). Pull-to-refresh in the UI calls
/// repo.refreshQueue() directly if it wants error feedback.
final inspectionsQueueProvider =
    StreamProvider.autoDispose<List<InspectionSummary>>((ref) {
  final repo = ref.watch(inspectionsRepositoryProvider);
  final cache = ref.watch(inspectionsCacheDaoProvider);
  if (repo == null) return Stream.value(const []);

  Future.microtask(() async {
    try {
      await repo.refreshQueue();
    } catch (_) {
      // Silent — cache remains visible. UI's pull-to-refresh handles
      // explicit retry + error surfacing.
    }
  });

  return cache.watchQueue('queue');
});

/// Stream the cached detail. Same pattern as the queue.
final inspectionDetailProvider =
    StreamProvider.autoDispose.family<InspectionDetail?, int>((ref, id) {
  final repo = ref.watch(inspectionsRepositoryProvider);
  final cache = ref.watch(inspectionsCacheDaoProvider);
  if (repo == null) return Stream.value(null);

  Future.microtask(() async {
    try {
      await repo.refreshDetail(id);
    } catch (_) {}
  });

  return cache.watchDetail(id);
});
