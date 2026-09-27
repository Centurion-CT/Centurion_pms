// Stock requests screen — three tabs:
//   - Catalogue : browse the store, raise a request. Everyone.
//   - Pending   : approve/reject queue. Managers only (list still
//                 shown to non-managers as a read-only status view;
//                 they get their own outlet's pending items).
//   - Mine      : my recent requests + resolution status.
//
// Lives inside the POS tab (nested route). Reachable via a
// "Request stock" IconButton in the outlet-home app bar.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/auth/session_store.dart';
import '../../core/outbox/mutation_service.dart';
import '../../core/theme/keverna_theme.dart';
import '../pos/pos_models.dart' show Outlet, Product;
import '../pos/pos_repository.dart' show selectedOutletProvider;
import 'inventory_models.dart';
import 'inventory_repository.dart';

class StockRequestsScreen extends ConsumerWidget {
  const StockRequestsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);
    final isManager = session?.role == 'manager';
    final tabs = <String>['Catalogue', 'Pending review', 'Mine'];

    return DefaultTabController(
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Stock requests'),
          bottom: TabBar(
            tabs: tabs.map((l) => Tab(text: l)).toList(),
            labelColor: KevernaColors.ink,
            unselectedLabelColor: KevernaColors.inkMute,
            indicatorColor: KevernaColors.accentDeep,
          ),
        ),
        body: TabBarView(
          children: [
            const _CatalogueTab(),
            _PendingTab(isManager: isManager),
            const _MineTab(),
          ],
        ),
      ),
    );
  }
}

// ── Catalogue ────────────────────────────────────────────────

class _CatalogueTab extends ConsumerStatefulWidget {
  const _CatalogueTab();

  @override
  ConsumerState<_CatalogueTab> createState() => _CatalogueTabState();
}

class _CatalogueTabState extends ConsumerState<_CatalogueTab> {
  final _searchCtrl = TextEditingController();
  String? _query;

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final outlet = ref.watch(selectedOutletProvider);
    final catalogue = ref.watch(storeProductsProvider(_query));

    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(storeProductsProvider(_query)),
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (outlet == null)
            const Card(
              child: Padding(
                padding: EdgeInsets.all(14),
                child: Text(
                  'Pick an outlet from the POS tab first — stock requests need a target.',
                  style: TextStyle(color: KevernaColors.inkSoft),
                ),
              ),
            )
          else
            Text('Requesting for ${outlet.name}',
                style: Theme.of(context).textTheme.bodyMedium),
          const SizedBox(height: 12),

          TextField(
            controller: _searchCtrl,
            onChanged: (v) => setState(() => _query = v.trim().isEmpty ? null : v.trim()),
            decoration: InputDecoration(
              hintText: 'Search store catalogue…',
              prefixIcon: const Icon(Icons.search, size: 20),
              suffixIcon: _query != null
                  ? IconButton(
                      icon: const Icon(Icons.close, size: 18),
                      onPressed: () {
                        _searchCtrl.clear();
                        setState(() => _query = null);
                      },
                    )
                  : null,
              isDense: true,
            ),
          ),
          const SizedBox(height: 12),

          catalogue.when(
            data: (products) => products.isEmpty
                ? const Padding(
                    padding: EdgeInsets.all(20),
                    child: Text('Nothing in the store catalogue matched.',
                        style: TextStyle(color: KevernaColors.inkMute)),
                  )
                : Column(children: products.map((p) => _productRow(p, outlet)).toList()),
            loading: () => const Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: CircularProgressIndicator()),
            ),
            error: (e, _) => Text('Load failed: $e',
                style: const TextStyle(color: KevernaColors.danger)),
          ),
        ],
      ),
    );
  }

  Widget _productRow(Product p, Outlet? outlet) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        title: Text(p.name),
        subtitle: Text(
            '${p.sku ?? '—'} · in store: ${p.quantityOnHand}',
            style: TextStyle(
              color: p.lowStock ? KevernaColors.danger : KevernaColors.inkSoft,
            )),
        trailing: ElevatedButton(
          onPressed: outlet == null ? null : () => _openRequestSheet(p, outlet),
          style: ElevatedButton.styleFrom(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          ),
          child: const Text('Request'),
        ),
      ),
    );
  }

  Future<void> _openRequestSheet(Product product, Outlet outlet) async {
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _CreateRequestSheet(product: product, outlet: outlet),
    );
    if (ok == true) {
      ref.invalidate(myStockRequestsProvider);
    }
  }
}

