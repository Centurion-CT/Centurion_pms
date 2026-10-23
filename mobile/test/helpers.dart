import 'package:centurion_staff/app.dart';
import 'package:centurion_staff/core/api/api_error.dart';
import 'package:centurion_staff/core/api/staff_api.dart';
import 'package:centurion_staff/core/auth/session.dart';
import 'package:centurion_staff/core/storage/local_db.dart';
import 'package:centurion_staff/demo/demo_backend.dart';
import 'package:centurion_staff/features/auth/login_service.dart';
import 'package:centurion_staff/features/staff/providers.dart';
import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

LocalDb memoryDb() {
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;
  return LocalDb.forTesting(
    DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true),
  );
}

class FakeLoginService implements LoginService {
  FakeLoginService(this.respond);

  final LoginResult Function(LoginRequest req) respond;
  final List<LoginRequest> calls = [];

  @override
  Future<LoginResult> login(LoginRequest req) async {
    calls.add(req);
    return respond(req);
  }
}

/// A StaffApi that records sends and answers them from a script.
class ScriptedApi implements StaffApi {
  ScriptedApi({this.onSend});

  final Map<String, dynamic> Function(String path)? onSend;
  final List<(String path, Map<String, dynamic> body, String key)> sent = [];

  @override
  Future<Map<String, dynamic>> get(String path) async => throw const ApiError(code: 'network');

  @override
  Future<Map<String, dynamic>> send(
    String method,
    String path,
    Map<String, dynamic> body, {
    required String idempotencyKey,
  }) async {
    sent.add((path, body, idempotencyKey));
    return onSend?.call(path) ?? {'status': 'ok'};
  }
}

class Harness {
  Harness({Session? session, LoginService? login, bool liveOutbox = false}) {
    db = memoryDb();
    backend = DemoBackend(latency: Duration.zero);
    overrides = [
      localDbProvider.overrideWithValue(db),
      secureKvProvider.overrideWithValue(MemorySecureKv()..values['centurion-staff-host'] = 'https://hotel.test'),
      outboxAutoStartProvider.overrideWithValue(false),
      connectivityProvider.overrideWith((ref) => Stream.value(true)),
      demoBackendProvider.overrideWithValue(backend),
      staffApiProvider.overrideWith((ref) {
        final s = ref.watch(sessionProvider.select((s) => s.session));
        return s == null ? null : DemoStaffApi(backend, s.operator);
      }),
      if (!liveOutbox) outboxWorkerProvider.overrideWith((ref) => null),
      if (login != null) loginServiceProvider.overrideWithValue(login),
      sessionProvider.overrideWith(
        (ref) => SessionNotifier(ref.watch(sessionStoreProvider), initial: session),
      ),
    ];
  }

  late final LocalDb db;
  late final DemoBackend backend;
  late final List<Override> overrides;
  late ProviderContainer container;

  Future<void> pumpApp(WidgetTester tester, {double textScale = 1}) async {
    tester.view.physicalSize = const Size(412, 915);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    tester.platformDispatcher.textScaleFactorTestValue = textScale;
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
    await tester.pumpWidget(
      ProviderScope(
        overrides: overrides,
        child: Builder(
          builder: (context) {
            container = ProviderScope.containerOf(context);
            return const CenturionStaffApp();
          },
        ),
      ),
    );
    await settle(tester);
  }

  /// Pump until idle; sqlite + zero-latency fakes need a few turns.
  Future<void> settle(WidgetTester tester) async {
    for (var i = 0; i < 8; i++) {
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 5)));
      await tester.pump(const Duration(milliseconds: 100));
    }
    await tester.pump(const Duration(milliseconds: 400));
  }

  Future<void> dispose(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox());
    await tester.runAsync(() => db.close());
  }
}
