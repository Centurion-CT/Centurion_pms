<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Actions\Bookings\AddBookingCharge;
use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\ExtendBooking;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\MarkCheckedOut;
use App\Actions\Bookings\MarkNoShow;
use App\Actions\Bookings\ResendBookingConfirmation;
use App\Actions\Bookings\SettleCharges;
use App\Enums\BookingChargeType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\ManualDiscountType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\RoomInspectionStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\GuestRequest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomInspection;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Bookings\EarlyCheckoutQuote;
use App\Services\Corporates\CorporateResolver;
use App\Services\Customers\CustomerLinker;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Guests\GuestIdDocuments;
use App\Services\Payments\BookingPayments;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Host app front desk (docs/host-app/PHASE-2.md, section B): the
 * Bookings tab, booking detail and every front-desk write. All
 * branch-scoped (rooms of the bound branch + category-only bookings
 * against its categories). Writes are idempotent through the
 * `mobile.idempotency` middleware and money goes through the shared
 * {@see BookingPayments} layer the web dashboard uses.
 */
class BookingsController extends HostController
{
    private const SEGMENTS = ['arriving', 'in_house', 'leaving', 'upcoming'];

    private const UPCOMING_DAYS = 14;

    /** Desk bookings list views (`view` param). */
    private const VIEWS = ['upcoming', 'in_house', 'past', 'cancelled', 'all'];

    /** Statuses that are still waiting to arrive. */
    private const ARRIVING_STATUSES = [
        BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold,
    ];

    public function __construct(private readonly BookingPayments $payments) {}

