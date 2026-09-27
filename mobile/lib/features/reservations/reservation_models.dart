// Plain dataclasses mirroring ReservationResource on the server. Kept
// manual so a fresh contributor can read the shape without running
// codegen.

class ReservationSummary {
  const ReservationSummary({
    required this.id,
    required this.bookingRef,
    required this.guestName,
    required this.status,
    required this.statusLabel,
    required this.checkinDate,
    required this.checkoutDate,
    required this.balanceDue,
    this.roomNumber,
    this.categoryName,
  });

  final int id;
  final String bookingRef;
  final String guestName;
  final String status;
  final String statusLabel;
  final DateTime? checkinDate;
  final DateTime? checkoutDate;
  final int balanceDue;
  final String? roomNumber;
  final String? categoryName;

  factory ReservationSummary.fromJson(Map<String, dynamic> j) {
    final room = j['room'] as Map<String, dynamic>?;
    return ReservationSummary(
      id: j['id'] as int,
      bookingRef: j['booking_ref'] as String,
      guestName: (j['guest_name'] as String?) ?? '',
      status: j['status'] as String,
      statusLabel: j['status_label'] as String,
      checkinDate: _parseDateOnly(j['checkin_date'] as String?),
      checkoutDate: _parseDateOnly(j['checkout_date'] as String?),
      balanceDue: (j['balance_due'] as int?) ?? 0,
      roomNumber: room?['room_number'] as String?,
      categoryName: room?['category_name'] as String?,
    );
  }
}

class ReservationCharge {
  const ReservationCharge({
    required this.id,
    required this.description,
    required this.amount,
    required this.settled,
    this.type,
    this.settledMethod,
  });

  final int id;
  final String? type;
  final String description;
  final int amount;
  final bool settled;
  final String? settledMethod;

  factory ReservationCharge.fromJson(Map<String, dynamic> j) => ReservationCharge(
        id: j['id'] as int,
        type: j['type'] as String?,
        description: (j['description'] as String?) ?? '',
        amount: (j['amount'] as int?) ?? 0,
        settled: (j['settled'] as bool?) ?? false,
        settledMethod: j['settled_method'] as String?,
      );
}

class ReservationPayment {
  const ReservationPayment({
    required this.id,
    required this.amount,
    required this.method,
    this.status,
    this.referenceNote,
    this.paidAt,
  });

  final int id;
  final int amount;
  final String method;
  final String? status;
  final String? referenceNote;
  final DateTime? paidAt;

  factory ReservationPayment.fromJson(Map<String, dynamic> j) => ReservationPayment(
        id: j['id'] as int,
        amount: (j['amount'] as int?) ?? 0,
        method: (j['method'] as String?) ?? 'other',
        status: j['status'] as String?,
        referenceNote: j['reference_note'] as String?,
        paidAt: j['paid_at'] != null ? DateTime.tryParse(j['paid_at'] as String) : null,
      );
}

class ReservationDetail {
  const ReservationDetail({
    required this.id,
    required this.bookingRef,
    required this.guestName,
    required this.status,
    required this.statusLabel,
    required this.checkinDate,
    required this.checkoutDate,
    required this.totalAmount,
    required this.balanceDue,
    required this.charges,
    required this.payments,
    this.guestEmail,
    this.guestPhone,
    this.roomNumber,
    this.categoryName,
    this.specialRequests,
  });

  final int id;
  final String bookingRef;
  final String guestName;
  final String? guestEmail;
  final String? guestPhone;
  final String status;
  final String statusLabel;
  final DateTime? checkinDate;
  final DateTime? checkoutDate;
  final int totalAmount;
  final int balanceDue;
  final String? roomNumber;
  final String? categoryName;
  final String? specialRequests;
  final List<ReservationCharge> charges;
  final List<ReservationPayment> payments;

  bool get canCheckIn => status == 'confirmed' || status == 'on_hold' || status == 'pending';
  bool get canCheckOut => status == 'checked_in';
  bool get canNoShow => status == 'confirmed' || status == 'on_hold' || status == 'pending';
  bool get canCancel =>
      status != 'cancelled' && status != 'checked_out' && status != 'no_show' && status != 'checked_in';

  factory ReservationDetail.fromJson(Map<String, dynamic> j) {
    final room = j['room'] as Map<String, dynamic>?;
    return ReservationDetail(
      id: j['id'] as int,
      bookingRef: j['booking_ref'] as String,
      guestName: (j['guest_name'] as String?) ?? '',
      guestEmail: j['guest_email'] as String?,
      guestPhone: j['guest_phone'] as String?,
      status: j['status'] as String,
      statusLabel: j['status_label'] as String,
      checkinDate: _parseDateOnly(j['checkin_date'] as String?),
      checkoutDate: _parseDateOnly(j['checkout_date'] as String?),
      totalAmount: (j['total_amount'] as int?) ?? 0,
      balanceDue: (j['balance_due'] as int?) ?? 0,
      roomNumber: room?['room_number'] as String?,
      categoryName: room?['category_name'] as String?,
      specialRequests: j['special_requests'] as String?,
      charges: ((j['charges'] as List?) ?? const [])
          .map((e) => ReservationCharge.fromJson((e as Map).cast<String, dynamic>()))
          .toList(),
      payments: ((j['payments'] as List?) ?? const [])
          .map((e) => ReservationPayment.fromJson((e as Map).cast<String, dynamic>()))
          .toList(),
    );
  }
}

DateTime? _parseDateOnly(String? raw) {
  if (raw == null) return null;
  try {
    return DateTime.parse(raw);
  } catch (_) {
    return null;
  }
}
