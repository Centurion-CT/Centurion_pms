// In-memory hotel for STAFF_DEMO=true builds and widget tests. Speaks the
// same JSON as the real staff API (PLAN.md) so the whole app stack
// (cache, outbox, optimistic view) runs unchanged on top of it.
//
// Demo sign in: any phone number, then PIN
//   1234 -> Blessing (housekeeper)   5678 -> Musa (porter)
//   0000 -> a manager (shows "not for you")   9999 -> pick a hotel first

import 'package:intl/intl.dart';

import '../core/api/api_error.dart';
import '../core/api/staff_api.dart';
import '../core/auth/session.dart';
import '../core/json.dart';
import '../features/auth/login_service.dart';

const demoBlessing = Operator(
  id: 7,
  name: 'Blessing Okafor',
  firstName: 'Blessing',
  initials: 'BO',
  role: 'house_cleaner',
  phone: '+2348031234567',
);

const demoMusa = Operator(
  id: 9,
  name: 'Musa Bello',
  firstName: 'Musa',
  initials: 'MB',
  role: 'porter',
  phone: '+2348057654321',
);

Session demoSession(Operator op) => Session(
      baseUrl: 'https://demo.centurion.local',
      token: 'demo-token-${op.id}',
      operator: op,
      tenantId: 1,
      tenantName: 'Centurion Apartments',
    );

class DemoBackend {
  DemoBackend({DateTime? now, this.latency = const Duration(milliseconds: 250)}) : _now = now ?? DateTime.now() {
    _seed();
  }

  final DateTime _now;
  final Duration latency;
  final Map<int, Map<String, dynamic>> rooms = {};
  final List<int> roomOrder = [];
  final Map<int, Map<String, dynamic>> checks = {};
  final List<Map<String, dynamic>> requests = [];
  int _checked = 2;
  int _checkTotal = 5;
  int _nextRequest = 100;

  String _at(int h, int m, {int dayOffset = 0}) =>
      DateTime(_now.year, _now.month, _now.day + dayOffset, h, m).toUtc().toIso8601String();

  Map<String, dynamic> _item(int id, String label, String icon, {bool checked = false}) =>
      {'id': id, 'label': label, 'icon': icon, 'checked': checked};

  List<Map<String, dynamic>> _groups(int base, {Set<String> checked = const {}}) {
    bool c(String icon) => checked.contains(icon);
    return [
      {
        'key': 'bathroom',
        'label': 'Bathroom',
        'items': [
          _item(base + 1, 'Toilet cleaned', 'toilet', checked: c('toilet')),
          _item(base + 2, 'Shower and sink cleaned', 'shower', checked: c('shower')),
          _item(base + 3, '2 fresh towels', 'towel', checked: c('towel')),
          _item(base + 4, 'Soap and shampoo', 'soap', checked: c('soap')),
        ],
      },
      {
        'key': 'bedroom',
        'label': 'Bedroom',
        'items': [
          _item(base + 5, 'Fresh sheets and pillowcases', 'sheets', checked: c('sheets')),
          _item(base + 6, 'Dust surfaces', 'dust', checked: c('dust')),
          _item(base + 7, 'Floor mopped', 'floor', checked: c('floor')),
        ],
      },
      {
        'key': 'extras',
        'label': 'Extras',
        'items': [
          _item(base + 8, '2 water bottles', 'water', checked: c('water')),
          _item(base + 9, 'Bin emptied', 'bin', checked: c('bin')),
        ],
      },
    ];
  }

  void _room(
    int id,
    String number,
    String category,
    String state, {
    bool doFirst = false,
    bool arrivingToday = false,
    String? leftAt,
    Map<String, dynamic>? sentBack,
    Set<String> checked = const {},
  }) {
    rooms[id] = {
      'inspection_id': id,
      'room_id': id + 1000,
      'number': number,
      'category': category,
      'state': state,
      'do_first': doFirst,
      'arriving_today': arrivingToday,
      'arriving_at': null,
      'guest_left_at': leftAt,
      'started_at': null,
      'assigned_to_me': true,
      'sent_back': sentBack,
      'groups': _groups(id * 100, checked: checked),
      'note': null,
    };
    roomOrder.add(id);
  }

  static const _all = {'toilet', 'shower', 'towel', 'soap', 'sheets', 'dust', 'floor', 'water', 'bin'};

