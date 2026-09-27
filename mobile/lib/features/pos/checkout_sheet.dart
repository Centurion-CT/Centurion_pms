// Checkout bottom sheet — multi-tender.
//
// Operator can add multiple tender rows (e.g. ₦2000 cash + ₦3000
// room). Live sum banner turns red until the tenders match the
// cart total exactly. "Room" tender reveals a booking picker that
// streams in-house guests from the reservations cache; falls back
// to manual entry if the guest isn't listed.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/outbox/mutation_service.dart';
import '../../core/theme/keverna_theme.dart';
import '../reservations/reservation_models.dart';
import '../reservations/reservations_repository.dart';
import 'pos_models.dart';
import 'pos_repository.dart';

class CheckoutSheet extends ConsumerStatefulWidget {
  const CheckoutSheet({super.key, required this.outlet});

  final Outlet outlet;

  @override
  ConsumerState<CheckoutSheet> createState() => _CheckoutSheetState();
}

class _CheckoutSheetState extends ConsumerState<CheckoutSheet> {
  static const _methods = <(String, String)>[
    ('cash', 'Cash'),
    ('transfer', 'Bank transfer'),
    ('card', 'Card'),
    ('pos', 'POS'),
    ('room', 'Charge to room'),
    ('wallet', 'Guest wallet'),
    ('other', 'Other'),
  ];

