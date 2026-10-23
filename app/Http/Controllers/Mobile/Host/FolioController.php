<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Actions\Bookings\ApplyPostCheckinDiscount;
use App\Actions\Bookings\AssignRoomToBooking;
use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\ChangeBookingDates;
use App\Actions\Bookings\EditReservation;
use App\Actions\Bookings\ReassignRoom;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ManualDiscountType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Bookings\RoomAllocator;
use App\Services\Customers\CustomerLinker;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Guests\GuestIdDocuments;
use App\Services\Payments\BookingPayments;
use App\Support\Payments\PaymentReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Keverna Desk phase 2 (docs/desk-app/PHASE-2.md, Server): folio work
 * the desk needs on top of the phone's front desk endpoints. Move room,
 * date changes, discounts, guest ID, and the printable receipt / folio.
 * Branch-scoped like {@see BookingsController}; every money change goes
 * through the same actions the web reservation page uses.
 */
class FolioController extends HostController
{
    /** Not arrived yet: dates can still move. */
    private const PRE_ARRIVAL = [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold];

    private const MOVABLE = [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn];

    // ── Move room ────────────────────────────────────────────────

    public function moveOptions(Request $request, string $ref, RoomAllocator $allocator): JsonResponse
    {
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        $room = $booking->room;
        if ($room === null) {
            return $this->fail('no_room', 'This booking has no room yet. Pick one at check-in.');
        }
        if (! in_array($booking->booking_status, self::MOVABLE, true)) {
            return $this->fail('action_failed', 'This booking can no longer change rooms.');
        }

        [$from, $remaining] = self::remainingStay($booking);
        $candidates = $allocator->availableRoomsInProperty(
            propertyId: (int) $this->property($request)->id,
            checkin: $from,
            checkout: $booking->checkout_date,
            excludeRoomId: (int) $room->id,
        );
        $currentRate = (int) $room->base_rate;

        return response()->json([
            'data' => $candidates->map(function (Room $r) use ($currentRate, $remaining): array {
                $diff = (int) $r->base_rate - $currentRate;

                return [
                    'id' => (int) $r->id,
                    'room_number' => (string) $r->room_number,
                    'floor' => $r->floor,
                    'category' => $r->category !== null ? ['id' => (int) $r->category->id, 'name' => (string) $r->category->name] : null,
                    'rate' => (int) $r->base_rate,
                    'rate_difference_per_night' => $diff,
                    'total_difference' => $diff * $remaining,
                    'housekeeping' => self::housekeeping($r),
                ];
            })->values()->all(),
            'current' => [
                'room_id' => (int) $room->id,
                'room_number' => (string) $room->room_number,
                'rate' => $currentRate,
            ],
            'remaining_nights' => $remaining,
            'from' => $from->toDateString(),
            'to' => $booking->checkout_date->toDateString(),
            'can_move_free' => self::canApproveDiscount($this->operator($request)),
        ]);
    }

