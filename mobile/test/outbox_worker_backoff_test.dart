// Backoff schedule test — OutboxWorker.backoffFor is what decides
// "is this queued entry ready for another attempt?". The exact
// schedule (30s → 60s → 2m → 4m → 8m → 16m → 30m, capped at 30m
// no matter how many times we've retried) is the load-bearing bit
// of the offline story: too aggressive and a bad server 5xx spirals
// into a request storm; too slow and a queued check-in lags after
// wifi comes back. Locking these numbers in with a test keeps
// future refactors honest.

import 'package:flutter_test/flutter_test.dart';
import 'package:keverna/core/outbox/outbox_worker.dart';

void main() {
  group('OutboxWorker.backoffFor', () {
    test('retry 0 → 30s', () {
      expect(OutboxWorker.backoffFor(0), const Duration(seconds: 30));
    });

    test('retry 1 → 60s (2×)', () {
      expect(OutboxWorker.backoffFor(1), const Duration(seconds: 60));
    });

    test('retry 2 → 2min', () {
      expect(OutboxWorker.backoffFor(2), const Duration(seconds: 120));
    });

    test('retry 3 → 4min', () {
      expect(OutboxWorker.backoffFor(3), const Duration(seconds: 240));
    });

    test('retry 5 → 16min', () {
      expect(OutboxWorker.backoffFor(5), const Duration(seconds: 960));
    });

    test('retry 6 → 30min (cap kicks in — raw would be 32m)', () {
      expect(OutboxWorker.backoffFor(6), const Duration(seconds: 1800));
    });

    test('retry 10 → still 30min (cap holds)', () {
      expect(OutboxWorker.backoffFor(10), const Duration(seconds: 1800));
    });

    test('absurd retry count doesn\'t overflow — still 30min', () {
      expect(OutboxWorker.backoffFor(999), const Duration(seconds: 1800));
    });
  });
}
