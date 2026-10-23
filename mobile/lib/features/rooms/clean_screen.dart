// Cleaning a room (A-clean). Opening a room that is "to clean" or "sent
// back" starts it (one queued `start`). Ticks toggle at once through the
// outbox. "Done, send to porter" asks for an optional note first.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../core/format.dart';
import '../../core/strings.dart';
import '../../core/theme/tokens.dart';
import '../../core/widgets/icons.dart';
import '../../core/widgets/widgets.dart';
import '../staff/models.dart';
import '../staff/providers.dart';
import 'room_ui.dart';

class CleanScreen extends ConsumerStatefulWidget {
  const CleanScreen({super.key, required this.inspectionId});

  final int inspectionId;

  @override
  ConsumerState<CleanScreen> createState() => _CleanScreenState();
}

class _CleanScreenState extends ConsumerState<CleanScreen> {
  bool _startQueued = false;
  bool _finishing = false;

  void _maybeStart(RoomDetail d) {
    if (_startQueued || !d.room.needsStart) return;
    _startQueued = true;
    ref.read(staffRepositoryProvider)?.startRoom(d.room);
  }

  Future<void> _finish(RoomDetail d) async {
    final unticked = d.totalCount - d.doneCount;
    final note = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (_) => FinishSheet(unticked: unticked),
    );
    if (note == null || !mounted) return;
    setState(() => _finishing = true);
    await ref.read(staffRepositoryProvider)?.finishRoom(d.room, note);
    if (!mounted) return;
    showSnack(context, S.sentToPorter(d.room.number));
    if (context.canPop()) context.pop();
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(roomDetailProvider(widget.inspectionId));
    final d = async.valueOrNull;
    if (d != null) WidgetsBinding.instance.addPostFrameCallback((_) => _maybeStart(d));
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
                      final ok = await ref.read(roomDetailProvider(widget.inspectionId).notifier).refresh();
                      if (!ok && context.mounted) showSnack(context, S.refreshFailed);
                    },
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.only(bottom: 140),
                      children: [
                        _Header(detail: d),
                        if (d != null)
                          ..._body(d)
                        else if (async.hasError)
                          ErrorState(
                            onRetry: () => ref.read(roomDetailProvider(widget.inspectionId).notifier).refresh(),
                          )
                        else
                          const LoadingBlock(),
                      ],
                    ),
                  ),
                  if (d != null && !d.room.isFinished)
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 0,
                      child: BottomActionArea(
                        child: ActionPill(
                          key: const Key('finish-room'),
                          label: S.doneSendToPorter,
                          icon: LucideIcons.send,
                          busy: _finishing,
                          onTap: () => _finish(d),
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

  List<Widget> _body(RoomDetail d) {
    final room = d.room;
    final repo = ref.read(staffRepositoryProvider);
    return [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
        child: AppCard(
          radius: 20,
          border: false,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(
                      text: S.doneOf(d.doneCount, d.totalCount),
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    const TextSpan(text: S.doneSuffix),
                  ],
                ),
                key: const Key('progress-text'),
                style: const TextStyle(fontSize: 15),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Align(
                  alignment: Alignment.centerRight,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 160),
                    child: ProgressBar(value: d.totalCount == 0 ? 0 : d.doneCount / d.totalCount, height: 10),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
      if (room.sentBack != null && !room.isFinished) SentBackBanner(sentBack: room.sentBack!),
      if (room.isFinished)
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: AppCard(
            color: room.state == RoomState.ready ? C.readyBg : C.accentSoftSolid,
            border: false,
            child: Row(
              children: [
                Icon(
                  room.state == RoomState.ready ? LucideIcons.circleCheck : LucideIcons.clock,
                  color: room.state == RoomState.ready ? C.readyFg : C.accentDeep,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    room.state == RoomState.ready ? S.readyBanner : S.withPorterBanner,
                    style: TextStyle(
                      fontWeight: FontWeight.w600,
                      color: room.state == RoomState.ready ? C.readyFg : C.accentDeep,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      for (final g in d.groups)
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: AppCard(
            padding: EdgeInsets.zero,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                  child: Semantics(
                    header: true,
                    child: Row(
                      children: [
                        Icon(groupIcon(g.key), size: 17, color: C.inkMute),
                        const SizedBox(width: 8),
                        Flexible(child: Text(g.label.toUpperCase(), style: T.sectionLabel)),
                      ],
                    ),
                  ),
                ),
                for (var i = 0; i < g.items.length; i++)
                  TickRow(
                    item: g.items[i],
                    first: i == 0,
                    enabled: !room.isFinished,
                    onToggle: () => repo?.tickItem(room, g.items[i].id, !g.items[i].checked),
                  ),
              ],
            ),
          ),
        ),
    ];
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.detail});

  final RoomDetail? detail;

  @override
  Widget build(BuildContext context) {
    final r = detail?.room;
    return Padding(
      padding: const EdgeInsets.fromLTRB(6, 8, 16, 0),
      child: Row(
        children: [
          BackCircle(onTap: () => context.canPop() ? context.pop() : context.go('/home')),
          const SizedBox(width: 6),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (r != null) Eyebrow(roomEyebrow(r)),
                Semantics(
                  header: true,
                  child: Text(r == null ? '' : S.roomTitle(r.number), style: T.roomTitle),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class SentBackBanner extends StatelessWidget {
  const SentBackBanner({super.key, required this.sentBack});

  final SentBack sentBack;

  @override
  Widget build(BuildContext context) {
    final reasons = sentBack.reasonsLabel;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      child: AppCard(
        color: C.toCleanBg,
        border: false,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(LucideIcons.undo2, size: 18, color: C.toCleanFg),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    S.sentBackBy(sentBack.by, hm(sentBack.at)),
                    style: const TextStyle(fontSize: 14.5, fontWeight: FontWeight.w600, color: C.toCleanFg),
                  ),
                ),
              ],
            ),
            if (reasons.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                reasons[0].toUpperCase() + reasons.substring(1),
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: C.ink),
              ),
            ],
            if (sentBack.note?.isNotEmpty ?? false) ...[
              const SizedBox(height: 4),
              Text('"${sentBack.note}"', style: const TextStyle(fontSize: 15, color: C.inkSoft)),
            ],
          ],
        ),
      ),
    );
  }
}

