// The signed-in staff member. Persisted in secure storage (Keystore /
// Keychain) so the token never touches sqlite.

import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../json.dart';
import '../strings.dart';

enum StaffRole { housekeeper, porter, other }

StaffRole roleFrom(String? raw) {
  switch (raw) {
    case 'house_cleaner':
    case 'housekeeper':
      return StaffRole.housekeeper;
    case 'porter':
      return StaffRole.porter;
    default:
      return StaffRole.other;
  }
}

String roleLabel(StaffRole r) => r == StaffRole.porter ? S.porter : S.housekeeper;

@immutable
class Operator {
  const Operator({
    required this.id,
    required this.name,
    required this.firstName,
    required this.initials,
    required this.role,
    required this.phone,
    this.roleLabelText,
  });

  final int id;
  final String name;
  final String firstName;
  final String initials;
  final String role;
  final String phone;

  /// Server's own label for the role ("Housekeeper"), when it sends one.
  final String? roleLabelText;

  StaffRole get staffRole => roleFrom(role);
  String get displayRole => (roleLabelText?.isNotEmpty ?? false) ? roleLabelText! : roleLabel(staffRole);

  factory Operator.fromJson(Object? raw) {
    final j = jMap(raw);
    final name = jStr(j['name']);
    final first = jStr(j['first_name'], name.split(' ').first);
    return Operator(
      id: jInt(j['id']),
      name: name,
      firstName: first,
      initials: jStr(j['initials'], _initials(name)),
      role: jStr(j['role']),
      phone: jStr(j['phone']),
      roleLabelText: jStrOrNull(j['role_label']),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'first_name': firstName,
        'initials': initials,
        'role': role,
        'phone': phone,
        if (roleLabelText != null) 'role_label': roleLabelText,
      };

  static String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return '?';
    return parts.take(2).map((p) => p[0].toUpperCase()).join();
  }
}

@immutable
class Session {
  const Session({
    required this.baseUrl,
    required this.token,
    required this.operator,
    required this.tenantId,
    required this.tenantName,
  });

  final String baseUrl;
  final String token;
  final Operator operator;
  final int tenantId;
  final String tenantName;

  StaffRole get role => operator.staffRole;

  Map<String, dynamic> toJson() => {
        'base_url': baseUrl,
        'token': token,
        'operator': operator.toJson(),
        'tenant': {'id': tenantId, 'name': tenantName},
      };

  factory Session.fromJson(Map<String, dynamic> j) {
    final tenant = jMap(j['tenant']);
    return Session(
      baseUrl: jStr(j['base_url']),
      token: jStr(j['token']),
      operator: Operator.fromJson(j['operator']),
      tenantId: jInt(tenant['id']),
      tenantName: jStr(tenant['name']),
    );
  }
}

/// Key/value secure storage, swappable in tests.
abstract class SecureKv {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class FlutterSecureKv implements SecureKv {
  const FlutterSecureKv();

  static const _s = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  @override
  Future<String?> read(String key) => _s.read(key: key);
  @override
  Future<void> write(String key, String value) => _s.write(key: key, value: value);
  @override
  Future<void> delete(String key) => _s.delete(key: key);
}

class MemorySecureKv implements SecureKv {
  final Map<String, String> values = {};
  @override
  Future<String?> read(String key) async => values[key];
  @override
  Future<void> write(String key, String value) async => values[key] = value;
  @override
  Future<void> delete(String key) async => values.remove(key);
}

class SessionStore {
  SessionStore(this._kv);

  final SecureKv _kv;

  static const _session = 'centurion-staff-session-v1';
  static const _host = 'centurion-staff-host';
  static const _lastOperator = 'centurion-staff-last-operator';

  Future<Session?> read() async {
    try {
      final raw = await _kv.read(_session);
      if (raw == null) return null;
      final s = Session.fromJson(jMap(jsonDecode(raw)));
      return s.token.isEmpty ? null : s;
    } catch (_) {
      await _kv.delete(_session);
      return null;
    }
  }

  Future<void> write(Session s) => _kv.write(_session, jsonEncode(s.toJson()));
  Future<void> clear() => _kv.delete(_session);

  /// The hotel address typed on the phone screen (overrides the build's).
  Future<String?> readHost() => _kv.read(_host);
  Future<void> writeHost(String host) => _kv.write(_host, host);

  Future<String?> readLastOperator() => _kv.read(_lastOperator);
  Future<void> writeLastOperator(String id) => _kv.write(_lastOperator, id);
}

final secureKvProvider = Provider<SecureKv>((ref) => const FlutterSecureKv());

final sessionStoreProvider = Provider<SessionStore>((ref) => SessionStore(ref.watch(secureKvProvider)));

@immutable
class SessionState {
  const SessionState({required this.hydrated, this.session, this.kickedOut = false});

  final bool hydrated;
  final Session? session;

  /// True when the server rejected the token (shows a note on sign in).
  final bool kickedOut;
}

class SessionNotifier extends StateNotifier<SessionState> {
  SessionNotifier(this._store, {Session? initial})
      : super(initial != null ? SessionState(hydrated: true, session: initial) : const SessionState(hydrated: false)) {
    if (initial == null) _hydrate();
  }

  final SessionStore _store;

  Future<void> _hydrate() async {
    final s = await _store.read();
    if (mounted) state = SessionState(hydrated: true, session: s);
  }

  Future<void> signIn(Session s) async {
    await _store.write(s);
    state = SessionState(hydrated: true, session: s);
  }

  Future<void> signOut({bool kickedOut = false}) async {
    await _store.clear();
    state = SessionState(hydrated: true, kickedOut: kickedOut);
  }
}

final sessionProvider = StateNotifierProvider<SessionNotifier, SessionState>((ref) {
  return SessionNotifier(ref.watch(sessionStoreProvider));
});