// ── Pending review (manager) ────────────────────────────────

class _PendingTab extends ConsumerWidget {
  const _PendingTab({required this.isManager});

  final bool isManager;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(pendingStockRequestsProvider);
    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(pendingStockRequestsProvider),
      child: async.when(
        data: (rows) => rows.isEmpty
            ? ListView(children: const [
                Padding(
                  padding: EdgeInsets.fromLTRB(24, 80, 24, 40),
                  child: Column(children: [
                    Icon(Icons.check_circle_outline, size: 48, color: KevernaColors.success),
                    SizedBox(height: 12),
                    Text('No pending requests.',
                        style: TextStyle(color: KevernaColors.inkSoft, height: 1.4),
                        textAlign: TextAlign.center),
                  ]),
                ),
              ])
            : ListView.separated(
                padding: const EdgeInsets.all(12),
                itemCount: rows.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) => _pendingRow(context, ref, rows[i], isManager),
              ),
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text('Load failed: $e', style: const TextStyle(color: KevernaColors.danger)),
          ),
        ),
      ),
    );
  }

  Widget _pendingRow(BuildContext context, WidgetRef ref, StockRequestSummary r, bool isManager) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('${r.quantity} × ${r.productName ?? '—'}',
                style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 4),
            Text('→ ${r.outletName ?? '—'} · ${r.requestedAt != null ? _relative(r.requestedAt!) : '—'}',
                style: Theme.of(context).textTheme.bodySmall),
            if (r.requestedNote != null && r.requestedNote!.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('"${r.requestedNote}"',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          fontStyle: FontStyle.italic,
                        )),
              ),
            if (isManager) ...[
              const SizedBox(height: 12),
              Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                TextButton(
                  onPressed: () => _openReject(context, ref, r),
                  style: TextButton.styleFrom(foregroundColor: KevernaColors.danger),
                  child: const Text('Reject'),
                ),
                const SizedBox(width: 6),
                ElevatedButton(
                  onPressed: () => _openApprove(context, ref, r),
                  child: const Text('Approve'),
                ),
              ]),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _openApprove(BuildContext context, WidgetRef ref, StockRequestSummary r) async {
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _ApproveSheet(request: r),
    );
    if (ok == true) {
      ref.invalidate(pendingStockRequestsProvider);
      ref.invalidate(myStockRequestsProvider);
    }
  }

  Future<void> _openReject(BuildContext context, WidgetRef ref, StockRequestSummary r) async {
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _RejectSheet(request: r),
    );
    if (ok == true) {
      ref.invalidate(pendingStockRequestsProvider);
      ref.invalidate(myStockRequestsProvider);
    }
  }

  String _relative(DateTime t) {
    final d = DateTime.now().difference(t);
    if (d.inMinutes < 1) return 'just now';
    if (d.inMinutes < 60) return '${d.inMinutes}m ago';
    if (d.inHours < 24) return '${d.inHours}h ago';
    return DateFormat('d MMM · HH:mm').format(t);
  }
}

// ── Mine ─────────────────────────────────────────────────────

