// Replays the outbox in tap order, one entry at a time.
//
// Triggers: drain() right after every enqueue, connectivity coming back,
// and a 20s safety timer.
//
// Per entry:
//   2xx                      -> delete, onSent (the change is now on the server)
//   no response / 5xx / 408 / 429 / 401
//                            -> keep it, back off, stop this pass (later
//                               entries wait so order is kept)
//   any other 4xx (409 wrong_state, 404, 422, 403)
//                            -> drop it, onDropped (the screen tells the user)

import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';

import '../api/api_error.dart';
import '../api/staff_api.dart';
import '../json.dart';
import '../storage/local_db.dart';
import 'outbox_dao.dart';

class OutboxWorker {
  OutboxWorker({
    required this.dao,
    required this.api,
    this.onSent,
    this.onDropped,
    this.onSettled,
  });

  final OutboxDao dao;
  final StaffApi api;
  final void Function(OutboxEntry entry)? onSent;
  final void Function(OutboxEntry entry, ApiError error)? onDropped;

  /// After a pass that changed something. [empty] is true when nothing is
  /// left to send (a good moment to refetch from the server).
  final void Function(bool empty)? onSettled;

  Timer? _tick;
  StreamSubscription<List<ConnectivityResult>>? _connSub;
  bool _draining = false;
  bool _again = false;
  bool _stopped = false;

  void start({bool listenConnectivity = true}) {
    _stopped = false;
    _tick ??= Timer.periodic(const Duration(seconds: 20), (_) => drain());
    if (listenConnectivity) {
      _connSub ??= Connectivity().onConnectivityChanged.listen((results) {
        if (results.any((r) => r != ConnectivityResult.none)) drain(force: true);
      });
    }
    drain(force: true);
  }

  void stop() {
    _stopped = true;
    _tick?.cancel();
    _tick = null;
    _connSub?.cancel();
    _connSub = null;
  }

  /// 5s, 10s, 20s, 40s, 80s, then every 2 min.
  static Duration backoffFor(int retryCount) {
    final s = (5 * (1 << retryCount.clamp(0, 6))).clamp(5, 120);
    return Duration(seconds: s);
  }

  Future<void> drain({bool force = false}) async {
    if (_stopped) return;
    if (_draining) {
      _again = true;
      return;
    }
    _draining = true;
    try {
      do {
        _again = false;
        await _pass(force: force);
        force = true; // A re-run was asked for by a fresh enqueue.
      } while (_again && !_stopped);
    } catch (e) {
      if (kDebugMode) debugPrint('[outbox] drain crashed: $e');
    } finally {
      _draining = false;
    }
  }

  Future<void> _pass({required bool force}) async {
    final entries = await dao.pending();
    if (entries.isEmpty) return;
    var changed = false;
    var blocked = false;
    for (final entry in entries) {
      if (_stopped) return;
      final last = entry.lastAttemptedAt;
      if (!force && last != null && DateTime.now().difference(last) < backoffFor(entry.retryCount)) {
        blocked = true;
        break;
      }
      try {
        await api.send(
          entry.httpMethod,
          entry.endpoint,
          jMap(jsonDecode(entry.payload)),
          idempotencyKey: entry.idempotencyKey,
        );
        await dao.delete(entry.id);
        changed = true;
        onSent?.call(entry);
      } on ApiError catch (err) {
        if (err.isRetryable || err.isUnauthorized) {
          await dao.bumpRetry(entry, err.code);
          blocked = true;
          break;
        }
        await dao.delete(entry.id);
        changed = true;
        onDropped?.call(entry, err);
      } catch (err) {
        await dao.bumpRetry(entry, '$err');
        blocked = true;
        break;
      }
    }
    if (changed) onSettled?.call(!blocked && (await dao.pending()).isEmpty);
  }
}
