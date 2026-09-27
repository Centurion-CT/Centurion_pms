// Mobile Home tab.
//
// Manager / Frontdesk / Reservation Officer see a glance dashboard
// with today's KPIs + attention lists. Everyone else gets a
// minimal welcome card pointing them at the tab that matters for
// their role (housekeepers → Housekeeping, PosStaff → POS, etc.).
//
// KPI cards deep-link into the relevant tab so tapping "Arrivals
// today" jumps to the Reservations tab on the arrivals filter.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/auth/session_store.dart';
import '../../core/outbox/outbox_providers.dart';
import '../../core/theme/keverna_theme.dart';
import 'glance_models.dart';
import 'glance_repository.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  static const _managerRoles = {'manager', 'frontdesk', 'reservation_officer'};

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);
    final role = session?.role ?? '';
    if (_managerRoles.contains(role)) {
      return _ManagerGlance();
    }
    return _RoleWelcome(session: session);
  }
}

// ── Manager glance ────────────────────────────────────────────

class _ManagerGlance extends ConsumerStatefulWidget {
  @override
  ConsumerState<_ManagerGlance> createState() => _ManagerGlanceState();
}

class _ManagerGlanceState extends ConsumerState<_ManagerGlance> {
  Future<void> _refresh() async {
    final repo = ref.read(glanceRepositoryProvider);
    if (repo == null) return;
    try {
      await repo.refresh();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Refresh failed — showing cached numbers. ($e)'),
        backgroundColor: KevernaColors.danger,
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = ref.watch(sessionProvider);
    final glance = ref.watch(managerGlanceProvider);
    final firstName = (session?.operatorName ?? '').split(' ').first;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Today'),
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
        child: glance.when(
          data: (g) => g == null
              ? ListView(children: const [
                  Padding(
                    padding: EdgeInsets.fromLTRB(24, 100, 24, 40),
                    child: Center(child: CircularProgressIndicator()),
                  ),
                ])
              : _body(context, g, firstName),
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => Center(child: Text('Load failed: $e')),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, ManagerGlance g, String firstName) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        // Welcome line + as-of
        RichText(
          text: TextSpan(
            style: Theme.of(context).textTheme.displaySmall,
            children: [
              const TextSpan(text: 'Welcome'),
              if (firstName.isNotEmpty)
                TextSpan(text: ', $firstName',
                    style: const TextStyle(color: KevernaColors.accentDeep)),
              const TextSpan(text: '.'),
            ],
          ),
        ),
        const SizedBox(height: 4),
        Text(_contextLine(g),
            style: Theme.of(context).textTheme.bodyMedium),
        const SizedBox(height: 20),

        // ── KPI grid ──
        GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          childAspectRatio: 1.5,
          crossAxisSpacing: 10,
          mainAxisSpacing: 10,
          children: [
            _kpiCard(
              label: 'Arrivals today',
              value: '${g.kpis.arrivalsToday}',
              icon: Icons.flight_land,
              onTap: () => context.go('/reservations'),
            ),
            _kpiCard(
              label: 'Departures today',
              value: '${g.kpis.departuresToday}',
              icon: Icons.flight_takeoff,
              onTap: () => context.go('/reservations'),
            ),
            _kpiCard(
              label: 'In-house now',
              value: '${g.kpis.inHouse}',
              icon: Icons.hotel_outlined,
              onTap: () => context.go('/reservations'),
            ),
            _kpiCard(
              label: 'Outstanding',
              value: '₦${_money(g.kpis.outstandingTotal)}',
              icon: Icons.account_balance_wallet_outlined,
              highlight: g.kpis.outstandingTotal > 0,
              onTap: () => context.go('/reservations'),
            ),
            _kpiCard(
              label: 'Open inspections',
              value: '${g.kpis.openInspections}',
              icon: Icons.cleaning_services_outlined,
              onTap: () => context.go('/housekeeping'),
            ),
            _kpiCard(
              label: 'Rejected',
              value: '${g.kpis.rejectedInspections}',
              icon: Icons.report_gmailerrorred_outlined,
              highlight: g.kpis.rejectedInspections > 0,
              onTap: () => context.go('/housekeeping'),
            ),
            _kpiCard(
              label: 'Today · POS',
              value: '₦${_money(g.kpis.todayPosRevenue)}',
              icon: Icons.point_of_sale,
              onTap: () => context.go('/pos'),
            ),
            _kpiCard(
              label: 'Pending stock',
              value: '${g.kpis.pendingStockRequests}',
              icon: Icons.inventory_2_outlined,
              highlight: g.kpis.pendingStockRequests > 0,
              onTap: () => context.go('/pos/stock-requests'),
            ),
          ],
        ),

        // ── Attention lists ──
        if (g.bookingsNeedingAction.isNotEmpty) ...[
          const SizedBox(height: 24),
          _sectionTitle('Bookings needing action'),
          ...g.bookingsNeedingAction.map((b) => Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  onTap: () => context.push('/reservations/${b.bookingRef}'),
                  title: Text(b.guestName),
                  subtitle: Text(
                    '${b.bookingRef} · ${b.status.replaceAll('_', ' ')}'
                    '${b.checkinDate != null ? ' · ${DateFormat('d MMM').format(b.checkinDate!)}' : ''}',
                  ),
                  trailing: b.balanceDue > 0
                      ? Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: KevernaColors.danger.withValues(alpha: 0.1),
                            borderRadius: BorderRadius.circular(999),
                          ),
                          child: Text('₦${_money(b.balanceDue)}',
                              style: const TextStyle(
                                color: KevernaColors.danger,
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                              )),
                        )
                      : const Icon(Icons.arrow_forward_ios,
                          size: 14, color: KevernaColors.inkMute),
                ),
              )),
        ],

        if (g.rejectedInspections.isNotEmpty) ...[
          const SizedBox(height: 20),
          _sectionTitle('Rejected inspections'),
          ...g.rejectedInspections.map((i) => Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  onTap: () => context.push('/housekeeping/inspections/${i.id}'),
                  title: Text('Rm ${i.roomNumber ?? '—'} · ${i.categoryName ?? '—'}'),
                  subtitle: i.summaryNote != null && i.summaryNote!.isNotEmpty
                      ? Text('⚠ ${i.summaryNote}',
                          style: const TextStyle(color: KevernaColors.danger))
                      : null,
                  trailing: const Icon(Icons.arrow_forward_ios,
                      size: 14, color: KevernaColors.inkMute),
                ),
              )),
        ],

        if (g.pendingStockRequests.isNotEmpty) ...[
          const SizedBox(height: 20),
          _sectionTitle('Pending stock requests'),
          ...g.pendingStockRequests.map((r) => Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  onTap: () => context.go('/pos/stock-requests'),
                  title: Text('${r.quantity} × ${r.productName ?? '—'}'),
                  subtitle: Text('→ ${r.outletName ?? '—'}'),
                  trailing: const Icon(Icons.arrow_forward_ios,
                      size: 14, color: KevernaColors.inkMute),
                ),
              )),
        ],

        const SizedBox(height: 30),
        Center(
          child: Text('As of ${_relative(g.asOf)}',
              style: Theme.of(context).textTheme.bodySmall),
        ),
        const SizedBox(height: 20),
      ],
    );
  }

  Widget _kpiCard({
    required String label,
    required String value,
    required IconData icon,
    required VoidCallback onTap,
    bool highlight = false,
  }) {
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Icon(icon,
                      size: 20,
                      color: highlight ? KevernaColors.danger : KevernaColors.inkMute),
                  const Icon(Icons.arrow_forward_ios,
                      size: 10, color: KevernaColors.inkMute),
                ],
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(value,
                      style: TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w700,
                        color: highlight ? KevernaColors.danger : KevernaColors.ink,
                      )),
                  const SizedBox(height: 2),
                  Text(label,
                      style: const TextStyle(fontSize: 12, color: KevernaColors.inkSoft),
                      maxLines: 1, overflow: TextOverflow.ellipsis),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _sectionTitle(String label) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8, top: 4),
      child: Text(label.toUpperCase(),
          style: Theme.of(context).textTheme.labelSmall),
    );
  }

  String _contextLine(ManagerGlance g) {
    final bits = <String>[];
    if (g.kpis.arrivalsToday > 0) {
      bits.add('${g.kpis.arrivalsToday} ${g.kpis.arrivalsToday == 1 ? 'arrival' : 'arrivals'}');
    }
    if (g.kpis.departuresToday > 0) {
      bits.add(
          '${g.kpis.departuresToday} ${g.kpis.departuresToday == 1 ? 'departure' : 'departures'}');
    }
    if (g.kpis.outstandingTotal > 0) {
      bits.add('₦${_money(g.kpis.outstandingTotal)} outstanding');
    }
    if (bits.isEmpty) return 'A quiet day so far.';
    return '${bits.join(' · ')}.';
  }

  String _money(int amount) => NumberFormat.decimalPattern().format(amount);

  String _relative(DateTime t) {
    final d = DateTime.now().difference(t);
    if (d.inMinutes < 1) return 'just now';
    if (d.inMinutes < 60) return '${d.inMinutes}m ago';
    if (d.inHours < 24) return '${d.inHours}h ago';
    return DateFormat('d MMM · HH:mm').format(t);
  }
}

