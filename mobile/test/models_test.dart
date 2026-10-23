// Every contract shape in docs/staff-app/PLAN.md parses.

import 'package:centurion_staff/core/auth/session.dart';
import 'package:centurion_staff/features/auth/login_service.dart';
import 'package:centurion_staff/features/rooms/room_ui.dart';
import 'package:centurion_staff/features/staff/models.dart';
import 'package:flutter_test/flutter_test.dart';

const operatorJson = {
  'id': 7,
  'name': 'Blessing Okafor',
  'first_name': 'Blessing',
  'initials': 'BO',
  'role': 'house_cleaner',
  'role_label': 'Housekeeper',
  'phone': '+2348031234567',
};

Map<String, dynamic> roomJson({
  String state = 'to_clean',
  bool arrivingToday = false,
  String? arrivingAt,
  String? leftAt,
  Map<String, dynamic>? sentBack,
}) =>
    {
      'inspection_id': 41,
      'room_id': 9,
      'number': '204',
      'category': 'Deluxe King',
      'state': state,
      'do_first': true,
      'arriving_today': arrivingToday,
      'arriving_at': arrivingAt,
      'guest_left_at': leftAt,
      'started_at': null,
      'assigned_to_me': true,
      'progress': {'done': 3, 'total': 9},
      'sent_back': sentBack,
    };

