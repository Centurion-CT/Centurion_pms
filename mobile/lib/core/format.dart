import 'package:intl/intl.dart';

import 'strings.dart';

/// "2:00 PM"
String hm(DateTime? t) => t == null ? '' : DateFormat('h:mm a').format(t);

/// "Today 7:50 AM", "Yesterday", "Mon 20 Oct".
String whenLabel(DateTime? t, {DateTime? now}) {
  if (t == null) return '';
  final n = now ?? DateTime.now();
  final today = DateTime(n.year, n.month, n.day);
  final day = DateTime(t.year, t.month, t.day);
  final diff = today.difference(day).inDays;
  if (diff == 0) return S.todayAt(hm(t));
  if (diff == 1) return S.yesterday;
  return DateFormat('EEE d MMM').format(t);
}
