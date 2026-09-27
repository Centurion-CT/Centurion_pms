// Persists the paired session: the base URL of the tenant, the Sanctum
// bearer token, and the operator + tenant snapshot the /me endpoint
// returned. Backed by flutter_secure_storage (Keychain on iOS, Keystore
// on Android) — the token never touches sqlite or shared prefs.

import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class Session {
  const Session({
    required this.baseUrl,
    required this.token,
    required this.operator,
    required this.tenant,
  });

  final String baseUrl;
  final String token;
  final Map<String, dynamic> operator;
  final Map<String, dynamic> tenant;

  Map<String, dynamic> toJson() => {
        'base_url': baseUrl,
        'token': token,
        'operator': operator,
        'tenant': tenant,
      };

  static Session fromJson(Map<String, dynamic> j) => Session(
        baseUrl: j['base_url'] as String,
        token: j['token'] as String,
        operator: (j['operator'] as Map).cast<String, dynamic>(),
        tenant: (j['tenant'] as Map).cast<String, dynamic>(),
      );

  String? get role => operator['role'] as String?;
  String get operatorName => (operator['name'] as String?) ?? '';
  String get tenantName => (tenant['name'] as String?) ?? '';
}

class SessionStore {
  SessionStore(this._storage);

  static const _key = 'kv-session-v1';
  final FlutterSecureStorage _storage;

  Future<Session?> read() async {
    final raw = await _storage.read(key: _key);
    if (raw == null) return null;
    try {
      return Session.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      // Corrupt payload — nuke it so the app boots into pair flow.
      await _storage.delete(key: _key);
      return null;
    }
  }

  Future<void> write(Session s) async {
    await _storage.write(key: _key, value: jsonEncode(s.toJson()));
  }

  Future<void> clear() async {
    await _storage.delete(key: _key);
  }
}

final sessionStoreProvider = Provider<SessionStore>((ref) {
  return SessionStore(const FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  ));
});

// StateNotifier for the in-memory copy — GoRouter redirect reads this
// synchronously to decide pair-screen vs home-screen on cold-start.
final sessionProvider = StateNotifierProvider<SessionNotifier, Session?>((ref) {
  return SessionNotifier(ref.watch(sessionStoreProvider));
});

class SessionNotifier extends StateNotifier<Session?> {
  SessionNotifier(this._store) : super(null) {
    _hydrate();
  }

  final SessionStore _store;

  Future<void> _hydrate() async {
    state = await _store.read();
  }

  Future<void> setSession(Session s) async {
    await _store.write(s);
    state = s;
  }

  Future<void> clear() async {
    await _store.clear();
    state = null;
  }
}