  final _tenders = <_TenderRow>[];
  String? _bookingRef;
  bool _bookingManualEntry = false;
  final _manualBookingCtrl = TextEditingController();
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    // Seed with a single cash tender for the full total. Operator
    // can change the method / split from there.
    final total = ref.read(cartProvider.notifier).total;
    _tenders.add(_TenderRow(method: 'cash', amount: total));
  }

  @override
  void dispose() {
    for (final t in _tenders) {
      t.dispose();
    }
    _manualBookingCtrl.dispose();
    super.dispose();
  }

  int get _sumTender => _tenders.fold(0, (sum, t) => sum + t.amount);

  bool get _needsBooking => _tenders.any((t) => t.method == 'room');

  bool get _canSubmit {
    final total = ref.read(cartProvider.notifier).total;
    if (_sumTender != total || total <= 0) return false;
    if (_needsBooking && (_bookingRef == null || _bookingRef!.isEmpty)) return false;
    return true;
  }

  Future<void> _submit() async {
    final cart = ref.read(cartProvider);
    if (cart.isEmpty) return;
    if (!_canSubmit) return;

    setState(() => _busy = true);
    final repo = ref.read(posRepositoryProvider)!;
    try {
      final receipt = await repo.createSale(
        outletId: widget.outlet.id,
        cart: cart,
        tenders: _tenders
            .map((t) => Tender(
                  method: t.method,
                  amount: t.amount,
                  referenceNote:
                      t.refCtrl.text.trim().isEmpty ? null : t.refCtrl.text.trim(),
                ))
            .toList(),
        bookingRef: _needsBooking ? _bookingRef : null,
      );
      if (mounted) Navigator.of(context).pop(receipt);
    } on QueuedForRetry catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Sale saved locally — will sync when back online.'),
          backgroundColor: KevernaColors.accentDeep,
        ));
        ref.read(cartProvider.notifier).clear();
        Navigator.of(context).pop();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('Checkout failed: $e'),
          backgroundColor: KevernaColors.danger,
        ));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickBooking() async {
    final outcome = await showModalBottomSheet<BookingPickerOutcome>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _BookingPicker(),
    );
    if (outcome == null) return;
    switch (outcome) {
      case PickedBooking(:final ref):
        setState(() {
          _bookingRef = ref;
          _bookingManualEntry = false;
        });
      case RequestManualEntry():
        setState(() {
          _bookingManualEntry = true;
          _bookingRef = null;
        });
    }
  }

  @override
  Widget build(BuildContext context) {
    final cart = ref.watch(cartProvider);
    final total = ref.read(cartProvider.notifier).total;
    final insets = MediaQuery.of(context).viewInsets;
    final diff = _sumTender - total;

    return Padding(
      padding: EdgeInsets.only(bottom: insets.bottom),
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('Checkout', style: Theme.of(context).textTheme.headlineMedium),
                const SizedBox(height: 4),
                Text('${cart.length} ${cart.length == 1 ? 'item' : 'items'} · ${widget.outlet.name}',
                    style: Theme.of(context).textTheme.bodySmall),
                const SizedBox(height: 18),

                _totalBanner(total, diff),
                const SizedBox(height: 18),

                Text('Tenders', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 8),
                ...List.generate(_tenders.length, (i) => _tenderRow(i)),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () => setState(() {
                      _tenders.add(_TenderRow(method: 'cash', amount: 0));
                    }),
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('Split with another tender'),
                  ),
                ),

                if (_needsBooking) ...[
                  const SizedBox(height: 8),
                  Text('Charge to booking', style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 8),
                  _bookingPickerRow(),
                ],

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
                      onPressed: _busy || !_canSubmit ? null : _submit,
                      child: Text(_busy ? 'Recording…' : 'Complete sale'),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _totalBanner(int total, int diff) {
    final ok = diff == 0 && total > 0;
    final short = diff < 0;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: ok ? KevernaColors.cardDeep : KevernaColors.danger.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
          color: ok ? KevernaColors.lineSoft : KevernaColors.danger.withValues(alpha: 0.35),
        ),
      ),
      child: Row(
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Total', style: Theme.of(context).textTheme.bodySmall),
              Text('₦${_money(total)}',
                  style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                        color: KevernaColors.accentDeep,
                        fontWeight: FontWeight.w700,
                      )),
            ],
          ),
          const Spacer(),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(ok ? 'Balanced' : (short ? 'Under' : 'Over'),
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: ok ? KevernaColors.success : KevernaColors.danger,
                        fontWeight: FontWeight.w600,
                      )),
              Text('₦${_money(_sumTender)}',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        color: ok ? KevernaColors.ink : KevernaColors.danger,
                      )),
            ],
          ),
        ],
      ),
    );
  }

  Widget _tenderRow(int i) {
    final t = _tenders[i];
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            flex: 4,
            child: DropdownButtonFormField<String>(
              initialValue: t.method,
              isDense: true,
              decoration: const InputDecoration(labelText: 'Method', isDense: true),
              items: _methods
                  .map((m) => DropdownMenuItem(value: m.$1, child: Text(m.$2)))
                  .toList(),
              onChanged: (v) => setState(() => t.method = v ?? 'cash'),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            flex: 3,
            child: TextField(
              controller: t.amountCtrl,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(labelText: '₦', isDense: true),
              onChanged: (v) => setState(() => t.amount = int.tryParse(v.trim()) ?? 0),
            ),
          ),
          if (_tenders.length > 1)
            IconButton(
              tooltip: 'Remove',
              icon: const Icon(Icons.close, size: 20),
              onPressed: () => setState(() {
                _tenders.removeAt(i).dispose();
              }),
            )
          else
            const SizedBox(width: 40),
        ],
      ),
    );
  }

  Widget _bookingPickerRow() {
    if (_bookingManualEntry) {
      return Row(
        children: [
          Expanded(
            child: TextField(
              controller: _manualBookingCtrl,
              textCapitalization: TextCapitalization.characters,
              inputFormatters: [LengthLimitingTextInputFormatter(20)],
              onChanged: (v) => setState(() => _bookingRef = v.trim().toUpperCase()),
              decoration: const InputDecoration(
                labelText: 'Booking ref',
                hintText: 'KV-1234',
                prefixIcon: Icon(Icons.hotel_outlined, size: 20),
              ),
            ),
          ),
          TextButton(
            onPressed: () => setState(() {
              _bookingManualEntry = false;
              _bookingRef = null;
              _manualBookingCtrl.clear();
            }),
            child: const Text('Pick guest'),
          ),
        ],
      );
    }
    if (_bookingRef == null) {
      return OutlinedButton.icon(
        onPressed: _pickBooking,
        icon: const Icon(Icons.hotel_outlined, size: 18),
        label: const Text('Choose in-house guest'),
      );
    }
    return Row(
      children: [
        const Icon(Icons.check_circle, color: KevernaColors.success, size: 20),
        const SizedBox(width: 8),
        Expanded(
          child: Text(_bookingRef!, style: Theme.of(context).textTheme.titleMedium),
        ),
        TextButton(
          onPressed: () => setState(() => _bookingRef = null),
          child: const Text('Change'),
        ),
      ],
    );
  }

  String _money(int amount) => NumberFormat.decimalPattern().format(amount);
}

