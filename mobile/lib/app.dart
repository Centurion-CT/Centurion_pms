import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/router.dart';
import 'core/strings.dart';
import 'core/theme/theme.dart';
import 'features/staff/providers.dart';

final scaffoldMessengerKey = GlobalKey<ScaffoldMessengerState>();

class CenturionStaffApp extends ConsumerStatefulWidget {
  const CenturionStaffApp({super.key});

  @override
  ConsumerState<CenturionStaffApp> createState() => _CenturionStaffAppState();
}

class _CenturionStaffAppState extends ConsumerState<CenturionStaffApp> {
  StreamSubscription<String>? _messages;

  @override
  void initState() {
    super.initState();
    // Changes the server refused while replaying the outbox.
    _messages = ref.read(outboxMessagesProvider).stream.listen((msg) {
      scaffoldMessengerKey.currentState
        ?..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(msg), duration: const Duration(seconds: 6)));
    });
  }

  @override
  void dispose() {
    _messages?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Keep the outbox worker alive while signed in.
    ref.watch(outboxWorkerProvider);
    final router = ref.watch(routerProvider);
    return MaterialApp.router(
      title: S.appName,
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      scaffoldMessengerKey: scaffoldMessengerKey,
      routerConfig: router,
    );
  }
}
