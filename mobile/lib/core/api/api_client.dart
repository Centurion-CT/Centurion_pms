// Dio configured for /api/mobile/v1. Two interceptors:
//   1. Auth interceptor — stamps Bearer <token> on every request.
//   2. Idempotency interceptor — for non-GET requests, stamps an
//      Idempotency-Key header if the caller didn't provide one. The
//      caller (usually the outbox worker) SHOULD pre-generate a ULID
//      before the first attempt so retries reuse the same key —
//      this fallback exists so ad-hoc mutations from the UI don't
//      accidentally omit the header.
//
// Base URL comes from the paired session. Requests fire before the
// session hydrates (rare — only if UI code hits the API pre-hydration)
// are rejected client-side.

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:ulid/ulid.dart';

import '../auth/session_store.dart';

class ApiClient {
  ApiClient({required Dio dio}) : _dio = dio;

  final Dio _dio;

  Dio get raw => _dio;
}

Dio buildDio(String baseUrl, {String? token}) {
  final dio = Dio(BaseOptions(
    // Trailing slash matters — without it Dio drops the separator
    // between base and sub-path (baseUrl+'me' → '.../v1me' → 404).
    baseUrl: '$baseUrl/api/mobile/v1/',
    connectTimeout: const Duration(seconds: 10),
    receiveTimeout: const Duration(seconds: 15),
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
  ));

  if (token != null) {
    dio.options.headers['Authorization'] = 'Bearer $token';
  }

  dio.interceptors.add(InterceptorsWrapper(
    onRequest: (options, handler) {
      final method = options.method.toUpperCase();
      final isMutation = method != 'GET' && method != 'HEAD' && method != 'OPTIONS';
      if (isMutation && !options.headers.containsKey('Idempotency-Key')) {
        options.headers['Idempotency-Key'] = Ulid().toString();
      }
      handler.next(options);
    },
  ));

  return dio;
}

final apiClientProvider = Provider<ApiClient?>((ref) {
  final session = ref.watch(sessionProvider);
  if (session == null) return null;
  return ApiClient(dio: buildDio(session.baseUrl, token: session.token));
});
