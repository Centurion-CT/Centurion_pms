// View models for the staff API (docs/staff-app/PLAN.md). Parsers are
// forgiving: a missing optional field never breaks a screen.

import 'package:flutter/foundation.dart';

import '../../core/auth/session.dart';
import '../../core/json.dart';

// ---------------------------------------------------------------- session

@immutable
class StaffSessionInfo {
  const StaffSessionInfo({
    required this.operator,
    required this.propertyName,
    required this.shiftLabel,
    required this.dateLabel,
  });

  final Operator operator;
  final String propertyName;
  final String shiftLabel;
  final String dateLabel;

  factory StaffSessionInfo.fromJson(Map<String, dynamic> body) {
    final d = jMap(body['data']);
    return StaffSessionInfo(
      operator: Operator.fromJson(d['operator']),
      propertyName: jStr(jMap(d['property'])['name']),
      shiftLabel: jStr(d['shift_label']),
      dateLabel: jStr(d['date_label']),
    );
  }
}

// ---------------------------------------------------------------- rooms

enum RoomState { toClean, cleaning, sentBack, withPorter, ready }

RoomState roomStateFrom(String raw) {
  switch (raw) {
    case 'cleaning':
      return RoomState.cleaning;
    case 'sent_back':
      return RoomState.sentBack;
    case 'with_porter':
      return RoomState.withPorter;
    case 'ready':
      return RoomState.ready;
    default:
      return RoomState.toClean;
  }
}

@immutable
class SentBack {
  const SentBack({required this.by, required this.at, required this.reasons, required this.reasonsLabel, this.note});

  final String by;
  final DateTime? at;
  final List<String> reasons;
  final String reasonsLabel;
  final String? note;

  static SentBack? fromJson(Object? raw) {
    if (raw is! Map) return null;
    final j = jMap(raw);
    return SentBack(
      by: jStr(j['by']),
      at: jTime(j['at']),
      reasons: jStrings(j['reasons']),
      reasonsLabel: jStr(j['reasons_label']),
      note: jStrOrNull(j['note']),
    );
  }
}

@immutable
class Room {
  const Room({
    required this.inspectionId,
    required this.roomId,
    required this.number,
    required this.category,
    required this.state,
    required this.doFirst,
    required this.arrivingToday,
    required this.arrivingAt,
    required this.guestLeftAt,
    required this.startedAt,
    required this.assignedToMe,
    required this.done,
    required this.total,
    required this.sentBack,
  });

  final int inspectionId;
  final int roomId;
  final String number;
  final String category;
  final RoomState state;
  final bool doFirst;
  final bool arrivingToday;
  final DateTime? arrivingAt;
  final DateTime? guestLeftAt;
  final DateTime? startedAt;
  final bool assignedToMe;
  final int done;
  final int total;
  final SentBack? sentBack;

  bool get isFinished => state == RoomState.withPorter || state == RoomState.ready;
  bool get needsStart => state == RoomState.toClean || state == RoomState.sentBack;

  factory Room.fromJson(Map<String, dynamic> j) {
    final p = jMap(j['progress']);
    return Room(
      inspectionId: jInt(j['inspection_id']),
      roomId: jInt(j['room_id']),
      number: jStr(j['number']),
      category: jStr(j['category']),
      state: roomStateFrom(jStr(j['state'])),
      doFirst: jBool(j['do_first']),
      arrivingToday: jBool(j['arriving_today']),
      arrivingAt: jTime(j['arriving_at']),
      guestLeftAt: jTime(j['guest_left_at']),
      startedAt: jTime(j['started_at']),
      assignedToMe: jBool(j['assigned_to_me']),
      done: jInt(p['done']),
      total: jInt(p['total']),
      sentBack: SentBack.fromJson(j['sent_back']),
    );
  }
}

@immutable
class RoomsData {
  const RoomsData({
    required this.ready,
    required this.total,
    required this.toClean,
    required this.withPorter,
    required this.rooms,
  });

  final int ready;
  final int total;
  final int toClean;
  final int withPorter;
  final List<Room> rooms;