class TickRow extends StatelessWidget {
  const TickRow({super.key, required this.item, required this.first, required this.enabled, required this.onToggle});

  final ChecklistItem item;
  final bool first;
  final bool enabled;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final checked = item.checked;
    return Semantics(
      checked: checked,
      enabled: enabled,
      label: item.label,
      hint: checked ? S.untick(item.label) : S.tick(item.label),
      excludeSemantics: true,
      child: InkWell(
        key: Key('tick-${item.id}'),
        onTap: enabled ? onToggle : null,
        child: Container(
          constraints: const BoxConstraints(minHeight: 64),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          decoration: BoxDecoration(
            border: first ? null : const Border(top: BorderSide(color: C.lineSoft)),
          ),
          child: Row(
            children: [
              AnimatedContainer(
                duration: const Duration(milliseconds: 150),
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: checked ? C.accent : Colors.transparent,
                  border: checked ? null : Border.all(color: C.tickRing, width: 2),
                ),
                child: checked ? const Icon(LucideIcons.check, size: 22, color: Colors.white) : null,
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Text(
                  item.label,
                  style: checked
                      ? const TextStyle(
                          fontSize: 16,
                          color: C.inkMute,
                          decoration: TextDecoration.lineThrough,
                          decorationColor: C.inkMute,
                        )
                      : const TextStyle(fontSize: 16, fontWeight: FontWeight.w500, color: C.ink),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// "Anything broken or missing?" Pops with the note ('' for none), or
/// null when dismissed.
class FinishSheet extends StatefulWidget {
  const FinishSheet({super.key, required this.unticked});

  final int unticked;

  @override
  State<FinishSheet> createState() => _FinishSheetState();
}

class _FinishSheetState extends State<FinishSheet> {
  final _note = TextEditingController();

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
              const Text(S.anythingBroken,
                  style: TextStyle(fontFamily: F.display, fontSize: 24, fontWeight: FontWeight.w600),),
              const SizedBox(height: 4),
              const Text(S.anythingBrokenBody, style: T.sub),
              if (widget.unticked > 0) ...[
                const SizedBox(height: 14),
                Container(
                  key: const Key('unticked-warning'),
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  decoration: BoxDecoration(color: C.warnBg, borderRadius: BorderRadius.circular(16)),
                  child: Row(
                    children: [
                      const Icon(LucideIcons.triangleAlert, size: 18, color: C.warnFg),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          S.itemsNotTicked(widget.unticked),
                          style: const TextStyle(fontWeight: FontWeight.w600, color: C.warnFg),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
              const SizedBox(height: 16),
              TextField(
                key: const Key('finish-note'),
                controller: _note,
                minLines: 2,
                maxLines: 4,
                maxLength: 500,
                textCapitalization: TextCapitalization.sentences,
                decoration: const InputDecoration(hintText: S.noteHint, counterText: ''),
              ),
              const SizedBox(height: 16),
              ActionPill(
                key: const Key('send-to-porter'),
                label: S.sendToPorter,
                icon: LucideIcons.send,
                onTap: () => Navigator.of(context).pop(_note.text.trim()),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
