import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../features/auth/other_screens.dart';
import '../features/auth/phone_screen.dart';
import '../features/auth/pin_screen.dart';
import '../features/checks/checks_screen.dart';
import '../features/me/me_screen.dart';
import '../features/rooms/clean_screen.dart';
import '../features/rooms/rooms_screen.dart';
import '../features/shell/shell.dart';
import '../features/supplies/supplies_screen.dart';
import 'auth/session.dart';

final rootNavigatorKey = GlobalKey<NavigatorState>();

const _authPaths = {'/signin', '/signin/pin', '/signin/hotel', '/not-for-you'};

/// Pure redirect rule (tested on its own).
String? redirectFor(SessionState s, String location) {
  if (!s.hydrated) return location == '/splash' ? null : '/splash';
  final signedIn = s.session != null;
  if (!signedIn) return _authPaths.contains(location) ? null : '/signin';
  if (_authPaths.contains(location) || location == '/splash' || location == '/') return '/home';
  return null;
}

class _SessionListenable extends ChangeNotifier {
  _SessionListenable(Ref<Object?> ref) {
    ref.listen<SessionState>(sessionProvider, (_, __) => notifyListeners());
  }
}

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = _SessionListenable(ref);
  ref.onDispose(refresh.dispose);
  return GoRouter(
    navigatorKey: rootNavigatorKey,
    initialLocation: '/splash',
    refreshListenable: refresh,
    redirect: (context, state) => redirectFor(ref.read(sessionProvider), state.matchedLocation),
    routes: [
      GoRoute(path: '/', redirect: (_, __) => '/home'),
      GoRoute(path: '/splash', builder: (_, __) => const SplashScreen()),
      GoRoute(
        path: '/signin',
        builder: (_, __) => const PhoneScreen(),
        routes: [
          GoRoute(path: 'pin', builder: (_, __) => const PinScreen()),
          GoRoute(path: 'hotel', builder: (_, __) => const HotelPickScreen()),
        ],
      ),
      GoRoute(path: '/not-for-you', builder: (_, __) => const NotForYouScreen()),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => StaffShell(shell: shell),
        branches: [
          StatefulShellBranch(
            routes: [GoRoute(path: '/home', builder: (_, __) => const _HomeTab())],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/supplies', builder: (_, __) => const SuppliesScreen())],
          ),
          StatefulShellBranch(
            routes: [GoRoute(path: '/me', builder: (_, __) => const MeScreen())],
          ),
        ],
      ),
      GoRoute(
        path: '/rooms/:id',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, state) => CleanScreen(inspectionId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0),
      ),
      GoRoute(
        path: '/checks/:id',
        parentNavigatorKey: rootNavigatorKey,
        builder: (_, state) => CheckScreen(inspectionId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0),
      ),
    ],
  );
});

class _HomeTab extends ConsumerWidget {
  const _HomeTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final role = ref.watch(sessionProvider.select((s) => s.session?.role));
    return role == StaffRole.porter ? const ChecksScreen() : const RoomsScreen();
  }
}
