import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'core/auth/session.dart';
import 'core/config.dart';
import 'demo/demo_backend.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]);

  final overrides = <Override>[];
  if (kDemo && (kDemoAutoLogin == 'housekeeper' || kDemoAutoLogin == 'porter')) {
    const op = kDemoAutoLogin == 'porter' ? demoMusa : demoBlessing;
    overrides.add(
      sessionProvider.overrideWith(
        (ref) => SessionNotifier(ref.watch(sessionStoreProvider), initial: demoSession(op)),
      ),
    );
  }
  runApp(ProviderScope(overrides: overrides, child: const CenturionStaffApp()));
}
