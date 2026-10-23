// One error type for every call. Server errors follow {error, detail}.

import 'package:dio/dio.dart';

import '../json.dart';
import '../strings.dart';

class ApiError implements Exception {
  const ApiError({required this.code, this.status, this.detail});

  /// Server `error` code, or 'network' when nothing came back.
  final String code;
  final int? status;
  final String? detail;

  bool get isNetwork => status == null;
  bool get isUnauthorized => status == 401;
  bool get isWrongState => status == 409 || code == 'wrong_state';

  /// Worth retrying later (connection, server trouble, rate limit).
  bool get isRetryable => status == null || status! >= 500 || status == 408 || status == 429;

  String get message {
    if (isNetwork) return S.cantReach;
    return (detail != null && detail!.isNotEmpty) ? detail! : S.somethingWrong;
  }

  factory ApiError.from(Object err) {
    if (err is ApiError) return err;
    if (err is DioException) {
      final r = err.response;
      if (r == null) return const ApiError(code: 'network');
      final body = jMap(r.data);
      return ApiError(
        code: jStr(body['error'], 'http_${r.statusCode}'),
        status: r.statusCode,
        detail: jStrOrNull(body['detail']),
      );
    }
    return ApiError(code: 'unknown', status: 0, detail: '$err');
  }

  @override
  String toString() => 'ApiError($status $code: $detail)';
}
