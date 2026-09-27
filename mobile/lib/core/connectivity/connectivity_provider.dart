// A boolean stream — true when the device has *any* usable network
// interface (wifi, mobile, ethernet, vpn), false when it has none.
// Powers the MainShell's "you're offline" banner.
//
// Deliberately coarse: connectivity_plus returns a *list* of active
// transports these days, and we don't care which one — as long as
// there's at least one non-'none' entry we treat the device as
// online. False positives (transport up but no actual internet)
// are handled elsewhere: the outbox worker + refresh calls surface
// real request failures via their own error paths.

import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final connectivityProvider = StreamProvider<bool>((ref) {
  final connectivity = Connectivity();
  final controller = StreamController<bool>();

  // Seed with the current state so subscribers don't wait for the
  // first change event.
  connectivity.checkConnectivity().then((results) {
    if (controller.isClosed) return;
    controller.add(_online(results));
  });

  final sub = connectivity.onConnectivityChanged.listen((results) {
    controller.add(_online(results));
  });

  ref.onDispose(() {
    sub.cancel();
    controller.close();
  });

  return controller.stream;
});

bool _online(List<ConnectivityResult> results) {
  return results.any((r) => r != ConnectivityResult.none);
}
