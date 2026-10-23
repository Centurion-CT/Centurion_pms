// Porter: To check (list) and Check this room (A-porter).

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../core/format.dart';
import '../../core/strings.dart';
import '../../core/theme/tokens.dart';
import '../../core/widgets/icons.dart';
import '../../core/widgets/widgets.dart';
import '../rooms/room_ui.dart';
import '../rooms/rooms_screen.dart' show refreshAll;
import '../shell/home_header.dart';
import '../staff/models.dart';
import '../staff/providers.dart';

class ChecksScreen extends ConsumerWidget {
  const ChecksScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final checks = ref.watch(checksProvider);
    final data = checks.valueOrNull;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: () => refreshAll(context, [
            ref.read(checksProvider.notifier).refresh(),
            ref.read(staffSessionProvider.notifier).refresh(),
          ]),
          child: CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              const SliverToBoxAdapter(child: OfflineBanner()),
              SliverToBoxAdapter(child: HomeHeader(summary: data == null ? '' : S.roomsToCheck(data.rooms.length))),
              if (data != null) ...[
                SliverToBoxAdapter(
                  child: TodayCard(done: data.checked, total: data.total, ofText: S.ofRoomsChecked(data.total)),
                ),
                if (data.rooms.isEmpty)
                  const SliverToBoxAdapter(
                    child: EmptyState(text: S.nothingToCheck, icon: LucideIcons.clipboardCheck),
                  )
                else
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
                    sliver: SliverList.separated(
                      itemCount: data.rooms.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 12),
                      itemBuilder: (context, i) => CheckCard(
                        check: data.rooms[i],
                        onTap: () => context.push('/checks/${data.rooms[i].inspectionId}'),
                      ),
                    ),
                  ),
              ] else if (checks.hasError)
                SliverToBoxAdapter(child: ErrorState(onRetry: () => ref.read(checksProvider.notifier).refresh()))
              else
                const SliverToBoxAdapter(child: LoadingBlock()),
            ],
          ),
        ),
      ),
    );
  }
}

class CheckCard extends StatelessWidget {
  const CheckCard({super.key, required this.check, required this.onTap});

  final Check check;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final arriving = arrivalLine(check.arrivingAt, check.arrivingToday);
    final sub = S.cleanedBy(check.cleanerFirstName, hm(check.cleanedAt));
    return AppCard(
      onTap: onTap,
      semanticLabel: [S.roomTitle(check.number), S.chipWithPorter, arriving, check.category, sub]
          .where((s) => s.isNotEmpty)
          .join(', '),
      child: ExcludeSemantics(
        child: Row(
          children: [
            RoomBadge(check.number, bg: C.accentSoftSolid, fg: C.accentDeep),
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
                      const StateChip(S.chipWithPorter, bg: C.accentSoftSolid, fg: C.accentDeep),
                      if (arriving.isNotEmpty)
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(LucideIcons.clock, size: 14, color: C.toCleanFg),
                            const SizedBox(width: 4),
                            Text(
                              arriving,
                              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: C.toCleanFg),
                            ),
                          ],
                        ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(check.category, style: T.cardTitle),
                  Text(sub, style: T.cardSub),
                ],
              ),
            ),
            const SizedBox(width: 8),
            const Icon(LucideIcons.chevronRight, size: 22, color: C.inkFaint),
          ],
        ),
      ),
    );
  }
}

class CheckScreen extends ConsumerStatefulWidget {
  const CheckScreen({super.key, required this.inspectionId});

  final int inspectionId;

  @override
  ConsumerState<CheckScreen> createState() => _CheckScreenState();
}

class _CheckScreenState extends ConsumerState<CheckScreen> {
  bool _busy = false;

  Future<void> _ready(Check c) async {
    setState(() => _busy = true);
    await ref.read(staffRepositoryProvider)?.markReady(c);
    if (!mounted) return;
    showSnack(context, S.roomReady(c.number));
    if (context.canPop()) context.pop();
  }

