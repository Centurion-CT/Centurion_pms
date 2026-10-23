<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\RoomCategory;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pre-check-in reservation edit. Front desk fixes anything that was
 * captured wrong at reserve time, a typo in the guest name, dates
 * that need shifting, a wrong category pick, a discount the manager
 * forgot to apply. Everything that moves money reprices in one
 * atomic transaction so the ledger stays consistent.
 *
 * Scope:
 *   - Customer fields (name / phone / email / adults / children /
 *     special_requests) are always editable while the booking is
 *     "live" (Pending / Confirmed / OnHold).
 *   - Dates, room_category_id, and the manual discount
 *     are editable only while the booking is still a category hold
 *     (room_id === null). Pinned bookings go through ReassignRoom
 *     + ExtendStay instead — those tools own the per-room
 *     availability guard the simple re-block below can't.
 *
 * Not editable here: booking_ref, status, room_id/room_ids,
 * corporate_id, payments, charges — each has its own flow with its
 * own guards.
 */
class EditReservation
{
    public function __construct(
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @param  array{
     *     guest_name?: string,
     *     guest_phone?: ?string,
     *     guest_email?: ?string,
     *     adults?: int,
     *     children?: int,
     *     special_requests?: ?string,
     *     checkin_date?: string,
     *     checkout_date?: string,
     *     room_category_id?: ?int,
     *     manual_discount_type?: string,
     *     manual_discount_percent?: float,
     *     manual_discount_amount?: int,
     * }  $input
     *
     * @throws BookingValidationException
     * @throws RangeNotAvailableException
     * @throws RuntimeException
     */
    public function handle(Booking $booking, array $input, Operator $operator): Booking
    {
        $legal = [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold];
        if (! in_array($booking->booking_status, $legal, true)) {
            throw new RuntimeException("Cannot edit a {$booking->booking_status->value} booking.");
        }

        $guestName = isset($input['guest_name']) ? trim((string) $input['guest_name']) : $booking->guest_name;
        if ($guestName === '') {
            throw new BookingValidationException('Guest name cannot be empty.');
        }

        $adults = isset($input['adults']) ? (int) $input['adults'] : (int) $booking->adults;
        $children = isset($input['children']) ? max(0, (int) $input['children']) : (int) $booking->children;
        if ($adults < 1) {
            throw new BookingValidationException('At least one adult is required.');
        }

        $email = array_key_exists('guest_email', $input)
            ? trim((string) ($input['guest_email'] ?? ''))
            : (string) $booking->guest_email;
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BookingValidationException('Email address looks invalid.');
        }
        $phone = array_key_exists('guest_phone', $input)
            ? trim((string) ($input['guest_phone'] ?? ''))
            : (string) $booking->guest_phone;

        $specialRequests = array_key_exists('special_requests', $input)
            ? (trim((string) ($input['special_requests'] ?? '')) !== ''
                ? trim((string) $input['special_requests'])
                : null)
            : $booking->special_requests;

        // All money-side edits are gated to unassigned category holds.
        // A pinned room means availability depends on *that* room
        // specifically, which this action's category re-block logic
        // can't guard. ReassignRoom + ExtendStay cover the pinned
        // path with the right per-room checks.
        $wantsDateEdit = array_key_exists('checkin_date', $input) || array_key_exists('checkout_date', $input);
        $wantsCategoryEdit = array_key_exists('room_category_id', $input)
            && (int) ($input['room_category_id'] ?? 0) > 0
            && (int) $input['room_category_id'] !== (int) ($booking->room_category_id ?? 0);
        $wantsDiscountEdit = array_key_exists('manual_discount_type', $input)
            || array_key_exists('manual_discount_amount', $input)
            || array_key_exists('manual_discount_percent', $input);
        $isCategoryHold = $booking->room_id === null && $booking->room_category_id !== null;

        if (($wantsDateEdit || $wantsCategoryEdit || $wantsDiscountEdit) && ! $isCategoryHold) {
            throw new RuntimeException(
                'This booking is already pinned to a specific room. Use "Change room" or "Extend" to shift dates.'
            );
        }

        $checkin = $wantsDateEdit && ($input['checkin_date'] ?? '') !== ''
            ? Carbon::parse($input['checkin_date'])->startOfDay()
            : $booking->checkin_date->copy()->startOfDay();
        $checkout = $wantsDateEdit && ($input['checkout_date'] ?? '') !== ''
            ? Carbon::parse($input['checkout_date'])->startOfDay()
            : $booking->checkout_date->copy()->startOfDay();
        if ($checkout->lessThanOrEqualTo($checkin)) {
            throw new BookingValidationException('Check-out must be after check-in.');
        }

        // Centurion category holds are always one room.
        $roomsCount = 1;

        $targetCategoryId = $wantsCategoryEdit
            ? (int) $input['room_category_id']
            : (int) ($booking->room_category_id ?? 0);

        // Resolve the manual discount from either shape (percent or
        // fixed). Caller passes manual_discount_type + a matching
        // percent / amount; we recompute amount from roomTotal below
        // because the roomTotal might shift (new category / dates).
        $currentType = $booking->manual_discount_type instanceof BackedEnum
            ? (string) $booking->manual_discount_type->value
            : (string) ($booking->manual_discount_type ?? 'percent');
        $discountType = (string) ($input['manual_discount_type'] ?? $currentType);
        if (! in_array($discountType, ['percent', 'fixed'], true)) {
            $discountType = 'percent';
        }
        $discountPercent = (float) ($input['manual_discount_percent'] ?? $booking->manual_discount_percent ?? 0);
        if ($discountPercent < 0) {
            $discountPercent = 0;
        }
        if ($discountPercent > 100) {
            $discountPercent = 100;
        }
        $discountFixedInput = (int) ($input['manual_discount_amount'] ?? $booking->manual_discount_amount ?? 0);
        if ($discountFixedInput < 0) {
            $discountFixedInput = 0;
        }

        return DB::transaction(function () use (
            $booking, $operator,
            $guestName, $phone, $email, $adults, $children, $specialRequests,
            $wantsDateEdit, $wantsCategoryEdit, $wantsDiscountEdit,
            $isCategoryHold, $checkin, $checkout, $roomsCount, $targetCategoryId,
            $discountType, $discountPercent, $discountFixedInput,
        ): Booking {
            $datesMoved = $wantsDateEdit && (
                ! $checkin->equalTo($booking->checkin_date->copy()->startOfDay())
                || ! $checkout->equalTo($booking->checkout_date->copy()->startOfDay())
            );
            $categoryMoved = $wantsCategoryEdit && $targetCategoryId !== (int) ($booking->room_category_id ?? 0);

            $moneyMoved = $datesMoved || $categoryMoved || $wantsDiscountEdit;

            if ($moneyMoved && $isCategoryHold) {
                $category = RoomCategory::query()->lockForUpdate()->findOrFail($targetCategoryId);
                if ($category->tenant_id !== $booking->tenant_id) {
                    throw new RuntimeException('Category belongs to a different property.');
                }

                if ($category->maxOccupancy() > 0 && ($adults + $children) > $category->maxOccupancy()) {
                    throw new BookingValidationException(sprintf(
                        'This category sleeps up to %d guests; you entered %d.',
                        $category->maxOccupancy(),
                        $adults + $children,
                    ));
                }

                // Dates or category changing invalidate the
                // existing category blocks — release them first so the
                // free-units count below doesn't count this booking
                // against itself.
                $needsReblock = $datesMoved || $categoryMoved;
                if ($needsReblock) {
                    AvailabilityBlock::query()
                        ->where('booking_id', $booking->id)
                        ->whereNull('room_id')
                        ->where('type', AvailabilityBlockType::Booked->value)
                        ->delete();

                    $free = $this->availability->categoryFreeUnits($category, $checkin, $checkout);
                    if ($free < $roomsCount) {
                        throw new RangeNotAvailableException(
                            $roomsCount === 1
                                ? 'This category is fully booked for the new dates.'
                                : sprintf('Only %d %s free on the new dates; you asked for %d.',
                                    $free,
                                    $free === 1 ? 'room is' : 'rooms are',
                                    $roomsCount,
                                ),
                        );
                    }

                    $this->availability->reserveCategoryRangeForBooking(
                        $category, $checkin, $checkout, $booking->id,
                    );
                }

                $nights = (int) $checkin->diffInDays($checkout, absolute: true);
                $roomTotal = $nights * (int) $category->base_rate * $roomsCount;

                // Preserve existing corporate discount; manual discount
                // reprices against the new roomTotal every time.
                $corporateOff = max(0, (int) ($booking->corporate_discount_naira ?? 0));
                $manualOff = $discountType === 'percent'
                    ? (int) floor(max(0, $roomTotal - $corporateOff) * $discountPercent / 100)
                    : min($discountFixedInput, max(0, $roomTotal - $corporateOff));
                $manualOff = max(0, $manualOff);

                $newTotal = max(0, $roomTotal - $corporateOff - $manualOff);
                $oldTotal = (int) $booking->total_amount;
                $delta = $newTotal - $oldTotal;

                // Only shift balance_due when the guest has actually
                // paid something. Pending bookings (balance_due = 0,
                // no deposit) read total_amount at check-in time.
                $isSettled = $booking->payment_status !== PaymentStatus::Pending;
                $newBalanceDue = $isSettled
                    ? max(0, (int) $booking->balance_due + $delta)
                    : (int) $booking->balance_due;

                $booking->room_category_id = $category->id;
                $booking->checkin_date = $checkin->toDateString();
                $booking->checkout_date = $checkout->toDateString();
                $booking->nights = $nights;
                $booking->room_total = $roomTotal;
                $booking->total_amount = $newTotal;
                $booking->balance_due = $newBalanceDue;
                $booking->manual_discount_type = $discountType;
                $booking->manual_discount_percent = $discountType === 'percent' ? $discountPercent : 0;
                $booking->manual_discount_amount = $manualOff;
                if ($manualOff > 0) {
                    $booking->manual_discount_by_operator_id = $operator->id;
                } elseif ((int) $booking->manual_discount_amount === 0) {
                    $booking->manual_discount_by_operator_id = null;
                }
            }

            $booking->guest_name = $guestName;
            $booking->guest_phone = $phone;
            $booking->guest_email = $email !== '' ? $email : null;
            $booking->adults = $adults;
            $booking->children = $children;
            $booking->special_requests = $specialRequests;
            $booking->save();

            // Keep the linked customer record (CRM) in sync for the
            // name / phone / email the front desk just corrected.
            if ($booking->customer_id !== null) {
                $customer = Customer::query()->find($booking->customer_id);
                if ($customer !== null) {
                    $customerDirty = false;
                    if ($customer->name !== $guestName) {
                        $customer->name = $guestName;
                        $customerDirty = true;
                    }
                    if ($phone !== '' && $customer->phone !== $phone) {
                        $customer->phone = $phone;
                        $customerDirty = true;
                    }
                    if ($email !== '' && $customer->email !== $email) {
                        $customer->email = $email;
                        $customerDirty = true;
                    }
                    if ($customerDirty) {
                        $customer->save();
                    }
                }
            }

            return $booking->refresh();
        });
    }
}
