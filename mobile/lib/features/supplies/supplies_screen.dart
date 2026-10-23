// Supplies (A-supplies). Steppers start at 0; "Ask the store" sends only
// lines above 0 and the request shows straight away under "Asked earlier".

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../core/format.dart';
import '../../core/strings.dart';
import '../../core/theme/tokens.dart';
import '../../core/widgets/icons.dart';
import '../../core/widgets/widgets.dart';
import '../staff/models.dart';
import '../staff/providers.dart';

class SuppliesScreen extends ConsumerStatefulWidget {
  const SuppliesScreen({super.key});

  @override
  ConsumerState<SuppliesScreen> createState() => _SuppliesScreenState();
}

class _SuppliesScreenState extends ConsumerState<SuppliesScreen> {
  final Map<int, int> _qty = {};
  bool _busy = false;

  int get _total => _qty.values.fold(0, (a, b) => a + b);

  Future<void> _ask(SuppliesData data) async {
    if (_total == 0) {
      showSnack(context, S.pickSomething);
      return;
    }
    setState(() => _busy = true);
    await ref.read(staffRepositoryProvider)?.askSupplies([for (final p in data.products) (p, _qty[p.id] ?? 0)]);
    if (!mounted) return;
    setState(() {
      _busy = false;
      _qty.clear();
    });
    showSnack(context, S.sentToStore);
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(suppliesProvider);
    final data = async.valueOrNull;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Stack(
          children: [
            RefreshIndicator(
              onRefresh: () async {
                final ok = await ref.read(suppliesProvider.notifier).refresh();
                if (!ok && context.mounted) showSnack(context, S.refreshFailed);
              },
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.only(bottom: 120),
                children: [
                  const OfflineBanner(),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Eyebrow(data?.outletName ?? S.housekeepingStore),
                        const SizedBox(height: 4),
                        Semantics(header: true, child: const Text(S.supplies, style: T.h1)),
                        const SizedBox(height: 4),
                        const Text(S.suppliesBody, style: T.sub),
                      ],
                    ),
                  ),
                  if (data != null)
                    ..._body(data)
                  else if (async.hasError)
                    ErrorState(onRetry: () => ref.read(suppliesProvider.notifier).refresh())
                  else
                    const LoadingBlock(),
                ],
              ),
            ),
            if (data != null && data.products.isNotEmpty)
              Positioned(
                left: 0,
                right: 0,
                bottom: 0,
                child: BottomActionArea(
                  child: ActionPill(
                    key: const Key('ask-store'),
                    label: S.askTheStore,
                    icon: LucideIcons.send,
                    busy: _busy,
                    enabled: _total > 0,
                    onTap: () => _ask(data),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  List<Widget> _body(SuppliesData data) {
    return [
      if (data.products.isEmpty)
        const EmptyState(text: S.noProducts, icon: LucideIcons.package)
      else
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
          child: AppCard(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: Column(
              children: [
                for (var i = 0; i < data.products.length; i++)
                  _ProductRow(
                    product: data.products[i],
                    qty: _qty[data.products[i].id] ?? 0,
                    first: i == 0,
                    onChanged: (v) => setState(() => _qty[data.products[i].id] = v),
                  ),
              ],
            ),
          ),
        ),
      if (data.requests.isNotEmpty) ...[
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 24, 20, 8),
          child: Semantics(header: true, child: Text(S.askedEarlier.toUpperCase(), style: T.sectionLabel)),
        ),
        for (final r in data.requests)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: _RequestRow(request: r),
          ),
      ],
    ];
  }
}

class _ProductRow extends StatelessWidget {
  const _ProductRow({required this.product, required this.qty, required this.first, required this.onChanged});

  final Product product;
  final int qty;
  final bool first;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 8),
      decoration: BoxDecoration(border: first ? null : const Border(top: BorderSide(color: C.lineSoft))),
      child: Row(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(color: C.paper, borderRadius: BorderRadius.circular(16)),
            child: Icon(productIcon(product.icon, product.name), size: 22, color: C.ink),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(product.name, style: T.cardTitle),
                if (product.unit != null) Text(product.unit!, style: T.cardSub),
              ],
            ),
          ),
          _StepButton(
            key: Key('minus-${product.id}'),
            icon: LucideIcons.minus,
            label: S.less(product.name),
            dark: false,
            onTap: qty > 0 ? () => onChanged(qty - 1) : null,
          ),
          SizedBox(
            width: 34,
            child: Text(
              '$qty',
              key: Key('qty-${product.id}'),
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: F.display,
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: qty == 0 ? C.inkFaint : C.ink,
              ),
            ),
          ),
          _StepButton(
            key: Key('plus-${product.id}'),
            icon: LucideIcons.plus,
            label: S.more(product.name),
            dark: true,
            onTap: qty < 99 ? () => onChanged(qty + 1) : null,
          ),
        ],
      ),
    );
  }
}

/// 46px circle inside a 56px tap target.
class _StepButton extends StatelessWidget {
  const _StepButton({super.key, required this.icon, required this.label, required this.dark, required this.onTap});

  final IconData icon;
  final String label;
  final bool dark;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: label,
      enabled: onTap != null,
      excludeSemantics: true,
      child: InkResponse(
        onTap: onTap,
        radius: 28,
        child: SizedBox(
          width: 56,
          height: 56,
          child: Center(
            child: Opacity(
              opacity: onTap == null ? 0.4 : 1,
              child: Container(
                width: 46,
                height: 46,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: dark ? C.inkDeep : C.card,
                  border: dark ? null : Border.all(color: const Color(0x261A2430)),
                ),
                child: Icon(icon, size: 20, color: dark ? Colors.white : C.ink),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _RequestRow extends StatelessWidget {
  const _RequestRow({required this.request});

  final SupplyRequest request;

  @override
  Widget build(BuildContext context) {
    final (IconData icon, Color bg, Color fg, String status) = switch (request.status) {
      RequestStatus.waiting => (LucideIcons.clock, C.warnBg, C.warnFg, S.statusWaiting),
      RequestStatus.approved => (
          LucideIcons.check,
          C.readyBg,
          C.readyFg,
          request.by != null ? S.approvedBy(request.by!) : S.statusReadyToCollect,
        ),
      RequestStatus.declined => (LucideIcons.x, C.toCleanBg, C.toCleanFg, S.statusDeclined),
      RequestStatus.cancelled => (LucideIcons.x, C.cardDeep, C.inkMute, S.statusCancelled),
    };
    final when = whenLabel(request.createdAt);
    return AppCard(
      radius: 20,
      padding: const EdgeInsets.all(14),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(14)),
            child: Icon(icon, size: 20, color: fg),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(request.summary, style: T.cardTitle.copyWith(fontSize: 15)),
                Text([when, status].where((s) => s.isNotEmpty).join(' · '), style: T.cardSub),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
