<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Approvals;

use App\Actions\Bookings\ExtendBooking;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\MarkCheckedOut;
use App\Enums\BookingStatus;
use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\GuestPortalIntent;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\RoomCategory;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Front-desk queue for guest actions the geo-fence gate held back.
 *
 * A guest whose device coord scored below the property's minimum
 * threshold has their sensitive tap (extend / order / reserve /
 * checkout / request) written to `guest_action_approvals` with the
 * payload snapshot. The desk sees them here, calls the guest to
 * confirm intent, then Approves (runs the underlying action) or
 * Rejects (leaves the booking untouched, notes why).
 *
 * Branch-scoped via PropertyContext so a Lagos operator doesn't
 * approve an Abuja request.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    #[Url(as: 'status', except: 'pending')]
    public string $statusFilter = 'pending';

    public ?int $selectedId = null;
    public string $rejectNote = '';

    public function open(int $id): void
    {
        $this->selectedId = $id;
        $this->rejectNote = '';
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->rejectNote = '';
    }

    /**
     * Run the underlying booking action captured in the approval's
     * payload snapshot. Kind decides the branch:
     *   extend / extend_card → ExtendBooking (extend_card would need
     *                          a card charge, we execute the intent
     *                          creation the same way the portal does)
     *   checkout             → MarkCheckedOut
     *   order_room           → PosSale + CompletePosSale(tender=room)
     *   order_card           → InitiatePortalPayment (Paystack link)
     *   reserve              → same InitiatePortalPayment path
     *   request:<kind>       → GuestRequest row
     */
    public function approve(int $id): void
    {
        $approval = GuestActionApproval::query()->find($id);
        $operator = auth('web')->user();
        if ($approval === null || ! $approval->isPending() || ! $operator instanceof Operator) {
            return;
        }
        $booking = Booking::query()->find($approval->booking_id);
        if ($booking === null) {
            $this->markResolved($approval, $operator, 'rejected', 'Booking no longer exists.');
            return;
        }

        try {
            $this->executePayload($approval, $booking, $operator);
        } catch (\Throwable $e) {
            $this->dispatch('notify', title: "Couldn't approve", message: $e->getMessage(), kind: 'error');
            return;
        }

        $this->markResolved($approval, $operator, 'approved', null);

        // Kind-specific success copy so the operator knows what
        // actually happened. For no-payment check-in the guest is
        // NOT yet checked in — the front desk still has to do that
        // when the room clears housekeeping.
        $msg = match ($approval->kind) {
            'checkin_no_payment' => 'Booking is now Confirmed and cleared for check-in without payment. Front desk notified.',
            'checkout'           => 'Guest checked out.',
            'extend', 'extend_card' => 'Stay extended.',
            'order_room', 'order_card' => 'Order landed on the room folio.',
            'reserve'            => 'Reservation request queued for the desk.',
            default              => 'Guest action executed.',
        };
        $this->dispatch('notify', title: 'Approved', message: $msg, kind: 'ok');
        $this->selectedId = null;
    }

    public function reject(int $id): void
    {
        $approval = GuestActionApproval::query()->find($id);
        $operator = auth('web')->user();
        if ($approval === null || ! $approval->isPending() || ! $operator instanceof Operator) {
            return;
        }
        $this->markResolved($approval, $operator, 'rejected', trim($this->rejectNote) ?: null);
        $this->dispatch('notify', title: 'Rejected', message: "Guest's request was not carried out.", kind: 'ok');
        $this->selectedId = null;
        $this->rejectNote = '';
    }

    private function markResolved(GuestActionApproval $approval, Operator $operator, string $status, ?string $note): void
    {
        $approval->forceFill([
            'status' => $status,
            'approved_by_operator_id' => $operator->getKey(),
            'operator_note' => $note,
            'resolved_at' => Carbon::now(),
        ])->save();

        activity('guest_action_approval')
            ->causedBy($operator)
            ->performedOn($approval)
            ->withProperties(['kind' => $approval->kind, 'status' => $status])
            ->log("Approval {$approval->id} {$status}.");
    }

    private function executePayload(GuestActionApproval $approval, Booking $booking, Operator $operator): void
    {
        $payload = is_array($approval->payload) ? $approval->payload : [];
        $kind = $approval->kind;

        // Requests are just DB rows - no external side effects, we
        // recreate them exactly as the portal would have.
        if (str_starts_with($kind, 'request:')) {
            GuestRequest::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'room_id' => $booking->room_id,
                'kind' => (string) ($payload['kind'] ?? substr($kind, 8)),
                'status' => 'open',
                'notes' => $payload['notes'] ?? null,
                'body' => $payload['body'] ?? [],
            ]);
            return;
        }

        match ($kind) {
            'extend' => $this->executeExtend($booking, $operator, $payload),
            'extend_card' => $this->executeExtendCard($booking, $operator, $payload),
            'checkout' => app(MarkCheckedOut::class)->handle($booking, $operator),
            'order_room' => $this->executeOrderRoom($booking, $operator, $payload),
            'order_card' => $this->executeOrderCard($booking, $operator, $payload),
            'reserve' => $this->executeReserve($booking, $operator, $payload),
            'checkin_no_payment' => $this->executeCheckinNoPayment($booking, $operator),
            default => throw new \RuntimeException("Unknown approval kind: {$kind}"),
        };
    }

    /**
     * Manager approved check-in without payment. Behavior:
     *
     *   1. Flip the booking Pending → Confirmed with balance_due =
     *      total_amount so the folio shows what's owed.
     *   2. Drop a note in the front-desk queue so the shift knows the
     *      booking is cleared for no-payment check-in.
     *
     * Actual MarkCheckedIn is NOT called here — the room typically
     * hasn't cleared housekeeping yet, and forcing check-in through
     * a dirty inspection defeats the whole gate. Once housekeeping
     * signs off, the standard "Check in" button on the reservation
     * page runs against the now-Confirmed booking with no extra
     * approval needed. The approval row itself is the audit trail.
     */
    private function executeCheckinNoPayment(Booking $booking, Operator $operator): void
    {
        if ($booking->booking_status !== BookingStatus::Pending) {
            throw new \RuntimeException(
                "Booking is no longer Pending (state: {$booking->booking_status->value}). "
                ."If it's already Checked in, no action needed.",
            );
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $operator): void {
            $booking->forceFill([
                'booking_status' => BookingStatus::Confirmed,
                'balance_due' => (int) $booking->total_amount,
            ])->save();

            // Front-desk-visible note. Uses GuestRequest as the queue
            // surface since it's the shift's canonical "things to
            // work" list. `kind = custom` keeps it out of the SLA
            // urgency stack; the notes body carries the actionable
            // detail. Consumed by the operator when they hit
            // "Check in" against the (now-Confirmed) booking.
            GuestRequest::query()->create([
                'tenant_id'  => $booking->tenant_id,
                'booking_id' => $booking->id,
                'room_id'    => $booking->room_id,
                'kind'       => 'custom',
                'status'     => 'open',
                'notes'      => sprintf(
                    'Manager approved check-in without payment (₦%s on the folio). '
                    .'Proceed with normal check-in once the room clears housekeeping.',
                    number_format((int) $booking->total_amount),
                ),
                'body'       => [
                    'source'                   => 'checkin_no_payment_approval',
                    'approved_by_operator_id'  => $operator->getKey(),
                    'approved_by_operator_name' => $operator->name,
                    'balance_owed'             => (int) $booking->total_amount,
                ],
            ]);
        });
    }

    private function executeExtend(Booking $booking, Operator $operator, array $payload): void
    {
        $newCheckout = Carbon::parse((string) ($payload['new_checkout_date'] ?? ''));
        // The approval may have sat in the queue while another booking
        // took the range - check before we call ExtendBooking so the
        // desk gets a clean "room isn't free anymore" note instead of
        // the raw exception bubbling through.
        $max = $booking->maxExtendableCheckoutDate();
        if ($max !== null && $newCheckout->gt($max)) {
            throw new \RuntimeException(
                'Room is now reserved from '.$max->format('D, d M').' - extension no longer possible.',
            );
        }
        app(ExtendBooking::class)->handle(
            $booking, $newCheckout, $operator,
            breakfastIncluded: (bool) ($payload['breakfast_included'] ?? false),
        );
    }

    /**
     * The card-payment branch is a Paystack intent, not something the
     * operator can execute on the guest's behalf without their card.
     * Treat approval as "confirm the guest still wants to pay" - we
     * create the intent and stash the checkout URL on the approval row
     * so the desk can pass the link back to the guest.
     */
    private function executeExtendCard(Booking $booking, Operator $operator, array $payload): void
    {
        // Cheapest way to honour the guest's intent: run the extend
        // directly as if it had been ExtendBooking (wallet-first
        // through the operator; charges land on the folio and settle
        // at checkout). Matches the desk's usual manual flow.
        $newCheckout = Carbon::parse((string) ($payload['new_checkout_date'] ?? ''));
        app(ExtendBooking::class)->handle(
            $booking, $newCheckout, $operator,
            breakfastIncluded: (bool) ($payload['breakfast_included'] ?? false),
        );
    }

    private function executeOrderRoom(Booking $booking, Operator $operator, array $payload): void
    {
        $outlet = Outlet::query()->findOrFail((int) ($payload['outlet_id'] ?? 0));
        $cart = [];
        foreach ((array) ($payload['cart'] ?? []) as $productId => $qty) {
            if ((int) $qty > 0) $cart[] = ['product_id' => (int) $productId, 'quantity' => (int) $qty];
        }
        if ($cart === []) throw new \RuntimeException('Order cart was empty.');
        $sale = app(CreatePosSaleDraft::class)->handle(
            outlet: $outlet, operator: $operator, cart: $cart,
            bookingId: $booking->id, customerId: $booking->customer_id,
            notes: 'Room service (approved from queue)'.
                (! empty($payload['notes']) ? ' · '.$payload['notes'] : ''),
        );
        app(CompletePosSale::class)->handle(sale: $sale, operator: $operator, method: 'room');
    }

    private function executeOrderCard(Booking $booking, Operator $operator, array $payload): void
    {
        // Same reasoning as executeExtendCard: charging a card requires
        // the guest; approve here just moves the order onto the folio.
        $this->executeOrderRoom($booking, $operator, $payload);
    }

    private function executeReserve(Booking $booking, Operator $operator, array $payload): void
    {
        // Card-first flow - we can't charge the guest's card from the
        // desk, but we CAN raise a GuestRequest so the desk works the
        // new reservation manually with the guest on the phone.
        GuestRequest::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'room_id' => $booking->room_id,
            'kind' => 'other',
            'status' => 'open',
            'notes' => 'Reserve-another-room approval - call guest to take payment.',
            'body' => [
                'category_id' => $payload['category_id'] ?? null,
                'checkin' => $payload['checkin'] ?? null,
                'checkout' => $payload['checkout'] ?? null,
                'adults' => $payload['adults'] ?? 1,
                'children' => $payload['children'] ?? 0,
                'amount' => $payload['amount'] ?? null,
            ],
        ]);
    }

    public function render(): View
    {
        $propertyId = app(PropertyContext::class)->current()?->getKey();

        $items = GuestActionApproval::query()
            ->with([
                'booking:id,booking_ref,guest_name,guest_phone',
                'room:id,room_number,property_id',
                'approvedBy:id,name',
            ])
            ->where('status', $this->statusFilter)
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $selected = $this->selectedId
            ? $items->firstWhere('id', $this->selectedId)
            : null;

        $counts = GuestActionApproval::query()
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return view('livewire.dashboard.approvals.index', [
            'items' => $items,
            'selected' => $selected,
            'counts' => $counts,
        ]);
    }
}