// ── Non-manager welcome ───────────────────────────────────────

class _RoleWelcome extends ConsumerWidget {
  const _RoleWelcome({required this.session});

  final Session? session;

  static const _roleTabHint = {
    'house_cleaner': (label: 'Housekeeping', route: '/housekeeping', icon: Icons.cleaning_services),
    'porter': (label: 'Housekeeping', route: '/housekeeping', icon: Icons.cleaning_services),
    'supervisor': (label: 'Housekeeping', route: '/housekeeping', icon: Icons.cleaning_services),
    'pos_staff': (label: 'POS', route: '/pos', icon: Icons.point_of_sale),
    'inventory_clerk': (label: 'POS', route: '/pos', icon: Icons.inventory_2_outlined),
    'stock_keeper': (label: 'POS', route: '/pos', icon: Icons.inventory_2_outlined),
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final role = session?.role ?? '';
    final firstName = (session?.operatorName ?? '').split(' ').first;
    final hint = _roleTabHint[role];

    return Scaffold(
      appBar: AppBar(
        title: const Text('Home'),
        actions: [
          IconButton(
            icon: const Icon(Icons.person_outline),
            tooltip: 'Signed in as ${session?.operatorName ?? ''}',
            onPressed: () => _showSignOutSheet(context, ref),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          const SizedBox(height: 24),
          RichText(
            text: TextSpan(
              style: Theme.of(context).textTheme.displaySmall,
              children: [
                const TextSpan(text: 'Welcome'),
                if (firstName.isNotEmpty)
                  TextSpan(text: ', $firstName',
                      style: const TextStyle(color: KevernaColors.accentDeep)),
                const TextSpan(text: '.'),
              ],
            ),
          ),
          const SizedBox(height: 8),
          Text(_roleLabel(role),
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    letterSpacing: 1.2,
                    textBaseline: TextBaseline.alphabetic,
                  )),
          const SizedBox(height: 30),
          if (hint != null)
            Card(
              child: ListTile(
                leading: Icon(hint.icon, color: KevernaColors.accentDeep),
                title: Text('Your day is on the ${hint.label} tab.'),
                subtitle: const Text('Tap to jump straight there.'),
                trailing: const Icon(Icons.arrow_forward_ios,
                    size: 14, color: KevernaColors.inkMute),
                onTap: () => context.go(hint.route),
              ),
            )
          else
            Text(
              'Your role does not have a dedicated dashboard yet — '
              'ask a manager if you need one added.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
        ],
      ),
    );
  }

  String _roleLabel(String role) {
    return role.replaceAll('_', ' ').toUpperCase();
  }
}

// ── Sign-out sheet (shared) ───────────────────────────────────

void _showSignOutSheet(BuildContext context, WidgetRef ref) {
  showModalBottomSheet<void>(
    context: context,
    builder: (_) => SafeArea(
      child: Consumer(
        builder: (context, sheetRef, _) => ListTile(
          leading: const Icon(Icons.logout, color: KevernaColors.danger),
          title: const Text('Sign out & unlink this phone'),
          onTap: () async {
            Navigator.of(context).pop();
            // Nuke cache first so the next operator paired on this
            // device doesn't see the previous session's rows.
            await sheetRef.read(localDbProvider).clearCache();
            await sheetRef.read(sessionProvider.notifier).clear();
          },
        ),
      ),
    ),
  );
}