  factory RoomsData.fromJson(Map<String, dynamic> body) {
    final d = jMap(body['data']);
    final p = jMap(d['progress']);
    final c = jMap(d['counts']);
    return RoomsData(
      ready: jInt(p['ready']),
      total: jInt(p['total']),
      toClean: jInt(c['to_clean']),
      withPorter: jInt(c['with_porter']),
      rooms: jList(d['rooms']).map(Room.fromJson).toList(),
    );
  }
}

@immutable
class ChecklistItem {
  const ChecklistItem({required this.id, required this.label, required this.icon, required this.checked});

  final int id;
  final String label;
  final String icon;
  final bool checked;

  factory ChecklistItem.fromJson(Map<String, dynamic> j) => ChecklistItem(
        id: jInt(j['id']),
        label: jStr(j['label']),
        icon: jStr(j['icon'], 'generic'),
        checked: jBool(j['checked']),
      );
}

@immutable
class ChecklistGroup {
  const ChecklistGroup({required this.key, required this.label, required this.items});

  final String key;
  final String label;
  final List<ChecklistItem> items;

  factory ChecklistGroup.fromJson(Map<String, dynamic> j) => ChecklistGroup(
        key: jStr(j['key'], 'room'),
        label: jStr(j['label']),
        items: jList(j['items']).map(ChecklistItem.fromJson).toList(),
      );
}

@immutable
class RoomDetail {
  const RoomDetail({required this.room, required this.groups, this.note});

  final Room room;
  final List<ChecklistGroup> groups;
  final String? note;

  Iterable<ChecklistItem> get items => groups.expand((g) => g.items);
  int get doneCount => items.where((i) => i.checked).length;
  int get totalCount => items.length;

  factory RoomDetail.fromJson(Map<String, dynamic> body) {
    final d = jMap(body['data']);
    return RoomDetail(
      room: Room.fromJson(d),
      groups: jList(d['groups']).map(ChecklistGroup.fromJson).toList(),
      note: jStrOrNull(d['note']),
    );
  }
}

// ---------------------------------------------------------------- checks

@immutable
class Check {
  const Check({
    required this.inspectionId,
    required this.number,
    required this.category,
    required this.cleanedByName,
    required this.cleanedByFirstName,
    required this.cleanedByInitials,
    required this.cleanedAt,
    required this.minutes,
    required this.score,
    required this.arrivingToday,
    required this.arrivingAt,
    required this.note,
  });

  final int inspectionId;
  final String number;
  final String category;
  final String cleanedByName;
  final String cleanedByFirstName;
  final String cleanedByInitials;
  final DateTime? cleanedAt;
  final int? minutes;
  final int? score;
  final bool arrivingToday;
  final DateTime? arrivingAt;
  final String? note;

  String get cleanerFirstName =>
      cleanedByFirstName.isNotEmpty ? cleanedByFirstName : cleanedByName.trim().split(' ').first;

  factory Check.fromJson(Map<String, dynamic> j) {
    final by = jMap(j['cleaned_by']);
    return Check(
      inspectionId: jInt(j['inspection_id']),
      number: jStr(j['number']),
      category: jStr(j['category']),
      cleanedByName: jStr(by['name']),
      cleanedByFirstName: jStr(by['first_name']),
      cleanedByInitials: jStr(by['initials']),
      cleanedAt: jTime(j['cleaned_at']),
      minutes: jIntOrNull(j['minutes']),
      score: jIntOrNull(j['score']),
      arrivingToday: jBool(j['arriving_today']),
      arrivingAt: jTime(j['arriving_at']),
      note: jStrOrNull(j['note']),
    );
  }
}

@immutable
class ChecksData {
  const ChecksData({required this.checked, required this.total, required this.rooms});

  final int checked;
  final int total;
  final List<Check> rooms;

  factory ChecksData.fromJson(Map<String, dynamic> body) {
    final d = jMap(body['data']);
    final p = jMap(d['progress']);
    return ChecksData(
      checked: jInt(p['checked']),
      total: jInt(p['total']),
      rooms: jList(d['rooms']).map(Check.fromJson).toList(),
    );
  }
}

@immutable
class CheckItem {
  const CheckItem({required this.label, required this.icon, required this.checked});

  final String label;
  final String icon;
  final bool checked;

