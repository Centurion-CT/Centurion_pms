// Session-gated router.
//
// - `/pair` is the only anonymous screen. Unpaired phones land here.
// - Once paired, the MainShell (bottom nav) wraps three parallel
//   navigation stacks (Housekeeping / Reservations / Outbox) via
//   StatefulShellRoute so each tab keeps its own history.
// - Deep-nav routes (`/inspections/:id`, `/reservations/:ref`) live
//   inside their tab branch so their AppBars slide over the bottom
//   nav naturally.

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../features/auth/login_screen.dart';
import '../features/home/home_screen.dart';
import '../features/inspections/inspection_detail_screen.dart';
import '../features/inspections/inspections_queue_screen.dart';
import '../features/inventory/stock_requests_screen.dart';
import '../features/outbox/outbox_screen.dart';
import '../features/pair/pair_screen.dart';
import '../features/pos/pos_screen.dart';
import '../features/reservations/create_booking_screen.dart';
import '../features/reservations/reservation_detail_screen.dart';
import '../features/reservations/reservations_screen.dart';
import 'auth/role_visibility.dart';
import 'auth/session_store.dart';
import 'shell/main_shell.dart';

/// GoRouter as a Riverpod Provider so main.dart's ConsumerWidget
/// can watch it with a plain WidgetRef. Also lets us hot-swap the
/// router in tests by overriding the provider.
final routerProvider = Provider<GoRouter>((ref) {
  final listenable = _SessionListenable(ref);
  return GoRouter(
    initialLocation: '/home',
    refreshListenable: listenable,
    redirect: (context, state) {
      final session = ref.read(sessionProvider);
      final signedIn = session != null;
      final path = state.matchedLocation;
      final onAnonymous = path == '/login' || path == '/pair';
      if (!signedIn && !onAnonymous) return '/login';
      if (signedIn && onAnonymous) return '/home';

      // Deep-link guard: bounce role-inappropriate tab paths back to
      // /home instead of loading a screen whose data fetches will 403.
      // Belt-and-braces alongside the tab filter in MainShell — that
      // hides the buttons; this catches URLs typed / bookmarked / hit
      // by back-nav.
      if (signedIn) {
        final gates = RoleVisibility.forRole(session.role);
        if (path.startsWith('/housekeeping') && !gates.canSeeHousekeeping) return '/home';
        if (path.startsWith('/reservations') && !gates.canSeeReservations) return '/home';
        if (path.startsWith('/pos') && !gates.canSeePos) return '/home';
      }
      return null;
    },
    routes: [
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/pair', builder: (_, __) => const PairScreen()),

      StatefulShellRoute.indexedStack(
        builder: (_, __, shell) => MainShell(navigationShell: shell),
        branches: [
          // Home tab (first)
          StatefulShellBranch(routes: [
            GoRoute(path: '/home', builder: (_, __) => const HomeScreen()),
          ]),
          // Housekeeping tab
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/housekeeping',
              builder: (_, __) => const InspectionsQueueScreen(),
              routes: [
                GoRoute(
                  path: 'inspections/:id',
                  builder: (_, state) => InspectionDetailScreen(id: int.parse(state.pathParameters['id']!)),
                ),
              ],
            ),
          ]),
          // Reservations tab
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/reservations',
              builder: (_, __) => const ReservationsScreen(),
              routes: [
                // `create` before `:ref` — go_router matches in
                // declaration order and `:ref` is greedy.
                GoRoute(
                  path: 'create',
                  builder: (_, __) => const CreateBookingScreen(),
                ),
                GoRoute(
                  path: ':ref',
                  builder: (_, state) => ReservationDetailScreen(bookingRef: state.pathParameters['ref']!),
                ),
              ],
            ),
          ]),
          // POS tab
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/pos',
              builder: (_, __) => const PosScreen(),
              routes: [
                GoRoute(
                  path: 'stock-requests',
                  builder: (_, __) => const StockRequestsScreen(),
                ),
              ],
            ),
          ]),
          // Outbox tab
          StatefulShellBranch(routes: [
            GoRoute(path: '/outbox', builder: (_, __) => const OutboxScreen()),
          ]),
        ],
      ),
    ],
  );
});

/// Bridge sessionProvider → Listenable so GoRouter re-runs redirect()
/// on sign-in / sign-out without a manual context.go().
class _SessionListenable extends ChangeNotifier {
  _SessionListenable(Ref ref) {
    ref.listen(sessionProvider, (_, __) => notifyListeners());
  }
}
