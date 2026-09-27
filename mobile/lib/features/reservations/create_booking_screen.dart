// Manual walk-in booking form. Single scrollable page:
//   - Dates (checkin + checkout) with duration hint.
//   - Party size (adults + children steppers).
//   - Room picker — fetches on-demand once dates + party are set.
//     Shows per-row rate + capacity + optional breakfast tier.
//   - Guest info (name + phone required, email optional).
//   - Optional: breakfast tier, discount code, special requests.
//   - Optional: settle-now (deposit or full) with method dropdown.
//   - Submit — creates booking via MutationService; jumps to the
//     new reservation detail on success.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/outbox/mutation_service.dart';
import '../../core/theme/keverna_theme.dart';
import 'available_room_models.dart';
import 'reservations_repository.dart';

class CreateBookingScreen extends ConsumerStatefulWidget {
  const CreateBookingScreen({super.key});

  @override
  ConsumerState<CreateBookingScreen> createState() => _CreateBookingScreenState();
}

class _CreateBookingScreenState extends ConsumerState<CreateBookingScreen> {
  DateTime _checkin = DateTime.now();
  DateTime _checkout = DateTime.now().add(const Duration(days: 1));
  int _adults = 1;
  int _children = 0;

  List<AvailableRoom>? _rooms;
  bool _loadingRooms = false;
  AvailableRoom? _selectedRoom;
  bool _breakfastIncluded = false;

  final _nameCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _requestsCtrl = TextEditingController();
  final _discountCtrl = TextEditingController();
  final _sourceNoteCtrl = TextEditingController();

  String _source = 'walk_in';
  String _paymentType = 'full';   // 'full' | 'deposit'

  bool _settleNow = false;
  final _settleAmountCtrl = TextEditingController();
  String _settleMethod = 'cash';
  final _settleRefCtrl = TextEditingController();

  bool _busy = false;

  @override
  void dispose() {
    _nameCtrl.dispose();
    _emailCtrl.dispose();
    _phoneCtrl.dispose();
    _requestsCtrl.dispose();
    _discountCtrl.dispose();
    _sourceNoteCtrl.dispose();
    _settleAmountCtrl.dispose();
    _settleRefCtrl.dispose();
    super.dispose();
  }

  int get _nights => _checkout.difference(_checkin).inDays;

  int get _estimatedTotal {
    if (_selectedRoom == null || _nights <= 0) return 0;
    return _selectedRoom!.rateFor(breakfastIncluded: _breakfastIncluded) * _nights;
  }

  Future<void> _pickDates() async {
    final now = DateTime.now();
    final range = await showDateRangePicker(
      context: context,
      firstDate: now,
      lastDate: now.add(const Duration(days: 365)),
      initialDateRange: DateTimeRange(start: _checkin, end: _checkout),
    );
    if (range == null) return;
    setState(() {
      _checkin = range.start;
      _checkout = range.end;
      _selectedRoom = null;   // dates changed → re-pick room
      _rooms = null;
    });
    await _loadRooms();
  }

