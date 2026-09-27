<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Pos;

use App\Actions\Pos\OpenOutletShift;
use App\Enums\BookingStatus;
use App\Enums\CreditLineStatus;
use App\Enums\OperatorRole;
use App\Livewire\Concerns\UsesPosKioskLayout;
use App\Models\Booking;
use App\Models\CreditLine;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\OutletShift;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The POS terminal, thin server-rendered scaffold around an Alpine.js
 * client-side app.
 *
 * Cart / product grid / payment sheet state live in the {@see resources/js/pos-cart.js}
 * Alpine store, because those interactions need to work offline (a
 * phone that lost Wi-Fi mid-shift can't `wire:click`). Sale completion
 * always funnels through /dashboard/pos/queue, which the offline queue
 * drains automatically on reconnect.
 *
 * This Livewire component keeps only the pieces that inherently need a
 * server: booking lookup for charge-to-room + the in-house guest picker.
 * Both are gated to online-only in the view.
 */
class Terminal extends Component
{
    use UsesPosKioskLayout;

    public int $outletId;

    /** Booking lookup, only used when a "Charge to room" tender is active. */
    public string $bookingRef = '';

    public ?int $bookingIdResolved = null;

    public string $bookingLookupError = '';

    /** Credit-line picker, a house-account tab covering one or more rooms. */
    public ?int $creditLineIdResolved = null;

    public string $creditLineNameResolved = '';

    public string $creditLineLookupError = '';

    /** Active shift for the signed-in operator on this outlet, if any. */
    public ?int $activeShiftId = null;

    public string $shiftError = '';

    public function mount(Outlet $outlet): void
    {
        $operator = Operator::query()->findOrFail(auth('web')->id());

        // Manager sees every outlet. PosStaff must be explicitly assigned.
        if ($operator->role !== OperatorRole::Manager) {
            $assigned = $operator->outlets()->where('outlets.id', $outlet->id)->exists();
            if (! $assigned) {
                throw new NotFoundHttpException;
            }
        }

        $this->outletId = $outlet->id;

        // Surface the operator's currently-open shift on this outlet.
        // The hero renders "Open shift" if none, or the running duration
        // + a "Close shift" link if one is active. Managers still see
        // whatever they've personally opened; someone else's open shift
        // shows up on /dashboard/pos/shifts, not here.
        $this->activeShiftId = OutletShift::query()
            ->where('outlet_id', $outlet->id)
            ->where('operator_id', $operator->id)
            ->whereNull('closed_at')
            ->latest('id')
            ->value('id');
    }

    public function openShift(OpenOutletShift $action): void
    {
        $operator = Operator::query()->findOrFail(auth('web')->id());
        $outlet = Outlet::query()->findOrFail($this->outletId);
        try {
            $shift = $action->handle($outlet, $operator);
            $this->activeShiftId = $shift->id;
            $this->shiftError = '';
            $this->dispatch('notify',
                title: 'Shift opened',
                message: 'Opening stock snapshotted. Ring the till.',
                kind: 'ok',
            );
        } catch (RuntimeException $e) {
            $this->shiftError = $e->getMessage();
            $this->dispatch('notify', title: "Can't open shift", message: $e->getMessage(), kind: 'err');
        }
    }

    /* ── Booking lookup (online-only, drives charge-to-room) ─────── */

    public function lookupBooking(): void
    {
        $this->bookingIdResolved = null;
        $this->bookingLookupError = '';
        $ref = trim($this->bookingRef);
        if ($ref === '') {
            $this->bookingLookupError = 'Enter a booking reference (e.g. KV-XXXXXX).';

            return;
        }
        $booking = Booking::query()->where('booking_ref', $ref)->first();
        if ($booking === null) {
            $this->bookingLookupError = 'No booking with that reference.';

            return;
        }
        if (! in_array($booking->booking_status->value, ['confirmed', 'checked_in', 'on_hold'], true)) {
            $this->bookingLookupError = "Booking {$ref} is {$booking->booking_status->value}, can't charge to room.";

            return;
        }
        $this->bookingIdResolved = $booking->id;
        $this->dispatchBookingResolved();
    }

    /**
     * One-tap select from the in-house guest list, cheaper than typing
     * a booking ref when the guest is already staying.
     */
    public function pickInHouseBooking(int $bookingId): void
    {
        $booking = Booking::query()
            ->whereIn('booking_status', $this->inHouseStatuses())
            ->find($bookingId);
        if ($booking === null) {
            $this->bookingIdResolved = null;
            $this->bookingLookupError = 'That booking is no longer in-house.';

            return;
        }
        $this->bookingIdResolved = $booking->id;
        $this->bookingRef = $booking->booking_ref;
        $this->bookingLookupError = '';
        $this->dispatchBookingResolved();
    }

    public function clearBookingSelection(): void
    {
        $this->bookingIdResolved = null;
        $this->bookingRef = '';
        $this->bookingLookupError = '';
        $this->dispatch('pos-booking-cleared');
    }

    /* ── Credit-line lookup (online-only, drives charge-to-room via a tab) ─── */

    /**
     * Pick a credit line for the current sale. Booking selection is
     * cleared, a sale can charge to a booking OR a credit line, not
     * both.
     */
    public function pickCreditLine(int $lineId): void
    {
        $line = CreditLine::query()->with('customer')->find($lineId);
        if ($line === null) {
            $this->creditLineLookupError = 'Credit line not found.';

            return;
        }
        if (! $line->status->acceptsCharges()) {
            $this->creditLineLookupError = sprintf(
                'Credit line "%s" is %s, new charges are not accepted.',
                $line->name,
                $line->status->label(),
            );

            return;
        }
        $this->creditLineIdResolved = $line->id;
        $this->creditLineNameResolved = $line->name;
        $this->creditLineLookupError = '';

        // Clear any prior booking selection so we don't confuse the
        // downstream complete-sale action.
        $this->bookingIdResolved = null;
        $this->bookingRef = '';

        $this->dispatch(
            'pos-credit-line-resolved',
            id: $this->creditLineIdResolved,
            name: $this->creditLineNameResolved,
        );
    }

    public function clearCreditLineSelection(): void
    {
        $this->creditLineIdResolved = null;
        $this->creditLineNameResolved = '';
        $this->creditLineLookupError = '';
        $this->dispatch('pos-credit-line-cleared');
    }

    private function dispatchBookingResolved(): void
    {
        // Bridge into the Alpine store so complete() has the resolved
        // booking on hand without another round-trip. Also send the
        // guest's wallet balance so the Wallet tender chip can enable
        // / disable itself without a server hop.
        $walletBalance = 0;
        if ($this->bookingIdResolved !== null) {
            $booking = Booking::query()->with('customer.wallet')->find($this->bookingIdResolved);
            $walletBalance = (int) ($booking?->customer?->walletBalance() ?? 0);
        }

        $this->dispatch(
            'pos-booking-resolved',
            ref: $this->bookingRef,
            id: $this->bookingIdResolved,
            walletBalance: $walletBalance,
        );
    }

    /** @return list<string> */
    private function inHouseStatuses(): array
    {
        return [
            BookingStatus::CheckedIn->value,
            BookingStatus::Confirmed->value,
            BookingStatus::OnHold->value,
        ];
    }

    /**
     * Phone-based Customer lookup for the payment sheet. Returns an
     * array the Alpine store consumes directly. Tenant-scoped via the
     * Customer model's BelongsToTenant trait; the phone match is
     * lax on formatting (digits-only comparison) so operators can key
     * "08012345678" or "+234 801 234 5678" and still hit the same row.
     *
     * @return array{found: bool, id?: int, name?: string, phone?: ?string}
     */
    public function lookupCustomerByPhone(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '' || strlen($digits) < 4) {
            return ['found' => false];
        }
        // Normalise: strip leading zero / country prefix variants and
        // match on the trailing 10 digits so "08012345678" and
        // "+2348012345678" resolve to the same person.
        $tail = substr($digits, -10);
        $customer = \App\Models\Customer::query()
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', ''), '(', '') LIKE ?", ['%'.$tail])
            ->orderByDesc('id')
            ->first();

        if ($customer === null) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'id' => (int) $customer->id,
            'name' => (string) $customer->name,
            'phone' => $customer->phone,
        ];
    }

    /**
     * Inline profile create from the payment sheet. Lets the cashier
     * attach a walk-in to the CRM without leaving the till. Duplicate
     * phone numbers are OK — CustomerLinker upstream reconciles them
     * at booking time; here we just create the row so the sale can tie.
     *
     * @return array{id: int, name: string}|array{error: string}
     */
    public function saveQuickCustomer(string $phone, string $name): array
    {
        $name = trim($name);
        $phone = trim($phone);
        if ($name === '') {
            return ['error' => 'Customer name is required.'];
        }
        $customer = \App\Models\Customer::query()->create([
            'tenant_id' => \App\Models\Tenant::current()?->id,
            'name' => $name,
            'phone' => $phone !== '' ? $phone : null,
            'lifecycle' => \App\Enums\CustomerLifecycle::Prospect,
        ]);

        return [
            'id' => (int) $customer->id,
            'name' => (string) $customer->name,
        ];
    }

    public function render(): View
    {
        $outlet = Outlet::query()->findOrFail($this->outletId);
        $products = $outlet->products()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'outlet_id', 'sku', 'barcode', 'name', 'price', 'track_stock', 'quantity_on_hand', 'updated_at']);

        // In-house roster only fetched server-side because it's only ever
        // shown when the booking lookup UI is open (which is online-only).
        // A CheckedIn guest is physically at the property, dates don't
        // gate their eligibility to charge-to-room (an overstay guest
        // still needs to be able to buy a drink at the bar). Pre-arrival
        // Confirmed / OnHold guests DO need the date-window filter, since
        // those bookings might sit months in the future.
        $today = Carbon::today();
        $inHouseBookings = Booking::query()
            ->with('customer.wallet')
            ->where(function ($q) use ($today): void {
                $q->where('booking_status', BookingStatus::CheckedIn->value)
                    ->orWhere(function ($qq) use ($today): void {
                        $qq->whereIn('booking_status', [BookingStatus::Confirmed->value, BookingStatus::OnHold->value])
                            ->where('checkin_date', '<=', $today)
                            ->where('checkout_date', '>=', $today);
                    });
            })
            ->orderBy('guest_name')
            ->limit(50)
            ->get(['id', 'booking_ref', 'guest_name', 'booking_status', 'checkin_date', 'checkout_date', 'customer_id']);

        // Active credit lines for the "charge to a tab" picker. Cheap
        //, a property has a handful at most. Pre-loading the room
        // set means the operator sees which rooms the tab covers
        // without a follow-up round-trip.
        $creditLines = CreditLine::query()
            ->where('status', CreditLineStatus::Active->value)
            ->with(['customer:id,name', 'rooms:id,room_number'])
            ->orderBy('name')
            ->limit(50)
            ->get();

        $view = view('livewire.dashboard.pos.terminal', [
            'outlet' => $outlet,
            'productsBoot' => $products->map(fn ($p) => [
                'id' => (int) $p->id,
                'outlet_id' => (int) $p->outlet_id,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'name' => $p->name,
                'price' => (int) $p->price,
                'track_stock' => (bool) $p->track_stock,
                'quantity_on_hand' => (int) $p->quantity_on_hand,
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])->values(),
            'inHouseBookings' => $inHouseBookings,
            'creditLines' => $creditLines,
        ]);

        return $this->applyKioskLayout($view);
    }
}
