// POST {host}/api/mobile/v1/staff/login with phone + PIN.
// 200 signs in, 300 asks which hotel, 401/403/429 come back as failures.

import 'package:device_info_plus/device_info_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../core/auth/session.dart';
import '../../core/config.dart';
import '../../core/json.dart';
import '../../core/strings.dart';
import '../../demo/demo_backend.dart';

@immutable
class Tenant {
  const Tenant({required this.id, required this.name});

  final int id;
  final String name;
}

@immutable
class LoginRequest {
  const LoginRequest({required this.host, required this.phone, required this.pin, this.tenantId});

  final String host;
  final String phone;
  final String pin;
  final int? tenantId;
}

sealed class LoginResult {
  const LoginResult();
}

class LoginSuccess extends LoginResult {
  const LoginSuccess(this.session);
  final Session session;
}

class LoginPickTenant extends LoginResult {
  const LoginPickTenant(this.tenants);
  final List<Tenant> tenants;
}

class LoginFailure extends LoginResult {
  const LoginFailure({required this.code, this.status, this.detail});

  final String code;
  final int? status;
  final String? detail;

  bool get isStaffOnly => code == 'staff_only';

  String get message {
    switch (code) {
      case 'invalid_credentials':
        return S.wrongPin;
      case 'too_many_attempts':
        return (detail?.isNotEmpty ?? false) ? detail! : S.tooManyAttempts;
      case 'suspended':
        return (detail?.isNotEmpty ?? false) ? detail! : S.suspended;
      case 'network':
        return S.cantReach;
      default:
        if (status == 401) return S.wrongPin;
        return (detail?.isNotEmpty ?? false) ? detail! : S.somethingWrong;
    }
  }
}

abstract class LoginService {
  Future<LoginResult> login(LoginRequest req);
}

@immutable
class DeviceMeta {
  const DeviceMeta({required this.platform, required this.deviceName, required this.appVersion});

  final String platform;
  final String deviceName;
  final String appVersion;
}

Future<DeviceMeta> readDeviceMeta() async {
  var platform = 'android';
  var name = 'Phone';
  var version = '';
  try {
    final info = DeviceInfoPlugin();
    if (kIsWeb) {
      platform = 'web';
      name = (await info.webBrowserInfo).browserName.name;
    } else if (defaultTargetPlatform == TargetPlatform.iOS) {
      platform = 'ios';
      name = (await info.iosInfo).name;
    } else if (defaultTargetPlatform == TargetPlatform.android) {
      final a = await info.androidInfo;
      name = '${a.manufacturer} ${a.model}'.trim();
    }
  } catch (_) {}
  try {
    final p = await PackageInfo.fromPlatform();
    version = '${p.version}+${p.buildNumber}';
  } catch (_) {}
  return DeviceMeta(platform: platform, deviceName: name, appVersion: version);
}

class DioLoginService implements LoginService {
  DioLoginService({Dio? dio, Future<DeviceMeta> Function()? meta})
      : _dio = dio ?? Dio(),
        _meta = meta ?? readDeviceMeta;

  final Dio _dio;
  final Future<DeviceMeta> Function() _meta;

  @override
  Future<LoginResult> login(LoginRequest req) async {
    final meta = await _meta();
    final host = normalizeHost(req.host);
    Response<dynamic> resp;
    try {
      resp = await _dio.post<dynamic>(
        '$host/api/mobile/v1/staff/login',
        data: {
          'phone': req.phone,
          'pin': req.pin,
          if (req.tenantId != null) 'tenant_id': req.tenantId,
          'platform': meta.platform,
          'device_name': meta.deviceName,
          'app_version': meta.appVersion,
        },
        options: Options(
          headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
          validateStatus: (_) => true,
          followRedirects: false,
          sendTimeout: const Duration(seconds: 15),
          receiveTimeout: const Duration(seconds: 20),
        ),
      );
    } on DioException {
      return const LoginFailure(code: 'network');
    }
    return parseLoginResponse(resp.statusCode ?? 0, resp.data, fallbackBaseUrl: host);
  }
}

LoginResult parseLoginResponse(int status, Object? data, {required String fallbackBaseUrl}) {
  final body = jMap(data);
  if (status == 200 || status == 201) {
    final tenant = jMap(body['tenant']);
    final base = normalizeHost(jStr(body['base_url'], fallbackBaseUrl));
    return LoginSuccess(
      Session(
        baseUrl: base.isEmpty ? fallbackBaseUrl : base,
        token: jStr(body['token']),
        operator: Operator.fromJson(body['operator']),
        tenantId: jInt(tenant['id']),
        tenantName: jStr(tenant['name']),
      ),
    );
  }
  if (status == 300 || body['status'] == 'pick_tenant') {
    return LoginPickTenant(
      jList(body['tenants']).map((t) => Tenant(id: jInt(t['id']), name: jStr(t['name']))).toList(),
    );
  }
  return LoginFailure(
    code: jStr(body['error'], status == 401 ? 'invalid_credentials' : 'http_$status'),
    status: status,
    detail: jStrOrNull(body['detail']),
  );
}

final loginServiceProvider = Provider<LoginService>((ref) {
  if (kDemo) return const DemoLoginService();
  return DioLoginService();
});
