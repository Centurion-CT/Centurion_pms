<?php

declare(strict_types=1);

namespace App\Livewire\GuestPortal;

use App\Actions\Bookings\ExtendBooking;
use App\Actions\Bookings\MarkCheckedOut;
use App\Actions\GuestPortal\InitiatePortalPayment;
use App\Enums\BookingStatus;
use App\Enums\GuestRequestKind;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GuestActionApproval;
use App\Models\GuestPortalIntent;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Services\Customers\CustomerLinker;
use App\Services\GuestPortal\GuestPinService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * In-room QR portal for the currently-checked-in guest.
 *
 * Flow (all in this single component, state-machined):
 *
 *   auth.phone  - guest enters phone; we look up the CheckedIn
 *                 booking on this room whose guest_phone matches
 *                 (loose normalise: strip non-digits + last-9 match
 *                 so +234 vs 0 vs 234 all resolve). No match ⇒ a
 *                 friendly "no active reservation" error rather than
 *                 leaking which specific field mismatched.
 *   auth.pin_set  - first scan at this property: guest sets a
 *                   4-digit PIN, confirmed twice. Stored bcrypt on
 *                   guest_room_pins (customer_id, property_id).
 *   auth.pin_enter - subsequent scans: enter their existing PIN.
 *                    3 wrong tries ⇒ lock the session, show a
 *                    "please contact front desk" note.
 *   home         - the 8-tile portal.
 *   {tile}       - sub-flow per tile (extend, checkout, rate, etc.).
 *
 * Verified state is cached in the session under
 * `guest_portal.verified.{room_id}` = booking_id so a Livewire
 * re-render or a browser refresh keeps them on the home page
 * without re-auth. Cleared when the booking flips to CheckedOut
 * (or Cancelled), so a checked-out guest scanning the QR next
 * morning gets the "no active reservation" screen naturally.
 */
#[Layout('layouts.guest-portal')]
class RoomPortal extends Component
{
    #[Locked]
    public int $propertyId;

    /**
     * Legacy per-room qr_slug URLs (/room/{slug}) pre-fill this at
     * mount time so the guest can skip typing their room number.
     * NULL when the guest scanned the new per-property QR - they'll
     * type the room number themselves at auth time. Kept for
     * backwards compat + a smoother upgrade path.
     */
    #[Locked]
    public ?int $prefillRoomId = null;

    #[Locked]
    public string $qrSlug;

    // ── State machine ───────────────────────────────────────────
    public string $step = 'auth.phone';

    // ── Auth inputs ─────────────────────────────────────────────
    public string $phone = '';
    public string $roomNumber = '';
    public string $pin = '';
    public string $pinConfirm = '';
    public int $pinAttempts = 0;
    public string $authError = '';

    /**
     * The room the guest is actually verified against - resolved
     * during phone submit from either $prefillRoomId (legacy QR) or
     * $roomNumber (new property QR). Set to the room's id after
     * successful auth so session-verified state is tied to a
     * specific room, matching the earlier per-room model.
     */
    #[Locked]
    public int $roomId = 0;

    // ── Extend / checkout / rate / requests ─────────────────────
    public string $extendCheckoutDate = '';
    // Extension inherits the original booking's breakfast tier by
    // default; the guest can flip this on the extend tile if their
    // property offers the breakfast option.
    public bool $extendBreakfastIncluded = false;
    public string $checkoutConfirm = '';

    public int $ratingStars = 5;
    public string $ratingBody = '';

    public string $requestKind = '';
    public string $requestNotes = '';
    public string $taxiWhen = '';
    public string $taxiDestination = '';
    public int $transferTargetCategoryId = 0;
    public string $orderText = '';

    // ── Reserve-another-room tile ───────────────────────────────
    public int $reserveCategoryId = 0;
    public string $reserveCheckin = '';
    public string $reserveCheckout = '';
    public int $reserveAdults = 1;
    public int $reserveChildren = 0;

    // ── Order tile (POS-backed) ─────────────────────────────────
    // Which outlet the guest is browsing; drives the product list.
    public int $orderOutletId = 0;
    // Cart: product_id → quantity. Zeros are dropped at submit time.
    public array $orderCart = [];
    public string $orderNotes = '';

    // ── State the view renders regardless of step ───────────────
    //
    // Called from BOTH:
    //   /portal/{qrSlug}       - per-property QR (new default)
    //   /room/{qrSlug}         - per-room QR (legacy backwards compat)
    //
    // We look up the slug on properties.portal_qr_slug first; if that
    // misses, fall back to rooms.qr_slug so pre-existing printed
    // per-room QRs still land the guest on the portal. When we resolve
    // via the room path we pre-fill $prefillRoomId + $roomNumber so
    // the guest doesn't have to type their room number.
    public function mount(string $qrSlug): void
    {
        $property = \App\Models\Property::query()->where('portal_qr_slug', $qrSlug)->first();
        if ($property === null) {
            // Legacy fallback - old per-room QRs still route via the
            // /room/{qr_slug} URL which points at this same component.
            $room = Room::query()->where('qr_slug', $qrSlug)->first();
            if ($room === null) {
                throw new NotFoundHttpException;
            }
            $property = $room->property;
            $this->prefillRoomId = $room->getKey();
            $this->roomNumber = (string) $room->room_number;
        }
        if ($property === null) {
            throw new NotFoundHttpException;
        }
        $this->propertyId = $property->getKey();
        $this->qrSlug = $qrSlug;

        // Session-carry: if we already verified this session AND the
        // booking is still CheckedIn, jump straight to home instead of
        // making the guest re-enter phone + PIN on a reload / re-open.
        //
        // Two lookup paths so BOTH QR flavours restore cleanly:
        //   1. Legacy per-room QR: we know the room at mount time
        //      (prefillRoomId), read `guest_portal.verified.{roomId}`.
        //   2. Per-property QR: we don't know the room until the guest
        //      types it, so we ALSO stash a property-keyed pointer on
        //      successful verify. On reload we resolve the room through
        //      it - but DON'T set prefillRoomId (which is semantically
        //      "the QR was per-room"), so a stale record still shows
        //      the room-number input on the auth.phone screen.
        $candidateRoomId = $this->prefillRoomId;
        if ($candidateRoomId === null) {
            $propRecord = session("guest_portal.property.{$this->propertyId}");
            if (is_array($propRecord) && ! empty($propRecord['room_id'])) {
                $candidateRoomId = (int) $propRecord['room_id'];
            }
        }

        if ($candidateRoomId !== null) {
            $verifiedBookingId = session("guest_portal.verified.{$candidateRoomId}");
            if ($verifiedBookingId !== null) {
                $booking = Booking::query()->find($verifiedBookingId);
                if ($booking !== null && $booking->room_id === $candidateRoomId
                    && $booking->booking_status === BookingStatus::CheckedIn) {
                    $this->roomId = $candidateRoomId;
                    $this->step = 'home';
                    // Session restore path - coord may already be in the
                    // session from an earlier fetch this session, or the
                    // browser may need re-prompting.
                    $this->maybeRequestCoordsFromBrowser();
                    return;
                }
                // Stale session - booking moved on. Clear both keys so
                // the guest gets a clean phone-entry flow.
                session()->forget([
                    "guest_portal.verified.{$candidateRoomId}",
                    "guest_portal.property.{$this->propertyId}",
                ]);
            }
        }

        // ── Preview bypass (?demo=1) ─────────────────────────────
        // Query-param opt-in that skips the phone/room#/PIN flow and
        // drops straight into home against the first CheckedIn
        // booking on the property. Purely for iterating on the
        // portal redesign without typing credentials each reload;
        // production QR posters won't carry this param.
        //
        // Tests + real guests hit the standard auth.phone path
        // because they don't pass ?demo=1.
        if ($this->step === 'auth.phone' && request()->query('demo') === '1') {
            $anyInHouse = Booking::query()
                ->whereHas('room', fn ($r) => $r->where('property_id', $this->propertyId))
                ->where('booking_status', BookingStatus::CheckedIn->value)
                ->first();
            if ($anyInHouse !== null) {
                $this->roomId = (int) $anyInHouse->room_id;
                $this->step = 'home';
                $this->maybeRequestCoordsFromBrowser();
                return;
            }
        }
    }