  void _seed() {
    _room(1, '204', 'Deluxe King', 'to_clean', doFirst: true, arrivingToday: true, leftAt: _at(8, 20));
    _room(2, '112', 'Standard Double', 'to_clean', leftAt: _at(9, 10));
    _room(
      3,
      '118',
      'Standard Double',
      'sent_back',
      leftAt: _at(7, 45),
      checked: _all.difference({'towel'}),
      sentBack: {
        'by': 'Musa',
        'at': _at(11, 20),
        'reasons': ['towels'],
        'reasons_label': 'towels',
        'note': 'Only one towel in the bathroom.',
      },
    );
    _room(4, '305', 'Executive Suite', 'to_clean', leftAt: _at(8, 40));
    _room(5, '210', 'Deluxe King', 'with_porter', leftAt: _at(7, 30), checked: _all);
    _room(6, '107', 'Standard Twin', 'ready', leftAt: _at(7, 10), checked: _all);

    checks[5] = {
      'inspection_id': 5,
      'number': '210',
      'category': 'Deluxe King',
      'cleaned_by': {'name': 'Blessing', 'initials': 'BO'},
      'cleaned_at': _at(10, 42),
      'minutes': 38,
      'score': 95,
      'arriving_today': false,
      'arriving_at': null,
      'note': 'Bedside lamp bulb is out.',
      'items': [
        {'label': 'Bathroom clean, 2 towels', 'icon': 'shower', 'checked': true},
        {'label': 'Bed made, fresh sheets', 'icon': 'sheets', 'checked': true},
        {'label': '2 water bottles', 'icon': 'water', 'checked': true},
        {'label': 'Bin empty', 'icon': 'bin', 'checked': true},
      ],
    };
    checks[21] = {
      'inspection_id': 21,
      'number': '115',
      'category': 'Standard Double',
      'cleaned_by': {'name': 'Grace', 'initials': 'GE'},
      'cleaned_at': _at(10, 5),
      'minutes': 31,
      'score': 88,
      'arriving_today': true,
      'arriving_at': null,
      'note': null,
      'items': [
        {'label': 'Bathroom clean, 2 towels', 'icon': 'shower', 'checked': true},
        {'label': 'Bed made, fresh sheets', 'icon': 'sheets', 'checked': true},
        {'label': '2 water bottles', 'icon': 'water', 'checked': false},
        {'label': 'Bin empty', 'icon': 'bin', 'checked': true},
      ],
    };
    checks[22] = {
      'inspection_id': 22,
      'number': '301',
      'category': 'Executive Suite',
      'cleaned_by': {'name': 'Blessing', 'initials': 'BO'},
      'cleaned_at': _at(9, 50),
      'minutes': 44,
      'score': 92,
      'arriving_today': false,
      'arriving_at': null,
      'note': null,
      'items': [
        {'label': 'Bathroom clean, 2 towels', 'icon': 'shower', 'checked': true},
        {'label': 'Bed made, fresh sheets', 'icon': 'sheets', 'checked': true},
        {'label': 'Minibar restocked', 'icon': 'minibar', 'checked': true},
        {'label': 'Bin empty', 'icon': 'bin', 'checked': true},
      ],
    };

    requests.addAll([
      {'id': 2, 'summary': 'Shampoo, 15', 'created_at': _at(7, 50), 'status': 'waiting', 'by': null},
      {
        'id': 1,
        'summary': 'Pillowcases, 10',
        'created_at': _at(16, 5, dayOffset: -1),
        'status': 'approved',
        'by': 'Musa',
      },
    ]);
  }

  // ------------------------------------------------------------ reads

  Map<String, dynamic> _roomJson(Map<String, dynamic> r, {bool detail = false}) {
    final items = jList(r['groups']).expand((g) => jList(g['items']));
    final out = Map<String, dynamic>.of(r)
      ..['progress'] = {'done': items.where((i) => jBool(i['checked'])).length, 'total': items.length};
    if (!detail) {
      out
        ..remove('groups')
        ..remove('note');
    }
    return jCopy(out);
  }

  Map<String, dynamic> _checkJson(Map<String, dynamic> c, {bool detail = false}) {
    final out = Map<String, dynamic>.of(c);
    if (!detail) out.remove('items');
    return jCopy(out);
  }

  String get _shift => _now.hour < 12 ? 'Morning shift' : (_now.hour < 18 ? 'Afternoon shift' : 'Night shift');

