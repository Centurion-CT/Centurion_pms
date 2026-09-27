<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Bookings;

use App\Actions\Bookings\AssignRoomToBooking;
use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\ConfirmBooking;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\MarkCheckedOut;
use App\Actions\Bookings\PayBookingFromWallet;
use App\Actions\Bookings\ResendBookingConfirmation;
use App\Actions\Bookings\SettleChargesFromWallet;
use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Room;
use App\Services\Availability\AvailabilityService;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Operator booking detail (PRD §6.2.5 / §14). Holds the five state-changing
 * actions: Confirm, Cancel (with optional refund flag), CheckIn, CheckOut,
 * Resend confirmation email.
 *
 * Cross-tenant isolation: `Booking::query()->where('booking_ref', ...)`
 * goes through BelongsToTenant's global scope, a foreign tenant's
 * booking_ref returns null and we 404. No per-controller code required.
 */
class Show extends Component
{
    #[Locked]
    public int $bookingId;

    public bool $refundRequested = false;

    /**
     * Room picked from the "Assign a room" dropdown for a
     * category-level (unassigned) booking. Read by assignRoom()
     * on submit, cleared after each attempt.
     */
    public ?int $assignRoomId = null;

    public function mount(string $bookingRef): void
    {
        $booking = Booking::query()->where('booking_ref', $bookingRef)->first();
        if ($booking === null) {
            throw new NotFoundHttpException;
        }
        $this->bookingId = $booking->id;
    }

    public function confirm(ConfirmBooking $action): void
    {
        $this->run(fn (Booking $b) => $action->handle($b, $this->operator()));
    }

    public function cancel(CancelBooking $action): void
    {
        $this->run(function (Booking $b) use ($action) {
            $result = $action->handle($b, $this->operator(), $this->refundRequested);
            $this->refundRequested = false;
            $note = $result->depositForfeit ? ' Deposit forfeit per policy.' : '';

            return "Booking cancelled.{$note}";
        });
    }