    // ────────────────────────────────────────────────────────────
    //  Auth: phone
    // ────────────────────────────────────────────────────────────
    public function submitPhone(): void
    {
        $this->authError = '';

        // Resolve the room from either the legacy prefill (per-room
        // QR) or the room number the guest typed in (per-property QR).
        $room = $this->resolveRoomForAuth();
        if ($room === null) {
            $this->authError = "We couldn't find that room in this property. Check the room number and try again.";
            return;
        }
        $this->roomId = $room->getKey();

        $booking = $this->findActiveBookingByPhone($this->phone);
        if ($booking === null) {
            $this->authError = "We couldn't find an active reservation on room {$room->room_number} for that phone number. Check the details and try again, or ask the front desk.";
            return;
        }

        // Resolve or link a Customer row so PIN storage has a target.
        $customer = $this->resolveOrLinkCustomer($booking);
        if ($customer === null) {
            $this->authError = "Something went wrong - please ask the front desk to help you.";
            return;
        }

        $property = $booking->room?->property;
        if ($property === null) {
            $this->authError = "This room isn't linked to a property. Ask the front desk.";
            return;
        }

        session([
            'guest_portal.pending_booking_id' => $booking->getKey(),
            'guest_portal.pending_customer_id' => $customer->getKey(),
            'guest_portal.pending_property_id' => $property->getKey(),
            'guest_portal.pending_phone' => $this->phone,
        ]);

        // Cross-Keverna PIN lookup: check the global table first, then
        // fall back to the legacy per-property table for guests who set
        // up under the old scheme. Global writes happen on next verify.
        $pins = app(GuestPinService::class);
        $hasPin = $pins->hasPinFor($this->phone, $customer, $property);
        $this->step = $hasPin ? 'auth.pin_enter' : 'auth.pin_set';
        $this->pin = '';
        $this->pinConfirm = '';
    }

    // ────────────────────────────────────────────────────────────
    //  Auth: PIN set (first time at this property)
    // ────────────────────────────────────────────────────────────
    public function submitPinSet(): void
    {
        $this->authError = '';
        if (! preg_match('/^\d{4}$/', $this->pin)) {
            $this->authError = 'Your PIN must be 4 digits.';
            return;
        }
        if ($this->pin !== $this->pinConfirm) {
            $this->authError = "The two PINs don't match. Try again.";
            return;
        }

        [$booking, $customer, $property] = $this->pending();
        $phone = (string) session('guest_portal.pending_phone', '');
        if ($booking === null || $customer === null || $property === null || $phone === '') {
            $this->authError = 'Session expired. Please re-enter your phone.';
            $this->step = 'auth.phone';
            return;
        }

        // Write to the global PIN store. Legacy per-property PINs are
        // no longer written; the read-fallback path handles the small
        // number of guests who set up before this change.
        app(GuestPinService::class)->set($phone, $this->pin);
        $this->confirmVerified($booking);
    }

    // ────────────────────────────────────────────────────────────
    //  Auth: PIN enter (returning guest)
    // ────────────────────────────────────────────────────────────
    public function submitPinEnter(): void
    {
        $this->authError = '';
        [$booking, $customer, $property] = $this->pending();
        $phone = (string) session('guest_portal.pending_phone', '');
        if ($booking === null || $customer === null || $property === null || $phone === '') {
            $this->authError = 'Session expired. Please re-enter your phone.';
            $this->step = 'auth.phone';
            return;
        }

        if (! app(GuestPinService::class)->verify($phone, (string) $this->pin, $customer, $property)) {
            $this->pinAttempts++;
            if ($this->pinAttempts >= 3) {
                $this->authError = "Too many wrong PIN attempts. Please ask the front desk to help you.";
                $this->step = 'auth.phone';
                $this->pinAttempts = 0;
                session()->forget([
                    'guest_portal.pending_booking_id',
                    'guest_portal.pending_customer_id',
                    'guest_portal.pending_property_id',
                    'guest_portal.pending_phone',
                ]);
                return;
            }
            $this->authError = "That PIN is incorrect. Try again - {$this->pinAttempts} of 3 attempts used.";
            $this->pin = '';
            return;
        }

        $this->confirmVerified($booking);
    }

    // ────────────────────────────────────────────────────────────
    //  Auth: PIN reset (Forgot PIN?)
    //
    //  From auth.pin_enter → "Forgot PIN?" → we send a 6-digit OTP
    //  to the guest's WhatsApp → they enter it → we drop them onto
    //  auth.pin_set (which now writes a NEW PIN over the old one).
    //  Uses GuestPinService's issueResetCode + verifyResetCode; the
    //  main PIN row (guest_global_pins) is deliberately NOT touched
    //  until the guest sets a new PIN - a mid-flow drop-off keeps
    //  their old PIN intact.
    // ────────────────────────────────────────────────────────────
    public string $resetOtp = '';
    public int $resetAttempts = 0;