    // ── Reads ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        if ($request->filled('view')) {
            return $this->listView($request);
        }
        $data = $request->validate([
            'segment' => ['nullable', Rule::in(self::SEGMENTS)],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $segment = $data['segment'] ?? 'arriving';
        $q = trim((string) ($data['q'] ?? ''));
        $property = $this->property($request);

        $counts = [];
        foreach (self::SEGMENTS as $seg) {
            $counts[$seg] = $this->segmentQuery($property, $seg, $q)->count();
        }

        $today = Carbon::today()->toDateString();
        $rows = $this->segmentQuery($property, $segment, $q)
            ->with(['room.category', 'roomCategory', 'corporate'])
            ->limit(200)
            ->get();

        $rows = match ($segment) {
            // Overdue (checkout already passed) first: they are urgent.
            'in_house', 'leaving' => $rows->sortBy(fn (Booking $b) => [
                $b->checkout_date->toDateString() < $today ? 0 : 1,
                $b->checkout_date->toDateString(),
                (string) $b->guest_name,
            ]),
            default => $rows->sortBy(fn (Booking $b) => [$b->checkin_date->toDateString(), (string) $b->guest_name]),
        };

        return response()->json([
            'data' => $rows->values()->map(fn (Booking $b) => BookingPresenter::card($b))->all(),
            'counts' => $counts,
        ]);
    }

    /**
     * Keverna Desk bookings list (docs/desk-app/PHASE-2.md, 6):
     * `view` upcoming | in_house | past | cancelled | all, plus q (name,
     * phone, ref, email, room), source, from / to (stays overlapping the
     * range) and page. Rows are the phone's BookingCard plus nights_left
     * and flags; the `segment` mode above is unchanged for the phone.
     */
    private function listView(Request $request): JsonResponse
    {
        $data = $request->validate([
            'view' => ['required', Rule::in(self::VIEWS)],
            'q' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', Rule::in(array_map(fn (BookingSource $s) => $s->value, BookingSource::cases()))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $view = $data['view'];
        $property = $this->property($request);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 50);

        $counts = [];
        foreach (self::VIEWS as $v) {
            $counts[$v] = $this->viewQuery($property, $v, $data)->count();
        }

        $today = Carbon::today()->toDateString();
        $query = $this->viewQuery($property, $view, $data)->with(['room.category', 'roomCategory', 'corporate']);
        match ($view) {
            'upcoming' => $query->orderBy('checkin_date')->orderBy('guest_name'),
            // Overdue (checkout already passed) first: they are urgent.
            'in_house' => $query->orderByRaw('CASE WHEN checkout_date < ? THEN 0 ELSE 1 END', [$today])
                ->orderBy('checkout_date')->orderBy('guest_name'),
            default => $query->orderByDesc('checkin_date')->orderByDesc('id'),
        };
        $total = $counts[$view];
        $rows = $query->forPage($page, $perPage)->get();

        $openRequests = GuestRequest::query()
            ->whereIn('booking_id', $rows->pluck('id'))
            ->where('status', 'open')
            ->pluck('booking_id')->map(fn ($id) => (int) $id)->unique()->all();

        return response()->json([
            'data' => $rows->map(fn (Booking $b) => BookingPresenter::listRow($b, in_array((int) $b->id, $openRequests, true)))->values()->all(),
            'counts' => $counts,
            'meta' => [
                'view' => $view,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $page * $perPage < $total,
            ],
        ]);
    }

    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }

        return response()->json(['data' => BookingPresenter::detail($booking, $this->operator($request))]);
    }

    public function extendQuote(Request $request, string $ref, PricingCalculator $pricing, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate(['checkout' => ['required', 'date_format:Y-m-d']]);
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }

        $current = $booking->checkout_date->copy()->startOfDay();
        $new = Carbon::parse($data['checkout'])->startOfDay();
        $max = $booking->maxExtendableCheckoutDate();
        $quote = [
            'available' => false,
            'max_checkout' => $max?->toDateString(),
            'extra_nights' => max(0, (int) $current->diffInDays($new, absolute: false)),
            'extension_amount' => 0,
            'new_total' => (int) $booking->total_amount,
            'outstanding_after' => BookingPayments::owed($booking),
        ];

        $room = $booking->room;
        $reason = match (true) {
            $room === null => 'Assign a room before extending.',
            ! in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn], true) => 'This booking can no longer be extended.',
            $new->lte($current) => 'Pick a date after the current check-out.',
            $max !== null && $new->gt($max) => 'The room is booked from '.$max->format('j M').'. Latest check-out is '.$max->format('j M').'.',
            ! $availability->isAvailable($room, $current, $new, (int) $booking->id) => 'The room is not free for those nights.',
            default => null,
        };
        if ($reason !== null) {
            return response()->json(['data' => $quote + ['reason' => $reason]]);
        }

        // Same maths as ExtendBooking: net subtotal for the added nights,
        // the guest's percent discount carried forward, minus overstay
        // the guest already paid for those nights.
        $breakdown = $pricing->calculate($room, $current, $new, breakfastIncluded: (bool) $booking->breakfast_included);
        $percent = $booking->manual_discount_type === ManualDiscountType::Percent
            ? max(0.0, min(100.0, (float) $booking->manual_discount_percent))
            : 0.0;
        $charge = max(0, $breakdown->netSubtotal - (int) floor($breakdown->netSubtotal * $percent / 100));
        $overstay = BookingCharge::query()->where('booking_id', $booking->id)->where('type', BookingChargeType::Overstay->value);
        $overstayPaid = (int) (clone $overstay)->where('settled', true)->sum('amount');
        $overstayUnsettled = (int) (clone $overstay)->where('settled', false)->sum('amount');
        $payable = max(0, $charge - $overstayPaid);

        $owedNow = (int) $booking->balance_due
            + (int) BookingCharge::query()->where('booking_id', $booking->id)->where('settled', false)->sum('amount')
            - $overstayUnsettled;

        return response()->json(['data' => array_merge($quote, [
            'available' => true,
            'extra_nights' => $breakdown->nights,
            'extension_amount' => $payable,
            'new_total' => (int) $booking->total_amount + $charge,
            'outstanding_after' => max(0, $owedNow) + $payable,
        ])]);
    }

    // ── Writes ───────────────────────────────────────────────────

    public function takePayment(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(PaymentMethod::takeValues())],
            'reference_note' => ['nullable', 'string', 'max:500'],
        ]);
        $operator = $this->operator($request);
        if (! self::capabilities($operator)['take_payment']) {
            return $this->forbidden();
        }
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }

        // The new payment rows' ids, so the desk can print the right receipt.
        $before = (int) Payment::query()->where('booking_id', $booking->id)->max('id');

        try {
            if ($booking->booking_status === BookingStatus::Pending) {
                $this->payments->confirmAndPay($booking, $operator, (int) $data['amount'], $data['method'], $data['reference_note'] ?? null);
            } else {
                $this->payments->take($booking, $operator, (int) $data['amount'], $data['method'], $data['reference_note'] ?? null);
            }
        } catch (Throwable $e) {
            return response()->json(['error' => 'payment_failed', 'detail' => $e->getMessage()], 422);
        }

        $paymentIds = Payment::query()->where('booking_id', $booking->id)->where('id', '>', $before)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return $this->ok($request, $booking, ['payment_ids' => $paymentIds]);
    }

    /**
     * Send the booking confirmation to the guest again (same as the web
     * reservation page: email + WhatsApp listeners on BookingConfirmed).
     */
    public function resendConfirmation(Request $request, string $ref, ResendBookingConfirmation $action): JsonResponse
    {
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        if (! in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn], true)) {
            return $this->fail('action_failed', 'Only a confirmed booking can be sent again.');
        }
        $action->handle($booking, $this->operator($request));

        return $this->ok($request, $booking, ['sent' => true]);
    }

    public function checkIn(Request $request, string $ref, MarkCheckedIn $action, CustomerLinker $linker, GuestIdDocuments $ids): JsonResponse
    {
        $data = $request->validate([
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['required_with:payment', 'integer', 'min:1'],
            'payment.method' => ['required_with:payment', Rule::in(PaymentMethod::takeValues())],
            'payment.reference_note' => ['nullable', 'string', 'max:500'],
            // Keverna Desk phase 2: ID seen at the desk + key cards.
            'keys_given' => ['nullable', 'integer', 'min:0', 'max:20'],
            ...GuestIdDocuments::rules('id_document'),
        ]);
        $idDocument = $data['id_document'] ?? null;
        if (is_array($idDocument)) {
            $ids->assertImage($idDocument);
        }
        $operator = $this->operator($request);
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        $payment = $data['payment'] ?? null;

        if (! in_array($booking->booking_status, [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold], true)) {
            return $this->fail('action_failed', 'This booking can not be checked in (it is '.strtolower($booking->booking_status->label()).').');
        }
        if ($booking->room_id === null) {
            return $this->fail('room_required', 'Assign a room before checking this guest in.');
        }
        if (Carbon::today()->lt($booking->checkin_date->copy()->startOfDay())) {
            return $this->fail('too_early', 'This guest arrives on '.$booking->checkin_date->format('j M').'.');
        }
        // Same hard block as MarkCheckedIn: a Failed inspection.
        $latest = RoomInspection::query()->where('room_id', $booking->room_id)->latest('id')->first();
        if ($latest?->status === RoomInspectionStatus::Failed) {
            return $this->fail('room_not_ready', 'This room was flagged as not fit for a guest. Clear the flag before checking someone in.');
        }
        if ($booking->booking_status === BookingStatus::Pending && $payment === null) {
            return $this->fail('payment_required', 'Take a payment to confirm this booking before check-in.');
        }
        if ($payment !== null && ! self::capabilities($operator)['take_payment']) {
            return $this->forbidden();
        }

        // Payment + check-in are one unit: a failed check-in rolls the
        // payment back so a retry never double-charges.
        $stage = 'payment';
        try {
            DB::transaction(function () use ($booking, $operator, $payment, $action, $ids, $idDocument, $data, &$stage): void {
                if ($payment !== null) {
                    if ($booking->booking_status === BookingStatus::Pending) {
                        $this->payments->confirmAndPay($booking, $operator, (int) $payment['amount'], $payment['method'], $payment['reference_note'] ?? null);
                    } elseif ($booking->outstandingTotal() > 0) {
                        $this->payments->take($booking, $operator, (int) $payment['amount'], $payment['method'], $payment['reference_note'] ?? null);
                    }
                }
                $stage = 'check_in';
                $action->handle($booking->refresh(), $operator);
                if (isset($data['keys_given'])) {
                    $booking->forceFill(['keys_given' => (int) $data['keys_given']])->save();
                }
                if (is_array($idDocument) && $idDocument !== []) {
                    $ids->record($booking->refresh(), $operator, $idDocument);
                }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->fail($stage === 'payment' ? 'payment_failed' : 'action_failed', $e->getMessage());
        }

        $linker->recordBookingEvent(
            booking: $booking->refresh(),
            event: 'booking.checked_in',
            subject: $booking->guest_name.' checked in',
            body: 'Room '.$booking->room?->name.' · '.Carbon::now()->toFormattedDateString(),
        );
        DashboardSnapshot::forget();

        $warning = $action->pendingInspection !== null
            ? 'Housekeeping is still on this room (waiting on '.$action->pendingInspection->status->waitingOn().'). Follow up before the guest gets there.'
            : null;

        return $this->ok($request, $booking, ['warning' => $warning]);
    }

    public function checkOut(Request $request, string $ref, MarkCheckedOut $action, CustomerLinker $linker, EarlyCheckoutQuote $quote, GuestIdDocuments $ids): JsonResponse
    {
        $data = $request->validate([
            'early_mode' => ['nullable', Rule::in(['keep', 'wallet', 'cash'])],
            'keys_returned' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);
        $operator = $this->operator($request);
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        if ($booking->booking_status !== BookingStatus::CheckedIn) {
            return $this->fail('action_failed', 'Only checked-in guests can be checked out.');
        }

        // Same guard as the web: money owed blocks check-out, except a
        // corporate-guaranteed stay (billed to the company afterwards).
        $outstanding = $booking->outstandingTotal();
        if ($outstanding > 0 && ! $booking->isCorporatePreAuthorized()) {
            return $this->balanceOutstanding($outstanding);
        }

        $policy = $booking->room?->property?->checkoutPolicy() ?? [
            'early_checkout_default_mode' => 'ask',
            'early_checkout_refund_percent' => 100,
        ];
        $q = $quote->quote($booking, refundPercent: (int) $policy['early_checkout_refund_percent']);
        $defaultMode = (string) $policy['early_checkout_default_mode'];
        $mode = 'keep';
        if ($q['is_early'] && $q['refundable_amount'] > 0) {
            $mode = $data['early_mode'] ?? ($defaultMode !== 'ask' ? $defaultMode : null);
            if ($mode === null) {
                return response()->json([
                    'error' => 'early_mode_required',
                    'detail' => 'The guest is leaving early. Choose what happens to the unused nights.',
                    'refundable_amount' => (int) $q['refundable_amount'],
                    'unused_nights' => (int) $q['unused_nights'],
                    'per_night' => (int) $q['per_night'],
                ], 422);
            }
        }

        try {
            $action->handle($booking, $operator, $mode);
        } catch (Throwable $e) {
            $fresh = $booking->refresh();
            $owed = $fresh->outstandingTotal();
            if ($owed > 0 && ! $fresh->isCorporatePreAuthorized()) {
                return $this->balanceOutstanding($owed, $e->getMessage());
            }

            return $this->fail('action_failed', $e->getMessage());
        }

        if (isset($data['keys_returned'])) {
            $booking->forceFill(['keys_returned' => (int) $data['keys_returned']])->save();
        }
        // NDPA retention runs from the real check-out.
        $ids->stampCheckout($booking);

        $linker->recordBookingEvent(
            booking: $booking->refresh(),
            event: 'booking.checked_out',
            subject: $booking->guest_name.' checked out',
            body: 'Total paid ₦'.number_format((int) $booking->total_amount),
        );
        DashboardSnapshot::forget();

        return $this->ok($request, $booking);
    }

    public function addCharge(Request $request, string $ref, AddBookingCharge $add, SettleCharges $settle): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::chargeTypeValues())],
            'amount' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
            'waive' => ['nullable', 'boolean'],
        ]);
        $operator = $this->operator($request);
        if (! self::capabilities($operator)['take_payment']) {
            return $this->forbidden();
        }
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        if (! in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn], true)) {
            return $this->fail('action_failed', 'Charges can only be added to confirmed or in-house stays.');
        }

        $type = BookingChargeType::from($data['type']);
        $description = trim((string) ($data['description'] ?? ''));

        try {
            DB::transaction(function () use ($add, $settle, $booking, $operator, $type, $description, $data): void {
                $charge = $add->handle(
                    $booking, $operator, $type, $description !== '' ? $description : $type->label(), (int) $data['amount'],
                );
                // Waive = on the folio for the audit trail, forgiven on
                // the spot (same as the web Today add-charge sheet).
                if ((bool) ($data['waive'] ?? false)) {
                    $settle->settleOne($charge, $operator, 'waived');
                }
            });
        } catch (Throwable $e) {
            return $this->fail('action_failed', $e->getMessage());
        }
        DashboardSnapshot::forget();

        return $this->ok($request, $booking);
    }

    public function extend(Request $request, string $ref, ExtendBooking $extend, CustomerLinker $linker): JsonResponse
    {
        $data = $request->validate([
            'checkout' => ['required', 'date_format:Y-m-d'],
            'collect' => ['required', Rule::in(['full', 'extension', 'wallet', 'none'])],
            'method' => ['nullable', Rule::in(PaymentMethod::deskValues())],
        ]);
        $operator = $this->operator($request);
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        if ($data['collect'] !== 'none' && ! self::capabilities($operator)['take_payment']) {
            return $this->forbidden();
        }
        if (in_array($data['collect'], ['full', 'extension'], true) && empty($data['method'])) {
            return $this->fail('method_required', 'Pick how the guest is paying.');
        }

        try {
            DB::transaction(function () use ($extend, $booking, $operator, $data): void {
                $extend->handle($booking, Carbon::parse($data['checkout']), $operator);
                $this->payments->collectForExtension($booking->refresh(), $operator, $data['collect'], $data['method'] ?? null);
            });
        } catch (RangeNotAvailableException) {
            return $this->fail('not_available', 'Those extra nights are not available. Try a shorter extension.');
        } catch (Throwable $e) {
            return $this->fail('extend_failed', $e->getMessage());
        }

        $linker->recordBookingEvent(
            booking: $booking->refresh(),
            event: 'booking.extended',
            subject: 'Stay extended to '.$booking->checkout_date->toFormattedDateString(),
        );
        DashboardSnapshot::forget();

        return $this->ok($request, $booking);
    }

    public function noShow(Request $request, string $ref, MarkNoShow $action, CustomerLinker $linker): JsonResponse
    {
        $data = $request->validate(['forfeit_deposit' => ['required', 'boolean']]);
        $operator = $this->operator($request);
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        $forfeit = (bool) $data['forfeit_deposit'];

        try {
            $action->handle($booking, $operator, $forfeit);
        } catch (Throwable $e) {
            return $this->fail('action_failed', $e->getMessage());
        }
        $linker->recordBookingEvent(
            booking: $booking->refresh(),
            event: 'booking.no_show',
            subject: $booking->guest_name.' marked no-show',
            body: $forfeit && (int) $booking->deposit_amount > 0
                ? 'Deposit ₦'.number_format((int) $booking->deposit_amount).' forfeit'
                : null,
        );
        DashboardSnapshot::forget();

        return $this->ok($request, $booking);
    }

    public function cancel(Request $request, string $ref, CancelBooking $action, CustomerLinker $linker): JsonResponse
    {
        $data = $request->validate(['refund_requested' => ['required', 'boolean']]);
        $operator = $this->operator($request);
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        $refund = (bool) $data['refund_requested'];

        try {
            $action->handle($booking, $operator, $refund);
        } catch (Throwable $e) {
            return $this->fail('action_failed', $e->getMessage());
        }
        $linker->recordBookingEvent(
            booking: $booking->refresh(),
            event: 'booking.cancelled',
            subject: 'Booking '.$booking->booking_ref.' cancelled',
            body: $refund ? 'Refund requested' : 'No refund requested',
        );
        DashboardSnapshot::forget();

        return $this->ok($request, $booking);
    }

    public function store(Request $request, CreateBooking $create, PricingCalculator $pricing, CorporateResolver $corporates): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'checkin' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'checkout' => ['required', 'date_format:Y-m-d', 'after:checkin'],
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_phone' => ['required', 'string', 'max:32'],
            'guest_email' => ['nullable', 'email', 'max:180'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['nullable', 'integer', 'min:0', 'max:20'],
            'breakfast' => ['nullable', 'boolean'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(BookingPayments::CREATE_STATUSES)],
            'amount' => ['nullable', 'integer', 'min:1'],
            'method' => ['nullable', Rule::in(PaymentMethod::deskValues())],
            'customer_id' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in(['walk_in', 'phone', 'whatsapp', 'other'])],
        ]);
        $operator = $this->operator($request);
        if (! self::capabilities($operator)['create_booking']) {
            return $this->forbidden();
        }
        $property = $this->property($request);
        $status = $data['status'];

        $room = Room::query()->where('id', $data['room_id'])->where('property_id', $property->id)->first();
        if ($room === null) {
            return $this->fail('room_not_found', 'That room is not in this branch.');
        }
        // The app lists rooms for a 1-adult party first and picks the
        // real party size afterwards, so the room must fit it here.
        $capAdults = (int) ($room->category?->capacity_adults ?? 0);
        $capChildren = (int) ($room->category?->capacity_children ?? 0);
        if ((int) $data['adults'] > $capAdults || (int) ($data['children'] ?? 0) > $capChildren) {
            return response()->json([
                'error' => 'room_too_small',
                'detail' => sprintf(
                    'Room %s takes up to %d adult%s and %d child%s. Pick a bigger room.',
                    $room->room_number, $capAdults, $capAdults === 1 ? '' : 's', $capChildren, $capChildren === 1 ? '' : 'ren',
                ),
                'capacity' => ['adults' => $capAdults, 'children' => $capChildren],
            ], 422);
        }

        $checkin = Carbon::parse($data['checkin']);
        $checkout = Carbon::parse($data['checkout']);
        $breakfast = (bool) ($data['breakfast'] ?? false);

        if (in_array($status, ['confirmed', 'on_hold'], true) && empty($data['method'])) {
            return $this->fail('method_required', 'Pick how the guest is paying.');
        }
        if ($status === 'on_hold') {
            $minDeposit = (int) $pricing->calculate($room, $checkin, $checkout, breakfastIncluded: $breakfast)->depositAmount;
            if ((int) ($data['amount'] ?? 0) < max(1, $minDeposit)) {
                return response()->json([
                    'error' => 'deposit_too_low',
                    'detail' => 'Deposit must be at least ₦'.number_format(max(1, $minDeposit)).' for this room.',
                    'min_deposit' => max(1, $minDeposit),
                ], 422);
            }
        }

        $walletCustomer = null;
        if ($status === 'wallet') {
            $walletCustomer = ! empty($data['customer_id'])
                ? Customer::query()->with('wallet')->find((int) $data['customer_id'])
                : LookupController::findCustomerByPhone($data['guest_phone']);
            if ($walletCustomer === null || $walletCustomer->walletBalance() <= 0) {
                return $this->fail('wallet_unavailable', 'This guest has no wallet balance to spend.');
            }
        }
        if ($status === 'corp_invoice' && $corporates->resolveByPhone($data['guest_phone']) === null) {
            return $this->fail('corporate_required', 'Bill to company needs a phone number on a company roster.');
        }

        $req = new CreateBookingRequest(
            guestName: $data['guest_name'],
            guestEmail: (string) ($data['guest_email'] ?? ''),
            guestPhone: $data['guest_phone'],
            adults: (int) $data['adults'],
            children: (int) ($data['children'] ?? 0),
            checkin: $checkin,
            checkout: $checkout,
            paymentType: $status === 'on_hold' ? PaymentType::Deposit : PaymentType::Full,
            specialRequests: ($data['special_requests'] ?? '') !== '' ? $data['special_requests'] : null,
            source: BookingSource::tryFrom((string) ($data['source'] ?? 'walk_in')) ?? BookingSource::WalkIn,
            autoApplyDiscounts: true,
            breakfastIncluded: $breakfast,
            corporateInvoiceLater: $status === 'corp_invoice',
        );

        try {
            $booking = DB::transaction(function () use ($create, $room, $req, $operator, $status, $data, $walletCustomer): Booking {
                $booking = $create->handle($room, $req);

                return $this->payments->settleOnCreate(
                    $booking,
                    $operator,
                    $status,
                    amount: isset($data['amount']) ? (int) $data['amount'] : null,
                    method: $data['method'] ?? null,
                    walletCustomerId: $walletCustomer?->id,
                );
            });
        } catch (RangeNotAvailableException) {
            return $this->fail('not_available', 'Those dates are not available for this room.');
        } catch (Throwable $e) {
            return $this->fail('create_failed', $e->getMessage());
        }

        return $this->ok($request, $booking);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public static function chargeTypeValues(): array
    {
        return array_values(array_map(
            fn (BookingChargeType $t) => $t->value,
            array_filter(
                BookingChargeType::cases(),
                fn (BookingChargeType $t) => ! in_array($t, [BookingChargeType::Extension, BookingChargeType::DepositForfeit], true),
            ),
        ));
    }

    /**
     * @return Builder<Booking>
     */
    private function segmentQuery(Property $property, string $segment, string $q): Builder
    {
        $today = Carbon::today();
        $query = BookingPresenter::scopeToBranch(Booking::query(), $property);

        match ($segment) {
            'arriving' => $query->whereIn('booking_status', self::ARRIVING_STATUSES)
                ->whereDate('checkin_date', $today->toDateString()),
            'in_house' => $query->where('booking_status', BookingStatus::CheckedIn->value),
            'leaving' => $query->where('booking_status', BookingStatus::CheckedIn->value)
                ->whereDate('checkout_date', '<=', $today->toDateString()),
            default => $query->whereIn('booking_status', self::ARRIVING_STATUSES)
                ->whereDate('checkin_date', '>', $today->toDateString())
                ->whereDate('checkin_date', '<=', $today->copy()->addDays(self::UPCOMING_DAYS)->toDateString()),
        };

        $this->applySearch($query, $q);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Booking>
     */
    private function viewQuery(Property $property, string $view, array $filters): Builder
    {
        $today = Carbon::today()->toDateString();
        $query = BookingPresenter::scopeToBranch(Booking::query(), $property);

        match ($view) {
            'upcoming' => $query->whereIn('booking_status', self::ARRIVING_STATUSES)
                ->whereDate('checkin_date', '>=', $today),
            'in_house' => $query->where('booking_status', BookingStatus::CheckedIn->value),
            'past' => $query->whereIn('booking_status', [BookingStatus::CheckedOut->value, BookingStatus::NoShow->value]),
            'cancelled' => $query->where('booking_status', BookingStatus::Cancelled->value),
            default => $query,
        };
        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('checkout_date', '>', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('checkin_date', '<=', $filters['to']);
        }
        $this->applySearch($query, trim((string) ($filters['q'] ?? '')));

        return $query;
    }

    /**
     * Name, ref, email, room number, or phone digits (3+).
     *
     * @param  Builder<Booking>  $query
     */
    private function applySearch(Builder $query, string $q): void
    {
        if ($q !== '') {
            $needle = '%'.mb_strtolower($q).'%';
            $digits = preg_replace('/\D+/', '', $q) ?? '';
            $query->where(function (Builder $w) use ($needle, $digits, $q): void {
                $w->whereRaw('LOWER(guest_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(booking_ref) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(guest_email) LIKE ?', [$needle])
                    ->orWhereHas('room', fn (Builder $r) => $r->where('room_number', 'like', '%'.$q.'%'));
                if (strlen($digits) >= 3) {
                    $w->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(guest_phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') LIKE ?",
                        ['%'.$digits.'%'],
                    );
                }
            });
        }
    }

    private function find(Request $request, string $ref): ?Booking
    {
        return BookingPresenter::scopeToBranch(Booking::query(), $this->property($request))
            ->where('booking_ref', $ref)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function ok(Request $request, Booking $booking, array $extra = []): JsonResponse
    {
        $fresh = Booking::query()->findOrFail($booking->id);

        return response()->json(array_merge([
            'status' => 'ok',
            'booking' => BookingPresenter::detail($fresh, $this->operator($request)),
        ], $extra));
    }

    private function fail(string $code, string $detail): JsonResponse
    {
        return response()->json(['error' => $code, 'detail' => $detail], 422);
    }

    private function balanceOutstanding(int $outstanding, ?string $detail = null): JsonResponse
    {
        return response()->json([
            'error' => 'balance_outstanding',
            'detail' => $detail ?? 'Outstanding balance of ₦'.number_format($outstanding).' must be cleared before check-out.',
            'outstanding' => $outstanding,
        ], 422);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'detail' => 'Booking not found in this branch.'], 404);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['error' => 'forbidden', 'detail' => 'Your role can not do this.'], 403);
    }
}