    public function checkIn(MarkCheckedIn $action): void
    {
        try {
            $action->handle($this->loadBooking(), $this->operator());
            if ($action->pendingInspection !== null) {
                $this->dispatch('notify',
                    title: 'Checked in — room needs cleaning attention',
                    message: sprintf(
                        'Housekeeping is still on this room (waiting on %s). Follow up before the guest complains.',
                        $action->pendingInspection->status->waitingOn(),
                    ),
                    kind: 'warn',
                );
            } else {
                $this->dispatch('notify', title: 'Done', message: 'Done.', kind: 'ok');
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    /**
     * Pin a specific Room to a category-level (unassigned) booking.
     * Front desk uses this before check-in - MarkCheckedIn refuses
     * to run while room_id is NULL. AssignRoomToBooking handles the
     * category + tenant + free-nights guards; a friendly notify
     * fires whether the assign succeeds or trips the guard.
     */
    public function assignRoom(AssignRoomToBooking $action): void
    {
        if ($this->assignRoomId === null) {
            $this->dispatch('notify', title: 'Pick a room first', message: 'Choose a room from the list.', kind: 'err');

            return;
        }

        $room = Room::query()->find($this->assignRoomId);
        if ($room === null) {
            $this->dispatch('notify', title: 'Room not found', message: 'That room is no longer available.', kind: 'err');
            $this->assignRoomId = null;

            return;
        }

        try {
            $action->handle($this->loadBooking(), $room, $this->operator());
            $this->assignRoomId = null;
            $this->dispatch('notify', title: 'Room assigned', message: "Room {$room->room_number} assigned. You can check the guest in now.", kind: 'ok');
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t assign room', message: $e->getMessage(), kind: 'err');
        }
    }

    public function checkOut(MarkCheckedOut $action): void
    {
        $this->run(fn (Booking $b) => $action->handle($b, $this->operator()));
    }

    public function resendConfirmation(ResendBookingConfirmation $action): void
    {
        $this->run(function (Booking $b) use ($action): string {
            $action->handle($b, $this->operator());

            return 'Confirmation email resent.';
        });
    }

    public function payOutstandingFromWallet(PayBookingFromWallet $action): void
    {
        $this->run(function (Booking $b) use ($action): string {
            $payment = $action->handle($b, $this->operator());
            if ($payment === null) {
                return 'Nothing to collect, booking is already settled.';
            }

            return 'Paid ₦'.number_format((int) $payment->amount).' from wallet.';
        });
    }

    public function sweepChargesFromWallet(SettleChargesFromWallet $action): void
    {
        $this->run(function (Booking $b) use ($action): string {
            $count = $action->handle($b, $this->operator());

            return $count === 0
                ? 'Wallet is empty or no charges to settle.'
                : "Settled {$count} charge(s) from wallet.";
        });
    }

    public function render(): View
    {
        $booking = $this->loadBooking();
        $audit = Activity::query()
            ->where('subject_type', $booking->getMorphClass())
            ->where('subject_id', $booking->id)
            ->latest('id')
            ->limit(20)
            ->get();

        // Category-level (unassigned) booking - surface the list of
        // Active rooms in the booking's category that are actually
        // free for its full date range so the front desk doesn't
        // have to eyeball the calendar. Empty list → nothing free,
        // dates need to shift or category needs a new unit.
        $assignableRooms = collect();
        if ($booking->isUnassigned()) {
            $availability = app(AvailabilityService::class);
            $assignableRooms = Room::query()
                ->where('room_category_id', $booking->room_category_id)
                ->where('status', RoomStatus::Active->value)
                ->orderBy('room_number')
                ->get()
                ->filter(fn (Room $r) => $availability->isAvailable(
                    $r, $booking->checkin_date, $booking->checkout_date,
                ))->values();
        }

        return view('livewire.dashboard.bookings.show', [
            'booking' => $booking,
            'audit' => $audit,
            'canConfirm' => $this->canConfirm($booking),
            // Cancel is a pre-arrival action, once the guest is
            // CheckedIn the exit is Check out, not Cancel. holdsInventory
            // includes CheckedIn (rightly so for AvailabilityBlock rules)
            // so we can't reuse it here.
            'canCancel' => $booking->booking_status->holdsInventory()
                && $booking->booking_status !== BookingStatus::CheckedIn,
            'canCheckIn' => $this->canCheckIn($booking),
            'canCheckOut' => $this->canCheckOut($booking),
            'walletBalance' => $booking->customer?->walletBalance() ?? 0,
            'assignableRooms' => $assignableRooms,
        ])->layout('layouts.tenant-auth');
    }

    private function loadBooking(): Booking
    {
        $booking = Booking::query()
            ->with('corporate', 'corporateMember', 'invoices')
            ->find($this->bookingId);
        if ($booking === null) {
            throw new NotFoundHttpException;
        }

        return $booking;
    }

    private function operator(): Operator
    {
        /** @var Operator $op */
        $op = auth()->guard('web')->user();

        return $op;
    }

    /**
     * @param  callable(Booking): (string|mixed)  $callable
     */
    private function run(callable $callable): void
    {
        try {
            $result = $callable($this->loadBooking());
            $this->dispatch('notify', title: 'Done', message: is_string($result) ? $result : 'Done.', kind: 'ok');
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    /**
     * "Confirm booking" flips a pre-arrival booking to Confirmed.
     * Only meaningful for Pending / OnHold rows, anything further
     * along the lifecycle (already Confirmed, or advanced past it
     * via CheckedIn / CheckedOut / NoShow / Cancelled) has nothing
     * left to confirm.
     */
    private function canConfirm(Booking $booking): bool
    {
        return in_array($booking->booking_status, [
            BookingStatus::Pending, BookingStatus::OnHold,
        ], true);
    }

    /**
     * Check-in is only available on the day of arrival. Future
     * bookings would create phantom in-house nights + break occupancy
     * math; past dates would silently retro-date the check-in and hide
     * the fact that the guest never really arrived on their booked
     * night. Late arrivals must be re-slotted (Extend to today's date)
     * or marked NoShow, the operator makes that call explicitly.
     */
    private function canCheckIn(Booking $booking): bool
    {
        if (! in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold], true)) {
            return false;
        }

        return $booking->checkin_date !== null
            && $booking->checkin_date->toDateString() === \Illuminate\Support\Carbon::today()->toDateString();
    }

    /**
     * Check-out is only surfaced once a guest is physically CheckedIn.
     * A Confirmed / OnHold guest who never arrived should be marked
     * NoShow (or Cancelled), not "checked out". Same date guard as
     * check-in, a booking whose check-in date is still in the future
     * can't have anyone to check out.
     */
    private function canCheckOut(Booking $booking): bool
    {
        if ($booking->booking_status !== BookingStatus::CheckedIn) {
            return false;
        }

        return $booking->checkin_date !== null
            && $booking->checkin_date->lessThanOrEqualTo(\Illuminate\Support\Carbon::today());
    }
}