class _MineTab extends ConsumerWidget {
  const _MineTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(myStockRequestsProvider);
    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(myStockRequestsProvider),
      child: async.when(
        data: (rows) => rows.isEmpty
            ? ListView(children: const [
                Padding(
                  padding: EdgeInsets.fromLTRB(24, 80, 24, 40),
                  child: Text(
                    'You have not raised any requests yet.',
                    style: TextStyle(color: KevernaColors.inkMute),
                    textAlign: TextAlign.center,
                  ),
                ),
              ])
            : ListView.separated(
                padding: const EdgeInsets.all(12),
                itemCount: rows.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) => _mineRow(context, rows[i]),
              ),
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text('Load failed: $e', style: const TextStyle(color: KevernaColors.danger)),
          ),
        ),
      ),
    );
  }

  Widget _mineRow(BuildContext context, StockRequestSummary r) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('${r.quantity} × ${r.productName ?? '—'}',
                      style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 4),
                  Text(
                    '→ ${r.outletName ?? '—'} · '
                    '${r.requestedAt != null ? DateFormat('d MMM · HH:mm').format(r.requestedAt!) : '—'}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  if (r.approvedQuantity != null && r.approvedQuantity != r.quantity)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text('Approved for ${r.approvedQuantity} (adjusted)',
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: KevernaColors.warn,
                              )),
                    ),
                  if (r.resolvedNote != null && r.resolvedNote!.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text('"${r.resolvedNote}"',
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                fontStyle: FontStyle.italic,
                              )),
                    ),
                ],
              ),
            ),
            _statusTag(r.status),
          ],
        ),
      ),
    );
  }

  Widget _statusTag(String? status) {
    final palette = switch (status) {
      'approved' || 'issued' => (bg: KevernaColors.success.withValues(alpha: 0.12), fg: KevernaColors.success),
      'rejected' || 'cancelled' => (bg: KevernaColors.danger.withValues(alpha: 0.10), fg: KevernaColors.danger),
      'pending' => (bg: KevernaColors.warn.withValues(alpha: 0.14), fg: const Color(0xFF92400E)),
      _ => (bg: KevernaColors.cardDeep, fg: KevernaColors.inkSoft),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(color: palette.bg, borderRadius: BorderRadius.circular(999)),
      child: Text(status ?? '—',
          style: TextStyle(color: palette.fg, fontSize: 11, fontWeight: FontWeight.w600)),
    );
  }
}

// ── Create-request sheet ─────────────────────────────────────

class _CreateRequestSheet extends ConsumerStatefulWidget {
  const _CreateRequestSheet({required this.product, required this.outlet});

  final Product product;
  final Outlet outlet;

  @override
  ConsumerState<_CreateRequestSheet> createState() => _CreateRequestSheetState();
}

class _CreateRequestSheetState extends ConsumerState<_CreateRequestSheet> {
  final _qtyCtrl = TextEditingController(text: '1');
  final _noteCtrl = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _qtyCtrl.dispose();
    _noteCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final qty = int.tryParse(_qtyCtrl.text.trim()) ?? 0;
    if (qty <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Enter a quantity greater than zero.'),
        backgroundColor: KevernaColors.danger,
      ));
      return;
    }
    setState(() => _busy = true);
    final repo = ref.read(inventoryRepositoryProvider);
    try {
      await repo?.createRequest(
        outletId: widget.outlet.id,
        productId: widget.product.id,
        quantity: qty,
        productLabel: widget.product.name,
        note: _noteCtrl.text.trim().isEmpty ? null : _noteCtrl.text.trim(),
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('Requested $qty × ${widget.product.name}'),
        ));
        Navigator.of(context).pop(true);
      }
    } on QueuedForRetry {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Request saved locally — will sync when back online.'),
          backgroundColor: KevernaColors.accentDeep,
        ));
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('Request failed: $e'),
          backgroundColor: KevernaColors.danger,
        ));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final insets = MediaQuery.of(context).viewInsets;
    return Padding(
      padding: EdgeInsets.only(bottom: insets.bottom),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Request stock', style: Theme.of(context).textTheme.headlineMedium),
              const SizedBox(height: 4),
              Text('${widget.product.name} → ${widget.outlet.name}',
                  style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 18),

              TextField(
                controller: _qtyCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w600),
                decoration: const InputDecoration(labelText: 'Quantity'),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _noteCtrl,
                decoration: const InputDecoration(
                  labelText: 'Note (optional)',
                  hintText: 'e.g. Running low for the weekend',
                ),
              ),
              const SizedBox(height: 22),

              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  TextButton(
                    onPressed: _busy ? null : () => Navigator.of(context).pop(),
                    child: const Text('Cancel'),
                  ),
                  const SizedBox(width: 8),
                  ElevatedButton(
                    onPressed: _busy ? null : _submit,
                    child: Text(_busy ? 'Sending…' : 'Send request'),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Approve sheet ────────────────────────────────────────────

class _ApproveSheet extends ConsumerStatefulWidget {
  const _ApproveSheet({required this.request});
  final StockRequestSummary request;

  @override
  ConsumerState<_ApproveSheet> createState() => _ApproveSheetState();
}

class _ApproveSheetState extends ConsumerState<_ApproveSheet> {
  late final TextEditingController _qtyCtrl;
  final _noteCtrl = TextEditingController();
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _qtyCtrl = TextEditingController(text: widget.request.quantity.toString());
  }

  @override
  void dispose() {
    _qtyCtrl.dispose();
    _noteCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _busy = true);
    final qty = int.tryParse(_qtyCtrl.text.trim()) ?? widget.request.quantity;
    final repo = ref.read(inventoryRepositoryProvider);
    try {
      await repo?.approveRequest(
        id: widget.request.id,
        approvedQuantity: qty != widget.request.quantity ? qty : null,
        note: _noteCtrl.text.trim().isEmpty ? null : _noteCtrl.text.trim(),
        productLabel: widget.request.productName ?? 'stock',
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Request approved.'),
        ));
        Navigator.of(context).pop(true);
      }
    } on QueuedForRetry {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Approval saved locally — will sync when back online.'),
          backgroundColor: KevernaColors.accentDeep,
        ));
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('Approve failed: $e'),
          backgroundColor: KevernaColors.danger,
        ));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final insets = MediaQuery.of(context).viewInsets;
    return Padding(
      padding: EdgeInsets.only(bottom: insets.bottom),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Approve request', style: Theme.of(context).textTheme.headlineMedium),
              const SizedBox(height: 4),
              Text(
                '${widget.request.quantity} × ${widget.request.productName ?? '—'} → '
                '${widget.request.outletName ?? '—'}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 18),
              TextField(
                controller: _qtyCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w600),
                decoration: const InputDecoration(
                  labelText: 'Approved quantity',
                  helperText: 'Adjust if you can only issue part of what was requested.',
                ),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _noteCtrl,
                decoration: const InputDecoration(
                  labelText: 'Note (optional)',
                  hintText: 'e.g. Rest arrives tomorrow',
                ),
              ),
              const SizedBox(height: 22),
              Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                TextButton(
                  onPressed: _busy ? null : () => Navigator.of(context).pop(),
                  child: const Text('Cancel'),
                ),
                const SizedBox(width: 8),
                ElevatedButton(
                  onPressed: _busy ? null : _submit,
                  child: Text(_busy ? 'Approving…' : 'Approve'),
                ),
              ]),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Reject sheet ─────────────────────────────────────────────

