// Small, forgiving JSON readers. The server is the source of truth for
// shapes (PLAN.md); these only stop one odd field from crashing a screen.

Map<String, dynamic> jMap(Object? v) => v is Map ? v.cast<String, dynamic>() : <String, dynamic>{};

List<Map<String, dynamic>> jList(Object? v) =>
    v is List ? v.whereType<Map<dynamic, dynamic>>().map((m) => m.cast<String, dynamic>()).toList() : const [];

List<String> jStrings(Object? v) => v is List ? v.map((e) => '$e').toList() : const [];

String jStr(Object? v, [String fallback = '']) => v == null ? fallback : '$v';

String? jStrOrNull(Object? v) => v == null ? null : '$v';

int jInt(Object? v, [int fallback = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  if (v is String) return int.tryParse(v) ?? fallback;
  return fallback;
}

int? jIntOrNull(Object? v) => v == null ? null : jInt(v);

bool jBool(Object? v) => v == true || v == 1 || v == '1' || v == 'true';

/// ISO 8601 with offset -> local DateTime.
DateTime? jTime(Object? v) {
  if (v is! String || v.isEmpty) return null;
  return DateTime.tryParse(v)?.toLocal();
}

/// Deep copy through JSON-compatible values.
Map<String, dynamic> jCopy(Map<String, dynamic> m) => _copy(m) as Map<String, dynamic>;

Object? _copy(Object? v) {
  if (v is Map) return v.map<String, dynamic>((k, x) => MapEntry('$k', _copy(x)));
  if (v is List) return v.map(_copy).toList();
  return v;
}
