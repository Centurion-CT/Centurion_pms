// Bottom sheet for the "Take payment" action on the reservation
// detail. Pre-fills amount to the outstanding balance; operator can
// change it (partial payments). Method choice is the same set the
// web dashboard supports; the record-payment endpoint is idempotent
// server-side so a queued outbox retry can't double-book cash.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/theme/keverna_theme.dart';

class TakePaymentResult {
  const TakePaymentResult({
    required this.amount,
    required this.method,
    this.referenceNote,
  });

  final int amount;
  final String method;
  final String? referenceNote;
}

class TakePaymentSheet extends StatefulWidget {
  const TakePaymentSheet({super.key, required this.defaultAmount});

  final int defaultAmount;

  @override
  State<TakePaymentSheet> createState() => _TakePaymentSheetState();
}

class _TakePaymentSheetState extends State<TakePaymentSheet> {
  late final TextEditingController _amountCtrl;
  final _refCtrl = TextEditingController();
  String _method = 'cash';

  static const _methods = <(String, String)>[
    ('cash', 'Cash'),
    ('transfer', 'Bank transfer'),
    ('card', 'Card'),
    ('pos', 'POS'),
    ('other', 'Other'),
  ];

  @override
  void initState() {
    super.initState();
    _amountCtrl = TextEditingController(text: widget.defaultAmount.toString());
  }

  @override
  void dispose() {
    _amountCtrl.dispose();
    _refCtrl.dispose();
    super.dispose();
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
              Text('Take payment', style: Theme.of(context).textTheme.headlineMedium),
              const SizedBox(height: 4),
              Text('Records a manual payment against this reservation.',
                  style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 18),

              TextField(
                controller: _amountCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w600),
                decoration: const InputDecoration(
                  labelText: 'Amount (₦)',
                  prefixText: '₦ ',
                ),
              ),
              const SizedBox(height: 14),

              DropdownButtonFormField<String>(
                initialValue: _method,
                decoration: const InputDecoration(labelText: 'Method'),
                items: _methods
                    .map((m) => DropdownMenuItem(value: m.$1, child: Text(m.$2)))
                    .toList(),
                onChanged: (v) => setState(() => _method = v ?? 'cash'),
              ),
              const SizedBox(height: 14),

              TextField(
                controller: _refCtrl,
                decoration: const InputDecoration(
                  labelText: 'Reference / note (optional)',
                  hintText: 'e.g. Transfer ID 8k3v, cheque #221',
                ),
              ),
              const SizedBox(height: 22),

              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  TextButton(
                    onPressed: () => Navigator.of(context).pop(),
                    child: const Text('Cancel'),
                  ),
                  const SizedBox(width: 8),
                  ElevatedButton(
                    onPressed: () {
                      final amount = int.tryParse(_amountCtrl.text.trim()) ?? 0;
                      if (amount <= 0) {
                        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                          content: Text('Enter an amount greater than zero.'),
                          backgroundColor: KevernaColors.danger,
                        ));
                        return;
                      }
                      Navigator.of(context).pop(TakePaymentResult(
                        amount: amount,
                        method: _method,
                        referenceNote:
                            _refCtrl.text.trim().isEmpty ? null : _refCtrl.text.trim(),
                      ));
                    },
                    child: const Text('Record payment'),
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
