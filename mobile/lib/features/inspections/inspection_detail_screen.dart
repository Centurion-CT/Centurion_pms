// Per-inspection screen — housekeeper's / porter's / supervisor's
// action surface. Streams from the local cache so cold-open renders
// the last-seen state instantly. Writes go through MutationService.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/outbox/mutation_service.dart';
import '../../core/outbox/outbox_providers.dart';
import '../../core/theme/keverna_theme.dart';
import 'inspection_models.dart';
import 'inspections_repository.dart';

class InspectionDetailScreen extends ConsumerStatefulWidget {
  const InspectionDetailScreen({super.key, required this.id});

  final int id;

  @override
  ConsumerState<InspectionDetailScreen> createState() => _InspectionDetailScreenState();
}

class _InspectionDetailScreenState extends ConsumerState<InspectionDetailScreen> {
  // Local edit buffer — flags + damage notes that the operator has
  // typed but not yet submitted. Merged with the cache stream on
  // render so the operator's in-flight edits survive a stream tick.
  final Map<int, bool> _pendingChecks = {};
  final Map<int, String?> _pendingDamage = {};
  final _summaryCtrl = TextEditingController();
  bool _summaryEdited = false;
  bool _busy = false;
  String? _busyLabel;

  @override
  void dispose() {
    _summaryCtrl.dispose();
    super.dispose();
  }

