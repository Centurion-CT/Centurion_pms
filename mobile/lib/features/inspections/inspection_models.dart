// Plain dataclasses mirroring the InspectionResource JSON shape from
// app/Http/Resources/Mobile/InspectionResource.php. Kept manual (not
// generated) so a fresh contributor can read the schema without
// running codegen.

class InspectionSummary {
  const InspectionSummary({
    required this.id,
    required this.status,
    required this.statusLabel,
    required this.waitingOn,
    required this.roomNumber,
    required this.categoryName,
    required this.createdAt,
    this.triggeredByRef,
    this.triggeredByGuest,
  });

  final int id;
  final String status;
  final String statusLabel;
  final String waitingOn;
  final String? roomNumber;
  final String? categoryName;
  final DateTime createdAt;
  final String? triggeredByRef;
  final String? triggeredByGuest;

  factory InspectionSummary.fromJson(Map<String, dynamic> j) {
    final room = j['room'] as Map<String, dynamic>?;
    final trig = j['triggered_by'] as Map<String, dynamic>?;
    return InspectionSummary(
      id: j['id'] as int,
      status: j['status'] as String,
      statusLabel: j['status_label'] as String,
      waitingOn: j['waiting_on'] as String,
      roomNumber: room?['room_number'] as String?,
      categoryName: room?['category_name'] as String?,
      createdAt: DateTime.parse(j['created_at'] as String),
      triggeredByRef: trig?['booking_ref'] as String?,
      triggeredByGuest: trig?['guest_name'] as String?,
    );
  }
}

class InspectionItem {
  InspectionItem({
    required this.id,
    required this.label,
    required this.checked,
    required this.sortOrder,
    this.damageNote,
  });

  final int id;
  final String label;
  bool checked;
  String? damageNote;
  final int sortOrder;

  factory InspectionItem.fromJson(Map<String, dynamic> j) {
    return InspectionItem(
      id: j['id'] as int,
      label: j['label'] as String,
      checked: j['checked'] as bool,
      damageNote: j['damage_note'] as String?,
      sortOrder: j['sort_order'] as int,
    );
  }
}

class InspectionDetail {
  const InspectionDetail({
    required this.id,
    required this.status,
    required this.statusLabel,
    required this.readinessScore,
    required this.items,
    required this.roomNumber,
    required this.categoryName,
    this.summaryNote,
  });

  final int id;
  final String status;
  final String statusLabel;
  final int readinessScore;
  final List<InspectionItem> items;
  final String? roomNumber;
  final String? categoryName;
  final String? summaryNote;

  factory InspectionDetail.fromJson(Map<String, dynamic> j) {
    final room = j['room'] as Map<String, dynamic>?;
    final items = (j['items'] as List)
        .map((e) => InspectionItem.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
    return InspectionDetail(
      id: j['id'] as int,
      status: j['status'] as String,
      statusLabel: j['status_label'] as String,
      readinessScore: (j['readiness_score'] as int?) ?? 0,
      items: items,
      roomNumber: room?['room_number'] as String?,
      categoryName: room?['category_name'] as String?,
      summaryNote: j['summary_note'] as String?,
    );
  }

  bool get canTick => status == 'pending' || status == 'cleaning_done';
}
