// Outbox replay + optimistic overlay.

import 'dart:convert';

import 'package:centurion_staff/core/api/api_error.dart';
import 'package:centurion_staff/core/outbox/outbox_dao.dart';
import 'package:centurion_staff/core/outbox/outbox_worker.dart';
import 'package:centurion_staff/core/storage/local_db.dart';
import 'package:centurion_staff/features/staff/optimistic.dart';
import 'package:centurion_staff/features/staff/staff_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import 'helpers.dart';

void main() {
  late LocalDb db;
  late OutboxDao dao;

  setUp(() {
    db = memoryDb();
    dao = OutboxDao(db);
  });
  tearDown(() => db.close());

  Future<void> add(String endpoint, {String label = 'Room 204', Map<String, dynamic> body = const {}}) => dao.enqueue(
        idempotencyKey: 'key-$endpoint',
        httpMethod: 'POST',
        endpoint: endpoint,
        payload: jsonEncode(body),
        label: label,
      );

  test('a 409 wrong_state entry is dropped, reported, and the rest still send in order', () async {
    final api = ScriptedApi(
      onSend: (path) {
        if (path == 'staff/rooms/1/finish') {
          throw const ApiError(code: 'wrong_state', status: 409, detail: 'Already with the porter.');
        }
        return {'status': 'ok'};
      },
    );
    final dropped = <(OutboxEntry, ApiError)>[];
    final sent = <String>[];
    var settledEmpty = false;
    final worker = OutboxWorker(
      dao: dao,
      api: api,
      onSent: (e) => sent.add(e.endpoint),
      onDropped: (e, err) => dropped.add((e, err)),
      onSettled: (empty) => settledEmpty = empty,
    );

    await add('staff/rooms/1/items/5', body: {'checked': true});
    await add('staff/rooms/1/finish');
    await add('staff/supplies', label: 'Soap, 2', body: {'lines': <Object>[]});
    await worker.drain(force: true);

    expect(api.sent.map((s) => s.$1), ['staff/rooms/1/items/5', 'staff/rooms/1/finish', 'staff/supplies']);
    expect(sent, ['staff/rooms/1/items/5', 'staff/supplies']);
    expect(dropped.single.$1.endpoint, 'staff/rooms/1/finish');
    expect(dropMessage(dropped.single.$1, dropped.single.$2), 'Room 204 was already sent to the porter.');
    expect(await dao.pending(), isEmpty);
    expect(settledEmpty, isTrue);
  });

  test('network trouble keeps the entry, keeps its key, and blocks later ones', () async {
    var fail = true;
    final api = ScriptedApi(
      onSend: (path) {
        if (fail) throw const ApiError(code: 'network');
        return {'status': 'ok'};
      },
    );
    final worker = OutboxWorker(dao: dao, api: api);
    await add('staff/rooms/1/start');
    await add('staff/rooms/1/items/5', body: {'checked': true});

    await worker.drain(force: true);
    expect(api.sent.length, 1);
    final left = await dao.pending();
    expect(left.length, 2);
    expect(left.first.retryCount, 1);

    fail = false;
    await worker.drain(force: true);
    expect(await dao.pending(), isEmpty);
    // The retry reused the same Idempotency-Key.
    expect(api.sent[0].$3, api.sent[1].$3);
  });

  test('backoff grows and caps', () {
    expect(OutboxWorker.backoffFor(0), const Duration(seconds: 5));
    expect(OutboxWorker.backoffFor(2), const Duration(seconds: 20));
    expect(OutboxWorker.backoffFor(20), const Duration(seconds: 120));
  });

  group('optimistic overlay', () {
    Map<String, dynamic> list() => {
          'data': {
            'progress': {'ready': 0, 'total': 2},
            'counts': {'to_clean': 2, 'with_porter': 0},
            'rooms': [
              {'inspection_id': 1, 'number': '204', 'state': 'to_clean', 'started_at': null},
              {'inspection_id': 2, 'number': '112', 'state': 'to_clean', 'started_at': null},
            ],
          },
        };

    test('start then finish moves the room to the porter and the end of the list', () {
      final out = applyChanges('staff/rooms', list(), const [
        PendingChange(endpoint: 'staff/rooms/1/start', body: {}),
        PendingChange(endpoint: 'staff/rooms/1/finish', body: {}),
      ]);
      final rooms = (out['data'] as Map)['rooms'] as List;
      expect(rooms.map((r) => (r as Map)['number']), ['112', '204']);
      expect((rooms.last as Map)['state'], 'with_porter');
      expect((out['data'] as Map)['counts'], {'to_clean': 1, 'with_porter': 1});
    });

    test('ticks recount progress on the detail', () {
      final detail = {
        'data': {
          'inspection_id': 1,
          'state': 'cleaning',
          'progress': {'done': 0, 'total': 2},
          'groups': [
            {
              'key': 'bathroom',
              'items': [
                {'id': 5, 'checked': false},
                {'id': 6, 'checked': false},
              ],
            },
          ],
        },
      };
      final out = applyChanges('staff/rooms/1', detail, const [
        PendingChange(endpoint: 'staff/rooms/1/items/5', body: {'checked': true}),
        PendingChange(endpoint: 'staff/rooms/1/items/6', body: {'checked': true}),
        PendingChange(endpoint: 'staff/rooms/1/items/6', body: {'checked': false}),
      ]);
      expect((out['data'] as Map)['progress'], {'done': 1, 'total': 2});
      // The input is never mutated.
      expect(((detail['data']!['groups'] as List).first as Map)['items'], [
        {'id': 5, 'checked': false},
        {'id': 6, 'checked': false},
      ]);
    });

    test('porter actions remove the room and count it as checked', () {
      final checks = {
        'data': {
          'progress': {'checked': 1, 'total': 3},
          'rooms': [
            {'inspection_id': 7},
            {'inspection_id': 8},
          ],
        },
      };
      final out = applyChanges('staff/checks', checks, const [
        PendingChange(endpoint: 'staff/checks/7/send-back', body: {
          'reasons': ['towels'],
        },),
      ]);
      expect(((out['data'] as Map)['rooms'] as List).length, 1);
      expect((out['data'] as Map)['progress'], {'checked': 2, 'total': 3});
    });

    test('a supplies request shows as waiting straight away', () {
      final out = applyChanges(
        'staff/supplies',
        {
          'data': {'products': <Object>[], 'requests': <Object>[]},
        },
        const [PendingChange(endpoint: 'staff/supplies', body: {}, label: 'Soap bars, 4')],
      );
      final req = ((out['data'] as Map)['requests'] as List).single as Map;
      expect(req['summary'], 'Soap bars, 4');
      expect(req['status'], 'waiting');
    });
  });
}
