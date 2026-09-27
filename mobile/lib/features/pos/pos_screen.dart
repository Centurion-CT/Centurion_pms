// POS tab entry. Two states:
//   - No outlet selected → outlet picker.
//   - Outlet selected → product grid + cart drawer + scan FAB.
// Selection lives in memory (selectedOutletProvider) so a phone
// restart lands back on the picker.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/theme/keverna_theme.dart';
import 'checkout_sheet.dart';
import 'pos_models.dart';
import 'pos_repository.dart';
import 'scan_sheet.dart';

class PosScreen extends ConsumerWidget {
  const PosScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final outlet = ref.watch(selectedOutletProvider);
    if (outlet == null) return const _OutletPicker();
    return _OutletHome(outlet: outlet);
  }
}

class _OutletPicker extends ConsumerWidget {
  const _OutletPicker();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(outletsProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Pick outlet')),
      body: RefreshIndicator(
        onRefresh: () async {
          final repo = ref.read(posRepositoryProvider);
          if (repo != null) {
            try {
              await repo.refreshOutlets();
            } catch (_) {}
          }
        },
        child: async.when(
          data: (outlets) {
            if (outlets.isEmpty) {
              return ListView(children: const [
                Padding(
                  padding: EdgeInsets.fromLTRB(24, 80, 24, 40),
                  child: Text(
                    'No outlets attached to your account.\nAsk a manager to grant access on Staff → Outlets.',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: KevernaColors.inkSoft, height: 1.4),
                  ),
                ),
              ]);
            }
            return ListView.separated(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              itemCount: outlets.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final o = outlets[i];
                return Card(
                  child: InkWell(
                    borderRadius: BorderRadius.circular(14),
                    onTap: () => ref.read(selectedOutletProvider.notifier).state = o,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
                      child: Row(
                        children: [
                          const Icon(Icons.storefront_outlined, color: KevernaColors.accentDeep),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(o.name, style: Theme.of(context).textTheme.titleMedium),
                                if (o.type != null)
                                  Text(o.type!,
                                      style: Theme.of(context).textTheme.bodySmall),
                              ],
                            ),
                          ),
                          const Icon(Icons.arrow_forward_ios, size: 14, color: KevernaColors.inkMute),
                        ],
                      ),
                    ),
                  ),
                );
              },
            );
          },
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => Center(child: Text('Load failed: $e')),
        ),
      ),
    );
  }
}

class _OutletHome extends ConsumerStatefulWidget {
  const _OutletHome({required this.outlet});

  final Outlet outlet;

  @override
  ConsumerState<_OutletHome> createState() => _OutletHomeState();
}

