// Reservations repository + Riverpod providers. Same read-through /
// mutation-service pattern as inspections.

import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/outbox/mutation_service.dart';
import '../../core/outbox/outbox_providers.dart';
import 'available_room_models.dart';
import 'reservation_models.dart';
import 'reservations_cache_dao.dart';

class ReservationsRepository {
  ReservationsRepository({
    required Dio dio,
    required MutationService mutations,
    required ReservationsCacheDao cache,
  })  : _dio = dio,
        _mutations = mutations,
        _cache = cache;

  final Dio _dio;
  final MutationService _mutations;
  final ReservationsCacheDao _cache;

  // ── Refresh ──

  Future<void> refreshList(String filter, {String? q}) async {
    final resp = await _dio.get<Map<String, dynamic>>(
      'reservations',
      queryParameters: {
        'filter': filter,
        if (q != null && q.isNotEmpty) 'q': q,
      },
    );
    final rows = (resp.data!['data'] as List)
        .map((e) => (e as Map).cast<String, dynamic>())
        .toList();
    await _cache.upsertList(filter, rows);
  }

  Future<void> refreshDetail(String ref) async {
    final resp = await _dio.get<Map<String, dynamic>>('reservations/$ref');
    final data = (resp.data!['data'] as Map).cast<String, dynamic>();
    await _cache.upsertDetail(ref, data);
  }

  // ── Writes ──

  Future<ReservationDetail> checkIn(String ref, {required String guestName}) {
    return _run('reservations/$ref/check-in', const {}, 'Check in $ref', ref);
  }

  Future<ReservationDetail> checkOut(String ref, {required String guestName}) {
    return _run('reservations/$ref/check-out', const {}, 'Check out $ref', ref);
  }

  Future<ReservationDetail> markNoShow(String ref, {bool forfeitDeposit = true}) {
    return _run(
      'reservations/$ref/no-show',
      {'forfeit_deposit': forfeitDeposit},
      'No-show $ref',
      ref,
    );
  }

  Future<ReservationDetail> cancel(String ref, {bool refundDeposit = false}) {
    return _run(
      'reservations/$ref/cancel',
      {'refund_deposit': refundDeposit},
      'Cancel $ref',
      ref,
    );
  }

  Future<ReservationDetail> recordPayment(
    String ref, {
    required int amount,
    required String method,
    String? referenceNote,
  }) {
    return _run(
      'reservations/$ref/payment',
      {
        'amount': amount,
        'method': method,
        if (referenceNote != null) 'reference_note': referenceNote,
      },
      'Payment ₦$amount for $ref',
      ref,
    );
  }