  Map<String, dynamic> get(String path, Operator op) {
    switch (path) {
      case 'staff/session':
        return {
          'data': {
            'operator': op.toJson(),
            'property': {'id': 1, 'name': 'Centurion Apartments'},
            'shift_label': _shift,
            'date_label': DateFormat('EEEE d MMM').format(_now),
          },
        };
      case 'staff/rooms':
        final list = roomOrder.map((id) => rooms[id]!).toList();
        final open = list.where((r) => r['state'] != 'with_porter' && r['state'] != 'ready');
        final finished = list.where((r) => r['state'] == 'with_porter' || r['state'] == 'ready');
        return {
          'data': {
            'progress': {'ready': list.where((r) => r['state'] == 'ready').length, 'total': list.length},
            'counts': {
              'to_clean': open.length,
              'with_porter': list.where((r) => r['state'] == 'with_porter').length,
            },
            'rooms': [...open, ...finished].map(_roomJson).toList(),
          },
        };
      case 'staff/checks':
        return {
          'data': {
            'progress': {'checked': _checked, 'total': _checkTotal},
            'rooms': checks.values.map(_checkJson).toList(),
          },
        };
      case 'staff/supplies':
        return {
          'data': {
            'outlet': {'id': 1, 'name': 'Housekeeping store'},
            'products': [
              {'id': 11, 'name': 'Toilet roll', 'unit': 'rolls', 'icon': 'toilet_roll'},
              {'id': 12, 'name': 'Soap bars', 'unit': 'bars', 'icon': 'soap'},
              {'id': 13, 'name': 'Bath towels', 'unit': 'towels', 'icon': 'towel'},
              {'id': 14, 'name': 'Water bottles', 'unit': 'bottles', 'icon': 'water'},
            ],
            'requests': jCopy({'r': requests})['r'],
          },
        };
      case 'staff/me':
        final porter = op.staffRole == StaffRole.porter;
        return {
          'data': {
            'operator': op.toJson(),
            'week': {
              'rooms_cleaned': porter ? 0 : 23,
              'rooms_checked': porter ? 41 : 0,
              'avg_minutes': porter ? null : 36,
              'sent_back': porter ? 3 : 2,
            },
          },
        };
    }
    final room = RegExp(r'^staff/rooms/(\d+)$').firstMatch(path);
    if (room != null) {
      final r = rooms[int.parse(room.group(1)!)];
      if (r == null) throw const ApiError(code: 'not_found', status: 404, detail: 'That room is not on your list.');
      return {'data': _roomJson(r, detail: true)};
    }
    final check = RegExp(r'^staff/checks/(\d+)$').firstMatch(path);
    if (check != null) {
      final c = checks[int.parse(check.group(1)!)];
      if (c == null) {
        throw const ApiError(code: 'not_found', status: 404, detail: 'That room is not waiting for a check.');
      }
      return {'data': _checkJson(c, detail: true)};
    }
    throw const ApiError(code: 'not_found', status: 404, detail: 'Not found.');
  }

  // ------------------------------------------------------------ writes

  static const _wrongState = ApiError(code: 'wrong_state', status: 409, detail: 'This room has moved on.');