  Future<void> _sendBack(Check c) async {
    final payload = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (_) => SendBackSheet(cleanerFirstName: c.cleanerFirstName),
    );
    if (payload == null || !mounted) return;
    setState(() => _busy = true);
    final reasons = (payload['reasons'] as List).cast<String>();
    await ref.read(staffRepositoryProvider)?.sendBack(c, reasons, payload['note'] as String?);
    if (!mounted) return;
    showSnack(context, S.roomSentBack(c.number));
    if (context.canPop()) context.pop();
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(checkDetailProvider(widget.inspectionId));
    final d = async.valueOrNull;
    final c = d?.check;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const OfflineBanner(),
            Expanded(
              child: Stack(
                children: [
                  RefreshIndicator(
                    onRefresh: () async {
                      final ok = await ref.read(checkDetailProvider(widget.inspectionId).notifier).refresh();
                      if (!ok && context.mounted) showSnack(context, S.refreshFailed);
                    },
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.only(bottom: 140),
                      children: [
                        Padding(
                          padding: const EdgeInsets.fromLTRB(6, 8, 16, 0),
                          child: Row(
                            children: [
                              BackCircle(onTap: () => context.canPop() ? context.pop() : context.go('/home')),
                              const SizedBox(width: 6),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Eyebrow(S.checkThisRoom),
                                    Semantics(
                                      header: true,
                                      child: Text(c == null ? '' : S.roomTitle(c.number), style: T.roomTitle),
                                    ),
                                  ],
                                ),
                              ),
                              if (c != null) const StateChip(S.chipWithPorter, bg: C.accentSoftSolid, fg: C.accentDeep),
                            ],
                          ),
                        ),
                        if (d != null)
                          ..._body(d)
                        else if (async.hasError)
                          ErrorState(
                            onRetry: () => ref.read(checkDetailProvider(widget.inspectionId).notifier).refresh(),
                          )
                        else
                          const LoadingBlock(),
                      ],
                    ),
                  ),
                  if (c != null)
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 0,
                      child: BottomActionArea(
                        child: Row(
                          children: [
                            OutlinePill(
                              key: const Key('send-back'),
                              label: S.sendBack,
                              onTap: _busy ? null : () => _sendBack(c),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: ActionPill(
                                key: const Key('room-ready'),
                                label: S.roomIsReady,
                                icon: LucideIcons.check,
                                busy: _busy,
                                onTap: () => _ready(c),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _body(CheckDetail d) {
    final c = d.check;
    final meta =
        [hm(c.cleanedAt), if (c.minutes != null) S.tookMinutes(c.minutes!)].where((s) => s.isNotEmpty).join(' · ');
    return [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
        child: AppCard(
          child: Row(
            children: [
              InitialsAvatar(c.cleanedByInitials),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(S.cleanedByTitle(c.cleanerFirstName), style: T.cardTitle),
                    if (meta.isNotEmpty) Text(meta, style: T.cardSub),
                  ],
                ),
              ),
              if (c.score != null)
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      '${c.score}',
                      style: const TextStyle(fontFamily: F.display, fontSize: 24, fontWeight: FontWeight.w700),
                    ),
                    const Text(S.score, style: TextStyle(fontSize: 11.5, color: C.inkMute)),
                  ],
                ),
            ],
          ),
        ),
      ),
      if (c.note?.isNotEmpty ?? false)
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: AppCard(
            color: C.warnBg,
            border: false,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(LucideIcons.triangleAlert, size: 18, color: C.warnFg),
                    const SizedBox(width: 8),
                    Text(
                      S.noted(c.cleanerFirstName),
                      style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w600, color: C.warnFg),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Text('"${c.note}"', style: const TextStyle(fontSize: 15.5, color: C.warnFg)),
              ],
            ),
          ),
        ),
      if (d.items.isNotEmpty)
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: AppCard(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Semantics(header: true, child: const Text(S.lookAtThese, style: T.cardTitle)),
                const SizedBox(height: 4),
                for (var i = 0; i < d.items.length; i++)
                  Semantics(
                    label: '${d.items[i].label}${d.items[i].checked ? '' : ', ${S.notTicked}'}',
                    excludeSemantics: true,
                    child: Container(
                      constraints: const BoxConstraints(minHeight: 56),
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      decoration: BoxDecoration(
                        border: i == 0 ? null : const Border(top: BorderSide(color: C.lineSoft)),
                      ),
                      child: Row(
                        children: [
                          Icon(itemIcon(d.items[i].icon), size: 22, color: C.inkMute),
                          const SizedBox(width: 12),
                          Expanded(child: Text(d.items[i].label, style: const TextStyle(fontSize: 16))),
                          Icon(
                            d.items[i].checked ? LucideIcons.check : LucideIcons.x,
                            size: 20,
                            color: d.items[i].checked ? C.readyFg : C.toCleanFg,
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
    ];
  }
}

/// One-tap reasons + optional note. Pops with the send-back payload.
class SendBackSheet extends StatefulWidget {
  const SendBackSheet({super.key, required this.cleanerFirstName});

  final String cleanerFirstName;

  @override
  State<SendBackSheet> createState() => _SendBackSheetState();
}

class _SendBackSheetState extends State<SendBackSheet> {
  final _picked = <String>{};
  final _note = TextEditingController();

  static const labels = {
    'towels': S.reasonTowels,
    'bathroom': S.reasonBathroom,
    'bed': S.reasonBed,
    'floor': S.reasonFloor,
    'smell': S.reasonSmell,
    'missing': S.reasonMissing,
    'other': S.reasonOther,
  };

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(S.whySendBack,
                  style: TextStyle(fontFamily: F.display, fontSize: 24, fontWeight: FontWeight.w600),),
              const SizedBox(height: 4),
              const Text(S.whySendBackBody, style: T.sub),
              const SizedBox(height: 16),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final key in sendBackReasons)
                    _ReasonChip(
                      key: Key('reason-$key'),
                      label: labels[key]!,
                      selected: _picked.contains(key),
                      onTap: () => setState(() => _picked.contains(key) ? _picked.remove(key) : _picked.add(key)),
                    ),
                ],
              ),
              const SizedBox(height: 16),
              TextField(
                key: const Key('send-back-note'),
                controller: _note,
                minLines: 1,
                maxLines: 3,
                maxLength: 500,
                textCapitalization: TextCapitalization.sentences,
                decoration: const InputDecoration(hintText: S.sendBackNoteHint, counterText: ''),
              ),
              const SizedBox(height: 16),
              ActionPill(
                key: const Key('confirm-send-back'),
                label: S.sendBackTo(widget.cleanerFirstName),
                icon: LucideIcons.undo2,
                enabled: _picked.isNotEmpty,
                onTap: () => Navigator.of(context).pop(buildSendBackPayload(_picked, _note.text)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ReasonChip extends StatelessWidget {
  const _ReasonChip({super.key, required this.label, required this.selected, required this.onTap});

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      label: label,
      excludeSemantics: true,
      child: Material(
        color: selected ? C.inkDeep : C.card,
        shape: StadiumBorder(side: BorderSide(color: selected ? C.inkDeep : C.line)),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Container(
            constraints: const BoxConstraints(minHeight: 56),
            padding: const EdgeInsets.symmetric(horizontal: 18),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (selected) ...[
                  const Icon(LucideIcons.check, size: 18, color: Colors.white),
                  const SizedBox(width: 6),
                ],
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w600,
                    color: selected ? Colors.white : C.ink,
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
