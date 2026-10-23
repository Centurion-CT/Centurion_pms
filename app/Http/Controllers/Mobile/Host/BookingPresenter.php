<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RoomInspectionStatus;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\GuestIdDocument;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RoomInspection;
use App\Models\Tenant;
use App\Services\Guests\GuestIdDocuments;
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
        $b->loadMissing(['room.category', 'room.property', 'roomCategory.property', 'customer.wallet', 'corporate']);
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
        $preArrival = in_array($status, [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnHold], true);
        $idDoc = app(GuestIdDocuments::class)->forBooking($b);

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
                'move_room' => $b->room_id !== null && ($preArrival || $status === BookingStatus::CheckedIn),
                'change_dates' => $preArrival,
                // Gives money away directly (else 403 needs_manager).
                'discount' => ($preArrival || $status === BookingStatus::CheckedIn)
                    && (int) $b->total_amount > 0 && HostController::canApproveDiscount($operator),
                'free_upgrade' => HostController::canApproveDiscount($operator),
            ],
            'payment_options' => [
                'methods' => PaymentMethod::takeOptions($walletBalance),
                'wallet_available' => $walletBalance,
            ],
            // ── Keverna Desk phase 2 (docs/desk-app/PHASE-2.md, 3) ──
            'history' => self::history($b),
            'guest' => self::guest($b, $walletBalance),
            'id_document' => $idDoc !== null ? GuestIdDocuments::present($idDoc, $b) : null,
            'keys' => [
                'given' => $b->keys_given !== null ? (int) $b->keys_given : null,
                'returned' => $b->keys_returned !== null ? (int) $b->keys_returned : null,
            ],
            'registration_card' => self::registrationCard($b, $operator, $idDoc, $paidTotal, $owed),
        ];
    }

    /**
     * Desk bookings list row: the BookingCard plus what the desk's
     * in-house / bookings tables show.
     *
     * @return array<string, mixed>
     */
    public static function listRow(Booking $b, bool $requestOpen = false): array
    {
        $card = self::card($b);
        $today = Carbon::today();
        $nightsLeft = $b->booking_status === BookingStatus::CheckedIn && $b->checkout_date !== null
            ? max(0, (int) $today->diffInDays($b->checkout_date->copy()->startOfDay(), absolute: false))
            : null;

        return $card + [
            'source' => $b->source?->value,
            'nights_left' => $nightsLeft,
            'flags' => [
                'owing' => (int) $card['balance_due'] > 0,
                'overdue' => (bool) $card['overdue'],
                'company' => $b->corporate_id !== null,
                'discount' => (int) $b->manual_discount_amount > 0,
                'request_open' => $requestOpen,
            ],
        ];
    }

    /**
     * Activity log of the booking, newest first, in desk wording.
     *
     * @return list<array{at: ?string, text: string, by: ?string, via: ?string}>
     */
    public static function history(Booking $b): array
    {
        $rows = Activity::query()
            ->where('subject_type', $b->getMorphClass())
            ->where('subject_id', $b->id)
            ->with('causer')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $out = [];
        foreach ($rows as $a) {
            $text = self::historyText($a);
            if ($text === null) {
                continue;
            }
            $props = $a->properties ?? collect();
            $causer = $a->causer;
            $out[] = [
                'at' => $a->created_at?->toIso8601String(),
                'text' => $text,
                'by' => $causer !== null && isset($causer->name) ? (string) $causer->name : null,
                'via' => $props->get('via'),
            ];
        }
        $out[] = [
            'at' => $b->created_at?->toIso8601String(),
            'text' => 'Booked'.($b->source !== null ? ' ('.$b->source->label().')' : ''),
            'by' => null,
            'via' => null,
        ];

        return $out;
    }

    private static function historyText(Activity $a): ?string
    {
        $p = $a->properties ?? collect();
        $money = fn (string $key): string => '₦'.number_format((int) $p->get($key, 0));

        return match ((string) $a->event) {
            'created', 'updated' => null,
            'checked_in' => 'Checked in',
            'checked_out' => 'Checked out',
            'confirmed' => 'Confirmed',
            'cancelled' => 'Cancelled',
            'no_show' => 'Marked no-show',
            'manual_payment_recorded' => 'Payment '.$money('amount').self::methodSuffix($p->get('method')),
            'part_payment_recorded' => 'Part payment '.$money('amount').self::methodSuffix($p->get('method')),
            'extended' => 'Stay extended (+'.$money('extension_charged').')',
            'extension_cancelled' => 'Extension cancelled',
            'room_reassigned' => 'Moved from room '.$p->get('old_room', '-').' to '.$p->get('new_room', '-')
                .((int) $p->get('rate_delta', 0) !== 0 ? ' ('.((int) $p->get('rate_delta') > 0 ? '+' : '-').'₦'.number_format(abs((int) $p->get('rate_delta'))).')' : ''),
            'room_upgrade_free' => 'Free upgrade, '.$money('rate_delta_waived').' not charged',
            'post_checkin_discount_applied' => 'Discount '.$money('amount').($p->get('reason') ? ': '.$p->get('reason') : ''),
            'dates_changed' => 'Dates changed to '.Carbon::parse((string) $p->get('new_checkin'))->format('j M').' - '.Carbon::parse((string) $p->get('new_checkout'))->format('j M'),
            'category_changed' => 'Category changed to '.$p->get('new_category', '-'),
            'id_document_recorded' => 'ID recorded ('.(GuestIdDocument::TYPES[(string) $p->get('type')] ?? 'ID').')',
            default => trim(str_replace([' — ', '—', '–'], [', ', ', ', '-'], (string) $a->description)) ?: null,
        };
    }

    private static function methodSuffix(mixed $method): string
    {
        return is_string($method) && $method !== '' ? ', '.PaymentMethod::labelFor($method) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function guest(Booking $b, int $walletBalance): array
    {
        $customer = $b->customer;
        $stays = $customer !== null ? (int) $customer->bookings()->count() : 0;
        $returning = $customer !== null && Booking::query()
            ->where('customer_id', $customer->id)
            ->where('id', '!=', $b->id)
            ->where('booking_status', BookingStatus::CheckedOut->value)
            ->exists();

        return [
            'customer_id' => $customer?->id !== null ? (int) $customer->id : null,
            'name' => (string) $b->guest_name,
            'phone' => $b->guest_phone ?: ($customer?->phone ?: null),
            'email' => $b->guest_email ?: ($customer?->email ?: null),
            'stays_count' => $stays,
            'returning' => $returning,
            'is_vip' => (bool) ($customer?->is_vip ?? false),
            'wallet_balance' => $walletBalance,
            'notes' => $customer?->notes,
        ];
    }

    /**
     * Everything the desk prints on the A4 registration card (rendered
     * by the desk itself; there is no web page for it). The ID number is
     * masked on paper.
     *
     * @return array<string, mixed>
     */
    private static function registrationCard(Booking $b, Operator $operator, ?GuestIdDocument $idDoc, int $paid, int $owed): array
    {
        $property = $b->room?->property ?? $b->roomCategory?->property;
        $checkedIn = Activity::query()
            ->where('subject_type', $b->getMorphClass())
            ->where('subject_id', $b->id)
            ->where('event', 'checked_in')
            ->with('causer')
            ->latest('id')
            ->first();
        $nights = max(1, (int) $b->nights);
        $address = $property !== null
            ? collect([$property->address, $property->city, $property->state])->filter(fn ($v) => trim((string) $v) !== '')->implode(', ')
            : '';
        $tenant = Tenant::current();

        return [
            'hotel' => [
                'name' => (string) ($property?->name ?? $tenant?->name ?? ''),
                'address' => $address !== '' ? $address : null,
                'phone' => $tenant?->phone ?: null,
                'email' => $tenant?->email ?: null,
            ],
            'booking_ref' => (string) $b->booking_ref,
            'guest' => [
                'name' => (string) $b->guest_name,
                'phone' => $b->guest_phone ?: null,
                'email' => $b->guest_email ?: null,
                'address' => $b->customer?->address,
                'nationality' => $idDoc !== null && ! $idDoc->isPurged() ? $idDoc->nationality : null,
                'id_type' => $idDoc?->typeLabel(),
                'id_number_masked' => $idDoc?->maskedNumber(),
            ],
            'company' => $b->corporate?->name,
            'room' => $b->room?->room_number,
            'category' => $b->room?->category?->name ?? $b->roomCategory?->name,
            'arrival' => $b->checkin_date?->toDateString(),
            'departure' => $b->checkout_date?->toDateString(),
            'nights' => (int) $b->nights,
            'adults' => (int) $b->adults,
            'children' => (int) $b->children,
            'rate_per_night' => intdiv(max(0, (int) $b->room_total), $nights),
            'total' => (int) $b->total_amount,
            'paid' => $paid,
            'balance' => $owed,
            'keys_given' => $b->keys_given !== null ? (int) $b->keys_given : null,
            'special_requests' => $b->special_requests,
            'checked_in_at' => $checkedIn?->created_at?->toIso8601String(),
            'checked_in_by' => $checkedIn?->causer?->name,
            'printed_by' => (string) $operator->name,
            'printed_at' => Carbon::now()->toIso8601String(),
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