  /// Fetch rooms available for a date range + party size. Straight
  /// network call; not cached because "which rooms are free"
  /// changes minute-by-minute at a front desk.
  Future<List<AvailableRoom>> availableRooms({
    required DateTime checkin,
    required DateTime checkout,
    required int adults,
    required int children,
  }) async {
    final resp = await _dio.get<Map<String, dynamic>>(
      'rooms/available',
      queryParameters: {
        'checkin': _isoDate(checkin),
        'checkout': _isoDate(checkout),
        'adults': adults,
        'children': children,
      },
    );
    return ((resp.data!['data'] as List))
        .map((e) => AvailableRoom.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  /// Manual walk-in booking. Delegates to POST /bookings which uses
  /// the same CreateBooking action as web. Writes go through
  /// MutationService so an offline attempt queues + retries.
  Future<ReservationDetail> createBooking({
    required int roomId,
    required String guestName,
    required String guestEmail,
    required String guestPhone,
    required int adults,
    required int children,
    required DateTime checkin,
    required DateTime checkout,
    required String paymentType, // 'full' | 'deposit'
    required String source, // 'walk_in' | 'phone' | 'whatsapp' | 'other'
    String? sourceNote,
    String? specialRequests,
    bool breakfastIncluded = false,
    String? discountCode,
    // Optional same-time settlement.
    int? settleAmount,
    String? settleMethod,
    String? settleReferenceNote,
  }) async {
    final settleNow = settleAmount != null && settleAmount > 0 && settleMethod != null
        ? {
            'amount': settleAmount,
            'method': settleMethod,
            if (settleReferenceNote != null) 'reference_note': settleReferenceNote,
          }
        : null;

    return _mutations.perform<ReservationDetail>(
      endpoint: 'bookings',
      body: {
        'room_id': roomId,
        'guest_name': guestName,
        'guest_email': guestEmail,
        'guest_phone': guestPhone,
        'adults': adults,
        'children': children,
        'checkin': _isoDate(checkin),
        'checkout': _isoDate(checkout),
        'payment_type': paymentType,
        'source': source,
        if (sourceNote != null) 'source_note': sourceNote,
        if (specialRequests != null) 'special_requests': specialRequests,
        'breakfast_included': breakfastIncluded,
        if (discountCode != null && discountCode.isNotEmpty) 'discount_code': discountCode,
        if (settleNow != null) 'settle_now': settleNow,
      },
      hint: 'Book $guestName',
      parse: (resp) {
        final data = resp.data as Map<String, dynamic>;
        final rsv = (data['reservation'] as Map).cast<String, dynamic>();
        return ReservationDetail.fromJson(rsv);
      },
    );
  }

  String _isoDate(DateTime d) {
    // Y-m-d only — server validates as date_format:Y-m-d.
    final y = d.year.toString().padLeft(4, '0');
    final m = d.month.toString().padLeft(2, '0');
    final day = d.day.toString().padLeft(2, '0');
    return '$y-$m-$day';
  }

  Future<ReservationDetail> _run(
    String endpoint,
    Map<String, dynamic> body,
    String hint,
    String ref,
  ) async {
    final fresh = await _mutations.perform<ReservationDetail>(
      endpoint: endpoint,
      body: body,
      hint: hint,
      parse: (resp) {
        final data = resp.data as Map<String, dynamic>;
        final rsv = (data['reservation']['data'] as Map).cast<String, dynamic>();
        return ReservationDetail.fromJson(rsv);
      },
    );
    // Upsert into cache so the detail screen reflects fresh state
    // immediately. List cache is refreshed in the background since
    // the write may have moved this row across filters (e.g. an
    // arrival became in_house after check-in).
    await _cache.upsertDetail(ref, _detailToJson(fresh));
    unawaited(refreshList('arrivals'));
    unawaited(refreshList('in_house'));
    unawaited(refreshList('departures'));
    return fresh;
  }

  /// Round-trip the parsed detail back into the server's shape so
  /// cache is parse-symmetric with fresh network payloads.
  Map<String, dynamic> _detailToJson(ReservationDetail d) {
    return {
      'id': d.id,
      'booking_ref': d.bookingRef,
      'guest_name': d.guestName,
      'guest_email': d.guestEmail,
      'guest_phone': d.guestPhone,
      'status': d.status,
      'status_label': d.statusLabel,
      'checkin_date': d.checkinDate?.toIso8601String().substring(0, 10),
      'checkout_date': d.checkoutDate?.toIso8601String().substring(0, 10),
      'total_amount': d.totalAmount,
      'balance_due': d.balanceDue,
      'special_requests': d.specialRequests,
      'room': {
        'room_number': d.roomNumber,
        'category_name': d.categoryName,
      },
      'charges': d.charges
          .map((c) => {
                'id': c.id,
                'type': c.type,
                'description': c.description,
                'amount': c.amount,
                'settled': c.settled,
                'settled_method': c.settledMethod,
              })
          .toList(),
      'payments': d.payments
          .map((p) => {
                'id': p.id,
                'amount': p.amount,
                'method': p.method,
                'status': p.status,
                'reference_note': p.referenceNote,
                'paid_at': p.paidAt?.toIso8601String(),
              })
          .toList(),
    };
  }
}

// ── Providers ──

final reservationsCacheDaoProvider = Provider<ReservationsCacheDao>((ref) {
  return ReservationsCacheDao(ref.watch(localDbProvider));
});

final reservationsRepositoryProvider = Provider<ReservationsRepository?>((ref) {
  final client = ref.watch(apiClientProvider);
  final mutations = ref.watch(mutationServiceProvider);
  final cache = ref.watch(reservationsCacheDaoProvider);
  if (client == null || mutations == null) return null;
  return ReservationsRepository(dio: client.raw, mutations: mutations, cache: cache);
});

final reservationsListProvider =
    StreamProvider.autoDispose.family<List<ReservationSummary>, String>((ref, filter) {
  final repo = ref.watch(reservationsRepositoryProvider);
  final cache = ref.watch(reservationsCacheDaoProvider);
  if (repo == null) return Stream.value(const []);

  Future.microtask(() async {
    try {
      await repo.refreshList(filter);
    } catch (_) {}
  });

  return cache.watchList(filter);
});

final reservationDetailProvider =
    StreamProvider.autoDispose.family<ReservationDetail?, String>((ref, refCode) {
  final repo = ref.watch(reservationsRepositoryProvider);
  final cache = ref.watch(reservationsCacheDaoProvider);
  if (repo == null) return Stream.value(null);

  Future.microtask(() async {
    try {
      await repo.refreshDetail(refCode);
    } catch (_) {}
  });

  return cache.watchDetail(refCode);
});
