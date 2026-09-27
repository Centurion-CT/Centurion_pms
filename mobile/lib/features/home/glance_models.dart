// Dataclasses mirroring the /manager/glance JSON. Manual parsing so
// the schema is grep-able; the payload is small enough that codegen
// would be overkill.

class GlanceKpis {
  const GlanceKpis({
    required this.arrivalsToday,
    required this.departuresToday,
    required this.inHouse,
    required this.outstandingTotal,
    required this.openInspections,
    required this.rejectedInspections,
    required this.pendingStockRequests,
    required this.todayPosRevenue,
    required this.todayBookingRevenue,
  });

  final int arrivalsToday;
  final int departuresToday;
  final int inHouse;
  final int outstandingTotal;
  final int openInspections;
  final int rejectedInspections;
  final int pendingStockRequests;
  final int todayPosRevenue;
  final int todayBookingRevenue;

  factory GlanceKpis.fromJson(Map<String, dynamic> j) => GlanceKpis(
        arrivalsToday: (j['arrivals_today'] as int?) ?? 0,
        departuresToday: (j['departures_today'] as int?) ?? 0,
        inHouse: (j['in_house'] as int?) ?? 0,
        outstandingTotal: (j['outstanding_total'] as int?) ?? 0,
        openInspections: (j['open_inspections'] as int?) ?? 0,
        rejectedInspections: (j['rejected_inspections'] as int?) ?? 0,
        pendingStockRequests: (j['pending_stock_requests'] as int?) ?? 0,
        todayPosRevenue: (j['today_pos_revenue'] as int?) ?? 0,
        todayBookingRevenue: (j['today_booking_revenue'] as int?) ?? 0,
      );
}

class GlanceAttentionBooking {
  const GlanceAttentionBooking({
    required this.bookingRef,
    required this.guestName,
    required this.status,
    required this.balanceDue,
    this.checkinDate,
    this.roomNumber,
  });

  final String bookingRef;
  final String guestName;
  final String status;
  final int balanceDue;
  final DateTime? checkinDate;
  final String? roomNumber;

  factory GlanceAttentionBooking.fromJson(Map<String, dynamic> j) => GlanceAttentionBooking(
        bookingRef: (j['booking_ref'] as String?) ?? '',
        guestName: (j['guest_name'] as String?) ?? '',
        status: (j['status'] as String?) ?? '',
        balanceDue: (j['balance_due'] as int?) ?? 0,
        checkinDate:
            j['checkin_date'] != null ? DateTime.tryParse(j['checkin_date'] as String) : null,
        roomNumber: j['room_number'] as String?,
      );
}

class GlanceAttentionInspection {
  const GlanceAttentionInspection({
    required this.id,
    this.roomNumber,
    this.categoryName,
    this.summaryNote,
    this.rejectedAt,
  });

  final int id;
  final String? roomNumber;
  final String? categoryName;
  final String? summaryNote;
  final DateTime? rejectedAt;

  factory GlanceAttentionInspection.fromJson(Map<String, dynamic> j) =>
      GlanceAttentionInspection(
        id: j['id'] as int,
        roomNumber: j['room_number'] as String?,
        categoryName: j['category_name'] as String?,
        summaryNote: j['summary_note'] as String?,
        rejectedAt:
            j['rejected_at'] != null ? DateTime.tryParse(j['rejected_at'] as String) : null,
      );
}

class GlanceAttentionStockRequest {
  const GlanceAttentionStockRequest({
    required this.id,
    required this.quantity,
    this.outletName,
    this.productName,
    this.requestedAt,
  });

  final int id;
  final int quantity;
  final String? outletName;
  final String? productName;
  final DateTime? requestedAt;

  factory GlanceAttentionStockRequest.fromJson(Map<String, dynamic> j) =>
      GlanceAttentionStockRequest(
        id: j['id'] as int,
        quantity: (j['quantity'] as int?) ?? 0,
        outletName: j['outlet_name'] as String?,
        productName: j['product_name'] as String?,
        requestedAt:
            j['requested_at'] != null ? DateTime.tryParse(j['requested_at'] as String) : null,
      );
}

class ManagerGlance {
  const ManagerGlance({
    required this.asOf,
    required this.kpis,
    required this.bookingsNeedingAction,
    required this.rejectedInspections,
    required this.pendingStockRequests,
  });

  final DateTime asOf;
  final GlanceKpis kpis;
  final List<GlanceAttentionBooking> bookingsNeedingAction;
  final List<GlanceAttentionInspection> rejectedInspections;
  final List<GlanceAttentionStockRequest> pendingStockRequests;

  factory ManagerGlance.fromJson(Map<String, dynamic> j) {
    final att = (j['attention'] as Map).cast<String, dynamic>();
    return ManagerGlance(
      asOf: DateTime.tryParse(j['as_of'] as String? ?? '') ?? DateTime.now(),
      kpis: GlanceKpis.fromJson((j['kpis'] as Map).cast<String, dynamic>()),
      bookingsNeedingAction: ((att['bookings_needing_action'] as List?) ?? const [])
          .map((e) => GlanceAttentionBooking.fromJson((e as Map).cast<String, dynamic>()))
          .toList(),
      rejectedInspections: ((att['rejected_inspections'] as List?) ?? const [])
          .map((e) => GlanceAttentionInspection.fromJson((e as Map).cast<String, dynamic>()))
          .toList(),
      pendingStockRequests: ((att['pending_stock_requests'] as List?) ?? const [])
          .map((e) => GlanceAttentionStockRequest.fromJson((e as Map).cast<String, dynamic>()))
          .toList(),
    );
  }
}
