// Reads: last good JSON from the cache, with pending outbox changes on top
// (see optimistic.dart). Fetches refresh the cache.
// Writes: always through the outbox with a ULID Idempotency-Key made once
// per change; the worker sends them in order.

import 'dart:convert';

import 'package:ulid/ulid.dart';

import '../../core/api/api_error.dart';
import '../../core/api/staff_api.dart';
import '../../core/json.dart';
import '../../core/outbox/outbox_dao.dart';
import '../../core/storage/cache_dao.dart';
import '../../core/storage/local_db.dart';
import '../../core/strings.dart';
import 'models.dart';
import 'optimistic.dart';

class StaffRepository {
  StaffRepository({required this.api, required this.cache, required this.outbox, required this.onEnqueued});

  final StaffApi api;
  final CacheDao cache;
  final OutboxDao outbox;

  /// Called after every enqueue (bump the view + kick the worker).
  final void Function() onEnqueued;

  Future<List<PendingChange>> _pending() async {
    final rows = await outbox.pending();
    return rows.map(changeOf).toList();
  }

  static PendingChange changeOf(OutboxEntry e) {
    Map<String, dynamic> body;
    try {
      body = jMap(jsonDecode(e.payload));
    } catch (_) {
      body = {};
    }
    return PendingChange(endpoint: e.endpoint, body: body, label: e.label, at: e.createdAt);
  }

  /// Cached JSON with pending changes applied, or null if never fetched.
  Future<Map<String, dynamic>?> read(String key) async {
    final raw = await cache.get(key);
    if (raw == null) return null;
    return applyChanges(key, raw, await _pending());
  }

  /// Fresh from the server (cached), with pending changes applied.
  Future<Map<String, dynamic>> fetch(String key) async {
    final body = await api.get(key);
    await cache.put(key, body);
    return applyChanges(key, body, await _pending());
  }

  /// A change reached the server: fold it into the cached JSON so the view
  /// does not flicker back before the next refetch.
  Future<void> bake(OutboxEntry e) async {
    final change = changeOf(e);
    for (final key in keysTouchedBy(e.endpoint)) {
      final raw = await cache.get(key);
      if (raw == null) continue;
      await cache.put(key, applyChanges(key, raw, [change]));
    }
  }

  Future<void> _enqueue(String endpoint, Map<String, dynamic> body, String label) async {
    await outbox.enqueue(
      idempotencyKey: Ulid().toString(),
      httpMethod: 'POST',
      endpoint: endpoint,
      payload: jsonEncode(body),
      label: label,
    );
    onEnqueued();
  }

  // ------------------------------------------------------------ writes

  Future<void> startRoom(Room r) => _enqueue('staff/rooms/${r.inspectionId}/start', {}, S.roomTitle(r.number));

  Future<void> tickItem(Room r, int itemId, bool checked) =>
      _enqueue('staff/rooms/${r.inspectionId}/items/$itemId', {'checked': checked}, S.roomTitle(r.number));

  Future<void> finishRoom(Room r, String? note) {
    final n = note?.trim() ?? '';
    return _enqueue('staff/rooms/${r.inspectionId}/finish', {if (n.isNotEmpty) 'note': n}, S.roomTitle(r.number));
  }

  Future<void> markReady(Check c) => _enqueue('staff/checks/${c.inspectionId}/ready', {}, S.roomTitle(c.number));

  Future<void> sendBack(Check c, Iterable<String> reasons, String? note) => _enqueue(
        'staff/checks/${c.inspectionId}/send-back',
        buildSendBackPayload(reasons, note),
        S.roomTitle(c.number),
      );

  Future<void> askSupplies(List<(Product product, int qty)> lines) {
    final picked = lines.where((l) => l.$2 > 0).toList();
    return _enqueue(
      'staff/supplies',
      {
        'lines': [
          for (final l in picked) {'product_id': l.$1.id, 'qty': l.$2},
        ],
      },
      supplySummary([for (final l in picked) (l.$1.name, l.$2)]),
    );
  }

  /// Best effort: the device is signed out locally either way.
  Future<void> logout() async {
    try {
      await api.send('POST', 'logout', {}, idempotencyKey: Ulid().toString());
    } on ApiError {
      // Offline or token already gone. Nothing else to do.
    }
  }
}

/// What to tell the user when the server refused a queued change.
String dropMessage(OutboxEntry e, ApiError err) {
  final label = e.label.isEmpty ? 'That room' : e.label;
  if (e.endpoint == 'staff/supplies') {
    return err.detail?.isNotEmpty == true ? '${S.suppliesNotSent} ${err.detail}' : S.suppliesNotSent;
  }
  if (err.isWrongState) {
    if (e.endpoint.endsWith('/finish')) return S.alreadyWithPorter(label);
    if (e.endpoint.startsWith('staff/checks/')) return S.alreadyChecked(label);
    return S.movedOn(label);
  }
  return S.notSaved(label, err.message);
}
