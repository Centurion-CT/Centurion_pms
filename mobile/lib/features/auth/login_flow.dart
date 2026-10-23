// State shared by the phone, PIN and hotel screens.

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import '../../core/config.dart';
import '../staff/providers.dart';
import 'login_service.dart';

@immutable
class LoginFlowState {
  const LoginFlowState({this.host = '', this.hostLoaded = false, this.phone = '', this.tenants = const []});

  final String host;
  final bool hostLoaded;
  final String phone;
  final List<Tenant> tenants;

  LoginFlowState copyWith({String? host, bool? hostLoaded, String? phone, List<Tenant>? tenants}) => LoginFlowState(
        host: host ?? this.host,
        hostLoaded: hostLoaded ?? this.hostLoaded,
        phone: phone ?? this.phone,
        tenants: tenants ?? this.tenants,
      );
}

enum LoginOutcome { signedIn, pickTenant, staffOnly, failed }

class LoginFlow extends StateNotifier<LoginFlowState> {
  LoginFlow(this._ref) : super(const LoginFlowState()) {
    _loadHost();
  }

  final Ref<Object?> _ref;

  /// Last failure message (for the PIN screen).
  String? lastError;

  Future<void> _loadHost() async {
    String? stored;
    try {
      stored = await _ref.read(sessionStoreProvider).readHost();
    } catch (_) {}
    if (!mounted) return;
    state = state.copyWith(host: (stored?.isNotEmpty ?? false) ? stored : kDefaultHost, hostLoaded: true);
  }

  bool get needsHost => !kDemo && normalizeHost(state.host).isEmpty;

  Future<void> setHost(String host) async {
    state = state.copyWith(host: host.trim());
    await _ref.read(sessionStoreProvider).writeHost(host.trim());
  }

  void setPhone(String phone) => state = state.copyWith(phone: phone.trim(), tenants: const []);

  Future<LoginOutcome> submit(String pin, {int? tenantId}) async {
    lastError = null;
    final result = await _ref.read(loginServiceProvider).login(
          LoginRequest(host: state.host, phone: state.phone, pin: pin, tenantId: tenantId),
        );
    switch (result) {
      case LoginSuccess(:final session):
        if (session.role == StaffRole.other) return LoginOutcome.staffOnly;
        await _adopt(session);
        return LoginOutcome.signedIn;
      case LoginPickTenant(:final tenants):
        state = state.copyWith(tenants: tenants);
        _pin = pin;
        return LoginOutcome.pickTenant;
      case LoginFailure():
        if (result.isStaffOnly) return LoginOutcome.staffOnly;
        lastError = result.message;
        return LoginOutcome.failed;
    }
  }

  String _pin = '';

  /// Resend the PIN with the chosen hotel (after a 300).
  Future<LoginOutcome> pickTenant(int tenantId) => submit(_pin, tenantId: tenantId);

  Future<void> _adopt(Session s) async {
    final store = _ref.read(sessionStoreProvider);
    final db = _ref.read(localDbProvider);
    final last = await store.readLastOperator();
    // Queued changes belong to whoever made them. A different person on
    // this phone starts with an empty outbox.
    if (last != null && last != '${s.operator.id}') await db.clearOutbox();
    await db.clearCache();
    await store.writeLastOperator('${s.operator.id}');
    _pin = '';
    await _ref.read(sessionProvider.notifier).signIn(s);
  }
}

final loginFlowProvider = StateNotifierProvider<LoginFlow, LoginFlowState>((ref) => LoginFlow(ref));
