<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Bookings;

use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\CreateCategoryReservation;
use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\PayBookingFromWallet;
use App\Actions\Bookings\RecordManualPayment;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Livewire\Concerns\HasCustomerLookup;
use App\Livewire\Concerns\HasDiscountLookup;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Pricing\PricingCalculator;
use Throwable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Walk-in / phone booking form (PRD §14). Same engine as the public guest
 * form but no Paystack handshake, the operator picks the final
 * payment_status manually (typical: "Succeeded" for a settled cash booking,
 * "Pending" for a guest-promised-to-pay scenario).
 *
 * Source is stamped as Manual so dashboard reports can split organic vs
 * walk-in volume.
 */
class ManualCreate extends Component
{
    use HasCustomerLookup;
    use HasDiscountLookup;

    // roomId is validated via rules() at submit-time so we can flip it
    // to nullable when the operator chooses the category-only path.
    public ?int $roomId = null;

    // Category-level reservation mode. When true, the operator holds
    // inventory against a room category without pinning a specific
    // room; the front desk assigns a room from that category at
    // check-in via AssignRoomToBooking. Skips discount + package +
    // deposit + wallet plumbing (all assume a concrete Room) - lands
    // the booking as Pending + Pending, operator settles payment
    // from the booking-show page.
    public bool $categoryOnly = false;

    public ?int $categoryId = null;

    #[Validate('required|string|max:120')]
    public string $guest_name = '';

    #[Validate('required|email|max:180')]
    public string $guest_email = '';

    #[Validate('required|string|max:32|regex:/^\+?[0-9\s\-()]{8,}$/')]
    public string $guest_phone = '';

    #[Validate('required|integer|min:1')]
    public int $adults = 1;

    #[Validate('required|integer|min:0')]
    public int $children = 0;

    #[Validate('required|date|after_or_equal:today')]
    public string $checkin = '';

    #[Validate('required|date|after:checkin')]
    public string $checkout = '';

    #[Validate('nullable|string|max:1000')]
    public string $special_requests = '';

    /**
     * How the operator's cash-settlement choice maps to booking / payment
     * status:
     *   succeeded → Confirmed + Succeeded, record a manual Payment row
     *               for the full total.
     *   on_hold   → OnHold + Succeeded, record a manual Payment row for
     *               the operator-supplied deposit; balance_due carries
     *               the remainder. Auto-upgrades to Confirmed when the
     *               deposit covers or exceeds the total (surplus →
     *               guest wallet via RecordManualPayment).
     *   wallet    → spend the picked customer's wallet via PayBookingFromWallet;
     *               flips Confirmed if wallet covers, OnHold otherwise.
     *   pending   → Pending + Pending, no receipt recorded.
     */
    #[Validate('required|in:pending,succeeded,on_hold,wallet')]
    // Default to 'pending' so a fresh reservation with no payment
    // touch DOES NOT auto-record a phantom cash payment (see prod
    // bug where "Paid in full" was pre-selected + operators didn't
    // realise, then check-in showed Outstanding=₦0 without a real
    // payment ever changing hands). Operator must actively choose
    // "Paid in full" to record settlement.
    public string $payment_settlement = 'pending';

    /**
     * How the guest paid, cash / transfer / card / POS / other.
     * Persists on the Payment row for reconciliation. Required only
     * when the settlement path records a Payment (succeeded / on_hold).
     */
    #[Validate('required|in:cash,transfer,card,pos,other')]
    public string $payment_method = 'cash';

    /**
     * Amount (₦) the operator collected up-front. Only meaningful for
     * on_hold, succeeded uses the full total, pending records nothing,
     * wallet uses the customer's balance. Nullable so the field can
     * start empty and force the operator to type explicitly.
     */
    public ?int $payment_amount = null;

    // Channel the booking came in on (walk-in, Booking.com, WhatsApp, …).
    #[Validate('required|string')]
    public string $source = 'walk_in';

    #[Validate('nullable|string|max:120')]
    public string $source_note = '';

    // "With complimentary breakfast" tier, offered when the picked
    // room's category has breakfast_included_rate set. Ignored otherwise.
    #[Validate('boolean')]
    public bool $breakfast_included = false;

