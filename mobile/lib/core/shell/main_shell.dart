// Bottom-nav shell around the five top-level tabs — Home,
// Housekeeping, Reservations, POS, Outbox. Uses go_router's
// StatefulShellRoute so each tab keeps its own navigation stack
// (pushing an inspection detail from the Housekeeping tab doesn't
// affect the Reservations tab).
//
// Renders a slim offline banner above the current screen when
// connectivity is down. Reads stay visible from cache and writes
// still queue via the outbox — the banner tells the operator why
// pull-to-refresh isn't returning fresh data.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../auth/role_visibility.dart';
import '../auth/session_store.dart';
import '../connectivity/connectivity_provider.dart';
import '../outbox/outbox_providers.dart';
import '../theme/keverna_theme.dart';

class MainShell extends ConsumerWidget {
  const MainShell({super.key, required this.navigationShell});

  final StatefulNavigationShell navigationShell;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pending = ref.watch(outboxPendingCountProvider).valueOrNull ?? 0;
    // Assume online while the first probe is in flight — avoids a
    // banner flash on every cold start.
    final online = ref.watch(connectivityProvider).valueOrNull ?? true;

    final role = ref.watch(sessionProvider)?.role;
    final visible = _visibleTabsFor(role, pending: pending);
    // The router owns 5 fixed branches; we only expose a subset.
    // Map the visible-index the NavigationBar reports back to the
    // real branch-index so goBranch lands on the right stack.
    final selectedVisible = () {
      for (var i = 0; i < visible.length; i++) {
        if (visible[i].branchIndex == navigationShell.currentIndex) return i;
      }
      return 0;
    }();

    return Scaffold(
      body: Column(
        children: [
          if (!online) const _OfflineBanner(),
          Expanded(child: navigationShell),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: selectedVisible,
        onDestinationSelected: (i) {
          final branch = visible[i].branchIndex;
          navigationShell.goBranch(
            branch,
            initialLocation: branch == navigationShell.currentIndex,
          );
        },
        backgroundColor: KevernaColors.card,
        indicatorColor: KevernaColors.accent.withValues(alpha: 0.35),
        destinations: visible.map((t) => t.destination).toList(),
      ),
    );
  }

  /// Which tabs this operator's role can act on. Uses [RoleVisibility]
  /// so the client-side mask matches the server-side guards on each
  /// controller — the alternative is showing a tab that renders a
  /// 403 as soon as it fetches.
  List<_TabDef> _visibleTabsFor(String? role, {required int pending}) {
    final gates = RoleVisibility.forRole(role);
    return [
      if (gates.canSeeHome)
        const _TabDef(
          branchIndex: 0,
          destination: const NavigationDestination(
            icon: Icon(Icons.dashboard_outlined),
            selectedIcon: Icon(Icons.dashboard),
            label: 'Home',
          ),
        ),
      if (gates.canSeeHousekeeping)
        const _TabDef(
          branchIndex: 1,
          destination: const NavigationDestination(
            icon: Icon(Icons.cleaning_services_outlined),
            selectedIcon: Icon(Icons.cleaning_services),
            label: 'Housekeeping',
          ),
        ),
      if (gates.canSeeReservations)
        const _TabDef(
          branchIndex: 2,
          destination: const NavigationDestination(
            icon: Icon(Icons.event_note_outlined),
            selectedIcon: Icon(Icons.event_note),
            label: 'Reservations',
          ),
        ),
      if (gates.canSeePos)
        const _TabDef(
          branchIndex: 3,
          destination: const NavigationDestination(
            icon: Icon(Icons.point_of_sale_outlined),
            selectedIcon: Icon(Icons.point_of_sale),
            label: 'POS',
          ),
        ),
      if (gates.canSeeOutbox)
        _TabDef(
          branchIndex: 4,
          destination: NavigationDestination(
            icon: _OutboxIcon(pending: pending, selected: false),
            selectedIcon: _OutboxIcon(pending: pending, selected: true),
            label: 'Outbox',
          ),
        ),
    ];
  }
}

class _TabDef {
  const _TabDef({required this.branchIndex, required this.destination});
  final int branchIndex;
  final NavigationDestination destination;
}

/// Slim strip that sits above the current screen when the device
/// has no active connectivity. Warm amber so it's noticed without
/// screaming "error" — being offline isn't a failure state in this
/// app (queued writes drain when service returns).
class _OfflineBanner extends StatelessWidget {
  const _OfflineBanner();

  @override
  Widget build(BuildContext context) {
    return Material(
      color: KevernaColors.warn.withValues(alpha: 0.14),
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: const [
              Icon(Icons.cloud_off, size: 16, color: Color(0xFF92400E)),
              SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Offline — reads may be stale. Writes will sync when you reconnect.',
                  style: TextStyle(
                    color: Color(0xFF92400E),
                    fontWeight: FontWeight.w600,
                    fontSize: 12.5,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Sync icon with a tiny badge when the outbox has pending entries.
/// Uses a Stack instead of NavigationDestination's built-in badge
/// because we want to badge the icon-glyph itself, not a Material
/// Badge overlay.
class _OutboxIcon extends StatelessWidget {
  const _OutboxIcon({required this.pending, required this.selected});

  final int pending;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final icon = selected ? const Icon(Icons.sync) : const Icon(Icons.sync_outlined);
    if (pending == 0) return icon;
    return Stack(
      clipBehavior: Clip.none,
      children: [
        icon,
        Positioned(
          right: -6,
          top: -4,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
            decoration: BoxDecoration(
              color: KevernaColors.accentDeep,
              borderRadius: BorderRadius.circular(999),
            ),
            child: Text(
              pending > 99 ? '99+' : '$pending',
              style: const TextStyle(
                color: KevernaColors.card,
                fontSize: 10,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ),
      ],
    );
  }
}
