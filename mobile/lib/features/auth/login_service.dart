// Email + password sign-in. Hits the central-domain endpoint
// (POST https://keverna.com/api/mobile/v1/login), which returns
// either:
//   - {token, operator, tenant, device}       → sign-in complete
//   - {status: "pick_tenant", tenants: [...]} → operator belongs to
//                                                more than one tenant;
//                                                UI shows a picker,
//                                                second call carries
//                                                tenant_id to complete.
//
// The central domain is compiled in via --dart-define so QA can
// point staging builds at a staging environment without a rebuild:
//   flutter run --dart-define=KEVERNA_CENTRAL=keverna.test
// Defaults to keverna.com for release builds.

import 'dart:convert';

import 'package:device_info_plus/device_info_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../core/auth/session_store.dart';

/// The central domain the app hits for /login. Compile-time constant
/// so QA can point a staging build at a staging env without a code
/// change: `--dart-define=KEVERNA_CENTRAL=staging.keverna.test`.
const String _centralDomain =
    String.fromEnvironment('KEVERNA_CENTRAL', defaultValue: 'keverna.com');

class LoginTenantOption {
  const LoginTenantOption({
    required this.id,
    required this.slug,
    required this.name,
    this.role,
    this.lastLoginAt,
  });

  final int id;
  final String slug;
  final String name;
  final String? role;
  final DateTime? lastLoginAt;

  factory LoginTenantOption.fromJson(Map<String, dynamic> j) {
    return LoginTenantOption(
      id: j['id'] as int,
      slug: (j['slug'] as String?) ?? '',
      name: (j['name'] as String?) ?? '',
      role: j['role'] as String?,
      lastLoginAt:
          j['last_login_at'] != null ? DateTime.tryParse(j['last_login_at'] as String) : null,
    );
  }
}

/// Either sign-in completed (session stored) or the operator needs
/// to pick which tenant they meant. Sealed so the UI can pattern-
/// match without an "unknown" branch.
sealed class LoginOutcome {}

class LoginCompleted extends LoginOutcome {
  LoginCompleted(this.session);
  final Session session;
}

class LoginNeedsTenantPick extends LoginOutcome {
  LoginNeedsTenantPick({required this.tenants, required this.email, required this.password});
  final List<LoginTenantOption> tenants;
  final String email;
  final String password;
}

class LoginService {
  LoginService(this._sessionNotifier);

  final SessionNotifier _sessionNotifier;

  Future<LoginOutcome> signIn({
    required String email,
    required String password,
    int? tenantId,
  }) async {
    final fingerprint = await _deviceFingerprint();
    final dio = Dio(BaseOptions(
      // Trailing slash matters — without it Dio concatenates the
      // sub-path directly (baseUrl+'login' → '.../v1login'), which
      // 404s server-side. Same fix in api_client.dart.
      baseUrl: 'https://$_centralDomain/api/mobile/v1/',
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 15),
      headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
      // 300 Multiple Choices is a legitimate response for us — don't
      // let Dio throw on it.
      validateStatus: (status) => status != null && status < 500,
    ));

    Response<dynamic> resp;
    try {
      resp = await dio.post<dynamic>('login', data: {
        'email': email,
        'password': password,
        if (tenantId != null) 'tenant_id': tenantId,
        ...fingerprint,
      });
    } on DioException catch (e) {
      throw LoginFailure._fromResponse(e.response);
    }

    if (resp.statusCode == 300) {
      final body = resp.data as Map<String, dynamic>;
      final tenants = (body['tenants'] as List)
          .map((e) => LoginTenantOption.fromJson((e as Map).cast<String, dynamic>()))
          .toList();
      return LoginNeedsTenantPick(
        tenants: tenants,
        email: email,
        password: password,
      );
    }

    if (resp.statusCode == 200) {
      final body = resp.data as Map<String, dynamic>;
      // Session store expects the tenant subdomain to build subsequent
      // request base URLs. Reconstruct it from the returned tenant
      // slug + the central domain we called.
      final tenantSlug = ((body['tenant'] as Map)['slug'] as String);
      final session = Session(
        baseUrl: 'https://$tenantSlug.$_centralDomain',
        token: body['token'] as String,
        operator: (body['operator'] as Map).cast<String, dynamic>(),
        tenant: (body['tenant'] as Map).cast<String, dynamic>(),
      );
      await _sessionNotifier.setSession(session);
      return LoginCompleted(session);
    }

    // Anything else — surface as a failure with the server's error code.
    throw LoginFailure._fromResponse(resp);
  }

  Future<Map<String, dynamic>> _deviceFingerprint() async {
    final plugin = DeviceInfoPlugin();
    final pkg = await PackageInfo.fromPlatform();
    if (kIsWeb) {
      final web = await plugin.webBrowserInfo;
      return {
        'platform': 'web',
        'device_name': '${web.browserName.name} on ${web.platform ?? 'web'}',
        'app_version': '${pkg.version}+${pkg.buildNumber}',
      };
    }
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

class LoginFailure implements Exception {
  const LoginFailure(this.reason, this.message);

  final String reason;
  final String message;

  factory LoginFailure._fromResponse(Response<dynamic>? r) {
    if (r == null) {
      return const LoginFailure('network', 'Network error — check your connection and try again.');
    }
    final body = r.data;
    if (body is Map && body['error'] is String) {
      return LoginFailure(body['error'] as String, _humanize(body['error'] as String));
    }
    // Attempt to parse if the body is a raw string (rare).
    if (body is String) {
      try {
        final parsed = jsonDecode(body);
        if (parsed is Map && parsed['error'] is String) {
          return LoginFailure(parsed['error'] as String, _humanize(parsed['error'] as String));
        }
      } catch (_) {}
    }
    return LoginFailure('unknown', 'Sign-in failed (${r.statusCode}).');
  }

  static String _humanize(String code) {
    return switch (code) {
      'invalid_credentials' => 'Wrong email or password.',
      'no_tenants' => 'This account has no property access. Ask a manager to invite you.',
      'tenant_not_accessible' => "That property isn't on your account.",
      'link_broken' => 'Account link is broken. Ask a manager to re-invite you.',
      'too_many_attempts' => 'Too many attempts. Wait a moment and try again.',
      _ => 'Sign-in failed. Try again.',
    };
  }

  @override
  String toString() => message;
}

final loginServiceProvider = Provider<LoginService>((ref) {
  return LoginService(ref.watch(sessionProvider.notifier));
});