  Future<void> _run(
    Future<InspectionDetail> Function() action,
    String label, {
    String? queuedMessage,
  }) async {
    setState(() {
      _busy = true;
      _busyLabel = label;
    });
    try {
      await action();
      _pendingChecks.clear();
      _pendingDamage.clear();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$label — done.')),
        );
      }
    } on QueuedForRetry {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(queuedMessage ?? 'Saved locally — will sync when back online.'),
          backgroundColor: KevernaColors.accentDeep,
        ));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('$label failed: $e'),
          backgroundColor: KevernaColors.danger,
        ));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Apply local edits on top of the cache row before render.
  InspectionDetail _merge(InspectionDetail base) {
    final merged = base.items.map((item) {
      final checked = _pendingChecks[item.id] ?? item.checked;
      final damage = _pendingDamage.containsKey(item.id)
          ? _pendingDamage[item.id]
          : item.damageNote;
      return InspectionItem(
        id: item.id,
        label: item.label,
        checked: checked,
        damageNote: damage,
        sortOrder: item.sortOrder,
      );
    }).toList();
    return InspectionDetail(
      id: base.id,
      status: base.status,
      statusLabel: base.statusLabel,
      readinessScore: base.readinessScore,
      items: merged,
      roomNumber: base.roomNumber,
      categoryName: base.categoryName,
      summaryNote: base.summaryNote,
    );
  }

  @override
  Widget build(BuildContext context) {
    final repo = ref.watch(inspectionsRepositoryProvider);
    final async = ref.watch(inspectionDetailProvider(widget.id));

    return Scaffold(
      appBar: AppBar(title: const Text('Inspection')),
      body: async.when(
        data: (cached) {
          if (cached == null) {
            return const Center(child: CircularProgressIndicator());
          }
          if (!_summaryEdited && cached.summaryNote != null) {
            _summaryCtrl.text = cached.summaryNote!;
          }
          return _detail(_merge(cached), repo);
        },
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: Text('Load failed: $e')),
      ),
    );
  }

  Widget _detail(InspectionDetail ins, InspectionsRepository? repo) {
    final syncing =
        ref.watch(inspectionHasPendingWriteProvider(ins.id)).valueOrNull ?? false;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('Rm ${ins.roomNumber ?? '—'}', style: Theme.of(context).textTheme.displaySmall),
        Text(ins.categoryName ?? '—', style: Theme.of(context).textTheme.bodyMedium),
        const SizedBox(height: 12),
        Row(
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(
                color: KevernaColors.cardDeep,
                borderRadius: BorderRadius.circular(999),
              ),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                Text(ins.statusLabel, style: Theme.of(context).textTheme.bodySmall),
                if (ins.readinessScore > 0) ...[
                  const SizedBox(width: 8),
                  Text('· ${ins.readinessScore}/100',
                      style: Theme.of(context).textTheme.bodySmall),
                ],
              ]),
            ),
            if (syncing) ...const [
              SizedBox(width: 8),
              _SyncingChip(),
            ],
          ],
        ),
        const SizedBox(height: 22),

        Text('Checklist', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        if (ins.items.isEmpty)
          Text('No items on this inspection yet.',
              style: Theme.of(context).textTheme.bodyMedium)
        else
          ...ins.items.map((item) => _itemTile(item, ins)),

        const SizedBox(height: 20),
        Text('Summary / hand-over note', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        TextField(
          controller: _summaryCtrl,
          maxLines: 3,
          maxLength: 2000,
          onChanged: (_) => _summaryEdited = true,
          decoration: const InputDecoration(
            hintText: 'e.g. Deep clean done, lamp bulb swapped, mini-bar restocked.',
          ),
        ),
        const SizedBox(height: 20),
        _actionButtons(ins, repo),
      ],
    );
  }

  Widget _itemTile(InspectionItem item, InspectionDetail ins) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        child: Column(
          children: [
            CheckboxListTile(
              value: item.checked,
              onChanged: ins.canTick
                  ? (v) => setState(() => _pendingChecks[item.id] = v ?? false)
                  : null,
              title: Text(item.label,
                  style: TextStyle(
                    decoration: item.checked ? TextDecoration.lineThrough : null,
                    color: item.checked ? KevernaColors.inkMute : KevernaColors.ink,
                  )),
              controlAffinity: ListTileControlAffinity.leading,
              contentPadding: EdgeInsets.zero,
              activeColor: KevernaColors.accentDeep,
            ),
            if (ins.canTick)
              TextField(
                controller: TextEditingController(text: item.damageNote ?? '')
                  ..selection = TextSelection.collapsed(offset: (item.damageNote ?? '').length),
                onChanged: (v) => _pendingDamage[item.id] = v.isEmpty ? null : v,
                style: Theme.of(context).textTheme.bodyMedium,
                decoration: const InputDecoration(
                  hintText: 'Damaged / missing? Note it here…',
                  isDense: true,
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _actionButtons(InspectionDetail ins, InspectionsRepository? repo) {
    if (repo == null) return const SizedBox.shrink();
    final roomLabel = 'Rm ${ins.roomNumber ?? '—'}';
    final summary = _summaryCtrl.text.trim().isEmpty ? null : _summaryCtrl.text.trim();

    final buttons = <Widget>[];
    if (ins.status == 'pending' || ins.status == 'cleaning_done') {
      buttons.add(ElevatedButton(
        onPressed: _busy
            ? null
            : () => _run(
                  () => repo.submit(ins.id, items: ins.items, summaryNote: summary, roomLabel: roomLabel),
                  'Submit for porter',
                ),
        child: Text(_busyLabel == 'Submit for porter' ? '…' : 'Submit for porter'),
      ));
    }
    if (ins.status == 'cleaning_done') {
      buttons.addAll([
        _dangerButton('Reject',
            () => repo.verify(ins.id, accept: false, summaryNote: summary, roomLabel: roomLabel),
            'Reject'),
        ElevatedButton(
          onPressed: _busy
              ? null
              : () => _run(
                    () => repo.verify(ins.id, accept: true, roomLabel: roomLabel),
                    'Verify',
                  ),
          child: const Text('Verify → supervisor'),
        ),
      ]);
    }
    if (ins.status == 'porter_verified') {
      buttons.addAll([
        _dangerButton('Reject',
            () => repo.signOff(ins.id, accept: false, summaryNote: summary, roomLabel: roomLabel),
            'Reject'),
        ElevatedButton(
          onPressed: _busy
              ? null
              : () => _run(
                    () => repo.signOff(ins.id, accept: true, roomLabel: roomLabel),
                    'Sign off',
                  ),
          child: const Text('Sign off — clear for guests'),
        ),
      ]);
    }

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      alignment: WrapAlignment.end,
      children: buttons,
    );
  }

  Widget _dangerButton(String label, Future<InspectionDetail> Function() action, String runLabel) {
    return TextButton(
      onPressed: _busy ? null : () => _run(action, runLabel),
      style: TextButton.styleFrom(foregroundColor: KevernaColors.danger),
      child: Text(label),
    );
  }
}

/// Small "Syncing…" pill that appears while an outbox entry
/// targeting this inspection is still in flight.
class _SyncingChip extends StatelessWidget {
  const _SyncingChip();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: KevernaColors.accent.withValues(alpha: 0.28),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: const [
          SizedBox(
            width: 10, height: 10,
            child: CircularProgressIndicator(
              strokeWidth: 1.6,
              color: KevernaColors.accentDeep,
            ),
          ),
          SizedBox(width: 6),
          Text('Syncing…',
              style: TextStyle(
                color: KevernaColors.accentDeep,
                fontSize: 12, fontWeight: FontWeight.w700,
              )),
        ],
      ),
    );
  }
}
