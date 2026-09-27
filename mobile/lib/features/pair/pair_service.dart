// Trades a MobilePairingCode for a Sanctum token by calling the
// anonymous POST /api/mobile/v1/pair endpoint. Called from the QR
// screen after decoding a scanned QR (payload = {base, code}) OR
// after manual code entry.

import 'dart:convert';

import 'package:device_info_plus/device_info_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session_store.dart';

class PairService {
  PairService(this._sessionNotifier);

  final SessionNotifier _sessionNotifier;

  /// [baseUrl] must be the tenant subdomain, e.g. https://chidi.keverna.com.
  /// [code] is the 8-char pair code from the QR / manual entry.
  Future<Session> pair({required String baseUrl, required String code}) async {
    final dio = buildDio(baseUrl);
    final fingerprint = await _deviceFingerprint();
    final Response<dynamic> resp;
    try {
      resp = await dio.post<Map<String, dynamic>>('pair', data: {
        'code': code,
        ...fingerprint,
      });
    } on DioException catch (e) {
      throw PairFailure.fromResponse(e.response);
    }

    final body = resp.data as Map<String, dynamic>;
    final session = Session(
      baseUrl: baseUrl,
      token: body['token'] as String,
      operator: (body['operator']['data'] as Map).cast<String, dynamic>(),
      tenant: (body['tenant']['data'] as Map).cast<String, dynamic>(),
    );
    await _sessionNotifier.setSession(session);
    return session;
  }

  Future<Map<String, dynamic>> _deviceFingerprint() async {
    final plugin = DeviceInfoPlugin();
    final pkg = await PackageInfo.fromPlatform();
    if (defaultTargetPlatform == TargetPlatform.iOS) {
      final ios = await plugin.iosInfo;
      return {
        'platform': 'ios',
        'device_name': '${ios.name} · ${ios.model}',
        'app_version': '${pkg.version}+${pkg.buildNumber}',
      };
    }
    final android = await plugin.androidInfo;
    return {
      'platform': 'android',
      'device_name': '${android.brand} ${android.model}',
      'app_version': '${pkg.version}+${pkg.buildNumber}',
    };
  }
}

class PairFailure implements Exception {
  const PairFailure(this.reason, this.message);

  final String reason;
  final String message;

  factory PairFailure.fromResponse(Response<dynamic>? r) {
    if (r == null) {
      return const PairFailure('network', 'Network error — check your connection and try again.');
    }
    final body = r.data;
    if (body is Map && body['error'] is String) {
      return PairFailure(body['error'] as String, _humanize(body['error'] as String));
    }
    return PairFailure('unknown', 'Pairing failed (${r.statusCode}).');
  }

  static String _humanize(String code) {
    return switch (code) {
      'code_invalid' => 'That pairing code isn\'t recognised.',
      'code_expired' => 'That code has expired. Generate a fresh one.',
      'code_already_consumed' => 'That code was already used — generate a new one.',
      'no_tenant' => 'Couldn\'t reach your property. Check the URL.',
      _ => 'Pairing failed. Try again.',
    };
  }

  @override
  String toString() => message;
}

/// QR payload = {"v":1,"base":"https://…","code":"XXXXXXXX"}.
/// Returns null if the string isn't a valid Keverna pair payload.
({String base, String code})? parsePairPayload(String raw) {
  try {
    final j = jsonDecode(raw);
    if (j is! Map) return null;
    final base = j['base'];
    final code = j['code'];
    if (base is! String || code is! String) return null;
    if (!base.startsWith('http')) return null;
    if (code.length != 8) return null;
    return (base: base, code: code);
  } catch (_) {
    return null;
  }
}

final pairServiceProvider = Provider<PairService>((ref) {
  return PairService(ref.watch(sessionProvider.notifier));
});