    // ── Post-create "Check in now?" prompt state ────────────────────
    // Same shape HasQuickReserveForm carries. When the reservation
    // is for today AND the resulting booking status can transition
    // to CheckedIn (Confirmed / OnHold), we stay on the page and
    // open the checkin-prompt partial instead of redirecting.
    public ?string $postCreateBookingRef = null;

    public string $postCreateGuestName = '';

    public bool $postCreateShowCheckInPrompt = false;

    /**
     * Which shape the ad-hoc discount was entered in — 'percent' or
     * 'fixed'. Both denormalise to a ₦-off on the booking row so
     * reports don't need to branch on this field.
     */
    #[Validate('in:percent,fixed')]
    public string $manual_discount_type = 'percent';

    /**
     * Percent entry (0-100, fractional allowed like 7.5). Only read
     * when $manual_discount_type === 'percent'.
     */
    #[Validate('numeric|min:0|max:100')]
    public float $manual_discount_percent = 0.0;

    /**
     * Flat ₦ off entry. Only read when $manual_discount_type ===
     * 'fixed'. Server caps at the post-auto-discount total.
     */
    #[Validate('integer|min:0')]
    public int $manual_discount_fixed_input = 0;

    /**
     * Livewire pulls #[Validate] annotations first; anything set in
     * rules() overrides those keys. We use this to (a) flip roomId
     * to nullable when categoryOnly is on and (b) require categoryId
     * only in that mode. The rest of the annotated rules stay put.
     */
    public function rules(): array
    {
        if ($this->categoryOnly) {
            return [
                'roomId'     => 'nullable',
                'categoryId' => 'required|integer|exists:room_categories,id',
            ];
        }

        return [
            'roomId'     => 'required|integer|exists:rooms,id',
            'categoryId' => 'nullable',
        ];
    }

    /**
     * Reset the category-only-relevant field when the operator flips
     * the toggle off, so a stale category pick doesn't ride into a
     * specific-room submission.
     */
    public function updatedCategoryOnly(): void
    {
        if ($this->categoryOnly) {
            // Category-only path skips discount + breakfast + wallet
            // (all assume a concrete Room). Payment settlement + method
            // + amount stay put so the guest can still pay a deposit
            // or the full stay at reservation time.
            $this->roomId = null;
            $this->breakfast_included = false;
            $this->manual_discount_percent = 0.0;
            $this->manual_discount_fixed_input = 0;
            if ($this->payment_settlement === 'wallet') {
                // Wallet path needs a specific-room pricing engine —
                // drop the operator down to Pending (safe default,
                // no phantom payment recorded) so they explicitly
                // pick another settlement track.
                $this->payment_settlement = 'pending';
            }
        } else {
            $this->categoryId = null;
        }
    }

    public function updatedManualDiscountPercent(): void
    {
        $this->manual_discount_percent = max(0.0, min(100.0, (float) $this->manual_discount_percent));
    }

    public function updatedManualDiscountFixedInput(): void
    {
        $this->manual_discount_fixed_input = max(0, (int) $this->manual_discount_fixed_input);
    }

    public function updatedManualDiscountType(): void
    {
        // Reset the OTHER mode's value so a stale entry doesn't
        // silently apply after the operator flips modes.
        if ($this->manual_discount_type === 'percent') {
            $this->manual_discount_fixed_input = 0;
        } else {
            $this->manual_discount_percent = 0.0;
        }
    }

    public function mount(): void
    {
        $this->checkin = Carbon::tomorrow()->toDateString();
        $this->checkout = Carbon::tomorrow()->addDays(2)->toDateString();
    }

    /**
     * Auto-nudge checkout to (checkin + 1 night) when the operator
     * picks a new checkin date and checkout is either empty or would
     * now be at/before the new checkin. Operator can still edit
     * checkout freely afterwards, the auto-fill only fires when the
     * current value would otherwise produce an invalid stay range.
     */
    public function updatedCheckin(): void
    {
        if ($this->checkin === '') {
            return;
        }
        try {
            $checkin = Carbon::parse($this->checkin);
        } catch (Throwable) {
            return;
        }

        $needsAutoFill = $this->checkout === '';
        if (! $needsAutoFill) {
            try {
                $needsAutoFill = Carbon::parse($this->checkout)->lessThanOrEqualTo($checkin);
            } catch (Throwable) {
                $needsAutoFill = true;
            }
        }
        if ($needsAutoFill) {
            $this->checkout = $checkin->copy()->addDay()->toDateString();
        }
    }

