// Optimistic view: what each cached GET looks like once the changes still
// waiting in the outbox are applied. Screens always read
// applyChanges(lastServerJson, pendingChanges), so a tick shows at once,
// survives leaving the screen, and works offline. When an entry reaches
// the server it is baked into the cached JSON; if the server refuses it,
// it is dropped and the view falls back to the server's version.
//
// All functions are pure (they copy the input) so they are easy to test.

import '../../core/json.dart';

class PendingChange {
  const PendingChange({required this.endpoint, required this.body, this.label = '', this.at});

  final String endpoint;
  final Map<String, dynamic> body;
  final String label;
  final DateTime? at;
}

final _start = RegExp(r'^staff/rooms/(\d+)/start$');
final _item = RegExp(r'^staff/rooms/(\d+)/items/(\d+)$');
final _finish = RegExp(r'^staff/rooms/(\d+)/finish$');
final _check = RegExp(r'^staff/checks/(\d+)/(ready|send-back)$');

/// The cache keys (GET paths) a change touches.
List<String> keysTouchedBy(String endpoint) {
  final room = _start.firstMatch(endpoint) ?? _item.firstMatch(endpoint) ?? _finish.firstMatch(endpoint);
  if (room != null) return ['staff/rooms', 'staff/rooms/${room.group(1)}'];
  final check = _check.firstMatch(endpoint);
  if (check != null) return ['staff/checks', 'staff/checks/${check.group(1)}'];
  if (endpoint == 'staff/supplies') return ['staff/supplies'];
  return const [];
}

Map<String, dynamic> applyChanges(String key, Map<String, dynamic> json, Iterable<PendingChange> changes) {
  var out = jCopy(json);
  for (final c in changes) {
    if (!keysTouchedBy(c.endpoint).contains(key)) continue;
    out = _apply(key, out, c);
  }
  return out;
}

Map<String, dynamic> _apply(String key, Map<String, dynamic> json, PendingChange c) {
  final data = json['data'];
  if (data is! Map<String, dynamic>) return json;
  final now = (c.at ?? DateTime.now()).toUtc().toIso8601String();

  final start = _start.firstMatch(c.endpoint);
  final item = _item.firstMatch(c.endpoint);
  final finish = _finish.firstMatch(c.endpoint);
  final check = _check.firstMatch(c.endpoint);

  if (key == 'staff/rooms') {
    final id = int.parse((start ?? item ?? finish)!.group(1)!);
    final rooms = (data['rooms'] as List?) ?? [];
    final idx = rooms.indexWhere((r) => r is Map && jInt(r['inspection_id']) == id);
    if (idx < 0) return json;
    final room = (rooms[idx] as Map).cast<String, dynamic>();
    if (start != null || item != null) {
      _markStarted(room, now);
    } else {
      final prev = jStr(room['state']);
      if (prev != 'with_porter' && prev != 'ready') {
        room['state'] = 'with_porter';
        final counts = jMap(data['counts']);
        counts['to_clean'] = (jInt(counts['to_clean']) - 1).clamp(0, 1 << 30);
        counts['with_porter'] = jInt(counts['with_porter']) + 1;
        data['counts'] = counts;
        // Finished rooms sit at the end of the list.
        rooms.removeAt(idx);
        rooms.add(room);
      }
    }
    return json;
  }

  if (key.startsWith('staff/rooms/')) {
    if (start != null) {
      _markStarted(data, now);
    } else if (item != null) {
      _markStarted(data, now);
      final itemId = int.parse(item.group(2)!);
      final checked = jBool(c.body['checked']);
      var done = 0;
      var total = 0;
      for (final g in (data['groups'] as List?) ?? const []) {
        if (g is! Map) continue;
        for (final it in (g['items'] as List?) ?? const []) {
          if (it is! Map) continue;
          if (jInt(it['id']) == itemId) it['checked'] = checked;
          total++;
          if (jBool(it['checked'])) done++;
        }
      }
      data['progress'] = {'done': done, 'total': total};
    } else if (finish != null) {
      final prev = jStr(data['state']);
      if (prev != 'with_porter' && prev != 'ready') data['state'] = 'with_porter';
      final note = jStr(c.body['note']).trim();
      if (note.isNotEmpty) data['note'] = note;
    }
    return json;
  }

  if (key == 'staff/checks' && check != null) {
    final id = int.parse(check.group(1)!);
    final rooms = (data['rooms'] as List?) ?? [];
    final before = rooms.length;
    rooms.removeWhere((r) => r is Map && jInt(r['inspection_id']) == id);
    if (rooms.length < before) {
      final p = jMap(data['progress']);
      p['checked'] = jInt(p['checked']) + 1;
      data['progress'] = p;
    }
    return json;
  }

  if (key == 'staff/supplies') {
    final reqs = (data['requests'] as List?) ?? [];
    reqs.insert(0, {
      'id': 0,
      'summary': c.label,
      'created_at': now,
      'status': 'waiting',
      'by': null,
    });
    data['requests'] = reqs;
    return json;
  }

  return json;
}

void _markStarted(Map<String, dynamic> room, String now) {
  final s = jStr(room['state']);
  if (s == 'to_clean' || s == 'sent_back') room['state'] = 'cleaning';
  room['started_at'] ??= now;
  room['assigned_to_me'] = true;
}
