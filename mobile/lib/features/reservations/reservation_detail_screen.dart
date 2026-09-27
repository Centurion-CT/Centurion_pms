// Per-reservation screen — guest info, charge/payment ledger, and
// role-appropriate action buttons (Check-in, Check-out, No-show,
// Cancel, Take payment).

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/outbox/mutation_service.dart';
import '../../core/outbox/outbox_providers.dart';
import '../../core/theme/keverna_theme.dart';
import 'reservation_models.dart';
import 'reservations_repository.dart';
import 'take_payment_sheet.dart';

class ReservationDetailScreen extends ConsumerStatefulWidget {
  const ReservationDetailScreen({super.key, required this.bookingRef});

  final String bookingRef;

  @override
  ConsumerState<ReservationDetailScreen> createState() => _ReservationDetailScreenState();
}

class _ReservationDetailScreenState extends ConsumerState<ReservationDetailScreen> {
  bool _busy = false;
  String? _busyLabel;

  Future<void> _run(
    Future<ReservationDetail> Function() action,
    String label, {
    String? queuedMessage,
  }) async {
    setState(() {
      _busy = true;
      _busyLabel = label;
    });
    try {
      await action();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$label — done.')),
        );
      }
    } on QueuedForRetry {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(queuedMessage ?? 'Saved locally — will sync when back online.'),
          backgroundColor: KevernaColors.accentDeep,
        ));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('$label failed: $e'),
          backgroundColor: KevernaColors.danger,
        ));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final repo = ref.watch(reservationsRepositoryProvider);
    final async = ref.watch(reservationDetailProvider(widget.bookingRef));

    return Scaffold(
      appBar: AppBar(title: Text(widget.bookingRef)),
      body: async.when(
        data: (r) {
          if (r == null) return const Center(child: CircularProgressIndicator());
          return _detail(r, repo);
        },
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: Text('Load failed: $e')),
      ),
    );
  }

  Widget _detail(ReservationDetail r, ReservationsRepository? repo) {
    final syncing = ref.watch(reservationHasPendingWriteProvider(r.bookingRef))
            .valueOrNull ??
        false;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(r.guestName, style: Theme.of(context).textTheme.displaySmall),
        const SizedBox(height: 6),
        Wrap(
          spacing: 8,
          runSpacing: 6,
          children: [
            _pill(r.statusLabel, _statusPalette(r.status)),
            if (r.roomNumber != null) _pill('Rm ${r.roomNumber}', _neutralPalette),
            if (r.checkinDate != null && r.checkoutDate != null)
              _pill(
                '${DateFormat('d MMM').format(r.checkinDate!)} → ${DateFormat('d MMM').format(r.checkoutDate!)}',
                _neutralPalette,
              ),
            if (syncing) const _SyncingChip(),
          ],
        ),
        const SizedBox(height: 20),

        // ── Guest contact ──
        _sectionTitle('Guest'),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (r.guestEmail != null) _kv('Email', r.guestEmail!),
                if (r.guestPhone != null) _kv('Phone', r.guestPhone!),
                if (r.specialRequests != null && r.specialRequests!.isNotEmpty)
                  _kv('Requests', r.specialRequests!),
              ],
            ),
          ),
        ),
        const SizedBox(height: 18),

        // ── Ledger ──
        _sectionTitle('Ledger'),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _kv('Total', '₦${_money(r.totalAmount)}'),
                _kv('Balance', '₦${_money(r.balanceDue)}',
                    valueColor: r.balanceDue > 0 ? KevernaColors.danger : KevernaColors.success),
                if (r.payments.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text('Payments', style: Theme.of(context).textTheme.labelSmall),
                  const SizedBox(height: 4),
                  ...r.payments.map((p) => _paymentRow(p)),
                ],
                if (r.charges.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text('Charges', style: Theme.of(context).textTheme.labelSmall),
                  const SizedBox(height: 4),
                  ...r.charges.map((c) => _chargeRow(c)),
                ],
              ],
            ),
          ),
        ),
        const SizedBox(height: 24),

        // ── Actions ──
        _actionButtons(r, repo),
      ],
    );
  }

  Widget _actionButtons(ReservationDetail r, ReservationsRepository? repo) {
    if (repo == null) return const SizedBox.shrink();
    final buttons = <Widget>[];

    if (r.balanceDue > 0) {
      buttons.add(ElevatedButton.icon(
        onPressed: _busy
            ? null
            : () async {
                final result = await showModalBottomSheet<TakePaymentResult>(
                  context: context,
                  isScrollControlled: true,
                  builder: (_) => TakePaymentSheet(defaultAmount: r.balanceDue),
                );
                if (result == null) return;
                await _run(
                  () => repo.recordPayment(r.bookingRef,
                      amount: result.amount, method: result.method, referenceNote: result.referenceNote),
                  'Payment recorded',
                  queuedMessage: 'Payment saved locally — will sync when back online.',
                );
              },
        icon: const Icon(Icons.payments_outlined),
        label: const Text('Take payment'),
      ));
    }
    if (r.canCheckIn) {
      buttons.add(ElevatedButton(
        onPressed: _busy
            ? null
            : () => _run(
                  () => repo.checkIn(r.bookingRef, guestName: r.guestName),
                  'Checked in',
                ),
        child: const Text('Check in'),
      ));
    }
    if (r.canCheckOut) {
      buttons.add(ElevatedButton(
        onPressed: _busy
            ? null
            : () => _run(
                  () => repo.checkOut(r.bookingRef, guestName: r.guestName),
                  'Checked out',
                ),
        child: const Text('Check out'),
      ));
    }
    if (r.canNoShow) {
      buttons.add(TextButton(
        onPressed: _busy
            ? null
            : () async {
                final confirmed = await _confirm(
                  title: 'Mark as no-show?',
                  body: 'The room re-enters inventory. Deposit is forfeited by default.',
                );
                if (!confirmed) return;
                await _run(() => repo.markNoShow(r.bookingRef), 'Marked no-show');
              },
        style: TextButton.styleFrom(foregroundColor: KevernaColors.danger),
        child: const Text('No-show'),
      ));
    }
    if (r.canCancel) {
      buttons.add(TextButton(
        onPressed: _busy
            ? null
            : () async {
                final confirmed = await _confirm(
                  title: 'Cancel this reservation?',
                  body: 'Nights are released. Any deposit refund happens separately.',
                );
                if (!confirmed) return;
                await _run(() => repo.cancel(r.bookingRef), 'Cancelled');
              },
        style: TextButton.styleFrom(foregroundColor: KevernaColors.danger),
        child: const Text('Cancel booking'),
      ));
    }

    if (buttons.isEmpty) return const SizedBox.shrink();
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      alignment: WrapAlignment.end,
      children: buttons,
    );
  }

  Future<bool> _confirm({required String title, required String body}) async {
    return await showDialog<bool>(
          context: context,
          builder: (_) => AlertDialog(
            title: Text(title),
            content: Text(body),
            actions: [
              TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Back')),
              TextButton(
                onPressed: () => Navigator.pop(context, true),
                style: TextButton.styleFrom(foregroundColor: KevernaColors.danger),
                child: const Text('Confirm'),
              ),
            ],
          ),
        ) ??
        false;
  }

  // ── UI helpers ──

  Widget _sectionTitle(String label) {
    return Padding(
      padding: const EdgeInsets.only(left: 4, bottom: 8),
      child: Text(label.toUpperCase(),
          style: Theme.of(context).textTheme.labelSmall),
    );
  }

  Widget _kv(String label, String value, {Color? valueColor}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 88,
            child: Text(label,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(color: KevernaColors.inkMute)),
          ),
          Expanded(
            child: Text(value,
                style: Theme.of(context).textTheme.bodyLarge?.copyWith(color: valueColor)),
          ),
        ],
      ),
    );
  }

  Widget _paymentRow(ReservationPayment p) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Text(
              '${p.method}${p.referenceNote != null ? ' · ${p.referenceNote}' : ''}',
              style: Theme.of(context).textTheme.bodyMedium,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          Text('₦${_money(p.amount)}',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  Widget _chargeRow(ReservationCharge c) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Text(
              '${c.description}${c.settled ? ' · settled${c.settledMethod != null ? ' (${c.settledMethod})' : ''}' : ''}',
              style: Theme.of(context).textTheme.bodyMedium,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          Text('₦${_money(c.amount)}',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                    color: c.settled ? KevernaColors.inkMute : null,
                    decoration: c.settled ? TextDecoration.lineThrough : null,
                  )),
        ],
      ),
    );
  }

  Widget _pill(String label, ({Color bg, Color fg}) palette) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: palette.bg, borderRadius: BorderRadius.circular(999)),
      child: Text(label,
          style: TextStyle(color: palette.fg, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }

  ({Color bg, Color fg}) _statusPalette(String status) => switch (status) {
        'confirmed' || 'checked_in' => (
            bg: KevernaColors.success.withValues(alpha: 0.12),
            fg: KevernaColors.success
          ),
        'on_hold' || 'pending' => (
            bg: KevernaColors.warn.withValues(alpha: 0.14),
            fg: const Color(0xFF92400E)
          ),
        'cancelled' || 'no_show' => (
            bg: KevernaColors.danger.withValues(alpha: 0.10),
            fg: KevernaColors.danger
          ),
        _ => _neutralPalette,
      };

  static const _neutralPalette = (bg: KevernaColors.cardDeep, fg: KevernaColors.inkSoft);

  String _money(int amount) => NumberFormat.decimalPattern().format(amount);
}

/// Small "Syncing…" pill that appears while an outbox entry
/// targeting this entity is still in flight. Auto-disappears when
/// the entry drains + the stream re-emits.
class _SyncingChip extends StatelessWidget {
  const _SyncingChip();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: KevernaColors.accent.withValues(alpha: 0.28),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: const [
          SizedBox(
            width: 10, height: 10,
            child: CircularProgressIndicator(
              strokeWidth: 1.6,
              color: KevernaColors.accentDeep,
            ),
          ),
          SizedBox(width: 6),
          Text('Syncing…',
              style: TextStyle(
                color: KevernaColors.accentDeep,
                fontSize: 12, fontWeight: FontWeight.w700,
              )),
        ],
      ),
    );
  }
}