  Map<String, dynamic> post(String path, Map<String, dynamic> body, Operator op) {
    if (path == 'logout') return {'status': 'ok'};
    if (path == 'staff/supplies') {
      final lines = jList(body['lines']);
      final names = {11: 'Toilet roll', 12: 'Soap bars', 13: 'Bath towels', 14: 'Water bottles'};
      requests.insert(0, {
        'id': _nextRequest++,
        'summary': lines.map((l) => '${names[jInt(l['product_id'])] ?? 'Item'}, ${jInt(l['qty'])}').join(' · '),
        'created_at': DateTime.now().toUtc().toIso8601String(),
        'status': 'waiting',
        'by': null,
      });
      return {'status': 'ok'};
    }
    final m = RegExp(r'^staff/rooms/(\d+)/(start|finish|items/(\d+))$').firstMatch(path);
    if (m != null) {
      final r = rooms[int.parse(m.group(1)!)];
      if (r == null) throw const ApiError(code: 'not_found', status: 404, detail: 'Not found.');
      final state = r['state'] as String;
      final finished = state == 'with_porter' || state == 'ready';
      if (finished) throw _wrongState;
      if (state == 'to_clean' || state == 'sent_back') r['state'] = 'cleaning';
      r['started_at'] ??= DateTime.now().toUtc().toIso8601String();
      if (m.group(2) == 'finish') {
        r['state'] = 'with_porter';
        r['note'] = jStrOrNull(body['note']);
        final id = r['inspection_id'] as int;
        checks[id] = {
          'inspection_id': id,
          'number': r['number'],
          'category': r['category'],
          'cleaned_by': {'name': op.firstName, 'initials': op.initials},
          'cleaned_at': DateTime.now().toUtc().toIso8601String(),
          'minutes': 30,
          'score': 90,
          'arriving_today': r['arriving_today'],
          'arriving_at': null,
          'note': jStrOrNull(body['note']),
          'items': [
            for (final i in jList(r['groups']).expand((g) => jList(g['items'])))
              {'label': i['label'], 'icon': i['icon'], 'checked': i['checked']},
          ],
        };
        _checkTotal++;
        return {'status': 'ok', 'room': _roomJson(r)};
      }
      if (m.group(3) != null) {
        final itemId = int.parse(m.group(3)!);
        for (final i in jList(r['groups']).expand((g) => jList(g['items']))) {
          if (jInt(i['id']) == itemId) i['checked'] = jBool(body['checked']);
        }
        return {
          'status': 'ok',
          'item': {'id': itemId, 'checked': jBool(body['checked'])},
        };
      }
      return {'status': 'ok', 'room': _roomJson(r)};
    }
    final c = RegExp(r'^staff/checks/(\d+)/(ready|send-back)$').firstMatch(path);
    if (c != null) {
      final id = int.parse(c.group(1)!);
      if (!checks.containsKey(id)) throw _wrongState;
      checks.remove(id);
      _checked++;
      final r = rooms[id];
      if (r != null) {
        if (c.group(2) == 'ready') {
          r['state'] = 'ready';
        } else {
          final reasons = jStrings(body['reasons']);
          const clears = {
            'towels': {'towel'},
            'bathroom': {'toilet', 'shower', 'soap'},
            'bed': {'sheets'},
            'floor': {'floor'},
            'missing': {'water'},
          };
          final clear = reasons.expand((x) => clears[x] ?? const <String>{}).toSet();
          for (final i in jList(r['groups']).expand((g) => jList(g['items']))) {
            if (clear.contains(i['icon'])) i['checked'] = false;
          }
          r['state'] = 'sent_back';
          r['sent_back'] = {
            'by': op.firstName,
            'at': DateTime.now().toUtc().toIso8601String(),
            'reasons': reasons,
            'reasons_label': reasons.join(', '),
            'note': jStrOrNull(body['note']),
          };
          roomOrder
            ..remove(id)
            ..insert(0, id);
        }
      }
      return {'status': 'ok'};
    }
    throw const ApiError(code: 'not_found', status: 404, detail: 'Not found.');
  }
}

class DemoStaffApi implements StaffApi {
  DemoStaffApi(this.backend, this.op);

  final DemoBackend backend;
  final Operator op;

  @override
  Future<Map<String, dynamic>> get(String path) async {
    await Future<void>.delayed(backend.latency);
    return backend.get(path, op);
  }

  @override
  Future<Map<String, dynamic>> send(
    String method,
    String path,
    Map<String, dynamic> body, {
    required String idempotencyKey,
  }) async {
    await Future<void>.delayed(backend.latency);
    return backend.post(path, body, op);
  }
}

class DemoLoginService implements LoginService {
  const DemoLoginService();

  @override
  Future<LoginResult> login(LoginRequest req) async {
    await Future<void>.delayed(const Duration(milliseconds: 400));
    switch (req.pin) {
      case '1234':
        return LoginSuccess(demoSession(demoBlessing));
      case '5678':
        return LoginSuccess(demoSession(demoMusa));
      case '0000':
        return const LoginFailure(code: 'staff_only', status: 403, detail: 'Managers use the web dashboard.');
      case '9999':
        if (req.tenantId == null) {
          return const LoginPickTenant([
            Tenant(id: 1, name: 'Centurion Apartments'),
            Tenant(id: 2, name: 'Centurion Apartments Annex'),
          ]);
        }
        return LoginSuccess(demoSession(demoBlessing));
      default:
        return const LoginFailure(code: 'invalid_credentials', status: 401);
    }
  }
}