    public function save(
        CreateBooking $create,
        RecordManualPayment $recordPayment,
        PayBookingFromWallet $walletPay,
        CreateCategoryReservation $createCategory,
    ): void {
        $this->validate();

        // Category-only path - the reservation lands unassigned +
        // Pending. Skips discount / packages / deposit / wallet
        // entirely; those all assume a concrete Room and rerun
        // when the front desk pins one at check-in. Operator can
        // still settle payment later from the booking-show page.
        if ($this->categoryOnly) {
            $this->saveCategoryOnly($createCategory);

            return;
        }

        // Deposit path needs an explicit amount at or above the room
        // category's minimum. Compute the floor from the same pricing
        // engine CreateBooking will land on so operator + engine agree.
        if ($this->payment_settlement === 'on_hold') {
            if ($this->payment_amount === null || $this->payment_amount < 1) {
                throw ValidationException::withMessages([
                    'payment_amount' => 'Enter how much the guest handed over as a deposit.',
                ]);
            }
            $preview = $this->pricingPreview();
            if ($preview !== null) {
                $minDeposit = (int) $preview['breakdown']->depositAmount;
                if ($minDeposit > 0 && $this->payment_amount < $minDeposit) {
                    throw ValidationException::withMessages([
                        'payment_amount' => 'Deposit must be at least ₦'.number_format($minDeposit).' for this room category.',
                    ]);
                }
            }
        }

        // Wallet payment prerequisites, matched customer with balance.
        if ($this->payment_settlement === 'wallet') {
            if ($this->selectedCustomerId === null) {
                throw ValidationException::withMessages([
                    'payment_settlement' => 'Pay from wallet needs an existing customer, pick one from the search.',
                ]);
            }
            if ($this->pickedCustomerWalletBalance <= 0) {
                throw ValidationException::withMessages([
                    'payment_settlement' => 'That customer has no wallet balance to spend.',
                ]);
            }
        }

        $room = Room::query()->find($this->roomId);
        if ($room === null) {
            throw ValidationException::withMessages(['roomId' => 'Room not found.']);
        }

        $source = BookingSource::tryFrom($this->source) ?? BookingSource::WalkIn;
        $note = trim($this->source_note);

        $discountArgs = $this->discountSubmissionArgs();

        $req = new CreateBookingRequest(
            guestName: $this->guest_name,
            guestEmail: $this->guest_email,
            guestPhone: $this->guest_phone,
            adults: $this->adults,
            children: $this->children,
            checkin: Carbon::parse($this->checkin),
            checkout: Carbon::parse($this->checkout),
            paymentType: $this->payment_settlement === 'on_hold'
                ? PaymentType::Deposit
                : PaymentType::Full,
            specialRequests: $this->special_requests !== '' ? $this->special_requests : null,
            source: $source,
            sourceNote: $note !== '' ? $note : null,
            discountCode: $discountArgs['discountCode'],
            autoApplyDiscounts: $discountArgs['autoApplyDiscounts'],
            breakfastIncluded: $this->breakfast_included,
            manualDiscountType: $this->manual_discount_type === 'fixed'
                ? \App\Enums\ManualDiscountType::Fixed
                : \App\Enums\ManualDiscountType::Percent,
            manualDiscountPercent: (float) $this->manual_discount_percent,
            manualDiscountAmount: (int) $this->manual_discount_fixed_input,
            manualDiscountByOperatorId: auth('web')->id(),
        );

        try {
            $booking = $create->handle($room, $req);
        } catch (BookingValidationException $e) {
            throw ValidationException::withMessages(['checkin' => $e->getMessage()]);
        } catch (RangeNotAvailableException) {
            throw ValidationException::withMessages([
                'checkin' => __('Those dates are not available for this room.'),
            ]);
        }

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        // Wallet path, CreateBooking with paymentType=Full sets
        // balance_due=0 (assumes settled). Reset to full total so
        // PayBookingFromWallet sees an outstanding to clear, then flip
        // status based on how much cleared.
        if ($this->payment_settlement === 'wallet') {
            $booking->forceFill([
                'balance_due'    => (int) $booking->total_amount,
                'payment_status' => PaymentStatus::Pending,
            ])->save();

            try {
                $walletPay->handle($booking->refresh(), $operator);
            } catch (Throwable $e) {
                throw ValidationException::withMessages([
                    'payment_settlement' => 'Wallet charge failed: '.$e->getMessage(),
                ]);
            }
            $booking->refresh();
            $fullyPaid = $booking->outstandingTotal() <= 0;
            $booking->forceFill([
                'booking_status' => $fullyPaid ? BookingStatus::Confirmed : BookingStatus::OnHold,
            ])->save();

            $this->finaliseManualCreateOutcome($booking);

            return;
        }

        // Deposit path, override CreateBooking's preset deposit / balance
        // (it uses a % from PricingCalculator) with what the operator
        // actually collected. Auto-upgrade to Confirmed when the deposit
        // covers or exceeds the total; surplus rides through to the
        // guest's wallet via RecordManualPayment.
        if ($this->payment_settlement === 'on_hold') {
            $total = (int) $booking->total_amount;
            $collected = (int) $this->payment_amount;
            $covers = $collected >= $total;
            $depositBooked = min($collected, $total);

            $booking->forceFill([
                'deposit_amount' => $depositBooked,
                'balance_due'    => max(0, $total - $depositBooked),
                'booking_status' => $covers ? BookingStatus::Confirmed : BookingStatus::OnHold,
                'payment_status' => PaymentStatus::Succeeded,
            ])->save();

            $recordPayment->handle(
                $booking->refresh(),
                $operator,
                amount: $collected,
                method: $this->payment_method,
            );

            $this->finaliseManualCreateOutcome($booking);

            return;
        }

        // Manual cash / pending path.
        $isPaid = $this->payment_settlement === 'succeeded';
        $booking->forceFill([
            'booking_status' => $isPaid ? BookingStatus::Confirmed : BookingStatus::Pending,
            'payment_status' => $isPaid ? PaymentStatus::Succeeded : PaymentStatus::Pending,
        ])->save();

        if ($isPaid) {
            $recordPayment->handle(
                $booking->refresh(),
                $operator,
                amount: (int) $booking->total_amount,
                method: $this->payment_method,
            );
        }

        $this->finaliseManualCreateOutcome($booking);
    }