/// Mutable per-row state for a tender. Kept out of setState-heavy
/// widget rebuilds so a keystroke in one row doesn't rebuild others.
class _TenderRow {
  _TenderRow({required this.method, required int amount})
      : amount = amount,
        amountCtrl = TextEditingController(text: amount > 0 ? amount.toString() : '');

  String method;
  int amount;
  final TextEditingController amountCtrl;
  final TextEditingController refCtrl = TextEditingController();

  void dispose() {
    amountCtrl.dispose();
    refCtrl.dispose();
  }
}

// ── Booking picker sheet ──────────────────────────────────────

/// Sealed outcome from the booking picker sheet.
///   PickedBooking       — operator tapped a guest row.
///   RequestManualEntry  — operator tapped "type ref manually" so
///                         the checkout sheet swaps to a text field.
sealed class BookingPickerOutcome {}

class PickedBooking extends BookingPickerOutcome {
  PickedBooking(this.ref);
  final String ref;
}

class RequestManualEntry extends BookingPickerOutcome {}

class _BookingPicker extends ConsumerStatefulWidget {
  const _BookingPicker();

  @override
  ConsumerState<_BookingPicker> createState() => _BookingPickerState();
}

class _BookingPickerState extends ConsumerState<_BookingPicker> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(reservationsListProvider('in_house'));
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.7,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: Row(
                children: [
                  Text('In-house guests',
                      style: Theme.of(context).textTheme.titleMedium),
                  const Spacer(),
                  IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.of(context).pop(),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: TextField(
                onChanged: (v) => setState(() => _query = v.trim().toLowerCase()),
                decoration: const InputDecoration(
                  hintText: 'Search name, ref, or room…',
                  prefixIcon: Icon(Icons.search, size: 20),
                  isDense: true,
                ),
              ),
            ),
            const SizedBox(height: 12),
            Expanded(
              child: async.when(
                data: (rows) {
                  final filtered = _query.isEmpty
                      ? rows
                      : rows.where((r) {
                          final q = _query;
                          return r.guestName.toLowerCase().contains(q)
                              || r.bookingRef.toLowerCase().contains(q)
                              || (r.roomNumber?.toLowerCase().contains(q) ?? false);
                        }).toList();
                  if (filtered.isEmpty) {
                    return const Padding(
                      padding: EdgeInsets.all(24),
                      child: Center(child: Text(
                        'No in-house guests match.',
                        style: TextStyle(color: KevernaColors.inkMute),
                      )),
                    );
                  }
                  return ListView.separated(
                    itemCount: filtered.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (context, i) => _guestRow(filtered[i]),
                  );
                },
                loading: () => const Center(child: CircularProgressIndicator()),
                error: (e, _) => Center(child: Text('Load failed: $e')),
              ),
            ),
            SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                child: TextButton.icon(
                  onPressed: () => Navigator.of(context).pop(RequestManualEntry()),
                  icon: const Icon(Icons.edit, size: 16),
                  label: const Text("Guest isn't listed — type ref manually"),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _guestRow(ReservationSummary r) {
    return ListTile(
      onTap: () => Navigator.of(context).pop(PickedBooking(r.bookingRef)),
      title: Text(r.guestName),
      subtitle: Text(
        '${r.bookingRef} · ${r.roomNumber != null ? 'Rm ${r.roomNumber}' : 'Unassigned'}',
      ),
      trailing: const Icon(Icons.arrow_forward_ios, size: 14, color: KevernaColors.inkMute),
    );
  }
}