class _OutletHomeState extends ConsumerState<_OutletHome> {
  final _searchCtrl = TextEditingController();
  String _query = '';

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _refresh() async {
    final repo = ref.read(posRepositoryProvider);
    if (repo == null) return;
    try {
      await repo.refreshProducts(widget.outlet.id);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Refresh failed — showing cached catalogue. ($e)'),
        backgroundColor: KevernaColors.danger,
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final products = ref.watch(productsProvider(widget.outlet.id));
    final cart = ref.watch(cartProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.outlet.name),
        actions: [
          IconButton(
            tooltip: 'Request stock',
            icon: const Icon(Icons.inventory_2_outlined),
            onPressed: () => GoRouter.of(context).push('/pos/stock-requests'),
          ),
          TextButton(
            onPressed: () => ref.read(selectedOutletProvider.notifier).state = null,
            child: const Text('Switch'),
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(60),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: TextField(
              controller: _searchCtrl,
              onChanged: (v) => setState(() => _query = v.trim().toLowerCase()),
              decoration: InputDecoration(
                hintText: 'Search products…',
                prefixIcon: const Icon(Icons.search, size: 20),
                suffixIcon: _query.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.close, size: 18),
                        onPressed: () {
                          _searchCtrl.clear();
                          setState(() => _query = '');
                        },
                      )
                    : null,
                isDense: true,
              ),
            ),
          ),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: products.when(
          data: (rows) {
            final filtered = _query.isEmpty
                ? rows
                : rows.where((p) {
                    final q = _query;
                    return p.name.toLowerCase().contains(q)
                        || (p.sku?.toLowerCase().contains(q) ?? false)
                        || (p.barcode?.contains(q) ?? false);
                  }).toList();
            if (filtered.isEmpty) {
              return ListView(children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 80, 24, 40),
                  child: Text(
                    _query.isEmpty
                        ? 'No products in this outlet yet.\nAdd them on the web dashboard.'
                        : 'No matches for "$_query".',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: KevernaColors.inkSoft, height: 1.4),
                  ),
                ),
              ]);
            }
            return _productGrid(filtered);
          },
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => Center(child: Text('Load failed: $e')),
        ),
      ),
      bottomNavigationBar: cart.isEmpty ? null : _cartBar(cart),
      floatingActionButton: FloatingActionButton(
        onPressed: _openScanner,
        backgroundColor: KevernaColors.accent,
        foregroundColor: KevernaColors.ink,
        child: const Icon(Icons.qr_code_scanner),
      ),
    );
  }

  Widget _productGrid(List<Product> products) {
    return GridView.builder(
      padding: const EdgeInsets.all(12),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        childAspectRatio: 1.1,
        crossAxisSpacing: 10,
        mainAxisSpacing: 10,
      ),
      itemCount: products.length,
      itemBuilder: (context, i) {
        final p = products[i];
        return _productCard(p);
      },
    );
  }

  Widget _productCard(Product p) {
    final oos = p.trackStock && p.quantityOnHand <= 0;
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: oos
            ? null
            : () {
                ref.read(cartProvider.notifier).add(p);
                ScaffoldMessenger.of(context).clearSnackBars();
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                  content: Text('Added ${p.name}'),
                  duration: const Duration(milliseconds: 700),
                ));
              },
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(p.name,
                        style: Theme.of(context).textTheme.titleMedium,
                        maxLines: 2, overflow: TextOverflow.ellipsis),
                    if (p.sku != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text(p.sku!,
                            style: Theme.of(context).textTheme.bodySmall),
                      ),
                  ],
                ),
              ),
              Row(
                children: [
                  Expanded(
                    child: Text('₦${_money(p.price)}',
                        style: Theme.of(context).textTheme.titleMedium?.copyWith(
                              color: KevernaColors.accentDeep,
                              fontWeight: FontWeight.w700,
                            )),
                  ),
                  if (oos)
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: KevernaColors.danger.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: const Text('Out',
                          style: TextStyle(
                              color: KevernaColors.danger,
                              fontSize: 10,
                              fontWeight: FontWeight.w700)),
                    )
                  else if (p.lowStock)
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: KevernaColors.warn.withValues(alpha: 0.14),
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Text('Low · ${p.quantityOnHand}',
                          style: const TextStyle(
                              color: Color(0xFF92400E),
                              fontSize: 10,
                              fontWeight: FontWeight.w700)),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _cartBar(List<CartLine> cart) {
    final total = ref.read(cartProvider.notifier).total;
    final itemCount = cart.fold<int>(0, (n, l) => n + l.quantity);
    return SafeArea(
      top: false,
      child: Container(
        color: KevernaColors.card,
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
        child: Row(
          children: [
            IconButton.outlined(
              onPressed: () => _openCartDrawer(),
              icon: Badge(
                label: Text('$itemCount'),
                backgroundColor: KevernaColors.accentDeep,
                textColor: KevernaColors.card,
                child: const Icon(Icons.shopping_cart_outlined),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Subtotal', style: Theme.of(context).textTheme.bodySmall),
                  Text('₦${_money(total)}',
                      style: Theme.of(context).textTheme.headlineMedium),
                ],
              ),
            ),
            ElevatedButton(
              onPressed: () => _openCheckout(),
              child: const Text('Checkout →'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _openCartDrawer() async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _CartDrawer(),
    );
  }

  Future<void> _openCheckout() async {
    final result = await showModalBottomSheet<PosSaleReceipt>(
      context: context,
      isScrollControlled: true,
      builder: (_) => CheckoutSheet(outlet: widget.outlet),
    );
    if (result != null && mounted) {
      ref.read(cartProvider.notifier).clear();
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Sale ${result.reference} · ₦${_money(result.total)}'),
      ));
    }
  }

  Future<void> _openScanner() async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => ScanSheet(outletId: widget.outlet.id),
    );
  }

  String _money(int amount) => NumberFormat.decimalPattern().format(amount);
}

class _CartDrawer extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cart = ref.watch(cartProvider);
    final total = ref.read(cartProvider.notifier).total;
    return SafeArea(
      top: false,
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Text('Cart · ${cart.length} ${cart.length == 1 ? 'item' : 'items'}',
                      style: Theme.of(context).textTheme.titleMedium),
                  const Spacer(),
                  TextButton(
                    onPressed: cart.isEmpty ? null : () {
                      ref.read(cartProvider.notifier).clear();
                      Navigator.of(context).pop();
                    },
                    style: TextButton.styleFrom(foregroundColor: KevernaColors.danger),
                    child: const Text('Clear all'),
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
            if (cart.isEmpty)
              const Padding(
                padding: EdgeInsets.all(32),
                child: Text('Cart is empty.', style: TextStyle(color: KevernaColors.inkMute)),
              )
            else
              Flexible(
                child: ListView.separated(
                  shrinkWrap: true,
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  itemCount: cart.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final line = cart[i];
                    return ListTile(
                      title: Text(line.product.name),
                      subtitle: Text('₦${NumberFormat.decimalPattern().format(line.product.price)} each'),
                      trailing: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          IconButton(
                            icon: const Icon(Icons.remove, size: 20),
                            onPressed: () => ref.read(cartProvider.notifier)
                                .setQuantity(line.product.id, line.quantity - 1),
                          ),
                          Text('${line.quantity}',
                              style: Theme.of(context).textTheme.titleMedium),
                          IconButton(
                            icon: const Icon(Icons.add, size: 20),
                            onPressed: () => ref.read(cartProvider.notifier)
                                .setQuantity(line.product.id, line.quantity + 1),
                          ),
                        ],
                      ),
                    );
                  },
                ),
              ),
            const Divider(height: 1),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Text('Subtotal', style: Theme.of(context).textTheme.titleMedium),
                  const Spacer(),
                  Text('₦${NumberFormat.decimalPattern().format(total)}',
                      style: Theme.of(context).textTheme.headlineMedium),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