  factory CheckItem.fromJson(Map<String, dynamic> j) =>
      CheckItem(label: jStr(j['label']), icon: jStr(j['icon'], 'generic'), checked: jBool(j['checked']));
}

@immutable
class CheckDetail {
  const CheckDetail({required this.check, required this.items});

  final Check check;
  final List<CheckItem> items;

  factory CheckDetail.fromJson(Map<String, dynamic> body) {
    final d = jMap(body['data']);
    return CheckDetail(check: Check.fromJson(d), items: jList(d['items']).map(CheckItem.fromJson).toList());
  }
}

/// Porter send-back reasons, in the order the sheet shows them.
const sendBackReasons = ['towels', 'bathroom', 'bed', 'floor', 'smell', 'missing', 'other'];

/// The body for POST checks/{id}/send-back.
Map<String, dynamic> buildSendBackPayload(Iterable<String> reasons, String? note) {
  final ordered = sendBackReasons.where(reasons.contains).toList();
  final n = note?.trim() ?? '';
  return {'reasons': ordered, if (n.isNotEmpty) 'note': n};
}

// ---------------------------------------------------------------- supplies

@immutable
class Product {
  const Product({required this.id, required this.name, required this.unit, required this.icon});

  final int id;
  final String name;
  final String? unit;
  final String icon;

  factory Product.fromJson(Map<String, dynamic> j) {
    final unit = jStrOrNull(j['unit']);
    return Product(
      id: jInt(j['id']),
      name: jStr(j['name']),
      unit: (unit == null || unit.trim().isEmpty) ? null : unit,
      icon: jStr(j['icon']),
    );
  }
}

enum RequestStatus { waiting, approved, declined, cancelled }

RequestStatus requestStatusFrom(String raw) {
  switch (raw) {
    case 'approved':
      return RequestStatus.approved;
    case 'declined':
      return RequestStatus.declined;
    case 'cancelled':
      return RequestStatus.cancelled;
    default:
      return RequestStatus.waiting;
  }
}

@immutable
class SupplyRequest {
  const SupplyRequest(
      {required this.id, required this.summary, required this.createdAt, required this.status, this.by,});

  final int id;
  final String summary;
  final DateTime? createdAt;
  final RequestStatus status;
  final String? by;

  factory SupplyRequest.fromJson(Map<String, dynamic> j) => SupplyRequest(
        id: jInt(j['id']),
        summary: jStr(j['summary']),
        createdAt: jTime(j['created_at']),
        status: requestStatusFrom(jStr(j['status'])),
        by: jStrOrNull(j['by']),
      );
}

@immutable
class SuppliesData {
  const SuppliesData({required this.outletName, required this.products, required this.requests});

  final String? outletName;
  final List<Product> products;
  final List<SupplyRequest> requests;

  factory SuppliesData.fromJson(Map<String, dynamic> body) {
    final d = jMap(body['data']);
    final outlet = d['outlet'];
    return SuppliesData(
      outletName: outlet is Map ? jStrOrNull(outlet['name']) : null,
      products: jList(d['products']).map(Product.fromJson).toList(),
      requests: jList(d['requests']).map(SupplyRequest.fromJson).toList(),
    );
  }
}

/// "Toilet roll, 12" for one line; lines joined with " · " otherwise.
String supplySummary(List<(String name, int qty)> lines) => lines.map((l) => '${l.$1}, ${l.$2}').join(' · ');

// ---------------------------------------------------------------- me

@immutable
class MeData {
  const MeData({
    required this.operator,
    required this.roomsCleaned,
    required this.roomsChecked,
    required this.avgMinutes,
    required this.sentBack,
  });

  final Operator operator;
  final int roomsCleaned;
  final int roomsChecked;
  final int? avgMinutes;
  final int sentBack;

  factory MeData.fromJson(Map<String, dynamic> body) {
    final d = jMap(body['data']);
    final w = jMap(d['week']);
    return MeData(
      operator: Operator.fromJson(d['operator']),
      roomsCleaned: jInt(w['rooms_cleaned']),
      roomsChecked: jInt(w['rooms_checked']),
      avgMinutes: jIntOrNull(w['avg_minutes']),
      sentBack: jInt(w['sent_back']),
    );
  }
}
