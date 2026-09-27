// Camera barcode scanner. On detect, calls
// posRepository.findByBarcode; if the product is found the operator
// is offered a "Add to cart" tile. Continues scanning until
// dismissed so an operator can rack up multiple products in one
// session.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/theme/keverna_theme.dart';
import 'pos_models.dart';
import 'pos_repository.dart';

class ScanSheet extends ConsumerStatefulWidget {
  const ScanSheet({super.key, required this.outletId});

  final int outletId;

  @override
  ConsumerState<ScanSheet> createState() => _ScanSheetState();
}

class _ScanSheetState extends ConsumerState<ScanSheet> {
  final _scanner = MobileScannerController();
  Product? _found;
  bool _lookingUp = false;
  String? _lastCode;

  @override
  void dispose() {
    _scanner.dispose();
    super.dispose();
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_lookingUp) return;
    for (final b in capture.barcodes) {
      final code = b.rawValue;
      if (code == null || code == _lastCode) continue;
      _lastCode = code;
      setState(() => _lookingUp = true);
      try {
        final repo = ref.read(posRepositoryProvider);
        final product = await repo?.findByBarcode(widget.outletId, code);
        if (!mounted) return;
        setState(() => _found = product);
      } finally {
        if (mounted) setState(() => _lookingUp = false);
      }
      return;
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.7,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 10),
              child: Row(
                children: [
                  Text('Scan barcode',
                      style: Theme.of(context).textTheme.titleMedium),
                  const Spacer(),
                  IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.of(context).pop(),
                  ),
                ],
              ),
            ),
            Expanded(
              child: Stack(
                children: [
                  MobileScanner(controller: _scanner, onDetect: _onDetect),
                  Center(
                    child: Container(
                      width: 220,
                      height: 140,
                      decoration: BoxDecoration(
                        border: Border.all(color: KevernaColors.accent, width: 2),
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                  ),
                  if (_lookingUp)
                    const Positioned(
                      top: 20,
                      left: 0,
                      right: 0,
                      child: Center(
                        child: Chip(label: Text('Looking up…')),
                      ),
                    ),
                ],
              ),
            ),
            if (_found != null) _foundRow(_found!),
            if (_found == null && _lastCode != null && !_lookingUp)
              Padding(
                padding: const EdgeInsets.all(16),
                child: Text('No product matched "$_lastCode".',
                    style: const TextStyle(color: KevernaColors.danger)),
              ),
          ],
        ),
      ),
    );
  }

  Widget _foundRow(Product p) {
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Card(
        child: ListTile(
          leading: const Icon(Icons.check_circle, color: KevernaColors.success, size: 28),
          title: Text(p.name),
          subtitle: Text('₦${p.price} · ${p.sku ?? p.barcode ?? '—'}'),
          trailing: ElevatedButton(
            onPressed: () {
              ref.read(cartProvider.notifier).add(p);
              setState(() {
                _found = null;
                _lastCode = null;
              });
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                content: Text('Added ${p.name}'),
                duration: const Duration(milliseconds: 700),
              ));
            },
            child: const Text('Add'),
          ),
        ),
      ),
    );
  }
}