    /**
     * Category-only submission - hold inventory against the picked
     * category, guest arrives + front desk picks a specific room
     * via AssignRoomToBooking before check-in. Discount + breakfast
     * + wallet still skipped (they assume a concrete Room), but the
     * payment settlement runs so the guest can pay a deposit or the
     * full stay at reservation time.
     */
    private function saveCategoryOnly(CreateCategoryReservation $create): void
    {
        $category = RoomCategory::query()->find($this->categoryId);
        if ($category === null) {
            throw ValidationException::withMessages(['categoryId' => 'Category not found.']);
        }

        $source = BookingSource::tryFrom($this->source) ?? BookingSource::WalkIn;
        $note = trim($this->source_note);

        try {
            $booking = $create->handle(
                category: $category,
                checkin: Carbon::parse($this->checkin),
                checkout: Carbon::parse($this->checkout),
                guestName: $this->guest_name,
                guestEmail: $this->guest_email,
                guestPhone: $this->guest_phone,
                adults: $this->adults,
                children: $this->children,
                specialRequests: $this->special_requests !== '' ? $this->special_requests : null,
                source: $source,
                sourceNote: $note !== '' ? $note : null,
            );
        } catch (BookingValidationException $e) {
            throw ValidationException::withMessages(['categoryId' => $e->getMessage()]);
        } catch (RangeNotAvailableException $e) {
            throw ValidationException::withMessages(['checkin' => $e->getMessage()]);
        }

        // Deposit path guard, mirrors the specific-room save().
        if ($this->payment_settlement === 'on_hold') {
            if ($this->payment_amount === null || $this->payment_amount < 1) {
                throw ValidationException::withMessages([
                    'payment_amount' => 'Enter how much the guest handed over as a deposit.',
                ]);
            }
        }

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;
        $total = (int) $booking->total_amount;

        // Apply payment settlement so the guest can pay at reservation.
        //   succeeded → Confirmed + Succeeded + record full-total payment
        //   on_hold   → OnHold (or Confirmed if deposit covers total) +
        //               Succeeded + record deposit payment
        //   pending   → Pending + Pending (no payment), status untouched
        //   wallet    → not offered on this path (see updatedCategoryOnly)
        if ($this->payment_settlement === 'on_hold' && $total > 0) {
            $collected = (int) $this->payment_amount;
            $covers = $collected >= $total;
            $depositBooked = min($collected, $total);

            $booking->forceFill([
                'deposit_amount' => $depositBooked,
                'balance_due'    => max(0, $total - $depositBooked),
                'booking_status' => $covers ? BookingStatus::Confirmed : BookingStatus::OnHold,
                'payment_status' => PaymentStatus::Succeeded,
            ])->save();

            app(RecordManualPayment::class)->handle(
                $booking->refresh(),
                $operator,
                amount: $collected,
                method: $this->payment_method,
            );
        } elseif ($this->payment_settlement === 'succeeded' && $total > 0) {
            // Bump balance_due to total so RecordManualPayment sees
            // an outstanding to cap the Payment row against; zero it
            // out afterwards.
            $booking->forceFill([
                'balance_due'    => $total,
                'booking_status' => BookingStatus::Confirmed,
                'payment_status' => PaymentStatus::Succeeded,
            ])->save();

            app(RecordManualPayment::class)->handle(
                $booking->refresh(),
                $operator,
                amount: $total,
                method: $this->payment_method,
            );

            $booking->refresh()->forceFill(['balance_due' => 0])->save();
        }

        // No CheckIn prompt - the booking is still unassigned, so
        // MarkCheckedIn would refuse it. Front desk must assign a
        // room from the booking-show page first.
        $this->redirect(route('tenant.bookings.show', $booking->booking_ref), navigate: true);
    }