    public function moveRoom(Request $request, string $ref, ReassignRoom $reassign, CustomerLinker $linker): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
            'charge' => ['nullable', Rule::in(['difference', 'free'])],
        ]);
        $operator = $this->operator($request);
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        $oldRoom = $booking->room;
        if ($oldRoom === null) {
            return $this->fail('no_room', 'This booking has no room yet. Pick one at check-in.');
        }
        $newRoom = Room::query()->where('id', $data['room_id'])->where('property_id', $this->property($request)->id)->first();
        if ($newRoom === null) {
            return $this->fail('room_not_found', 'That room is not in this branch.');
        }

        $charge = $data['charge'] ?? 'difference';
        [, $remaining] = self::remainingStay($booking);
        $preview = ((int) $newRoom->base_rate - (int) $oldRoom->base_rate) * $remaining;
        if ($charge === 'free' && $preview > 0 && ! self::canApproveDiscount($operator)) {
            return $this->needsManager('A manager has to approve a free upgrade. Charge the difference or ask a manager.');
        }

        $before = [
            'total_amount' => (int) $booking->total_amount,
            'room_total' => (int) $booking->room_total,
            'balance_due' => (int) $booking->balance_due,
        ];
        $wasInHouse = $booking->booking_status === BookingStatus::CheckedIn;
        $reason = trim((string) ($data['reason'] ?? ''));

        try {
            $result = DB::transaction(function () use ($reassign, $booking, $newRoom, $oldRoom, $operator, $charge, $before, $wasInHouse, $reason) {
                $result = $reassign->handle($booking, $newRoom, $operator);

                if ($charge === 'free' && $result->rateDelta > 0) {
                    $result->booking->forceFill($before)->save();
                    activity('booking')
                        ->causedBy($operator)
                        ->performedOn($result->booking)
                        ->event('room_upgrade_free')
                        ->withProperties(['rate_delta_waived' => $result->rateDelta, 'reason' => $reason !== '' ? $reason : null])
                        ->log('Free upgrade to room '.$newRoom->room_number.' (₦'.number_format($result->rateDelta).' not charged).');
                }

                // The guest slept in the old room: housekeeping turns it.
                if ($wasInHouse) {
                    $oldRoom->forceFill([
                        'housekeeping_status' => HousekeepingStatus::Dirty->value,
                        'housekeeping_updated_at' => Carbon::now(),
                        'housekeeping_updated_by_operator_id' => $operator->id,
                    ])->save();
                }

                return $result;
            });
        } catch (RangeNotAvailableException) {
            return $this->conflict('not_available', 'That room is not free for the rest of the stay. Pick another.');
        } catch (Throwable $e) {
            return $this->fail('action_failed', $e->getMessage());
        }

        $charged = $charge === 'free' && $result->rateDelta > 0 ? 0 : $result->rateDelta;
        $linker->recordBookingEvent(
            booking: $booking->refresh(),
            event: 'booking.room_reassigned',
            subject: 'Room changed from '.$oldRoom->room_number.' to '.$newRoom->room_number,
            body: trim(($reason !== '' ? $reason.'. ' : '').($charged !== 0 ? 'Rate difference '.($charged > 0 ? '+' : '-').'₦'.number_format(abs($charged)) : '')) ?: null,
        );
        DashboardSnapshot::forget();

        return $this->ok($request, $booking, ['move' => [
            'from_room' => (string) $oldRoom->room_number,
            'to_room' => (string) $newRoom->room_number,
            'remaining_nights' => $result->remainingNights,
            'rate_delta' => $result->rateDelta,
            'charged' => $charged,
            'free' => $charge === 'free' && $result->rateDelta > 0,
            'old_room_dirty' => $wasInHouse,
        ]]);
    }

    // ── Dates ────────────────────────────────────────────────────

    public function changeDates(Request $request, string $ref, EditReservation $edit, ChangeBookingDates $change, AssignRoomToBooking $assign): JsonResponse
    {
        $data = $request->validate([
            'checkin_date' => ['nullable', 'date_format:Y-m-d'],
            'checkout_date' => ['nullable', 'date_format:Y-m-d'],
            'room_id' => ['nullable', 'integer'],
        ]);
        $operator = $this->operator($request);
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        if (! in_array($booking->booking_status, self::PRE_ARRIVAL, true)) {
            return $this->fail('action_failed', 'Only bookings that have not arrived yet can change dates. Use extend or move room for guests in house.');
        }
        if (empty($data['checkin_date']) && empty($data['checkout_date']) && empty($data['room_id'])) {
            return $this->fail('nothing_to_change', 'Send new dates or a room.');
        }

        $checkin = Carbon::parse($data['checkin_date'] ?? $booking->checkin_date->toDateString())->startOfDay();
        $checkout = Carbon::parse($data['checkout_date'] ?? $booking->checkout_date->toDateString())->startOfDay();
        if ($checkout->lte($checkin)) {
            return $this->fail('invalid_dates', 'Check-out must be after check-in.');
        }
        if ($checkin->lt(Carbon::today())) {
            return $this->fail('invalid_dates', 'Check-in can not be in the past.');
        }

        $room = null;
        if (! empty($data['room_id'])) {
            $room = Room::query()->where('id', $data['room_id'])->where('property_id', $this->property($request)->id)->first();
            if ($room === null) {
                return $this->fail('room_not_found', 'That room is not in this branch.');
            }
        }
        $oldTotal = (int) $booking->total_amount;

        try {
            if ($booking->room_id === null) {
                // Category hold: the web's Edit reservation path, then pin
                // the room if the drag landed on one.
                DB::transaction(function () use ($edit, $assign, $booking, $operator, $checkin, $checkout, $room): void {
                    $datesMoved = ! $checkin->equalTo($booking->checkin_date->copy()->startOfDay())
                        || ! $checkout->equalTo($booking->checkout_date->copy()->startOfDay());
                    if ($datesMoved) {
                        $edit->handle($booking, [
                            'checkin_date' => $checkin->toDateString(),
                            'checkout_date' => $checkout->toDateString(),
                        ], $operator);
                    }
                    if ($room !== null) {
                        $assign->handle($booking->refresh(), $room, $operator);
                    }
                });
            } else {
                $change->handle($booking, $checkin, $checkout, $room, $operator);
            }
        } catch (RangeNotAvailableException) {
            return $this->conflict('not_available', 'Those dates are not available for this room.');
        } catch (BookingValidationException $e) {
            return $this->fail('action_failed', $e->getMessage());
        } catch (Throwable $e) {
            return $this->fail('action_failed', $e->getMessage());
        }
        DashboardSnapshot::forget();
        $fresh = $booking->refresh();

        return $this->ok($request, $booking, ['change' => [
            'old_total' => $oldTotal,
            'new_total' => (int) $fresh->total_amount,
            'delta' => (int) $fresh->total_amount - $oldTotal,
        ]]);
    }

    // ── Discount ─────────────────────────────────────────────────

    public function discountRequest(Request $request, string $ref, ApplyPostCheckinDiscount $discount, CustomerLinker $linker): JsonResponse
    {
        $data = $request->validate([
            'percent' => ['nullable', 'numeric', 'gt:0', 'max:100', 'required_without:amount', 'prohibits:amount'],
            'amount' => ['nullable', 'integer', 'min:1', 'required_without:percent'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $operator = $this->operator($request);
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        if (! in_array($booking->booking_status, self::MOVABLE, true)) {
            return $this->fail('action_failed', 'Discounts can only be given on live bookings.');
        }
        if (! self::canApproveDiscount($operator)) {
            return $this->needsManager('A manager has to give this discount. Ask a manager to unlock the desk and apply it.');
        }

        $isPercent = isset($data['percent']);
        try {
            $discount->handle(
                $booking,
                $operator,
                $isPercent ? ManualDiscountType::Percent : ManualDiscountType::Fixed,
                percent: $isPercent ? (float) $data['percent'] : 0.0,
                fixedAmount: $isPercent ? 0 : (int) $data['amount'],
                reason: $data['reason'],
            );
        } catch (Throwable $e) {
            return $this->fail('action_failed', $e->getMessage());
        }

        $linker->recordBookingEvent(
            booking: $booking->refresh(),
            event: 'booking.discount_applied',
            subject: 'Discount given',
            body: $data['reason'],
        );
        DashboardSnapshot::forget();

        return $this->ok($request, $booking, ['applied' => true]);
    }

    // ── Guest ID ─────────────────────────────────────────────────

    public function recordIdDocument(Request $request, string $ref, GuestIdDocuments $ids): JsonResponse
    {
        $data = $request->validate(GuestIdDocuments::rules(''));
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        if (in_array($booking->booking_status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
            return $this->fail('action_failed', 'This booking is not live.');
        }
        $ids->record($booking, $this->operator($request), $data, 'image_base64');

        return $this->ok($request, $booking);
    }

    public function idDocumentImage(Request $request, string $ref, GuestIdDocuments $ids): JsonResponse|Response
    {
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        $doc = $ids->forBooking($booking);
        $image = $doc !== null ? $ids->readImage($doc) : null;
        if ($image === null) {
            return response()->json(['error' => 'not_found', 'detail' => 'No ID scan on file.'], 404);
        }

        return response($image[0], 200, [
            'Content-Type' => $image[1],
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ── Printing ─────────────────────────────────────────────────

    public function receipt(Request $request, int $id): JsonResponse
    {
        $property = $this->property($request);
        $payment = Payment::query()
            ->whereKey($id)
            ->whereHas('booking', fn ($b) => BookingPresenter::scopeToBranch($b, $property))
            ->with(['booking.room.category', 'booking.roomCategory', 'booking.payments', 'collectedBy:id,name'])
            ->first();
        if ($payment === null) {
            return response()->json(['error' => 'not_found', 'detail' => 'Payment not found in this branch.'], 404);
        }
        $booking = $payment->booking;
        $operator = $this->operator($request);
        $label = PaymentReceipt::typeLabel($payment);

        return response()->json(['data' => [
            'hotel' => self::hotel($property),
            'number' => PaymentReceipt::number($payment),
            'at' => ($payment->paid_at ?? $payment->created_at)?->toIso8601String(),
            'guest' => (string) $booking->guest_name,
            'room' => $booking->room?->room_number,
            'booking_ref' => (string) $booking->booking_ref,
            'lines' => [['label' => $label, 'amount' => (int) $payment->amount]],
            'method' => $payment->method,
            'method_label' => PaymentMethod::labelFor($payment->method),
            'reference' => $payment->reference_note,
            'status' => $payment->status?->value,
            'paid' => (int) $payment->amount,
            'balance_after' => PaymentReceipt::balanceAfter($payment),
            'balance_now' => BookingPayments::owed($booking),
            'served_by' => $payment->collectedBy?->name,
            'printed_by' => (string) $operator->name,
            'pdf_url' => $operator->canAccessModule('payments')
                ? WebHandoffController::mint($request, $operator, '/dashboard/payments/'.$payment->id.'/receipt')
                : null,
        ]]);
    }

    public function invoice(Request $request, string $ref): JsonResponse
    {
        $booking = $this->find($request, $ref);
        if ($booking === null) {
            return $this->notFound();
        }
        $booking->loadMissing(['room.category', 'roomCategory', 'corporate']);
        $operator = $this->operator($request);
        $property = $this->property($request);

        $charges = BookingCharge::query()->where('booking_id', $booking->id)->orderBy('id')->get();
        $billable = $charges->reject(fn (BookingCharge $c) => $c->settled && $c->settled_method === 'waived');
        $extensions = (int) $billable->where('type', BookingChargeType::Extension)->sum('amount');

        // ExtendBooking adds the extra nights to total_amount AND as an
        // Extension charge, so the stay line is total_amount without them.
        $nights = (int) $booking->nights;
        $roomLabel = $booking->room !== null ? 'Room '.$booking->room->room_number : ($booking->roomCategory?->name ?? 'Room');
        $lines = [[
            'label' => $roomLabel.', '.$nights.' night'.($nights === 1 ? '' : 's'),
            'amount' => max(0, (int) $booking->total_amount - $extensions),
            'date' => $booking->checkin_date?->toDateString(),
        ]];
        foreach ($billable as $c) {
            $lines[] = [
                'label' => (string) ($c->description ?: $c->type->label()),
                'amount' => (int) $c->amount,
                'date' => $c->created_at?->toDateString(),
            ];
        }
        $unbilled = (int) $booking->unchargedOverstayAmount();
        if ($unbilled > 0) {
            $lines[] = ['label' => 'Overstay (not billed yet)', 'amount' => $unbilled, 'date' => Carbon::today()->toDateString()];
        }

        // Waived charges write a "waived" Payment row for the ledger; it
        // is not money the guest paid, and the charge is off the folio.
        $payments = Payment::query()->where('booking_id', $booking->id)
            ->where('status', PaymentStatus::Succeeded)
            ->where(fn ($q) => $q->whereNull('method')->orWhere('method', '!=', 'waived'))
            ->orderBy('paid_at')->orderBy('id')->get();
        $invoice = Invoice::query()->where('booking_id', $booking->id)
            ->where('status', '!=', InvoiceStatus::Void->value)->whereNotNull('number')->latest('id')->first();
        $canInvoicePdf = $operator->canAccessModule('bookings');

        return response()->json(['data' => [
            'hotel' => self::hotel($property),
            'number' => $invoice?->number,
            'booking_ref' => (string) $booking->booking_ref,
            'at' => Carbon::now()->toIso8601String(),
            'guest' => [
                'name' => (string) $booking->guest_name,
                'phone' => $booking->guest_phone ?: null,
                'email' => $booking->guest_email ?: null,
            ],
            'company' => $booking->corporate?->name,
            'room' => $booking->room?->room_number,
            'category' => $booking->room?->category?->name ?? $booking->roomCategory?->name,
            'checkin' => $booking->checkin_date?->toDateString(),
            'checkout' => $booking->checkout_date?->toDateString(),
            'nights' => $nights,
            'lines' => $lines,
            'discount' => (int) $booking->manual_discount_amount + (int) ($booking->corporate_discount_naira ?? 0),
            'total' => (int) array_sum(array_column($lines, 'amount')),
            'payments' => $payments->map(fn (Payment $p) => [
                'id' => (int) $p->id,
                'at' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                'method_label' => PaymentMethod::labelFor($p->method),
                'amount' => (int) $p->amount,
                'reference' => $p->reference_note,
            ])->values()->all(),
            'paid' => (int) $payments->sum('amount'),
            'balance' => BookingPayments::owed($booking),
            'served_by' => (string) $operator->name,
            'invoice' => $invoice !== null ? [
                'id' => (int) $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'total' => (int) $invoice->total,
                'pdf_url' => $canInvoicePdf ? WebHandoffController::mint($request, $operator, '/dashboard/invoices/'.$invoice->id.'/pdf') : null,
            ] : null,
            'pdf_url' => $invoice !== null && $canInvoicePdf
                ? WebHandoffController::mint($request, $operator, '/dashboard/invoices/'.$invoice->id.'/pdf')
                : null,
        ]]);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * @return array{name: string, address: ?string, phone: ?string, email: ?string}
     */
    public static function hotel(Property $property): array
    {
        $tenant = Tenant::current();
        $address = collect([$property->address, $property->city, $property->state])
            ->filter(fn ($v) => trim((string) $v) !== '')->implode(', ');

        return [
            'name' => (string) $property->name,
            'address' => $address !== '' ? $address : null,
            'phone' => $tenant?->phone ?: null,
            'email' => $tenant?->email ?: null,
        ];
    }

    /**
     * Where the rest of the stay starts (today for guests in house) and
     * how many nights remain: the same window ReassignRoom reprices.
     *
     * @return array{0: Carbon, 1: int}
     */
    public static function remainingStay(Booking $booking): array
    {
        $nights = (int) $booking->nights;
        $checkin = $booking->checkin_date->copy()->startOfDay();
        if ($booking->booking_status !== BookingStatus::CheckedIn) {
            return [$checkin, $nights];
        }
        $today = Carbon::today();
        $stayed = min($nights, max(0, (int) $checkin->diffInDays($today, absolute: false)));

        return [$today->greaterThan($checkin) ? $today : $checkin, max(0, $nights - $stayed)];
    }

    /**
     * @return array{state: string, label: string}
     */
    private static function housekeeping(Room $room): array
    {
        $status = $room->housekeeping_status instanceof HousekeepingStatus
            ? $room->housekeeping_status
            : (HousekeepingStatus::tryFrom((string) $room->housekeeping_status) ?? HousekeepingStatus::Clean);

        return ['state' => $status->value, 'label' => $status->label()];
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

    private function conflict(string $code, string $detail): JsonResponse
    {
        return response()->json(['error' => $code, 'detail' => $detail], 409);
    }

    private function needsManager(string $detail): JsonResponse
    {
        return response()->json(['error' => 'needs_manager', 'detail' => $detail], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'detail' => 'Booking not found in this branch.'], 404);
    }
}
