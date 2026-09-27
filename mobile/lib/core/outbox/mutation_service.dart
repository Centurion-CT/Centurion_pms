// Direct-first with outbox fallback.
//
// For every mutation:
//   1. Generate the Idempotency-Key upfront (ULID). Same key persists
//      to the outbox if we fall back — so a retry from the worker
//      hits the SAME cached response on the server (see
//      MobileIdempotency middleware, 24h cache).
//   2. Try the write directly through Dio.
//   3. Success → parse + return.
//   4. Client error (4xx) → bubble as DioException. Business-rule
//      violations must surface immediately — the operator can't act
//      on them from an outbox review screen later.
//   5. Network error / 5xx → enqueue into outbox with the same key,
//      kick the worker (which is a no-op if it's already draining),
//      throw QueuedForRetry. Callers treat this as a soft success:
//      the UI should say "Saved locally — will sync when back online."

import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:ulid/ulid.dart';

import 'outbox_dao.dart';
import 'outbox_worker.dart';

class MutationService {
  MutationService({
    required Dio dio,
    required OutboxDao dao,
    required OutboxWorker worker,
  })  : _dio = dio,
        _dao = dao,
        _worker = worker;

  final Dio _dio;
  final OutboxDao _dao;
  final OutboxWorker _worker;

  Future<T> perform<T>({
    required String endpoint,
    String httpMethod = 'POST',
    required Map<String, dynamic> body,
    required String hint,
    required T Function(Response<dynamic>) parse,
  }) async {
    final key = Ulid().toString();
    try {
      final resp = await _dio.request<dynamic>(
        endpoint,
        data: body,
        options: Options(
          method: httpMethod,
          headers: {'Idempotency-Key': key},
        ),
      );
      return parse(resp);
    } on DioException catch (err) {
      if (_isRetryable(err)) {
        await _dao.enqueue(
          idempotencyKey: key,
          httpMethod: httpMethod,
          endpoint: endpoint,
          payload: jsonEncode(body),
          hint: hint,
        );
        // Fire-and-forget — worker's own re-entry guard keeps this cheap.
        unawaited(_worker.drain());
        throw QueuedForRetry(hint: hint);
      }
      rethrow;
    }
  }

  bool _isRetryable(DioException err) {
    // No response body at all → connection / timeout / dns failure.
    if (err.response == null) return true;
    final status = err.response!.statusCode ?? 0;
    return status >= 500 && status < 600;
  }
}

/// Thrown by MutationService when the write couldn't be delivered but
/// has been queued to the outbox. Callers treat this as a soft
/// success: state on-device is updated optimistically, worker will
/// deliver + invalidate providers when connectivity returns.
class QueuedForRetry implements Exception {
  const QueuedForRetry({required this.hint});

  final String hint;

  @override
  String toString() => 'Queued for retry: $hint';
}

/// Sugar so callers don't have to import dart:async just to fire-and-forget.
void unawaited(Future<void> _) {}