  Future<void> _loadRooms() async {
    if (_nights <= 0) return;
    setState(() {
      _loadingRooms = true;
      _selectedRoom = null;
    });
    final repo = ref.read(reservationsRepositoryProvider);
    if (repo == null) {
      setState(() => _loadingRooms = false);
      return;
    }
    try {
      final rooms = await repo.availableRooms(
        checkin: _checkin,
        checkout: _checkout,
        adults: _adults,
        children: _children,
      );
      if (!mounted) return;
      setState(() => _rooms = rooms);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Couldn\'t load rooms: $e'),
        backgroundColor: KevernaColors.danger,
      ));
    } finally {
      if (mounted) setState(() => _loadingRooms = false);
    }
  }

  bool get _canSubmit {
    if (_selectedRoom == null) return false;
    if (_nights <= 0) return false;
    if (_nameCtrl.text.trim().isEmpty) return false;
    if (_phoneCtrl.text.trim().length < 8) return false;
    return true;
  }

  Future<void> _submit() async {
    if (!_canSubmit || _busy) return;
    setState(() => _busy = true);

    final repo = ref.read(reservationsRepositoryProvider);
    if (repo == null) return;

    final email = _emailCtrl.text.trim().isEmpty
        ? '${_phoneCtrl.text.trim().replaceAll(RegExp(r'\D'), '')}@walkin.keverna.local'
        : _emailCtrl.text.trim();

    final settleAmount = _settleNow
        ? (int.tryParse(_settleAmountCtrl.text.trim()) ?? 0)
        : null;

    try {
      final r = await repo.createBooking(
        roomId: _selectedRoom!.id,
        guestName: _nameCtrl.text.trim(),
        guestEmail: email,
        guestPhone: _phoneCtrl.text.trim(),
        adults: _adults,
        children: _children,
        checkin: _checkin,
        checkout: _checkout,
        paymentType: _paymentType,
        source: _source,
        sourceNote: _sourceNoteCtrl.text.trim().isEmpty ? null : _sourceNoteCtrl.text.trim(),
        specialRequests: _requestsCtrl.text.trim().isEmpty ? null : _requestsCtrl.text.trim(),
        breakfastIncluded: _breakfastIncluded,
        discountCode: _discountCtrl.text.trim().isEmpty ? null : _discountCtrl.text.trim(),
        settleAmount: settleAmount,
        settleMethod: _settleNow ? _settleMethod : null,
        settleReferenceNote: _settleNow && _settleRefCtrl.text.trim().isNotEmpty
            ? _settleRefCtrl.text.trim()
            : null,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Booked ${r.bookingRef} for ${r.guestName}.'),
      ));
      // Replace the form with the fresh reservation detail so the
      // operator lands where they can take payment / check-in.
      context.pushReplacement('/reservations/${r.bookingRef}');
    } on QueuedForRetry catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Booking saved locally — will sync when back online.'),
        backgroundColor: KevernaColors.accentDeep,
      ));
      context.pop();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text('Booking failed: $e'),
        backgroundColor: KevernaColors.danger,
      ));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('New booking')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _sectionTitle('When'),
          Card(
            child: ListTile(
              leading: const Icon(Icons.calendar_month_outlined),
              title: Text(
                '${DateFormat('EEE d MMM').format(_checkin)}  →  ${DateFormat('EEE d MMM').format(_checkout)}',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              subtitle: Text('$_nights ${_nights == 1 ? 'night' : 'nights'}'),
              trailing: TextButton(onPressed: _pickDates, child: const Text('Change')),
            ),
          ),

          const SizedBox(height: 16),
          _sectionTitle('Guests'),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                children: [
                  _stepper('Adults', _adults, min: 1,
                      onChange: (v) => setState(() { _adults = v; _rooms = null; })),
                  const Divider(height: 12),
                  _stepper('Children', _children, min: 0,
                      onChange: (v) => setState(() { _children = v; _rooms = null; })),
                ],
              ),
            ),
          ),

          const SizedBox(height: 16),
          _sectionTitle('Room'),
          if (_rooms == null && !_loadingRooms)
            OutlinedButton.icon(
              onPressed: _loadRooms,
              icon: const Icon(Icons.search),
              label: const Text('Find available rooms'),
            )
          else if (_loadingRooms)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_rooms!.isEmpty)
            Card(
              color: KevernaColors.danger.withValues(alpha: 0.06),
              child: const ListTile(
                leading: Icon(Icons.event_busy_outlined, color: KevernaColors.danger),
                title: Text('No rooms fit that date range + party.'),
                subtitle: Text('Try different dates or reduce the party size.'),
              ),
            )
          else
            ..._rooms!.map((r) => _roomTile(r)),

          if (_selectedRoom != null &&
              (_selectedRoom!.category?.hasBreakfastOption ?? false)) ...[
            const SizedBox(height: 8),
            SwitchListTile(
              value: _breakfastIncluded,
              onChanged: (v) => setState(() => _breakfastIncluded = v),
              title: const Text('Include breakfast'),
              subtitle: Text(
                'Rate becomes ₦${_money(_selectedRoom!.category!.breakfastRate ?? 0)} / night',
              ),
              contentPadding: EdgeInsets.zero,
              activeThumbColor: KevernaColors.accentDeep,
            ),
          ],

          const SizedBox(height: 16),
          _sectionTitle('Guest'),
          _kevernaField(_nameCtrl, 'Full name'),
          const SizedBox(height: 8),
          _kevernaField(_phoneCtrl, 'Phone', keyboardType: TextInputType.phone),
          const SizedBox(height: 8),
          _kevernaField(_emailCtrl, 'Email (optional)', keyboardType: TextInputType.emailAddress),

          const SizedBox(height: 16),
          _sectionTitle('Source'),
          DropdownButtonFormField<String>(
            initialValue: _source,
            decoration: const InputDecoration(labelText: 'How did they book?'),
            items: const [
              DropdownMenuItem(value: 'walk_in', child: Text('Walk-in')),
              DropdownMenuItem(value: 'phone', child: Text('Phone')),
              DropdownMenuItem(value: 'whatsapp', child: Text('WhatsApp')),
              DropdownMenuItem(value: 'other', child: Text('Other')),
            ],
            onChanged: (v) => setState(() => _source = v ?? 'walk_in'),
          ),
          const SizedBox(height: 8),
          _kevernaField(_sourceNoteCtrl, 'Source note (optional)',
              hint: 'e.g. Regular from Chidi Hotel'),

          const SizedBox(height: 16),
          ExpansionTile(
            tilePadding: EdgeInsets.zero,
            title: Text('More options', style: Theme.of(context).textTheme.titleMedium),
            children: [
              _kevernaField(_requestsCtrl, 'Special requests (optional)',
                  hint: 'e.g. Early check-in, extra pillows', maxLines: 2),
              const SizedBox(height: 8),
              _kevernaField(_discountCtrl, 'Discount code (optional)'),
            ],
          ),

          const SizedBox(height: 16),
          _sectionTitle('Payment'),
          DropdownButtonFormField<String>(
            initialValue: _paymentType,
            decoration: const InputDecoration(labelText: 'Booking payment type'),
            items: const [
              DropdownMenuItem(value: 'full', child: Text('Full payment')),
              DropdownMenuItem(value: 'deposit', child: Text('Deposit only')),
            ],
            onChanged: (v) => setState(() => _paymentType = v ?? 'full'),
          ),
          const SizedBox(height: 12),
          SwitchListTile(
            value: _settleNow,
            onChanged: (v) => setState(() => _settleNow = v),
            title: const Text('Take payment now'),
            subtitle: const Text('Record cash / transfer / card at the same time.'),
            contentPadding: EdgeInsets.zero,
            activeThumbColor: KevernaColors.accentDeep,
          ),
          if (_settleNow) ...[
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                flex: 3,
                child: TextField(
                  controller: _settleAmountCtrl,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: const InputDecoration(labelText: '₦ Amount'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                flex: 4,
                child: DropdownButtonFormField<String>(
                  initialValue: _settleMethod,
                  decoration: const InputDecoration(labelText: 'Method'),
                  items: const [
                    DropdownMenuItem(value: 'cash', child: Text('Cash')),
                    DropdownMenuItem(value: 'transfer', child: Text('Bank transfer')),
                    DropdownMenuItem(value: 'card', child: Text('Card')),
                    DropdownMenuItem(value: 'pos', child: Text('POS')),
                    DropdownMenuItem(value: 'other', child: Text('Other')),
                  ],
                  onChanged: (v) => setState(() => _settleMethod = v ?? 'cash'),
                ),
              ),
            ]),
            const SizedBox(height: 8),
            _kevernaField(_settleRefCtrl, 'Reference / note (optional)'),
          ],

          const SizedBox(height: 20),
          if (_selectedRoom != null)
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: KevernaColors.cardDeep,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: KevernaColors.lineSoft),
              ),
              child: Row(
                children: [
                  const Icon(Icons.receipt_long_outlined, color: KevernaColors.accentDeep),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Rm ${_selectedRoom!.roomNumber ?? '—'} · $_nights ${_nights == 1 ? 'night' : 'nights'}',
                          style: Theme.of(context).textTheme.bodyMedium,
                        ),
                        const SizedBox(height: 2),
                        Text('₦${_money(_estimatedTotal)}',
                            style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                                  color: KevernaColors.accentDeep,
                                  fontWeight: FontWeight.w700,
                                )),
                      ],
                    ),
                  ),
                  ElevatedButton(
                    onPressed: _busy || !_canSubmit ? null : _submit,
                    child: Text(_busy ? 'Saving…' : 'Book'),
                  ),
                ],
              ),
            )
          else
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: _busy || !_canSubmit ? null : _submit,
                child: Text(_busy ? 'Saving…' : 'Book'),
              ),
            ),
          const SizedBox(height: 30),
        ],
      ),
    );
  }

  Widget _sectionTitle(String label) => Padding(
        padding: const EdgeInsets.only(bottom: 8, top: 4),
        child: Text(label.toUpperCase(), style: Theme.of(context).textTheme.labelSmall),
      );

  Widget _stepper(String label, int value, {int min = 0, required void Function(int) onChange}) {
    return Row(
      children: [
        Expanded(child: Text(label, style: Theme.of(context).textTheme.titleMedium)),
        IconButton(
          icon: const Icon(Icons.remove_circle_outline),
          onPressed: value > min ? () => onChange(value - 1) : null,
        ),
        SizedBox(
          width: 32,
          child: Text('$value',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleLarge),
        ),
        IconButton(
          icon: const Icon(Icons.add_circle_outline),
          onPressed: value < 20 ? () => onChange(value + 1) : null,
        ),
      ],
    );
  }

  Widget _kevernaField(TextEditingController ctrl, String label,
      {String? hint, TextInputType? keyboardType, int maxLines = 1}) {
    return TextField(
      controller: ctrl,
      keyboardType: keyboardType,
      maxLines: maxLines,
      onChanged: (_) => setState(() {}),
      decoration: InputDecoration(labelText: label, hintText: hint),
    );
  }

  Widget _roomTile(AvailableRoom r) {
    final selected = _selectedRoom?.id == r.id;
    final rate = r.rateFor(breakfastIncluded: false);
    final cat = r.category;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      color: selected ? KevernaColors.accent.withValues(alpha: 0.20) : null,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(
          color: selected ? KevernaColors.accentDeep : KevernaColors.lineSoft,
          width: selected ? 1.6 : 1,
        ),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () => setState(() {
          _selectedRoom = r;
          if (!(cat?.hasBreakfastOption ?? false)) _breakfastIncluded = false;
        }),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Rm ${r.roomNumber ?? '—'} · ${cat?.name ?? 'Uncategorised'}',
                        style: Theme.of(context).textTheme.titleMedium),
                    if (cat != null)
                      Text(
                        'Sleeps ${cat.capacityAdults} adult${cat.capacityAdults == 1 ? '' : 's'}'
                        '${cat.capacityChildren > 0 ? ' + ${cat.capacityChildren} child${cat.capacityChildren == 1 ? '' : 'ren'}' : ''}',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text('₦${_money(rate)}',
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            color: KevernaColors.accentDeep,
                            fontWeight: FontWeight.w700,
                          )),
                  Text('per night', style: Theme.of(context).textTheme.bodySmall),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _money(int amount) => NumberFormat.decimalPattern().format(amount);
}
