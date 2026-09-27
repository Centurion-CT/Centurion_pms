// Reservations tab — 3 sub-tabs (Arrivals · In-house · Departures).
// Streams cached rows from Drift; pull-to-refresh calls the repo.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/auth/role_visibility.dart';
import '../../core/auth/session_store.dart';
import '../../core/theme/keverna_theme.dart';
import 'reservation_models.dart';
import 'reservations_repository.dart';

class ReservationsScreen extends ConsumerStatefulWidget {
  const ReservationsScreen({super.key});

  @override
  ConsumerState<ReservationsScreen> createState() => _ReservationsScreenState();
}

class _ReservationsScreenState extends ConsumerState<ReservationsScreen>
    with SingleTickerProviderStateMixin {
  static const _filters = ['arrivals', 'in_house', 'departures'];
  static const _labels = ['Arrivals', 'In-house', 'Departures'];

  late final TabController _tabs;

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: _filters.length, vsync: this);
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  Future<void> _refresh(String filter) async {
    final repo = ref.read(reservationsRepositoryProvider);
    if (repo == null) return;
    try {
      await repo.refreshList(filter);
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
    // Only show the "New booking" FAB for roles the server will accept
    // (BookingsController::ALLOWED_ROLES). Porters + housekeepers can
    // still open this tab to see who's arriving / where they are, but
    // shouldn't be shown a button that just 403s.
    final role = ref.watch(sessionProvider)?.role;
    final canCreate = RoleVisibility.forRole(role).canCreateBooking;

    return DefaultTabController(
      length: _filters.length,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Reservations'),
          bottom: TabBar(
            controller: _tabs,
            tabs: _labels.map((l) => Tab(text: l)).toList(),
            labelColor: KevernaColors.ink,
            unselectedLabelColor: KevernaColors.inkMute,
            indicatorColor: KevernaColors.accentDeep,
          ),
        ),
        body: TabBarView(
          controller: _tabs,
          children: _filters.map((f) => _tab(f)).toList(),
        ),
        floatingActionButton: canCreate
            ? FloatingActionButton.extended(
                onPressed: () => context.push('/reservations/create'),
                backgroundColor: KevernaColors.accent,
                foregroundColor: KevernaColors.ink,
                icon: const Icon(Icons.add),
                label: const Text('New booking'),
              )
            : null,
      ),
    );
  }

  Widget _tab(String filter) {
    final async = ref.watch(reservationsListProvider(filter));
    return RefreshIndicator(
      onRefresh: () => _refresh(filter),
      child: async.when(
        data: (rows) => rows.isEmpty ? _empty(context, filter) : _list(rows),
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: Text('Load failed: $e')),
      ),
    );
  }

  Widget _list(List<ReservationSummary> rows) {
    return ListView.separated(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      itemCount: rows.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (context, i) => _row(context, rows[i]),
    );
  }

  Widget _row(BuildContext context, ReservationSummary r) {
    final subtitle = <String>[
      r.roomNumber != null ? 'Rm ${r.roomNumber}' : 'Unassigned',
      if (r.checkinDate != null && r.checkoutDate != null)
        '${DateFormat('d MMM').format(r.checkinDate!)} → ${DateFormat('d MMM').format(r.checkoutDate!)}',
    ].join(' · ');

    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () => context.push('/reservations/${r.bookingRef}'),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(r.guestName,
                        style: Theme.of(context).textTheme.titleMedium,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 4),
                    Text(subtitle, style: Theme.of(context).textTheme.bodySmall),
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        _statusChip(r.status, r.statusLabel),
                        if (r.balanceDue > 0) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: KevernaColors.danger.withValues(alpha: 0.1),
                              borderRadius: BorderRadius.circular(999),
                            ),
                            child: Text('₦${_money(r.balanceDue)} owed',
                                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                      color: KevernaColors.danger,
                                      fontWeight: FontWeight.w600,
                                    )),
                          ),
                        ],
                      ],
                    ),
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

  Widget _statusChip(String status, String label) {
    final palette = switch (status) {
      'confirmed' || 'checked_in' => (bg: KevernaColors.success.withValues(alpha: 0.12), fg: KevernaColors.success),
      'on_hold' || 'pending' => (bg: KevernaColors.warn.withValues(alpha: 0.14), fg: const Color(0xFF92400E)),
      'checked_out' => (bg: KevernaColors.cardDeep, fg: KevernaColors.inkSoft),
      'cancelled' || 'no_show' => (bg: KevernaColors.danger.withValues(alpha: 0.10), fg: KevernaColors.danger),
      _ => (bg: KevernaColors.cardDeep, fg: KevernaColors.inkSoft),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: palette.bg, borderRadius: BorderRadius.circular(999)),
      child: Text(label,
          style: TextStyle(color: palette.fg, fontSize: 11, fontWeight: FontWeight.w600)),
    );
  }

  Widget _empty(BuildContext context, String filter) {
    final blurb = switch (filter) {
      'arrivals' => 'No arrivals expected today.',
      'in_house' => 'Nobody in-house right now.',
      'departures' => 'No departures scheduled today.',
      _ => 'Nothing to show.',
    };
    return ListView(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 80, 24, 40),
          child: Column(
            children: [
              const Icon(Icons.hotel_outlined, size: 44, color: KevernaColors.inkMute),
              const SizedBox(height: 12),
              Text(blurb,
                  style: Theme.of(context).textTheme.headlineMedium,
                  textAlign: TextAlign.center),
              const SizedBox(height: 6),
              Text('Pull down to refresh.', style: Theme.of(context).textTheme.bodyMedium),
            ],
          ),
        ),
      ],
    );
  }

  String _money(int amount) {
    return NumberFormat.decimalPattern().format(amount);
  }
}
