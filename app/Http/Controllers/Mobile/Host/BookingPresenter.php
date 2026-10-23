<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RoomInspectionStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RoomInspection;
use App\Services\Payments\BookingPayments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * JSON shapes for the host app's booking endpoints
 * (docs/host-app/PHASE-2.md, section B): the `BookingCard` row and the
 * booking detail. Money is NGN integers.
 */
class BookingPresenter
{
    /**
     * Bookings of one branch: rooms of the branch, plus category-only
     * (unassigned) bookings against the branch's categories.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public static function scopeToBranch(Builder $query, Property $property): Builder
    {
        $pid = (int) $property->id;

        return $query->where(function (Builder $q) use ($pid): void {
            $q->whereHas('room', fn (Builder $r) => $r->where('property_id', $pid))
                ->orWhere(function (Builder $q2) use ($pid): void {
                    $q2->whereNull('room_id')
                        ->whereHas('roomCategory', fn (Builder $c) => $c->where('property_id', $pid));
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function card(Booking $b): array
    {
        $owed = BookingPayments::owed($b);
        $today = Carbon::today()->toDateString();

        return [
            'ref' => (string) $b->booking_ref,
            'guest_name' => (string) $b->guest_name,
            'guest_phone' => (string) $b->guest_phone,
            'room_number' => $b->room?->room_number,
            'category' => $b->room?->category?->name ?? $b->roomCategory?->name,
            'checkin' => $b->checkin_date?->toDateString(),
            'checkout' => $b->checkout_date?->toDateString(),
            'nights' => (int) $b->nights,
            'status' => $b->booking_status->value,
            'status_label' => $b->booking_status->label(),
            'balance_due' => $owed,
            'total_amount' => (int) $b->total_amount,
            'chip' => self::chip($b, $owed),
            'overdue' => $b->booking_status === BookingStatus::CheckedIn
                && $b->checkout_date !== null
                && $b->checkout_date->toDateString() < $today,
            'corporate' => $b->corporate_id !== null && $b->corporate !== null
                ? ['name' => (string) $b->corporate->name]
                : null,
            'needs_room' => $b->room_id === null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Booking $b, Operator $operator): array
    {
        $b->loadMissing(['room.category', 'roomCategory', 'customer.wallet', 'corporate']);
        $card = self::card($b);
        $owed = (int) $card['balance_due'];
        $customer = $b->customer;
        $walletBalance = (int) ($customer?->walletBalance() ?? 0);

        $charges = BookingCharge::query()->where('booking_id', $b->id)->orderBy('id')->get();
        $payments = Payment::query()->where('booking_id', $b->id)->orderBy('id')->get();
        $paidTotal = (int) $payments->where('status', PaymentStatus::Succeeded)->sum('amount');

        $status = $b->booking_status;
        $today = Carbon::today();
        $arrived = $b->checkin_date !== null && $b->checkin_date->copy()->startOfDay()->lte($today);
        $caps = HostController::capabilities($operator);
        $live = ! in_array($status, [BookingStatus::Cancelled, BookingStatus::NoShow], true);

        return $card + [
            'adults' => (int) $b->adults,
            'children' => (int) $b->children,
            'special_requests' => $b->special_requests,
            'source' => $b->source?->value,
            'source_label' => $b->source?->label(),
            'guest_email' => $b->guest_email !== '' ? $b->guest_email : null,
            'customer' => $customer !== null ? [
                'id' => (int) $customer->id,
                'name' => (string) $customer->name,
                'is_vip' => (bool) $customer->is_vip,
                'stays_count' => (int) $customer->bookings()->count(),
                'wallet_balance' => $walletBalance,
            ] : null,
            'room_ready' => self::roomReady($b),
            'charges' => $charges->map(fn (BookingCharge $c) => [
                'id' => (int) $c->id,
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                'description' => (string) $c->description,
                'amount' => (int) $c->amount,
                'settled' => (bool) $c->settled,
                'settled_method' => $c->settled_method,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values()->all(),
            'payments' => $payments->map(fn (Payment $p) => [
                'id' => (int) $p->id,
                'amount' => (int) $p->amount,
                'method' => $p->method,
                'method_label' => PaymentMethod::labelFor($p->method),
                'type' => $p->type?->value,
                'status' => $p->status?->value,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'reference_note' => $p->reference_note,
            ])->values()->all(),
            'paid_total' => $paidTotal,
            'outstanding' => $owed,
            'can' => [
                'check_in' => in_array($status, [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold], true)
                    && $b->room_id !== null && $arrived,
                'check_out' => $status === BookingStatus::CheckedIn,
                'take_payment' => $caps['take_payment'] && $live && $owed > 0,
                'add_charge' => $caps['take_payment']
                    && in_array($status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn], true),
                'extend' => $b->room_id !== null
                    && in_array($status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn], true),
                'no_show' => in_array($status, [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold], true)
                    && $arrived,
                'cancel' => $status->holdsInventory() && $status !== BookingStatus::CheckedIn,
            ],
            'payment_options' => [
                'methods' => PaymentMethod::takeOptions($walletBalance),
                'wallet_available' => $walletBalance,
            ],
        ];
    }

    /**
     * Payment chip (same wording as host/today arrivals):
     *   info "Company pays" · late "₦X overdue" · due "₦X due" · ok "Paid"
     *
     * @return array{label: string, tone: string}
     */
    public static function chip(Booking $b, int $owed): array
    {
        if ($b->corporate_id !== null) {
            return ['label' => 'Company pays', 'tone' => 'info'];
        }
        if ($owed > 0 && $b->balance_due_at !== null && $b->balance_due_at->isPast()) {
            return ['label' => '₦'.number_format($owed).' overdue', 'tone' => 'late'];
        }
        if ($owed > 0) {
            return ['label' => '₦'.number_format($owed).' due', 'tone' => 'due'];
        }

        return ['label' => 'Paid', 'tone' => 'ok'];
    }

    /**
     * Room readiness for check-in, from the same signals MarkCheckedIn
     * uses (latest inspection) plus the room's housekeeping status:
     *   out_of_order  inspection Failed (check-in blocked) or room out of order
     *   inspecting    housekeeping inspection open, awaiting the housekeeper
     *                 (check-in allowed, with a warning)
     *   dirty         room marked dirty
     *   ready         otherwise
     *
     * @return array{state: ?string, label: string}
     */
    public static function roomReady(Booking $b): array
    {
        $room = $b->room;
        if ($room === null) {
            return ['state' => null, 'label' => 'No room assigned yet'];
        }

        $latest = RoomInspection::query()->where('room_id', $room->id)->latest('id')->first();
        if ($latest?->status === RoomInspectionStatus::Failed) {
            return ['state' => 'out_of_order', 'label' => 'Flagged not fit for a guest'];
        }
        if ($room->housekeeping_status === HousekeepingStatus::OutOfOrder) {
            return ['state' => 'out_of_order', 'label' => 'Out of order'];
        }
        if ($latest !== null && ! $latest->status->unlocksCheckin()) {
            return ['state' => 'inspecting', 'label' => 'Housekeeping in progress'];
        }
        if ($room->housekeeping_status === HousekeepingStatus::Dirty) {
            return ['state' => 'dirty', 'label' => 'Needs cleaning'];
        }

        return ['state' => 'ready', 'label' => 'Ready'];
    }
}
