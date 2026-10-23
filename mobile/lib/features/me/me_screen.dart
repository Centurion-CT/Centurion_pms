// Me: name, role, this week's numbers, sign out.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../core/auth/session.dart';
import '../../core/strings.dart';
import '../../core/theme/tokens.dart';
import '../../core/widgets/widgets.dart';
import '../auth/login_flow.dart';
import '../staff/providers.dart';

final appVersionProvider = FutureProvider<String>((ref) async {
  try {
    final p = await PackageInfo.fromPlatform();
    return p.version;
  } catch (_) {
    return '';
  }
});

Future<void> signOut(WidgetRef ref) async {
  await ref.read(staffRepositoryProvider)?.logout();
  final db = ref.read(localDbProvider);
  await db.clearCache();
  await db.clearOutbox();
  // Phones are often shared: the next person starts with an empty field.
  ref.read(loginFlowProvider.notifier).setPhone('');
  await ref.read(sessionProvider.notifier).signOut();
}

class MeScreen extends ConsumerWidget {
  const MeScreen({super.key});

  Future<void> _signOut(BuildContext context, WidgetRef ref) async {
    final pending = ref.read(outboxCountProvider).valueOrNull ?? 0;
    if (pending > 0) {
      final go = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: C.card,
          title: const Text(S.signOutPendingTitle),
          content: Text(S.signOutPendingBody(pending)),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text(S.stay)),
            TextButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text(S.signOutAnyway, style: TextStyle(color: C.toCleanFg)),
            ),
          ],
        ),
      );
      if (go != true) return;
    }
    await signOut(ref);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final me = ref.watch(meProvider);
    final session = ref.watch(sessionProvider.select((s) => s.session));
    final op = me.valueOrNull?.operator ?? session?.operator;
    final porter = (op?.staffRole ?? session?.role) == StaffRole.porter;
    final week = me.valueOrNull;
    final version = ref.watch(appVersionProvider).valueOrNull ?? '';

    final stats = <(String, String)>[
      if (week != null) ...[
        if (porter) (S.roomsChecked, '${week.roomsChecked}') else (S.roomsCleaned, '${week.roomsCleaned}'),
        if (!porter) (S.avgTime, week.avgMinutes == null ? '-' : S.minutes(week.avgMinutes!)),
        (S.sentBackStat, '${week.sentBack}'),
      ],
    ];

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () async {
            final ok = await ref.read(meProvider.notifier).refresh();
            if (!ok && context.mounted) showSnack(context, S.refreshFailed);
          },
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.only(bottom: 32),
            children: [
              const OfflineBanner(),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 0),
                child: Row(
                  children: [
                    InitialsAvatar(op?.initials ?? '', size: 64, bg: C.accent, fg: Colors.white),
                    const SizedBox(width: 16),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Eyebrow(op?.displayRole ?? ''),
                          const SizedBox(height: 2),
                          Semantics(header: true, child: Text(op?.name ?? '', style: T.h1)),
                          if (session != null) Text(session.tenantName, style: T.sub),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 28, 20, 8),
                child: Text(S.thisWeek.toUpperCase(), style: T.sectionLabel),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: week == null
                    ? (me.hasError
                        ? ErrorState(onRetry: () => ref.read(meProvider.notifier).refresh())
                        : const LoadingBlock())
                    : AppCard(
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                        child: Column(
                          children: [
                            for (var i = 0; i < stats.length; i++)
                              Container(
                                constraints: const BoxConstraints(minHeight: 60),
                                decoration: BoxDecoration(
                                  border: i == 0 ? null : const Border(top: BorderSide(color: C.lineSoft)),
                                ),
                                child: Row(
                                  children: [
                                    Expanded(child: Text(stats[i].$1, style: const TextStyle(fontSize: 16))),
                                    Text(
                                      stats[i].$2,
                                      style: const TextStyle(
                                        fontFamily: F.display,
                                        fontSize: 24,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                          ],
                        ),
                      ),
              ),
              if (op != null && op.phone.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                  child: AppCard(
                    child: Row(
                      children: [
                        const Icon(LucideIcons.phone, size: 20, color: C.inkMute),
                        const SizedBox(width: 12),
                        Text(op.phone, style: const TextStyle(fontSize: 16)),
                      ],
                    ),
                  ),
                ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 28, 16, 0),
                child: OutlinePill(
                  key: const Key('sign-out'),
                  label: S.signOut,
                  icon: LucideIcons.logOut,
                  onTap: () => _signOut(context, ref),
                ),
              ),
              if (version.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 16),
                  child: Text(
                    '${S.appName} · ${S.version(version)}',
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 13, color: C.inkMute),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