    /**
     * Post-create branch: same shape as HasQuickReserveForm — if the
     * checkin is for today AND the booking can transition to
     * CheckedIn (Confirmed / OnHold), stay on the page and let the
     * checkin-prompt modal render. Otherwise redirect straight to
     * the booking-show as before.
     */
    private function finaliseManualCreateOutcome(Booking $booking): void
    {
        $canPromptCheckIn = $booking->checkin_date->isSameDay(Carbon::today())
            && in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold], true);

        if ($canPromptCheckIn) {
            $this->postCreateBookingRef = $booking->booking_ref;
            $this->postCreateGuestName = $booking->guest_name;
            $this->postCreateShowCheckInPrompt = true;
            $this->dispatch('checkin-prompt-open');

            return;
        }

        $this->redirect(route('tenant.bookings.show', $booking->booking_ref), navigate: true);
    }

    public function confirmPostCreateCheckIn(MarkCheckedIn $action): void
    {
        if ($this->postCreateBookingRef === null) {
            return;
        }

        $booking = Booking::query()->where('booking_ref', $this->postCreateBookingRef)->first();
        if ($booking === null) {
            $this->skipPostCreateCheckIn();

            return;
        }

        $op = auth('web')->user();
        if ($op instanceof Operator) {
            try {
                $action->handle($booking, $op);
                if ($action->pendingInspection !== null) {
                    session()->flash('status', sprintf(
                        'Checked in %s — room needs cleaning attention (waiting on %s).',
                        $booking->guest_name,
                        $action->pendingInspection->status->waitingOn(),
                    ));
                } else {
                    session()->flash('status', "Checked in {$booking->guest_name}.");
                }
            } catch (Throwable $e) {
                session()->flash('status', 'Check-in failed: '.$e->getMessage());
            }
        }

        $ref = $this->postCreateBookingRef;
        $this->postCreateBookingRef = null;
        $this->postCreateGuestName = '';
        $this->postCreateShowCheckInPrompt = false;
        $this->redirect(route('tenant.bookings.show', $ref), navigate: true);
    }

    public function skipPostCreateCheckIn(): void
    {
        $ref = $this->postCreateBookingRef;
        if ($ref === null) {
            return;
        }
        $this->postCreateBookingRef = null;
        $this->postCreateGuestName = '';
        $this->postCreateShowCheckInPrompt = false;
        $this->redirect(route('tenant.bookings.show', $ref), navigate: true);
    }

    public function render(): View
    {
        $this->refreshDiscount();

        $rooms = Room::query()->active()->with('category')->orderByDisplayName()->get();
        $pickedRoom = $this->roomId !== null ? $rooms->firstWhere('id', $this->roomId) : null;
        // If the operator switches to a category without breakfast, snap
        // the toggle off so we don't submit a stale opt-in.
        if ($pickedRoom !== null && ! $pickedRoom->category?->hasBreakfastOption()) {
            $this->breakfast_included = false;
        }

        // Category-only mode surfaces a category picker + a live
        // "N units free for those dates" hint so the operator
        // knows if the hold is going to land before submitting.
        $categories = RoomCategory::query()->with('property')->orderBy('name')->get();
        $pickedCategory = $this->categoryId !== null
            ? $categories->firstWhere('id', $this->categoryId) : null;

        $categoryFree = null;
        if ($this->categoryOnly && $pickedCategory !== null
            && $this->checkin !== '' && $this->checkout !== '') {
            try {
                $ci = Carbon::parse($this->checkin);
                $co = Carbon::parse($this->checkout);
                if ($co->greaterThan($ci)) {
                    $categoryFree = app(AvailabilityService::class)
                        ->categoryFreeUnits($pickedCategory, $ci, $co);
                }
            } catch (Throwable) {
                $categoryFree = null;
            }
        }

        return view('livewire.dashboard.bookings.manual-create', [
            'rooms' => $rooms,
            'pickedRoom' => $pickedRoom,
            'categories' => $categories,
            'pickedCategory' => $pickedCategory,
            'categoryFree' => $categoryFree,
        ])->layout('layouts.tenant-auth');
    }

    protected function discountContext(): ?array
    {
        if ($this->roomId === null || $this->checkin === '' || $this->checkout === '') {
            return null;
        }
        $room = Room::query()->find($this->roomId);
        if ($room === null) {
            return null;
        }
        try {
            $checkin = Carbon::parse($this->checkin);
            $checkout = Carbon::parse($this->checkout);
        } catch (Throwable) {
            return null;
        }
        if ($checkout->lessThanOrEqualTo($checkin)) {
            return null;
        }

        try {
            $preview = app(PricingCalculator::class)->calculate(
                $room, $checkin, $checkout, 0, $this->breakfast_included,
            );
        } catch (Throwable) {
            return null;
        }

        return [
            'room' => $room,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'channel' => BookingSource::tryFrom($this->source) ?? BookingSource::WalkIn,
            'netSubtotal' => $preview->netSubtotal,
        ];
    }

    /**
     * Full itemised price preview for the standalone add-reservation
     * form. Reuses the resolved discount that HasDiscountLookup
     * already applied (auto-scan or manual-code) so the numbers here
     * are the same numbers CreateBooking will land on submit.
     *
     * @return array{
     *   breakdown: \App\Services\Pricing\PricingBreakdown,
     *   discountOff: int,
     *   discountLabel: ?string,
     *   discountCode: ?string,
     *   discountAutoApplied: bool,
     *   manualDiscountAmount: int,
     *   finalTotal: int
     * }|null
     */
    public function pricingPreview(): ?array
    {
        $ctx = $this->discountContext();
        if ($ctx === null) {
            return null;
        }

        try {
            $breakdown = app(PricingCalculator::class)->calculate(
                $ctx['room'], $ctx['checkin'], $ctx['checkout'], 0, $this->breakfast_included,
            );
        } catch (Throwable) {
            return null;
        }

        $discountOff = (int) ($this->appliedDiscountAmountOff ?? 0);
        $preManualTotal = max(0, $breakdown->netSubtotal - $discountOff);
        $manualDiscountAmount = $this->manual_discount_type === 'fixed'
            ? min((int) $this->manual_discount_fixed_input, $preManualTotal)
            : (int) floor($preManualTotal * $this->manual_discount_percent / 100);
        $finalTotal = max(0, $preManualTotal - $manualDiscountAmount);

        return [
            'breakdown' => $breakdown,
            'discountOff' => $discountOff,
            'discountLabel' => $this->appliedDiscountName,
            'discountCode' => $this->appliedDiscountCodeUsed,
            'discountAutoApplied' => $this->discountAutoApplied,
            'preManualTotal' => $preManualTotal,
            'manualDiscountAmount' => $manualDiscountAmount,
            'finalTotal' => $finalTotal,
        ];
    }
}
