// Drains the outbox — one entry at a time so server-side write order
// matches the operator's tap order. Not a real Dart isolate; a
// Timer + connectivity subscription is enough (nothing in here is
// CPU-bound and Dio does I/O off the event loop already).
//
// Trigger sources:
//   1. Explicit drain() call from the mutation service right after
//      enqueue — the common case of "user tapped submit, went
//      offline for 200ms, back on, immediately drain".
//   2. connectivity_plus events (wifi comes back, tethered off/on,
//      etc.) — recover from longer offline spells.
//   3. Timer.periodic every 30s — safety net for the exotic case
//      where connectivity events don't fire (some Android OEM ROMs
//      are stingy with them).
//
// Retry policy:
//   - Network / 5xx → bumpRetry, exponential backoff
//     (30s × 2^retryCount, capped at 30min).
//   - 4xx           → park. Server refused the write; retrying it
//                     won't help. Operator sees it in /outbox and
//                     decides to Retry (once they've fixed the
//                     underlying issue) or Discard.

import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../storage/local_db.dart';
import 'outbox_dao.dart';

class OutboxWorker {
  OutboxWorker({
    required this.dao,
    required this.dio,
    required this.onDrained,
  });

  final OutboxDao dao;
  final Dio dio;

  /// Called after each successful write so the caller can invalidate
  /// providers / refresh the UI to reflect fresh server state.
  final void Function(OutboxEntry entry) onDrained;

  Timer? _tick;
  StreamSubscription<List<ConnectivityResult>>? _connSub;
  bool _draining = false;
  bool _stopped = false;

  void start() {
    _stopped = false;
    _tick ??= Timer.periodic(const Duration(seconds: 30), (_) => drain());
    _connSub ??= Connectivity().onConnectivityChanged.listen((results) {
      final online = results.any((r) => r != ConnectivityResult.none);
      if (online) drain();
    });
    // Kick immediately in case queued entries are waiting at start-up.
    drain();
  }

  void stop() {
    _stopped = true;
    _tick?.cancel();
    _tick = null;
    _connSub?.cancel();
    _connSub = null;
  }

  Future<void> drain() async {
    if (_stopped || _draining) return;
    _draining = true;
    try {
      final entries = await dao.pending();
      for (final entry in entries) {
        if (_stopped) break;
        if (!_readyForRetry(entry)) continue;
        await _tryOne(entry);
      }
    } catch (e) {
      if (kDebugMode) debugPrint('[outbox] drain crashed: $e');
    } finally {
      _draining = false;
    }
  }

  bool _readyForRetry(OutboxEntry e) {
    if (e.lastAttemptedAt == null) return true;
    return DateTime.now().difference(e.lastAttemptedAt!) >=
        backoffFor(e.retryCount);
  }

  /// Exponential backoff schedule for a given retry count.
  /// 30s, 60s, 2m, 4m, 8m, 16m, 30m (capped). Extracted as a pure
  /// function so it can be tested without spinning a real worker.
  static Duration backoffFor(int retryCount) {
    final delaySeconds = (30 * (1 << retryCount.clamp(0, 10))).clamp(30, 1800);
    return Duration(seconds: delaySeconds);
  }

  Future<void> _tryOne(OutboxEntry entry) async {
    try {
      final resp = await dio.request<dynamic>(
        entry.endpoint,
        data: jsonDecode(entry.payload),
        options: Options(
          method: entry.httpMethod,
          headers: {'Idempotency-Key': entry.idempotencyKey},
        ),
      );
      final status = resp.statusCode ?? 0;
      if (status >= 200 && status < 300) {
        await dao.delete(entry.id);
        onDrained(entry);
      } else {
        // Dio only throws on !validateStatus responses, so a 3xx
        // slipping through here is a config edge case — park it so
        // it doesn't loop.
        await dao.park(entry.id, 'Unexpected status $status');
      }
    } on DioException catch (err) {
      final status = err.response?.statusCode ?? 0;
      final message = _errorMessage(err);
      final isClientError = status >= 400 && status < 500;
      if (isClientError) {
        await dao.park(entry.id, message);
      } else {
        await dao.bumpRetry(entry.id, message);
      }
    } catch (err) {
      // Any other throw (JSON decode, disk write, etc.) — treat as
      // retryable. Park after enough attempts via retryCount cap.
      await dao.bumpRetry(entry.id, err.toString());
    }
  }

  String _errorMessage(DioException err) {
    final body = err.response?.data;
    if (body is Map) {
      if (body['detail'] is String) return body['detail'] as String;
      if (body['error'] is String) return body['error'] as String;
    }
    return err.message ?? 'Request failed';
  }
}
