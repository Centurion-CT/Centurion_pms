// Role-filtered queue mirroring the housekeeping-home dashboard on
// web. Streams from the local cache so cold-start renders instantly
// and offline sessions have last-known data. Pull-to-refresh calls
// the repo directly for explicit retry with error feedback.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/auth/session_store.dart';
import '../../core/outbox/outbox_providers.dart';
import '../../core/theme/keverna_theme.dart';
import 'inspection_models.dart';
import 'inspections_repository.dart';

class InspectionsQueueScreen extends ConsumerStatefulWidget {
  const InspectionsQueueScreen({super.key});

  @override
  ConsumerState<InspectionsQueueScreen> createState() => _InspectionsQueueScreenState();
}

class _InspectionsQueueScreenState extends ConsumerState<InspectionsQueueScreen> {
  bool _hasEverSynced = false;

  Future<void> _refresh() async {
    final repo = ref.read(inspectionsRepositoryProvider);
    if (repo == null) return;
    try {
      await repo.refreshQueue();
      setState(() => _hasEverSynced = true);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Refresh failed — showing cached list. ($e)'),
        backgroundColor: KevernaColors.danger,
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = ref.watch(sessionProvider);
    final rows = ref.watch(inspectionsQueueProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Housekeeping queue'),
        actions: [
          IconButton(
            icon: const Icon(Icons.person_outline),
            tooltip: 'Signed in as ${session?.operatorName ?? ''}',
            onPressed: () => _showSignOutSheet(context, ref),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: rows.when(
          data: (list) => list.isEmpty ? _emptyState(context) : _list(context, list),
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => Center(child: Text('Load failed: $e')),
        ),
      ),
    );
  }

  Widget _list(BuildContext context, List<InspectionSummary> rows) {
    return ListView.separated(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      itemCount: rows.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (context, i) => _row(context, rows[i]),
    );
  }

  Widget _row(BuildContext context, InspectionSummary ins) {
    final subtitle = <String>[
      'opened ${_relative(ins.createdAt)}',
      if (ins.triggeredByGuest != null)
        'after ${ins.triggeredByGuest} (${ins.triggeredByRef ?? ''})',
    ].join(' · ');

    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () => context.push('/housekeeping/inspections/${ins.id}'),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text.rich(
                      TextSpan(children: [
                        TextSpan(text: 'Rm ${ins.roomNumber ?? '—'}'),
                        TextSpan(
                          text: '  ·  ${ins.categoryName ?? 'Uncategorised'}',
                          style: TextStyle(color: KevernaColors.inkMute, fontWeight: FontWeight.w400),
                        ),
                      ]),
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 4),
                    Text(subtitle, style: Theme.of(context).textTheme.bodySmall),
                  ],
                ),
              ),
              const Icon(Icons.arrow_forward_ios, size: 14, color: KevernaColors.inkMute),
            ],
          ),
        ),
      ),
    );
  }

  Widget _emptyState(BuildContext context) {
    return ListView(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 80, 24, 40),
          child: Column(
            children: [
              const Icon(Icons.check_circle_outline, size: 48, color: KevernaColors.success),
              const SizedBox(height: 12),
              Text('Nothing waiting on you.',
                  style: Theme.of(context).textTheme.headlineMedium, textAlign: TextAlign.center),
              const SizedBox(height: 8),
              Text('Pull down to refresh.',
                  style: Theme.of(context).textTheme.bodyMedium, textAlign: TextAlign.center),
            ],
          ),
        ),
      ],
    );
  }

  void _showSignOutSheet(BuildContext context, WidgetRef ref) {
    showModalBottomSheet<void>(
      context: context,
      builder: (_) => SafeArea(
        child: ListTile(
          leading: const Icon(Icons.logout, color: KevernaColors.danger),
          title: const Text('Sign out & unlink this phone'),
          onTap: () async {
            Navigator.of(context).pop();
            // Nuke cache first so the next operator paired on this
            // device doesn't see the previous session's rows.
            // Outbox stays put (in-flight writes still belong to
            // the signed-out operator server-side).
            await ref.read(localDbProvider).clearCache();
            await ref.read(sessionProvider.notifier).clear();
          },
        ),
      ),
    );
  }

  String _relative(DateTime t) {
    final d = DateTime.now().difference(t);
    if (d.inMinutes < 1) return 'just now';
    if (d.inMinutes < 60) return '${d.inMinutes}m ago';
    if (d.inHours < 24) return '${d.inHours}h ago';
    return DateFormat('d MMM · HH:mm').format(t);
  }
}
