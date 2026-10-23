// The thin transport for the staff API. Paths are relative to
// `{base_url}/api/mobile/v1/` (e.g. 'staff/rooms', 'logout').
//
// Every non-GET carries an Idempotency-Key the caller generated once per
// mutation (the outbox keeps it across retries). A 401 from any call fires
// [onUnauthorized] so the app drops the session and returns to sign in.

import 'package:dio/dio.dart';

import '../json.dart';
import 'api_error.dart';

abstract class StaffApi {
  Future<Map<String, dynamic>> get(String path);

  Future<Map<String, dynamic>> send(
    String method,
    String path,
    Map<String, dynamic> body, {
    required String idempotencyKey,
  });
}

class DioStaffApi implements StaffApi {
  DioStaffApi({required String baseUrl, required String token, this.onUnauthorized, Dio? dio}) : _dio = dio ?? Dio() {
    _dio.options
      ..baseUrl = '$baseUrl/api/mobile/v1/'
      ..connectTimeout = const Duration(seconds: 10)
      ..receiveTimeout = const Duration(seconds: 20)
      ..headers.addAll({
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
      });
  }

  final Dio _dio;
  final void Function()? onUnauthorized;

  @override
  Future<Map<String, dynamic>> get(String path) => _run(() => _dio.get<dynamic>(path));

  @override
  Future<Map<String, dynamic>> send(
    String method,
    String path,
    Map<String, dynamic> body, {
    required String idempotencyKey,
  }) {
    return _run(
      () => _dio.request<dynamic>(
        path,
        data: body,
        options: Options(method: method, headers: {'Idempotency-Key': idempotencyKey}),
      ),
    );
  }

  Future<Map<String, dynamic>> _run(Future<Response<dynamic>> Function() call) async {
    try {
      final resp = await call();
      return jMap(resp.data);
    } catch (e) {
      final err = ApiError.from(e);
      if (err.isUnauthorized) onUnauthorized?.call();
      throw err;
    }
  }
}