class _RejectSheet extends ConsumerStatefulWidget {
  const _RejectSheet({required this.request});
  final StockRequestSummary request;

  @override
  ConsumerState<_RejectSheet> createState() => _RejectSheetState();
}

class _RejectSheetState extends ConsumerState<_RejectSheet> {
  final _noteCtrl = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _noteCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final note = _noteCtrl.text.trim();
    if (note.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Give the requester a reason — they need to know why.'),
        backgroundColor: KevernaColors.danger,
      ));
      return;
    }
    setState(() => _busy = true);
    final repo = ref.read(inventoryRepositoryProvider);
    try {
      await repo?.rejectRequest(
        id: widget.request.id,
        note: note,
        productLabel: widget.request.productName ?? 'stock',
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Request rejected.'),
        ));
        Navigator.of(context).pop(true);
      }
    } on QueuedForRetry {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Rejection saved locally — will sync when back online.'),
          backgroundColor: KevernaColors.accentDeep,
        ));
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('Reject failed: $e'),
          backgroundColor: KevernaColors.danger,
        ));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final insets = MediaQuery.of(context).viewInsets;
    return Padding(
      padding: EdgeInsets.only(bottom: insets.bottom),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Reject request', style: Theme.of(context).textTheme.headlineMedium),
              const SizedBox(height: 4),
              Text(
                '${widget.request.quantity} × ${widget.request.productName ?? '—'} → '
                '${widget.request.outletName ?? '—'}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 18),
              TextField(
                controller: _noteCtrl,
                maxLines: 3,
                decoration: const InputDecoration(
                  labelText: 'Reason (required)',
                  hintText: 'e.g. Out of stock at supplier this week',
                ),
              ),
              const SizedBox(height: 22),
              Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                TextButton(
                  onPressed: _busy ? null : () => Navigator.of(context).pop(),
                  child: const Text('Cancel'),
                ),
                const SizedBox(width: 8),
                ElevatedButton(
                  onPressed: _busy ? null : _submit,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: KevernaColors.danger,
                    foregroundColor: KevernaColors.card,
                  ),
                  child: Text(_busy ? 'Rejecting…' : 'Reject'),
                ),
              ]),
            ],
          ),
        ),
      ),
    );
  }
}
