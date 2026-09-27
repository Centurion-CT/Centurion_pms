// Sync status + failed-writes review.
//
// - Top panel: live pending count (things still trying to sync).
// - Below: parked entries — mutations the server refused with a 4xx.
//   Each has a Retry (unpark + kick the drainer, usually after the
//   operator has fixed whatever the server complained about) and a
//   Discard (delete the row; the change is lost, but so is the guilt
//   of a bad tap sitting in the queue forever).

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/outbox/outbox_providers.dart';
import '../../core/storage/local_db.dart';
import '../../core/theme/keverna_theme.dart';

class OutboxScreen extends ConsumerWidget {
  const OutboxScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pending = ref.watch(outboxPendingCountProvider).valueOrNull ?? 0;
    final parked = ref.watch(outboxParkedProvider).valueOrNull ?? const <OutboxEntry>[];

    return Scaffold(
      appBar: AppBar(title: const Text('Sync outbox')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _pendingCard(context, pending),
          const SizedBox(height: 16),
          Text('Rejected by server',
              style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(
            'The server refused these writes (4xx). Fix the underlying issue,'
            ' then Retry — or Discard if the change no longer matters.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 12),
          if (parked.isEmpty)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text('Nothing rejected. Everything you\'ve done has synced or is still trying.',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodyMedium),
              ),
            )
          else
            ...parked.map((e) => _parkedRow(context, ref, e)),
        ],
      ),
    );
  }

  Widget _pendingCard(BuildContext context, int pending) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Icon(
              pending == 0 ? Icons.check_circle_outline : Icons.sync,
              size: 28,
              color: pending == 0 ? KevernaColors.success : KevernaColors.accentDeep,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    pending == 0
                        ? 'All synced'
                        : '$pending ${pending == 1 ? 'write' : 'writes'} waiting to sync',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  Text(
                    pending == 0
                        ? 'Nothing queued.'
                        : 'They\'ll go through automatically when connectivity is stable.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _parkedRow(BuildContext context, WidgetRef ref, OutboxEntry entry) {
    final dao = ref.read(outboxDaoProvider);
    final worker = ref.read(outboxWorkerProvider);

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(entry.hint ?? entry.endpoint,
                style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(
              'Attempted ${_relative(entry.lastAttemptedAt)} · retries: ${entry.retryCount}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (entry.lastError != null) ...[
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: KevernaColors.danger.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(entry.lastError!,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(color: KevernaColors.danger)),
              ),
            ],
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: () async {
                    final confirmed = await showDialog<bool>(
                          context: context,
                          builder: (_) => AlertDialog(
                            title: const Text('Discard this write?'),
                            content: const Text('The change will be lost. This can\'t be undone.'),
                            actions: [
                              TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep')),
                              TextButton(
                                onPressed: () => Navigator.pop(context, true),
                                style: TextButton.styleFrom(foregroundColor: KevernaColors.danger),
                                child: const Text('Discard'),
                              ),
                            ],
                          ),
                        ) ??
                        false;
                    if (!confirmed) return;
                    await dao.delete(entry.id);
                  },
                  style: TextButton.styleFrom(foregroundColor: KevernaColors.danger),
                  child: const Text('Discard'),
                ),
                const SizedBox(width: 6),
                ElevatedButton(
                  onPressed: () async {
                    await dao.unpark(entry.id);
                    await worker?.drain();
                  },
                  child: const Text('Retry'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  String _relative(DateTime? t) {
    if (t == null) return 'never';
    final d = DateTime.now().difference(t);
    if (d.inMinutes < 1) return 'just now';
    if (d.inMinutes < 60) return '${d.inMinutes}m ago';
    if (d.inHours < 24) return '${d.inHours}h ago';
    return DateFormat('d MMM · HH:mm').format(t);
  }
}
