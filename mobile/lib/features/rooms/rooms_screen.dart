// My rooms (A-rooms).

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../core/strings.dart';
import '../../core/theme/tokens.dart';
import '../../core/widgets/widgets.dart';
import '../shell/home_header.dart';
import '../staff/models.dart';
import '../staff/providers.dart';
import 'room_ui.dart';

Future<void> refreshAll(BuildContext context, List<Future<bool>> calls) async {
  final ok = await Future.wait(calls);
  if (ok.contains(false) && context.mounted) showSnack(context, S.refreshFailed);
}

class RoomsScreen extends ConsumerWidget {
  const RoomsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final rooms = ref.watch(roomsProvider);
    final data = rooms.valueOrNull;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () => refreshAll(context, [
            ref.read(roomsProvider.notifier).refresh(),
            ref.read(staffSessionProvider.notifier).refresh(),
          ]),
          child: CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              const SliverToBoxAdapter(child: OfflineBanner()),
              SliverToBoxAdapter(
                child: HomeHeader(summary: data == null ? '' : S.roomsSummary(data.toClean, data.withPorter)),
              ),
              if (data != null) ...[
                SliverToBoxAdapter(
                  child: TodayCard(done: data.ready, total: data.total, ofText: S.ofRoomsReady(data.total)),
                ),
                if (data.rooms.isEmpty)
                  const SliverToBoxAdapter(child: EmptyState(text: S.noRooms, icon: LucideIcons.bedDouble))
                else
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
                    sliver: SliverList.separated(
                      itemCount: data.rooms.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 12),
                      itemBuilder: (context, i) => RoomCard(
                        room: data.rooms[i],
                        onTap: () => context.push('/rooms/${data.rooms[i].inspectionId}'),
                      ),
                    ),
                  ),
              ] else if (rooms.hasError)
                SliverToBoxAdapter(child: ErrorState(onRetry: () => ref.read(roomsProvider.notifier).refresh()))
              else
                const SliverToBoxAdapter(child: LoadingBlock()),
            ],
          ),
        ),
      ),
    );
  }
}

class RoomCard extends StatelessWidget {
  const RoomCard({super.key, required this.room, required this.onTap});

  final Room room;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final look = roomLook(room.state);
    final badge = badgeLook(room.state);
    final sub = roomSubtitle(room);
    return Opacity(
      opacity: room.isFinished ? 0.7 : 1,
      child: AppCard(
        onTap: onTap,
        semanticLabel: [S.roomTitle(room.number), look.chip, if (room.doFirst) S.doFirst, room.category, sub]
            .where((s) => s.isNotEmpty)
            .join(', '),
        child: ExcludeSemantics(
          child: Row(
            children: [
              RoomBadge(room.number, bg: badge.bg, fg: badge.fg),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(
                      spacing: 8,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        StateChip(look.chip, bg: look.bg, fg: look.fg),
                        if (room.doFirst && !room.isFinished)
                          const Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(LucideIcons.clock, size: 14, color: C.toCleanFg),
                              SizedBox(width: 4),
                              Text(
                                S.doFirst,
                                style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: C.toCleanFg),
                              ),
                            ],
                          ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(room.category, style: T.cardTitle),
                    if (sub.isNotEmpty) Text(sub, style: T.cardSub),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              const Icon(LucideIcons.chevronRight, size: 22, color: C.inkFaint),
            ],
          ),
        ),
      ),
    );
  }
}