void main() {
  test('login 200 builds a session using base_url', () {
    final r = parseLoginResponse(
      200,
      {
        'token': 't1',
        'base_url': 'https://centurion.example.com/',
        'operator': operatorJson,
        'tenant': {'id': 3, 'name': 'Centurion Apartments'},
      },
      fallbackBaseUrl: 'https://host',
    );
    expect(r, isA<LoginSuccess>());
    final s = (r as LoginSuccess).session;
    expect(s.baseUrl, 'https://centurion.example.com');
    expect(s.token, 't1');
    expect(s.role, StaffRole.housekeeper);
    expect(s.operator.firstName, 'Blessing');
    expect(s.operator.displayRole, 'Housekeeper');
    expect(s.tenantName, 'Centurion Apartments');
    // Round trip through secure storage JSON.
    final again = Session.fromJson(s.toJson());
    expect(again.operator.initials, 'BO');
    expect(again.tenantId, 3);
  });

  test('login 300 asks for a hotel', () {
    final r = parseLoginResponse(
      300,
      {
        'status': 'pick_tenant',
        'tenants': [
          {'id': 1, 'name': 'A'},
          {'id': 2, 'name': 'B'},
        ],
      },
      fallbackBaseUrl: 'https://host',
    );
    expect((r as LoginPickTenant).tenants.map((t) => t.id), [1, 2]);
  });

  test('login failures map to friendly copy', () {
    LoginFailure f(int status, Map<String, dynamic> body) =>
        parseLoginResponse(status, body, fallbackBaseUrl: 'h') as LoginFailure;

    expect(f(401, {'error': 'invalid_credentials', 'detail': 'x'}).message, "That phone number and PIN don't match.");
    expect(
        f(429, {'error': 'too_many_attempts', 'detail': 'Try again in 5 minutes.'}).message, 'Try again in 5 minutes.',);
    expect(f(403, {'error': 'staff_only', 'detail': 'x'}).isStaffOnly, isTrue);
    expect(f(403, {'error': 'suspended', 'detail': 'Your access is paused.'}).message, 'Your access is paused.');
  });

  test('session', () {
    final s = StaffSessionInfo.fromJson(const {
      'data': {
        'operator': operatorJson,
        'property': {'id': 1, 'name': 'Centurion Apartments'},
        'shift_label': 'Morning shift',
        'date_label': 'Thursday 23 Oct',
      },
    });
    expect(s.dateLabel, 'Thursday 23 Oct');
    expect(s.shiftLabel, 'Morning shift');
    expect(s.propertyName, 'Centurion Apartments');
    expect(s.operator.name, 'Blessing Okafor');
  });

  test('rooms list', () {
    final d = RoomsData.fromJson({
      'data': {
        'progress': const {'ready': 1, 'total': 6},
        'counts': const {'to_clean': 4, 'with_porter': 1},
        'rooms': [
          roomJson(arrivingToday: true, leftAt: '2026-10-23T08:20:00+01:00'),
          roomJson(
            state: 'sent_back',
            sentBack: {
              'by': 'Musa',
              'at': '2026-10-23T11:20:00+01:00',
              'reasons': ['towels', 'bed'],
              'reasons_label': 'towels, bed',
              'note': null,
            },
          ),
        ],
      },
    });
    expect(d.ready, 1);
    expect(d.total, 6);
    expect(d.toClean, 4);
    expect(d.withPorter, 1);
    expect(d.rooms.first.state, RoomState.toClean);
    expect(d.rooms.first.doFirst, isTrue);
    expect(d.rooms.first.arrivingToday, isTrue);
    expect(d.rooms.first.done, 3);
    expect(d.rooms.last.sentBack!.reasons, ['towels', 'bed']);
    expect(d.rooms.last.sentBack!.note, isNull);
  });

  test('room card subtitles follow the plan', () {
    Room r(Map<String, dynamic> j) => Room.fromJson(j);
    expect(
      roomSubtitle(r(roomJson(state: 'sent_back', sentBack: {'by': 'Musa', 'reasons_label': 'towels'}))),
      'Porter sent it back: towels',
    );
    expect(roomSubtitle(r(roomJson(arrivingToday: true))), 'Guest arriving today');
    final at = DateTime(2026, 10, 23, 14).toIso8601String();
    expect(roomSubtitle(r(roomJson(arrivingAt: at, arrivingToday: true))), 'Guest arriving 2:00 PM');
    expect(roomSubtitle(r(roomJson(state: 'with_porter'))), 'Waiting for porter check');
    expect(roomSubtitle(r(roomJson(state: 'ready'))), 'Ready for the guest');
    final left = DateTime(2026, 10, 23, 9, 10).toIso8601String();
    expect(roomSubtitle(r(roomJson(leftAt: left))), 'Checked out 9:10 AM');
    expect(roomSubtitle(r(roomJson())), '');
    expect(roomLook(RoomState.withPorter).chip, 'With porter');
    expect(roomLook(RoomState.cleaning).chip, 'Cleaning');
  });

  test('room detail with groups', () {
    final d = RoomDetail.fromJson({
      'data': {
        ...roomJson(state: 'cleaning'),
        'note': null,
        'groups': const [
          {
            'key': 'bathroom',
            'label': 'Bathroom',
            'items': [
              {'id': 1, 'label': 'Toilet cleaned', 'icon': 'toilet', 'checked': true},
              {'id': 2, 'label': '2 fresh towels', 'icon': 'towel', 'checked': false},
            ],
          },
          {
            'key': 'extras',
            'label': 'Extras',
            'items': [
              {'id': 3, 'label': 'Bin emptied', 'icon': 'bin', 'checked': false},
            ],
          },
        ],
      },
    });
    expect(d.room.state, RoomState.cleaning);
    expect(d.groups.map((g) => g.key), ['bathroom', 'extras']);
    expect(d.doneCount, 1);
    expect(d.totalCount, 3);
    expect(d.groups.first.items.first.icon, 'toilet');
  });

  final checkJson = {
    'inspection_id': 50,
    'number': '210',
    'category': 'Deluxe King',
    'cleaned_by': {'name': 'Blessing Okafor', 'first_name': 'Blessing', 'initials': 'BO'},
    'cleaned_at': '2026-10-23T10:42:00+01:00',
    'minutes': 38,
    'score': 95,
    'arriving_today': false,
    'arriving_at': null,
    'note': 'Bedside lamp bulb is out.',
    'send_back_count': 0,
  };

  test('checks list and detail', () {
    final list = ChecksData.fromJson({
      'data': {
        'progress': const {'checked': 2, 'total': 5},
        'rooms': [checkJson],
      },
    });
    expect(list.checked, 2);
    expect(list.rooms.single.cleanerFirstName, 'Blessing');
    expect(list.rooms.single.minutes, 38);
    expect(list.rooms.single.score, 95);

    final d = CheckDetail.fromJson({
      'data': {
        ...checkJson,
        'minutes': null,
        'items': const [
          {'label': 'Bin empty', 'icon': 'bin', 'checked': true},
          {'label': '2 water bottles', 'icon': 'water', 'checked': false},
        ],
      },
    });
    expect(d.check.minutes, isNull);
    expect(d.items.map((i) => i.checked), [true, false]);
  });

  test('supplies, including a null unit and a null outlet', () {
    final s = SuppliesData.fromJson(const {
      'data': {
        'outlet': null,
        'products': [
          {'id': 1, 'name': 'Toilet roll', 'unit': 'rolls', 'icon': 'toilet_roll'},
          {'id': 2, 'name': 'Bleach', 'unit': null, 'icon': null},
        ],
        'requests': [
          {
            'id': 9,
            'summary': 'Pillowcases, 10',
            'created_at': '2026-10-22T16:00:00+01:00',
            'status': 'approved',
            'by': 'Musa',
          },
          {
            'id': 8,
            'summary': 'Shampoo, 15',
            'created_at': '2026-10-23T07:50:00+01:00',
            'status': 'waiting',
            'by': null,
          },
          {'id': 7, 'summary': 'Soap, 2', 'created_at': null, 'status': 'declined', 'by': 'Musa'},
          {'id': 6, 'summary': 'Soap, 1', 'created_at': null, 'status': 'cancelled', 'by': null},
        ],
      },
    });
    expect(s.outletName, isNull);
    expect(s.products[0].unit, 'rolls');
    expect(s.products[1].unit, isNull);
    expect(s.requests.map((r) => r.status), [
      RequestStatus.approved,
      RequestStatus.waiting,
      RequestStatus.declined,
      RequestStatus.cancelled,
    ]);
    expect(s.requests.first.by, 'Musa');
  });

  test('me', () {
    final porterJson = Map<String, dynamic>.of(operatorJson)..['role'] = 'porter';
    final m = MeData.fromJson({
      'data': {
        'operator': porterJson,
        'week': const {'rooms_cleaned': 0, 'rooms_checked': 41, 'avg_minutes': null, 'sent_back': 3},
      },
    });
    expect(m.operator.staffRole, StaffRole.porter);
    expect(m.roomsChecked, 41);
    expect(m.avgMinutes, isNull);
    expect(m.sentBack, 3);
  });

  test('send-back payload keeps contract order and drops an empty note', () {
    expect(buildSendBackPayload({'bed', 'towels'}, '  '), {
      'reasons': ['towels', 'bed'],
    });
    expect(buildSendBackPayload({'other'}, ' Stain on rug '), {
      'reasons': ['other'],
      'note': 'Stain on rug',
    });
  });
}
