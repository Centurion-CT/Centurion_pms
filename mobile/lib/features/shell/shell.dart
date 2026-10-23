// Bottom navigation with a pill highlight on the active tab (A mockups).
// Housekeeper: My rooms / Supplies / Me. Porter: To check / Supplies / Me.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../core/auth/session.dart';
import '../../core/strings.dart';
import '../../core/theme/tokens.dart';

class StaffShell extends ConsumerWidget {
  const StaffShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final role = ref.watch(sessionProvider.select((s) => s.session?.role));
    final porter = role == StaffRole.porter;
    final tabs = [
      (porter ? LucideIcons.clipboardCheck : LucideIcons.bedDouble, porter ? S.tabToCheck : S.tabMyRooms),
      (LucideIcons.package, S.tabSupplies),
      (LucideIcons.userRound, S.tabMe),
    ];
    return Scaffold(
      body: shell,
      bottomNavigationBar: DecoratedBox(
        decoration: const BoxDecoration(
          color: C.card,
          border: Border(top: BorderSide(color: C.line)),
        ),
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
            child: Row(
              children: [
                for (var i = 0; i < tabs.length; i++)
                  Expanded(
                    child: _NavItem(
                      icon: tabs[i].$1,
                      label: tabs[i].$2,
                      active: shell.currentIndex == i,
                      onTap: () => shell.goBranch(i, initialLocation: i == shell.currentIndex),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({required this.icon, required this.label, required this.active, required this.onTap});

  final IconData icon;
  final String label;
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: active,
      label: label,
      excludeSemantics: true,
      child: InkResponse(
        onTap: onTap,
        radius: 40,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 60),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              AnimatedContainer(
                duration: const Duration(milliseconds: 180),
                width: 80,
                height: 36,
                decoration: BoxDecoration(
                  color: active ? C.accent : Colors.transparent,
                  borderRadius: BorderRadius.circular(99),
                ),
                child: Icon(icon, size: 23, color: active ? Colors.white : C.inkMute),
              ),
              const SizedBox(height: 4),
              Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontFamily: F.body,
                  fontSize: 12.5,
                  fontWeight: FontWeight.w600,
                  color: active ? C.ink : C.inkMute,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