    public function requestPinReset(\App\Services\GuestPortal\GuestPinService $pins, \App\Services\GuestPortal\GuestNotifier $notifier): void
    {
        $this->authError = '';
        $phone = (string) session('guest_portal.pending_phone', '');
        if ($phone === '') {
            $this->authError = 'Session expired. Please re-enter your phone.';
            $this->step = 'auth.phone';
            return;
        }

        $code = $pins->issueResetCode($phone);
        if ($code === null) {
            $this->authError = "Something's off with that phone number - try again.";
            return;
        }

        try {
            $notifier->sendPinResetOtp($phone, $code);
        } catch (\Throwable $e) {
            $this->authError = "We couldn't send the reset code. Please ask the front desk.";
            return;
        }

        $this->step = 'auth.pin_reset';
        $this->resetOtp = '';
        $this->resetAttempts = 0;
    }

    public function submitPinReset(\App\Services\GuestPortal\GuestPinService $pins): void
    {
        $this->authError = '';
        $phone = (string) session('guest_portal.pending_phone', '');
        if ($phone === '') {
            $this->authError = 'Session expired. Please re-enter your phone.';
            $this->step = 'auth.phone';
            return;
        }

        if (! $pins->verifyResetCode($phone, (string) $this->resetOtp)) {
            $this->resetAttempts++;
            if ($this->resetAttempts >= 5) {
                $this->authError = 'Too many wrong codes. Ask the front desk to help you.';
                $this->step = 'auth.phone';
                $this->resetAttempts = 0;
                return;
            }
            $this->authError = "That code is incorrect. Try again - {$this->resetAttempts} of 5.";
            $this->resetOtp = '';
            return;
        }

        // OTP verified - drop into the set-new-PIN flow. Reuse the
        // existing pin_set step; submitPinSet writes the new PIN
        // over the old one via GuestPinService::set().
        $this->step = 'auth.pin_set';
        $this->pin = '';
        $this->pinConfirm = '';
        $this->resetOtp = '';
    }

    private function confirmVerified(Booking $booking): void
    {
        session()->put("guest_portal.verified.{$this->roomId}", $booking->getKey());
        // Property-keyed pointer so a page reload on the per-property
        // QR (where mount() doesn't yet know which room) can find its
        // way back to the same verified session without asking the
        // guest to re-enter phone + PIN.
        session()->put("guest_portal.property.{$this->propertyId}", [
            'room_id' => $this->roomId,
            'booking_id' => $booking->getKey(),
        ]);
        session()->forget([
            'guest_portal.pending_booking_id',
            'guest_portal.pending_customer_id',
            'guest_portal.pending_property_id',
            'guest_portal.pending_phone',
        ]);
        $this->step = 'home';
        $this->pin = '';
        $this->pinConfirm = '';
        $this->pinAttempts = 0;
        $this->extendCheckoutDate = $booking->checkout_date?->copy()->addDay()->toDateString() ?? '';

        // Ask the browser for geolocation so the gate has a coord score
        // to work with on the guest's next sensitive action. Only fires
        // when the property has actually configured a fence - nothing to
        // gate against otherwise.
        $this->maybeRequestCoordsFromBrowser();
    }

    public function logout(): void
    {
        session()->forget([
            "guest_portal.verified.{$this->roomId}",
            "guest_portal.property.{$this->propertyId}",
            "guest_portal.coords.{$this->propertyId}",
        ]);
        $this->step = 'auth.phone';
        $this->phone = '';
        $this->pin = '';
    }

    // ────────────────────────────────────────────────────────────
    //  Geo-fence: capture the guest's device coordinates once, at
    //  auth time. The layout runs navigator.geolocation.getCurrentPosition
    //  on the client and dispatches "portal-coords" back to this component
    //  with the numbers. We score against the property pin and stash it
    //  in the session so the gate can read it on every sensitive action
    //  without re-prompting. A denied browser prompt sends score=0 which,
    //  under a strict policy, sends every action to the approval queue.
    // ────────────────────────────────────────────────────────────
    #[On('portal-coords')]
    public function recordCoords(float $latitude, float $longitude): void
    {
        $property = Property::query()->find($this->propertyId);
        if ($property === null) return;

        $score = $property->computeGuestCoordScore($latitude, $longitude);
        $distance = $property->hasCoordinates()
            ? (int) round(Property::haversineDistanceMeters(
                (float) $property->latitude, (float) $property->longitude,
                $latitude, $longitude,
            ))
            : null;

        session()->put("guest_portal.coords.{$this->propertyId}", [
            'lat' => $latitude,
            'lng' => $longitude,
            'score' => $score,
            'distance' => $distance,
        ]);
    }

    #[On('portal-coords-denied')]
    public function recordCoordsDenied(): void
    {
        session()->put("guest_portal.coords.{$this->propertyId}", [
            'lat' => null,
            'lng' => null,
            'score' => 0,
            'distance' => null,
        ]);
    }

    /**
     * Dispatch a browser event asking the layout's JS to run
     * navigator.geolocation.getCurrentPosition() and fire the result
     * back as `portal-coords` / `portal-coords-denied`. Skipped when
     * this property hasn't turned the gate on, so a portal that
     * doesn't need coords never has to ask the guest.
     */
    private function maybeRequestCoordsFromBrowser(): void
    {
        $property = Property::query()->find($this->propertyId);
        if ($property === null || ! $property->portalGateEnabled()) return;
        // Already captured this session - no need to re-prompt.
        if (session("guest_portal.coords.{$this->propertyId}") !== null) return;

        $this->dispatch('portal-request-coords');
    }

