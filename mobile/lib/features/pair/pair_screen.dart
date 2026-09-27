// Camera-based QR scan + manual code fallback. First screen after
// cold-start when there's no stored session.

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/theme/keverna_theme.dart';
import 'pair_service.dart';

class PairScreen extends ConsumerStatefulWidget {
  const PairScreen({super.key});

  @override
  ConsumerState<PairScreen> createState() => _PairScreenState();
}

class _PairScreenState extends ConsumerState<PairScreen> {
  final _scanner = MobileScannerController();
  bool _showManual = false;
  bool _pairing = false;
  String? _errorMessage;

  final _baseCtrl = TextEditingController();
  final _codeCtrl = TextEditingController();

  @override
  void dispose() {
    _scanner.dispose();
    _baseCtrl.dispose();
    _codeCtrl.dispose();
    super.dispose();
  }

  Future<void> _pair({required String base, required String code}) async {
    if (_pairing) return;
    setState(() {
      _pairing = true;
      _errorMessage = null;
    });
    try {
      await ref.read(pairServiceProvider).pair(baseUrl: base, code: code);
      // Router redirect will kick us to /home the moment session is set.
    } on PairFailure catch (e) {
      setState(() => _errorMessage = e.message);
    } finally {
      if (mounted) setState(() => _pairing = false);
    }
  }

  void _onDetect(BarcodeCapture capture) {
    if (_pairing) return;
    for (final b in capture.barcodes) {
      final raw = b.rawValue;
      if (raw == null) continue;
      final parsed = parsePairPayload(raw);
      if (parsed != null) {
        _pair(base: parsed.base, code: parsed.code);
        return;
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Pair your phone')),
      body: SafeArea(
        child: _showManual ? _manualEntry() : _scanView(),
      ),
    );
  }

  Widget _scanView() {
    return Column(
      children: [
        Expanded(
          child: Stack(
            children: [
              MobileScanner(controller: _scanner, onDetect: _onDetect),
              Center(
                child: Container(
                  width: 240,
                  height: 240,
                  decoration: BoxDecoration(
                    border: Border.all(color: KevernaColors.accent, width: 2),
                    borderRadius: BorderRadius.circular(16),
                  ),
                ),
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 20, 24, 28),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'Scan the QR from your dashboard',
                style: Theme.of(context).textTheme.titleMedium,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 6),
              Text(
                'Staff → Linked devices → Generate pairing code',
                style: Theme.of(context).textTheme.bodySmall,
                textAlign: TextAlign.center,
              ),
              if (_errorMessage != null) ...[
                const SizedBox(height: 14),
                Text(
                  _errorMessage!,
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(color: KevernaColors.danger),
                  textAlign: TextAlign.center,
                ),
              ],
              const SizedBox(height: 20),
              TextButton(
                onPressed: () => setState(() => _showManual = true),
                child: const Text('Enter code manually'),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _manualEntry() {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Enter your pairing code',
            style: Theme.of(context).textTheme.headlineMedium,
          ),
          const SizedBox(height: 8),
          Text(
            'Both fields live on your dashboard under Staff → Linked devices.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 24),
          TextField(
            controller: _baseCtrl,
            keyboardType: TextInputType.url,
            autocorrect: false,
            decoration: const InputDecoration(
              labelText: 'Property URL',
              hintText: 'https://chidi-hotel.keverna.com',
            ),
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _codeCtrl,
            textCapitalization: TextCapitalization.characters,
            style: const TextStyle(letterSpacing: 4, fontSize: 20, fontWeight: FontWeight.w600),
            decoration: const InputDecoration(labelText: 'Pairing code', hintText: 'XXXXXXXX'),
          ),
          if (_errorMessage != null) ...[
            const SizedBox(height: 14),
            Text(
              _errorMessage!,
              style: Theme.of(context)
                  .textTheme
                  .bodyMedium
                  ?.copyWith(color: KevernaColors.danger),
            ),
          ],
          const SizedBox(height: 24),
          ElevatedButton(
            onPressed: _pairing
                ? null
                : () => _pair(
                      base: _baseCtrl.text.trim(),
                      code: _codeCtrl.text.trim().toUpperCase(),
                    ),
            child: _pairing
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2, color: KevernaColors.ink),
                  )
                : const Text('Pair phone'),
          ),
          const SizedBox(height: 12),
          TextButton(
            onPressed: () => setState(() => _showManual = false),
            child: const Text('Use camera instead'),
          ),
        ],
      ),
    );
  }
}