    /**
     * Runs the geo-fence check for a sensitive action. Returns true
     * when the action should execute directly, false when it was
     * queued for front-desk approval (and the caller must NOT run
     * the underlying action).
     *
     * The queue row captures the payload needed to replay the action
     * on approval so the operator's click can carry out the extend /
     * order / etc. without asking the guest to redo anything.
     */
    private function gateOrQueue(string $kind, array $payload): bool
    {
        $property = Property::query()->find($this->propertyId);
        if ($property === null) return true;
        if (! $property->portalGateEnabled()) return true;

        $booking = $this->currentBooking();
        if ($booking === null) return true; // pre-auth safety, nothing to charge

        $coords = session("guest_portal.coords.{$this->propertyId}");
        $score = is_array($coords) ? (int) ($coords['score'] ?? 0) : 0;
        $minScore = (int) ($property->guest_coord_min_score ?? 0);

        if ($score >= $minScore) return true;

        GuestActionApproval::create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'room_id' => $booking->room_id,
            'kind' => $kind,
            'payload' => $payload,
            'coord_score' => $score,
            'guest_latitude' => is_array($coords) ? ($coords['lat'] ?? null) : null,
            'guest_longitude' => is_array($coords) ? ($coords['lng'] ?? null) : null,
            'distance_meters' => is_array($coords) ? ($coords['distance'] ?? null) : null,
            'status' => 'pending',
        ]);

        activity('guest_action_approval')
            ->on($booking)
            ->withProperties(['kind' => $kind, 'coord_score' => $score, 'min_score' => $minScore])
            ->log("Guest {$kind} request queued for front-desk approval (coord score {$score} < {$minScore}).");

        $this->step = 'home';
        $this->dispatch('portal-flash', message: 'Your request has been sent to the front desk for confirmation. They will contact you shortly.');
        return false;
    }

    // ────────────────────────────────────────────────────────────
    //  Home: open a tile
    // ────────────────────────────────────────────────────────────
    public function openTile(string $tile): void
    {
        $legal = ['extend', 'checkout', 'rate', 'complaint', 'taxi', 'transfer', 'order', 'reserve', 'requests',
                  // Redesigned home adds:
                  //   housekeeping — request a cleaning visit
                  //   laundry      — laundry pickup request
                  //   bill         — view + pay the guest folio
                  //   info         — property info sheet (wifi / breakfast / rules)
                  //   contact      — reception phone / WhatsApp
                  'housekeeping', 'laundry', 'bill', 'info', 'contact'];
        if (! in_array($tile, $legal, true)) {
            return;
        }
        $this->step = 'tile.'.$tile;
        $this->authError = '';
        // Reset per-tile inputs so re-entering a tile is clean.
        $this->requestNotes = '';
        $this->taxiWhen = '';
        $this->taxiDestination = '';
        $this->orderText = '';
        $this->transferTargetCategoryId = 0;
        $this->ratingBody = '';
        $this->ratingStars = 5;
        $this->checkoutConfirm = '';
        // Extend tile seeds the breakfast toggle from the guest's
        // original booking so a stay booked WITH breakfast doesn't
        // silently drop to base-rate nights on extend.
        if ($tile === 'extend') {
            $booking = $this->currentBooking();
            $this->extendBreakfastIncluded = (bool) ($booking?->breakfast_included ?? false);
        }
        // Reserve tile: seed dates to tomorrow + day after so the guest
        // can see the price preview without having to type first.
        if ($tile === 'reserve') {
            $this->reserveCategoryId = 0;
            $this->reserveCheckin = Carbon::tomorrow()->toDateString();
            $this->reserveCheckout = Carbon::tomorrow()->addDay()->toDateString();
            $this->reserveAdults = 1;
            $this->reserveChildren = 0;
        }
        // Order tile: reset cart + snap to the first active outlet
        // that has products so the guest sees a menu immediately.
        if ($tile === 'order') {
            $this->orderCart = [];
            $this->orderNotes = '';
            // Use $propertyId directly - always known from mount(),
            // whereas $roomId is only reliable after auth. Same
            // downstream shape.
            $property = \App\Models\Property::query()->find($this->propertyId);
            if ($property !== null) {
                $firstOutlet = \App\Models\Outlet::query()
                    ->where('property_id', $property->getKey())
                    ->where('active', true)
                    ->whereHas('products', fn ($p) => $p->where('active', true))
                    ->orderBy('name')
                    ->first();
                $this->orderOutletId = $firstOutlet?->getKey() ?? 0;
            } else {
                $this->orderOutletId = 0;
            }
        }
    }

    public function backToHome(): void
    {
        $this->step = 'home';
        $this->authError = '';
    }

    /**
     * Guest-side cancel - flips one of the guest's own open requests to
     * status='cancelled'. Ownership guard: the request must be on the
     * booking the guest is verified against, otherwise we silently no-op
     * (a stale cross-tenant id can't affect anyone else's requests).
     */
    public function cancelRequest(int $requestId): void
    {
        $booking = $this->currentBooking();
        if ($booking === null) {
            $this->step = 'auth.phone';
            return;
        }

        $req = GuestRequest::query()->find($requestId);
        if ($req === null || (int) $req->booking_id !== (int) $booking->id) {
            return;
        }
        if ($req->status !== 'open') {
            return;
        }

        $req->forceFill([
            'status' => 'cancelled',
            'resolved_at' => Carbon::now(),
        ])->save();

        activity('guest_request')
            ->on($booking)
            ->withProperties(['kind' => $req->kind->value, 'request_id' => $req->id])
            ->log("Guest cancelled their {$req->kind->label()} request via the portal.");

        $this->dispatch('portal-flash', message: 'Request cancelled.');
    }

    // ────────────────────────────────────────────────────────────
    //  Tile: extend stay
    // ────────────────────────────────────────────────────────────
    public function submitExtend(ExtendBooking $action): void
    {
        $booking = $this->currentBooking();
        if ($booking === null) {
            $this->step = 'auth.phone';
            return;
        }
        try {
            $newCheckout = Carbon::parse($this->extendCheckoutDate);
        } catch (\Throwable) {
            $this->authError = 'Please pick a valid date.';
            return;
        }
        if ($newCheckout->lte($booking->checkout_date)) {
            $this->authError = 'New check-out must be after your current check-out date.';
            return;
        }

        // Front-load the collision check so the guest sees a clean
        // message instead of a raw exception from ExtendBooking.
        $maxCheckout = $booking->maxExtendableCheckoutDate();
        if ($maxCheckout !== null && $newCheckout->gt($maxCheckout)) {
            $this->authError = 'The room is reserved by another guest from '
                .$maxCheckout->format('D, d M').'. Pick an earlier date.';
            return;
        }

        if (! $this->gateOrQueue('extend', [
            'new_checkout_date' => $newCheckout->toDateString(),
            'breakfast_included' => $this->extendBreakfastIncluded,
        ])) {
            return;
        }

        try {
            // ExtendBooking needs an Operator for audit trail. Guest-
            // initiated portal extends attribute to the first Manager
            // (see systemOperator() for the rationale - a synthetic
            // "System" operator per tenant is a follow-up).
            $action->handle(
                $booking, $newCheckout, $this->systemOperator($booking),
                breakfastIncluded: $this->extendBreakfastIncluded,
            );
        } catch (\App\Services\Availability\RangeNotAvailableException) {
            // Someone else booked into the range between the guest
            // picking a date + submitting. Clean, friendly message
            // rather than the raw exception - same story the front
            // desk shows on their extend modal.
            $this->authError = 'The room is reserved by another guest for those dates. Pick an earlier date, or ask the front desk to help.';
            return;
        } catch (\Throwable $e) {
            $this->authError = 'We couldn\'t extend right now: '.$e->getMessage().'. Ask the front desk to help.';
            return;
        }

        $this->step = 'home';
        $this->dispatch('portal-flash', message: 'Your stay has been extended. See you around!');
    }

    /**
     * Same intent as submitExtend but pays the extension charge on the
     * spot via Paystack. Returns a redirect to the Paystack checkout
     * URL; the callback + webhook resolve the intent and complete the
     * ExtendBooking action on success.
     */
    public function submitExtendPayByCard(InitiatePortalPayment $init)
    {
        $booking = $this->currentBooking();
        if ($booking === null) {
            $this->step = 'auth.phone';
            return null;
        }
        try {
            $newCheckout = Carbon::parse($this->extendCheckoutDate);
        } catch (\Throwable) {
            $this->authError = 'Please pick a valid date.';
            return null;
        }
        $currentCheckout = $booking->checkout_date;
        if ($currentCheckout === null || $newCheckout->lte($currentCheckout)) {
            $this->authError = 'New check-out must be after your current check-out date.';
            return null;
        }

        // Refuse to take money for a range the room isn't free for.
        // Server-side echo of the datepicker's max attribute so a
        // guest fiddling with the field can't push a payment through
        // that ExtendBooking would then throw back on.
        $maxCheckout = $booking->maxExtendableCheckoutDate();
        if ($maxCheckout !== null && $newCheckout->gt($maxCheckout)) {
            $this->authError = 'The room is reserved by another guest from '
                .$maxCheckout->format('D, d M').'. Pick an earlier date.';
            return null;
        }

        // Amount matches what ExtendBooking will actually charge -
        // uses the SAME calculator + tier flag so the Paystack total
        // and the ledger stay in sync. Falls back to base_rate when
        // the room has no category (defensive; shouldn't happen).
        $addedNights = (int) $currentCheckout->diffInDays($newCheckout);
        if ($booking->room !== null) {
            $breakdown = app(\App\Services\Pricing\PricingCalculator::class)->calculate(
                $booking->room, $currentCheckout, $newCheckout,
                breakfastIncluded: $this->extendBreakfastIncluded,
            );
            $amount = (int) $breakdown->netSubtotal;
        } else {
            $amount = $addedNights * (int) ($booking->room?->base_rate ?? 0);
        }
        if ($amount <= 0) {
            $this->authError = 'Extension amount is zero - nothing to charge.';
            return null;
        }

        if (! $this->gateOrQueue('extend_card', [
            'new_checkout_date' => $newCheckout->toDateString(),
            'breakfast_included' => $this->extendBreakfastIncluded,
            'amount' => $amount,
        ])) {
            return null;
        }

        try {
            $result = $init->handle(
                booking: $booking,
                amountNgn: $amount,
                purpose: GuestPortalIntent::PURPOSE_EXTEND,
                payload: [
                    'new_checkout_date' => $newCheckout->toDateString(),
                    'breakfast_included' => $this->extendBreakfastIncluded,
                ],
            );
        } catch (\Throwable $e) {
            $this->authError = "Couldn't start payment: ".$e->getMessage().'. Ask the front desk.';
            return null;
        }

        // Redirect the guest to Paystack. Livewire returns the redirect
        // via $this->redirect() which becomes a full-page navigate.
        return $this->redirect($result['authorization_url'], navigate: false);
    }

    // ────────────────────────────────────────────────────────────
    //  Tile: check out
    // ────────────────────────────────────────────────────────────
    public function submitCheckout(MarkCheckedOut $action): void
    {
        if (mb_strtolower(trim($this->checkoutConfirm)) !== 'check out') {
            $this->authError = "Type 'check out' to confirm.";
            return;
        }
        $booking = $this->currentBooking();
        if ($booking === null) {
            $this->step = 'auth.phone';
            return;
        }
        if (! $this->gateOrQueue('checkout', [])) return;
        try {
            // MarkCheckedOut is normally operator-initiated. The guest
            // portal creates a "portal-checkout" operator-less path
            // by delegating to the same action - any pending balance
            // will throw and prompt the guest to see the front desk,
            // which is the correct behaviour.
            $systemOperator = $this->systemOperator($booking);
            $action->handle($booking, $systemOperator);
        } catch (\Throwable $e) {
            $this->authError = "Couldn't complete check-out: ".$e->getMessage().' Please see the front desk.';
            return;
        }

        // Clear both markers so a future scan on this same QR (or a
        // reload right after checkout) shows the "no active
        // reservation" screen rather than restoring the just-closed
        // booking.
        session()->forget([
            "guest_portal.verified.{$this->roomId}",
            "guest_portal.property.{$this->propertyId}",
        ]);
        $this->step = 'checkout.done';
    }

    // ────────────────────────────────────────────────────────────
    //  Tile: order (POS-backed)
    // ────────────────────────────────────────────────────────────
    /**
     * Bump / decrement the cart quantity for a product. Silently
     * ignores unknown or foreign-property products.
     */
    public function bumpCart(int $productId, int $delta): void
    {
        $product = \App\Models\Product::query()->find($productId);
        if ($product === null || (int) $product->outlet_id !== $this->orderOutletId) {
            return;
        }
        $cur = (int) ($this->orderCart[$productId] ?? 0);
        $next = max(0, min(20, $cur + $delta));
        if ($next === 0) {
            unset($this->orderCart[$productId]);
        } else {
            $this->orderCart[$productId] = $next;
        }
    }

    public function switchOrderOutlet(int $outletId): void
    {
        $this->orderOutletId = $outletId;
        $this->orderCart = [];
    }

    /**
     * Compute the cart total in naira. Reads through actual Product
     * prices so a stale cart with an updated price re-costs cleanly.
     */
    public function orderCartTotal(): int
    {
        if ($this->orderCart === []) return 0;
        $products = \App\Models\Product::query()->whereIn('id', array_keys($this->orderCart))->get();
        $total = 0;
        foreach ($products as $p) {
            $qty = (int) ($this->orderCart[$p->id] ?? 0);
            if ($qty > 0) $total += $qty * (int) $p->price;
        }
        return $total;
    }

    /**
     * Charge-to-room path - creates a real PosSale on the outlet,
     * completes it with tender 'room' so the amount lands on the
     * guest's folio as a BookingCharge to settle at check-out.
     */
    public function submitOrderChargeToRoom(): void
    {
        $booking = $this->currentBooking();
        if ($booking === null) { $this->step = 'auth.phone'; return; }
        if (! $this->gateOrQueue('order_room', [
            'outlet_id' => $this->orderOutletId,
            'cart' => $this->orderCart,
            'notes' => $this->orderNotes,
        ])) {
            return;
        }
        $sale = $this->createOrderSale($booking, chargeToRoom: true);
        if ($sale === null) return;
        try {
            app(\App\Actions\Pos\CompletePosSale::class)->handle(
                sale: $sale,
                operator: $this->systemOperator($booking),
                method: 'room',
            );
        } catch (\Throwable $e) {
            $this->authError = "Couldn't send your order: ".$e->getMessage().'. Ask the front desk.';
            return;
        }
        $this->step = 'home';
        $this->dispatch('portal-flash', message: 'Order sent - it will be added to your room bill.');
    }

    /**
     * Pay the outstanding folio balance on the current booking via
     * card. Previously the "Pay ₦X" button on the Bill tile bounced
     * into openTile('extend'), which put the guest on the Extend-stay
     * screen — wrong mental model + wrong amount (extension nightly
     * rate, not the outstanding total).
     *
     * The resolver has no follow-up for PURPOSE_BALANCE: marking the
     * linked Payment as Succeeded is enough. Booking::outstandingTotal
     * already reads succeeded payments to compute the remaining owe.
     */
    public function submitBillPayByCard(InitiatePortalPayment $init)
    {
        $booking = $this->currentBooking();
        if ($booking === null) { $this->step = 'auth.phone'; return null; }

        $amount = (int) $booking->outstandingTotal();
        if ($amount <= 0) {
            $this->authError = 'Nothing owed on this booking right now.';
            return null;
        }

        if (! $this->gateOrQueue('balance_card', ['amount' => $amount])) {
            return null;
        }

        try {
            $result = $init->handle(
                booking: $booking,
                amountNgn: $amount,
                purpose: GuestPortalIntent::PURPOSE_BALANCE,
                payload: ['amount' => $amount],
            );
        } catch (\Throwable $e) {
            $this->authError = "Couldn't start payment: ".$e->getMessage().'. Ask the front desk.';
            return null;
        }
        return $this->redirect($result['authorization_url'], navigate: false);
    }

    /**
     * Pay-by-card path - creates a Draft PosSale on the outlet, then
     * initiates a Paystack transaction whose resolver completes the
     * sale on success. Sale ID rides on the intent payload.
     */
    public function submitOrderPayByCard(InitiatePortalPayment $init)
    {
        $booking = $this->currentBooking();
        if ($booking === null) { $this->step = 'auth.phone'; return null; }
        if (! $this->gateOrQueue('order_card', [
            'outlet_id' => $this->orderOutletId,
            'cart' => $this->orderCart,
            'notes' => $this->orderNotes,
        ])) {
            return null;
        }
        $sale = $this->createOrderSale($booking, chargeToRoom: false);
        if ($sale === null) return null;

        $amount = (int) $sale->total;
        if ($amount <= 0) {
            $this->authError = 'Cart is empty - pick something first.';
            return null;
        }

        try {
            $result = $init->handle(
                booking: $booking,
                amountNgn: $amount,
                purpose: GuestPortalIntent::PURPOSE_ORDER,
                payload: [
                    'pos_sale_id' => $sale->id,
                    'outlet_id' => $sale->outlet_id,
                ],
            );
        } catch (\Throwable $e) {
            $this->authError = "Couldn't start payment: ".$e->getMessage().'. Ask the front desk.';
            return null;
        }
        return $this->redirect($result['authorization_url'], navigate: false);
    }

    /**
     * Build the PosSale from the cart. Returns null when the cart is
     * empty, the outlet is invalid, or the outlet's tenant/property
     * don't match the current guest booking (belt-and-braces vs
     * client-side tampering with orderOutletId).
     */
    private function createOrderSale(Booking $booking, bool $chargeToRoom): ?\App\Models\PosSale
    {
        if ($this->orderCart === []) {
            $this->authError = 'Add something to your cart first.';
            return null;
        }
        $outlet = \App\Models\Outlet::query()->find($this->orderOutletId);
        if ($outlet === null || $outlet->tenant_id !== $booking->tenant_id) {
            $this->authError = 'Outlet not found for this property.';
            return null;
        }
        $bookingProperty = $booking->room?->property_id;
        if ($bookingProperty !== null && (int) $outlet->property_id !== (int) $bookingProperty) {
            $this->authError = 'That outlet belongs to a different branch.';
            return null;
        }

        $cart = [];
        foreach ($this->orderCart as $productId => $qty) {
            $qty = (int) $qty;
            if ($qty > 0) $cart[] = ['product_id' => (int) $productId, 'quantity' => $qty];
        }

        try {
            return app(\App\Actions\Pos\CreatePosSaleDraft::class)->handle(
                outlet: $outlet,
                operator: $this->systemOperator($booking),
                cart: $cart,
                bookingId: $chargeToRoom ? $booking->id : null,
                customerId: $booking->customer_id,
                notes: 'Room service via guest portal'.($this->orderNotes !== '' ? ' · '.trim($this->orderNotes) : ''),
            );
        } catch (\Throwable $e) {
            $this->authError = "Couldn't build the order: ".$e->getMessage();
            return null;
        }
    }

    // ────────────────────────────────────────────────────────────
    //  Tile: reserve another room
    // ────────────────────────────────────────────────────────────
    /**
     * Compute the price preview for the reserve tile - used by the
     * view to show the total before the guest commits to paying.
     * Returns 0 when dates/category are invalid or no room is
     * available for the picked range.
     */
    public function reservePreviewAmount(): int
    {
        if ($this->reserveCategoryId <= 0 || $this->reserveCheckin === '' || $this->reserveCheckout === '') {
            return 0;
        }
        try {
            $in = Carbon::parse($this->reserveCheckin)->startOfDay();
            $out = Carbon::parse($this->reserveCheckout)->startOfDay();
        } catch (\Throwable) {
            return 0;
        }
        if ($out->lessThanOrEqualTo($in)) return 0;

        $cat = \App\Models\RoomCategory::query()->find($this->reserveCategoryId);
        if ($cat === null) return 0;
        $nights = (int) $in->diffInDays($out);
        return $nights * (int) $cat->base_rate;
    }

    public function submitReservePayByCard(InitiatePortalPayment $init)
    {
        $booking = $this->currentBooking();
        if ($booking === null) {
            $this->step = 'auth.phone';
            return null;
        }
        if ($this->reserveCategoryId <= 0) {
            $this->authError = 'Pick a category first.';
            return null;
        }
        try {
            $in = Carbon::parse($this->reserveCheckin)->startOfDay();
            $out = Carbon::parse($this->reserveCheckout)->startOfDay();
        } catch (\Throwable) {
            $this->authError = 'Please pick valid dates.';
            return null;
        }
        if ($out->lessThanOrEqualTo($in)) {
            $this->authError = 'Check-out must be after check-in.';
            return null;
        }
        $amount = $this->reservePreviewAmount();
        if ($amount <= 0) {
            $this->authError = 'Selected dates or category are invalid.';
            return null;
        }

        // Availability guard - refuse to take money for a category
        // with nothing free in the range. The resolver re-checks on
        // completion in case something got booked in the meantime.
        $cat = \App\Models\RoomCategory::query()->findOrFail($this->reserveCategoryId);
        $available = app(\App\Services\Bookings\RoomAllocator::class)->countAvailable($cat, $in, $out);
        if ($available <= 0) {
            $this->authError = 'No rooms of that category are available for those dates.';
            return null;
        }

        if (! $this->gateOrQueue('reserve', [
            'category_id' => $cat->id,
            'checkin' => $in->toDateString(),
            'checkout' => $out->toDateString(),
            'adults' => max(1, $this->reserveAdults),
            'children' => max(0, $this->reserveChildren),
            'amount' => $amount,
        ])) {
            return null;
        }

        try {
            $result = $init->handle(
                booking: $booking,
                amountNgn: $amount,
                purpose: GuestPortalIntent::PURPOSE_RESERVE,
                payload: [
                    'category_id' => $cat->id,
                    'checkin' => $in->toDateString(),
                    'checkout' => $out->toDateString(),
                    'adults' => max(1, $this->reserveAdults),
                    'children' => max(0, $this->reserveChildren),
                    // Reserve is on behalf of the currently-verified
                    // guest - reuse their contact details so the new
                    // booking is attributed to the same person.
                    'guest_name' => $booking->guest_name,
                    'guest_email' => $booking->guest_email,
                    'guest_phone' => $booking->guest_phone,
                ],
            );
        } catch (\Throwable $e) {
            $this->authError = "Couldn't start payment: ".$e->getMessage().'. Ask the front desk.';
            return null;
        }

        return $this->redirect($result['authorization_url'], navigate: false);
    }

    // ────────────────────────────────────────────────────────────
    //  Tile: rate stay
    // ────────────────────────────────────────────────────────────
    public function submitRating(): void
    {
        $booking = $this->currentBooking();
        if ($booking === null) {
            $this->step = 'auth.phone';
            return;
        }
        $stars = max(1, min(5, (int) $this->ratingStars));
        $property = $booking->room?->property;
        Review::query()->create([
            'tenant_id' => $booking->tenant_id,
            'property_id' => $property?->getKey(),
            'room_id' => $booking->room_id,
            'booking_id' => $booking->getKey(),
            'guest_name' => $booking->guest_name,
            'rating' => $stars,
            'body' => trim($this->ratingBody) !== '' ? trim($this->ratingBody) : 'Rated from in-room portal',
            'is_displayed' => false, // pending operator moderation
        ]);

        $this->step = 'home';
        $this->dispatch('portal-flash', message: 'Thanks for the rating!');
    }

    // ────────────────────────────────────────────────────────────
    //  Tile: request family (complaint, taxi, transfer, order, custom)
    // ────────────────────────────────────────────────────────────
    public function submitRequest(string $kind): void
    {
        $booking = $this->currentBooking();
        if ($booking === null) {
            $this->step = 'auth.phone';
            return;
        }
        $kindEnum = GuestRequestKind::tryFrom($kind);
        if ($kindEnum === null) {
            return;
        }

        $body = match ($kindEnum) {
            GuestRequestKind::Taxi => [
                'when' => trim($this->taxiWhen),
                'destination' => trim($this->taxiDestination),
            ],
            GuestRequestKind::Transfer => [
                'target_category_id' => $this->transferTargetCategoryId ?: null,
            ],
            GuestRequestKind::Order => [
                'freeform' => trim($this->orderText),
            ],
            default => [],
        };

        if (! $this->gateOrQueue('request:'.$kindEnum->value, [
            'kind' => $kindEnum->value,
            'notes' => trim($this->requestNotes) !== '' ? trim($this->requestNotes) : null,
            'body' => $body,
        ])) {
            return;
        }

        GuestRequest::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->getKey(),
            'room_id' => $booking->room_id,
            'kind' => $kindEnum->value,
            'status' => 'open',
            'notes' => trim($this->requestNotes) !== '' ? trim($this->requestNotes) : null,
            'body' => $body,
        ]);

        activity('guest_request')
            ->on($booking)
            ->withProperties(['kind' => $kindEnum->value])
            ->log("Guest raised a {$kindEnum->label()} from the in-room portal.");

        $this->step = 'home';
        $this->dispatch('portal-flash', message: 'Sent to the front desk - someone will be with you soon.');
    }

    /**
     * Housekeeping + laundry requests. Both are Custom-kind
     * GuestRequests with a `subkind` in the body so the operator
     * queue can group them without adding two enum cases.
     *
     * @param  'housekeeping'|'laundry'  $subkind
     */
    public function submitCustomRequest(string $subkind): void
    {
        if (! in_array($subkind, ['housekeeping', 'laundry'], true)) {
            return;
        }
        $booking = $this->currentBooking();
        if ($booking === null) {
            $this->step = 'auth.phone';
            return;
        }

        $body = ['subkind' => $subkind];
        if (! $this->gateOrQueue('request:'.$subkind, [
            'kind' => 'custom',
            'notes' => trim($this->requestNotes) !== '' ? trim($this->requestNotes) : null,
            'body' => $body,
        ])) {
            return;
        }

        GuestRequest::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->getKey(),
            'room_id' => $booking->room_id,
            'kind' => GuestRequestKind::Custom->value,
            'status' => 'open',
            'notes' => trim($this->requestNotes) !== '' ? trim($this->requestNotes) : null,
            'body' => $body,
        ]);

        activity('guest_request')
            ->on($booking)
            ->withProperties(['kind' => 'custom', 'subkind' => $subkind])
            ->log("Guest raised a {$subkind} request from the in-room portal.");

        $this->requestNotes = '';
        $this->step = 'home';
        $this->dispatch('portal-flash', message: 'Sent to the front desk — someone will be with you soon.');
    }

    // ────────────────────────────────────────────────────────────
    //  Render
    // ────────────────────────────────────────────────────────────
    public function render(): View
    {
        $booking = $this->currentBooking();
        // Property is always known post-mount (from the QR); room is
        // only known after successful auth. Pre-auth views (phone,
        // pin_set, pin_enter) render off the property alone.
        $property = \App\Models\Property::query()->find($this->propertyId);
        $room = $this->roomId > 0 ? Room::query()->with('category')->find($this->roomId) : null;

        // Category list for the transfer tile - same-property, active.
        $categories = $property !== null
            ? $property->roomCategories()->active()->orderBy('base_rate')->get()
            : collect();

        // Order tile: outlets on this property that have products,
        // and the products for the currently-selected outlet.
        $outlets = collect();
        $products = collect();
        if ($property !== null) {
            $outlets = \App\Models\Outlet::query()
                ->where('property_id', $property->getKey())
                ->where('active', true)
                ->whereHas('products', fn ($p) => $p->where('active', true))
                ->orderBy('name')->get();
            if ($this->orderOutletId > 0) {
                $products = \App\Models\Product::query()
                    ->where('outlet_id', $this->orderOutletId)
                    ->where('active', true)
                    ->orderBy('name')->get();
            }
        }

        // My-requests tile: guest's own request history on THIS
        // booking. Includes cancelled + resolved so they can see the
        // whole trail, sorted newest first. Messages are eager-loaded
        // so the guest sees any operator replies inline.
        $myRequests = $booking !== null
            ? GuestRequest::query()
                ->with(['messages' => fn ($q) => $q->orderBy('created_at')])
                ->where('booking_id', $booking->id)
                ->orderByDesc('id')
                ->limit(30)
                ->get()
            : collect();

        // Live projection of the same-day late-checkout fee so the
        // guest sees on the portal home why their balance is growing.
        // Uses the same calculator + policy snapshot the desk sees.
        $lateProjection = $booking !== null
            ? $booking->projectedLateCheckoutFee(\Illuminate\Support\Carbon::now())
            : ['kind' => 'none', 'amount' => 0, 'hours_late' => 0, 'description' => ''];

        return view('livewire.guest-portal.room-portal', [
            'room' => $room,
            'booking' => $booking,
            'property' => $property,
            'categories' => $categories,
            'outlets' => $outlets,
            'products' => $products,
            'myRequests' => $myRequests,
            'lateProjection' => $lateProjection,
        ])->layout('layouts.guest-portal');
    }

    // ────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────
    private function currentBooking(): ?Booking
    {
        $verifiedBookingId = session("guest_portal.verified.{$this->roomId}");
        if ($verifiedBookingId === null) {
            return null;
        }
        return Booking::query()->find($verifiedBookingId);
    }

    /**
     * Which specific room the guest is authing against.
     *   - Legacy /room/{slug} path: $prefillRoomId is set at mount()
     *     time; we just re-fetch to make sure it still exists +
     *     belongs to this property.
     *   - New /portal/{slug} path: $roomNumber is what the guest
     *     typed; we look up the room by number within the property.
     *
     * Trimmed + case-insensitive match on room_number so
     * "205", " 205 ", "205 " all resolve the same. Returns null
     * when nothing matches - the caller shows a friendly error.
     */
    private function resolveRoomForAuth(): ?Room
    {
        if ($this->prefillRoomId !== null) {
            return Room::query()
                ->where('id', $this->prefillRoomId)
                ->where('property_id', $this->propertyId)
                ->first();
        }
        $number = trim((string) $this->roomNumber);
        if ($number === '') return null;

        return Room::query()
            ->where('property_id', $this->propertyId)
            ->whereRaw('LOWER(room_number) = ?', [mb_strtolower($number)])
            ->first();
    }

    /**
     * Loose phone normalisation - Nigerian mobile numbers commonly
     * appear as +2348012345678, 2348012345678, 08012345678 depending
     * on where they were captured. Compare last 9 digits so the
     * variants resolve to the same guest.
     */
    private function findActiveBookingByPhone(string $phone): ?Booking
    {
        $normalised = preg_replace('/\D+/', '', $phone) ?? '';
        if ($normalised === '') return null;
        $tail = substr($normalised, -9);
        if (strlen($tail) < 9) return null;

        // Single-quoted string literals - Postgres treats "…" as an
        // identifier so `""` (empty string) errors with "zero-length
        // delimited identifier". Both Postgres and MySQL accept '…'
        // for string literals universally.
        return Booking::query()
            ->where('room_id', $this->roomId)
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->whereRaw("REPLACE(REPLACE(REPLACE(guest_phone, '+', ''), ' ', ''), '-', '') LIKE ?", ['%'.$tail])
            ->first();
    }

    private function resolveOrLinkCustomer(Booking $booking): ?Customer
    {
        if ($booking->customer_id !== null) {
            return Customer::query()->find($booking->customer_id);
        }
        try {
            return app(CustomerLinker::class)->attachToBooking($booking);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: ?Booking, 1: ?Customer, 2: ?Property}
     */
    private function pending(): array
    {
        $bookingId = session('guest_portal.pending_booking_id');
        $customerId = session('guest_portal.pending_customer_id');
        $propertyId = session('guest_portal.pending_property_id');
        if (! $bookingId || ! $customerId || ! $propertyId) {
            return [null, null, null];
        }
        return [
            Booking::query()->find($bookingId),
            Customer::query()->find($customerId),
            Property::query()->find($propertyId),
        ];
    }

    /**
     * Guest-initiated actions still need an Operator to attribute
     * things to (activity log, audit trails). Falls back to the
     * tenant's first Manager - good enough for MVP; a follow-up can
     * introduce a synthetic "System" operator per tenant.
     */
    private function systemOperator(Booking $booking): Operator
    {
        return Operator::query()
            ->where('tenant_id', $booking->tenant_id)
            ->where('role', \App\Enums\OperatorRole::Manager->value)
            ->orderBy('id')
            ->firstOrFail();
    }
}
