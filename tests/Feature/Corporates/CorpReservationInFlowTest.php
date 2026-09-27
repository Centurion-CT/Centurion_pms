<?php

declare(strict_types=1);

use App\Enums\CorporateMemberStatus;
use App\Enums\CorporateRateScope;
use App\Enums\CorporateRateType;
use App\Enums\CorporateReservationStatus;
use App\Enums\CorporateStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\CorporateRate;
use App\Models\CorporateReservation;
use App\Models\CorporateReservationCategory;
use App\Models\CorporateReservationMember;
use App\Models\CorporateWallet;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Corporates\CorporatePricingResolver;
use Illuminate\Support\Carbon;

/**
 * The three moving parts of a corp reservation in the booking flow:
 *  1. CorporatePricingResolver honours rate.min_nights (LoS gating).
 *  2. CorporateReservation::activeForMemberInWindow finds a live
 *     reservation for a given corp + member + property + dates.
 *  3. Full flow: a booking created via the trait picks up the linkage,
 *     the reservation is honoured (assigned_booking_id stamped).
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'corp-flow']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'accepts_corporates' => true,
    ]);
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 50_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->cat, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '101']);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->corp = Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'country' => 'NG',
        'accepts_reservations' => true,
        'created_by_tenant_id' => $this->tenant->id,
    ]);
    $this->member = CorporateMember::query()->create([
        'corporate_id' => $this->corp->id,
        'name' => 'Ada Traveller',
        'email' => 'ada@acme.test',
        'phone' => '+2348011111111',
        'role' => 'member',
        'status' => CorporateMemberStatus::Active->value,
    ]);
    CorporateWallet::query()->create([
        'corporate_id' => $this->corp->id,
        'balance_naira' => 5_000_000,
        'currency' => 'NGN',
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 12, 10, 0, 0));
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

// ── CorporatePricingResolver: min_nights gating ───────────────────

it('LoS rate does NOT apply when stay is shorter than min_nights', function (): void {
    CorporateRate::query()->create([
        'corporate_id' => $this->corp->id,
        'scope' => CorporateRateScope::Global->value,
        'scope_id' => null,
        'rate_type' => CorporateRateType::PercentOff->value,
        'rate_value' => 20,
        'min_nights' => 5,
    ]);
    $rate = app(CorporatePricingResolver::class)->forRoom(
        $this->corp->refresh(),
        $this->room,
        nights: 2,
    );
    expect($rate)->toBeNull();
});

it('LoS rate applies when stay meets min_nights', function (): void {
    CorporateRate::query()->create([
        'corporate_id' => $this->corp->id,
        'scope' => CorporateRateScope::Global->value,
        'scope_id' => null,
        'rate_type' => CorporateRateType::PercentOff->value,
        'rate_value' => 20,
        'min_nights' => 5,
    ]);
    $rate = app(CorporatePricingResolver::class)->forRoom(
        $this->corp->refresh(),
        $this->room,
        nights: 5,
    );
    expect($rate)->not->toBeNull();
    expect((int) $rate['off_per_unit'])->toBe(10_000);   // 20% off 50k
});

it('unconditional category rate still applies regardless of nights', function (): void {
    CorporateRate::query()->create([
        'corporate_id' => $this->corp->id,
        'scope' => CorporateRateScope::RoomCategory->value,
        'scope_id' => $this->cat->id,
        'rate_type' => CorporateRateType::PercentOff->value,
        'rate_value' => 10,
        'min_nights' => null,
    ]);
    $rate = app(CorporatePricingResolver::class)->forRoom(
        $this->corp->refresh(),
        $this->room,
        nights: 1,
    );
    expect($rate)->not->toBeNull();
    expect((int) $rate['off_per_unit'])->toBe(5_000);
});

// ── CorporateReservation::activeForMemberInWindow ─────────────────

it('finds a covering reservation for the corp + member', function (): void {
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id' => $this->property->id,
        'starts_at' => Carbon::today()->addDay(),
        'ends_at' => Carbon::today()->addDays(4),
        'status' => CorporateReservationStatus::Pending->value,
    ]);
    CorporateReservationCategory::query()->create([
        'corporate_reservation_id' => $r->id,
        'room_category_id' => $this->cat->id,
        'rooms_count' => 2,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $this->member->id,
    ]);

    $match = CorporateReservation::activeForMemberInWindow(
        corporateId: $this->corp->id,
        memberId: $this->member->id,
        propertyId: $this->property->id,
        checkin: Carbon::today()->addDays(2),
        checkout: Carbon::today()->addDays(3),
    );
    expect($match)->not->toBeNull();
    expect($match->id)->toBe($r->id);
});

it('returns null when the window does not overlap the requested stay', function (): void {
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id' => $this->property->id,
        'starts_at' => Carbon::today()->addDays(10),
        'ends_at' => Carbon::today()->addDays(14),
        'status' => CorporateReservationStatus::Pending->value,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $this->member->id,
    ]);

    $match = CorporateReservation::activeForMemberInWindow(
        corporateId: $this->corp->id,
        memberId: $this->member->id,
        propertyId: $this->property->id,
        checkin: Carbon::today()->addDays(2),
        checkout: Carbon::today()->addDays(3),
    );
    expect($match)->toBeNull();
});

it('returns null when the member is not on the reservation roster', function (): void {
    // Create a reservation but assign a different member.
    $other = CorporateMember::query()->create([
        'corporate_id' => $this->corp->id,
        'name' => 'Other Guy', 'email' => 'other@acme.test',
        'role' => 'member',
        'status' => CorporateMemberStatus::Active->value,
    ]);
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id' => $this->property->id,
        'starts_at' => Carbon::today()->addDay(),
        'ends_at' => Carbon::today()->addDays(4),
        'status' => CorporateReservationStatus::Pending->value,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $other->id,
    ]);

    $match = CorporateReservation::activeForMemberInWindow(
        corporateId: $this->corp->id,
        memberId: $this->member->id,   // the OTHER member — not on roster
        propertyId: $this->property->id,
        checkin: Carbon::today()->addDays(2),
        checkout: Carbon::today()->addDays(3),
    );
    expect($match)->toBeNull();
});

// ── upcomingForMember (discovery hint when dates aren't set) ────

it('upcomingForMember finds a future reservation regardless of a specific window', function (): void {
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id' => $this->property->id,
        'starts_at' => Carbon::today()->addDays(3),
        'ends_at' => Carbon::today()->addDays(5),
        'status' => CorporateReservationStatus::Pending->value,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $this->member->id,
    ]);

    $match = CorporateReservation::upcomingForMember(
        corporateId: $this->corp->id,
        memberId: $this->member->id,
        propertyId: $this->property->id,
    );
    expect($match)->not->toBeNull();
    expect($match->id)->toBe($r->id);
});

it('upcomingForMember excludes reservations that have already ended', function (): void {
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id' => $this->property->id,
        'starts_at' => Carbon::today()->subDays(5),
        'ends_at' => Carbon::today()->subDay(),
        'status' => CorporateReservationStatus::Pending->value,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $this->member->id,
    ]);

    $match = CorporateReservation::upcomingForMember(
        corporateId: $this->corp->id,
        memberId: $this->member->id,
        propertyId: $this->property->id,
    );
    expect($match)->toBeNull();
});

// ── Auto-fill + category filter on quick-reserve trait ──────────

it('auto-fills reserve dates when a corp reservation is detected + dates are empty', function (): void {
    // Seed a reservation whose window is Sep 14 → Sep 17 (into the
    // future relative to the frozen "now" of Sep 12).
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id' => $this->property->id,
        'starts_at' => Carbon::today()->addDays(2),
        'ends_at' => Carbon::today()->addDays(5),
        'status' => CorporateReservationStatus::Pending->value,
    ]);
    CorporateReservationCategory::query()->create([
        'corporate_reservation_id' => $r->id,
        'room_category_id' => $this->cat->id,
        'rooms_count' => 2,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $this->member->id,
    ]);
    // Attach the tenant Property to PropertyContext so the trait can
    // resolve the branch without a picked room.
    // PropertyContext resolves from operator.current_property_id — set
    // it directly so the trait's fallback branch resolver picks the
    // right property without a full switcher round-trip.
    $this->manager->forceFill(['current_property_id' => $this->property->id])->save();

    // Instantiate a stub Livewire component that uses the trait.
    $host = new class extends \Livewire\Component {
        use \App\Livewire\Concerns\HasQuickReserveForm;
        public function render(): string { return '<div></div>'; }
    };

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test($host::class)
        // Empty check-in / check-out before phone entry
        ->assertSet('reserveCheckin', '')
        ->assertSet('reserveCheckout', '')
        ->set('reservePhone', '+2348011111111')
        // Dates should now be auto-filled from the reservation.
        ->assertSet('reserveCheckin', $r->starts_at->toDateString())
        ->assertSet('reserveCheckout', $r->ends_at->toDateString())
        // And because the dates now cover the window, the toggle
        // flips on + the "is covered" flag turns true.
        ->assertSet('reserveUseCorporateReservation', true)
        ->assertSet('reserveCorpReservationIsCovered', true);
});

it('reservePricingPreview surfaces the corporate discount as a distinct line', function (): void {
    // Global 10% off for the corp — no min_nights, so it applies at
    // any length. The preview must expose corporateDiscountOff so
    // the price-breakdown partial can render it.
    CorporateRate::query()->create([
        'corporate_id' => $this->corp->id,
        'scope' => CorporateRateScope::Global->value,
        'scope_id' => null,
        'rate_type' => CorporateRateType::PercentOff->value,
        'rate_value' => 10,
    ]);
    // Attach the tenant Property to PropertyContext so the trait can
    // resolve the branch without a picked room.
    $this->manager->forceFill(['current_property_id' => $this->property->id])->save();

    $host = new class extends \Livewire\Component {
        use \App\Livewire\Concerns\HasQuickReserveForm;
        public function render(): string { return '<div></div>'; }
    };

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test($host::class)
        ->set('reservePhone', '+2348011111111')   // triggers corp match
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->addDay()->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDays(3)->toDateString())
        ->tap(function ($t): void {
            $preview = $t->instance()->reservePricingPreview();
            expect($preview)->not->toBeNull();
            // 2 nights × 50k × 10% = 10k saving.
            expect((int) $preview['corporateDiscountOff'])->toBe(10_000);
            expect($preview['corporateDiscountLabel'])->toContain('Acme');
            expect($preview['corporateDiscountLabel'])->toContain('10% off');
        });
});

it('refreshReserveEstimate zeros the manual discount when a corp rate is applied', function (): void {
    CorporateRate::query()->create([
        'corporate_id' => $this->corp->id,
        'scope' => CorporateRateScope::Global->value,
        'scope_id' => null,
        'rate_type' => CorporateRateType::PercentOff->value,
        'rate_value' => 10,
    ]);
    $this->manager->forceFill(['current_property_id' => $this->property->id])->save();

    $host = new class extends \Livewire\Component {
        use \App\Livewire\Concerns\HasQuickReserveForm;
        public function render(): string { return '<div></div>'; }
    };

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test($host::class)
        // Operator picked a manual discount first (before matching corp).
        ->set('reserveManualDiscountPercent', 15.0)
        ->set('reserveManualDiscountFixedInput', 5_000)
        // Corp match kicks in.
        ->set('reservePhone', '+2348011111111')
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveCheckin', Carbon::today()->addDay()->toDateString())
        ->set('reserveCheckout', Carbon::today()->addDays(3)->toDateString())
        // Manual state should now be reset — the section is hidden in
        // the blade so leaving it non-zero would silently post at
        // submit.
        ->assertSet('reserveManualDiscountPercent', 0.0)
        ->assertSet('reserveManualDiscountFixedInput', 0);
});

it('phone match auto-defaults reservePaymentStatus to corp_invoice', function (): void {
    $this->manager->forceFill(['current_property_id' => $this->property->id])->save();

    $host = new class extends \Livewire\Component {
        use \App\Livewire\Concerns\HasQuickReserveForm;
        public function render(): string { return '<div></div>'; }
    };

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test($host::class)
        ->assertSet('reservePaymentStatus', 'confirmed')
        ->set('reservePhone', '+2348011111111')
        ->assertSet('reservePaymentStatus', 'corp_invoice');
});

it('phone typed to a NON-corp resets a stale corp_invoice pick', function (): void {
    $this->manager->forceFill(['current_property_id' => $this->property->id])->save();
    $host = new class extends \Livewire\Component {
        use \App\Livewire\Concerns\HasQuickReserveForm;
        public function render(): string { return '<div></div>'; }
    };

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test($host::class)
        ->set('reservePhone', '+2348011111111')
        ->assertSet('reservePaymentStatus', 'corp_invoice')
        // Operator retypes to a phone that's on no corp roster.
        ->set('reservePhone', '08099999999')
        ->assertSet('reservePaymentStatus', 'confirmed');
});

it('reserveAllowedCategoryIds returns the reservation categories when toggle is on', function (): void {
    $secondCat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Suite', 'base_rate' => 100_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id' => $this->property->id,
        'starts_at' => Carbon::today()->addDay(),
        'ends_at' => Carbon::today()->addDays(3),
        'status' => CorporateReservationStatus::Pending->value,
    ]);
    // Allocate ONLY the Standard category, not the Suite.
    CorporateReservationCategory::query()->create([
        'corporate_reservation_id' => $r->id,
        'room_category_id' => $this->cat->id,
        'rooms_count' => 1,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $this->member->id,
    ]);
    // PropertyContext resolves from operator.current_property_id — set
    // it directly so the trait's fallback branch resolver picks the
    // right property without a full switcher round-trip.
    $this->manager->forceFill(['current_property_id' => $this->property->id])->save();

    $host = new class extends \Livewire\Component {
        use \App\Livewire\Concerns\HasQuickReserveForm;
        public function render(): string { return '<div></div>'; }
    };

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test($host::class)
        ->set('reservePhone', '+2348011111111')
        // Dates got auto-filled, toggle on, so allowed IDs = [Standard only].
        ->tap(function ($t) use ($secondCat): void {
            $allowed = $t->instance()->reserveAllowedCategoryIds();
            expect($allowed)->toBe([$this->cat->id]);
            expect($allowed)->not->toContain($secondCat->id);

            // Filter room list to allocated categories.
            $rooms = collect([
                $this->room,   // Standard — allowed
                Room::factory()->for($this->tenant)->for($this->property)
                    ->for($secondCat, 'category')
                    ->create(['status' => RoomStatus::Active, 'room_number' => '999']),
            ]);
            $filtered = $t->instance()->availableRoomsForReserve($rooms);
            expect($filtered->pluck('id')->all())->toBe([$this->room->id]);
        });
});

it('quick-reserve submit stamps assigned_booking_id + flips Pending → Active', function (): void {
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id'  => $this->property->id,
        'starts_at'    => Carbon::today()->addDay(),
        'ends_at'      => Carbon::today()->addDays(3),
        'status'       => CorporateReservationStatus::Pending->value,
    ]);
    CorporateReservationCategory::query()->create([
        'corporate_reservation_id' => $r->id,
        'room_category_id' => $this->cat->id,
        'rooms_count' => 1,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $this->member->id,
    ]);
    $this->manager->forceFill(['current_property_id' => $this->property->id])->save();

    $host = new class extends \Livewire\Component {
        use \App\Livewire\Concerns\HasQuickReserveForm;
        public function render(): string { return '<div></div>'; }
    };

    \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test($host::class)
        ->set('reservePhone', '+2348011111111')          // corp match + dates auto-fill
        ->set('reserveRoomId', $this->room->id)
        ->set('reserveAdults', 1)
        ->call('submitReserve');

    // The parent reservation flipped Pending → Active.
    expect($r->refresh()->status)->toBe(CorporateReservationStatus::Active);

    // The member row got assigned_booking_id.
    $slot = CorporateReservationMember::query()
        ->where('corporate_reservation_id', $r->id)
        ->where('corporate_member_id', $this->member->id)
        ->first();
    expect($slot->assigned_booking_id)->not->toBeNull();
});

it('attachToBooking skips the wallet hold when deferWalletHold is true', function (): void {
    // Zero out the wallet to prove we don't touch it in defer mode —
    // in the non-defer path this would throw insufficient-funds and
    // roll the booking transaction back.
    \App\Models\CorporateWallet::query()->where('corporate_id', $this->corp->id)
        ->update(['balance_naira' => 0]);

    $booking = \App\Models\Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Ada',
        'guest_email' => '',
        'guest_phone' => '+2348011111111',
        'total_amount' => 100_000,
        'balance_due' => 100_000,
        'deposit_amount' => 0,
        'nights' => 2,
        'corporate_id' => null,
        'corporate_member_id' => null,
    ]);

    app(\App\Services\Corporates\CorporateBookingService::class)
        ->attachToBooking($booking, deferWalletHold: true);

    $booking->refresh();
    // Corp stamped, discount not applied (no rate seeded), no hold.
    expect((int) $booking->corporate_id)->toBe($this->corp->id);
    expect((int) $booking->corporate_member_id)->toBe($this->member->id);
    expect(\App\Models\CorporateWalletTransaction::query()
        ->where('corporate_id', $this->corp->id)
        ->count())->toBe(0);
});

it('returns null when the reservation is Cancelled', function (): void {
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id' => $this->property->id,
        'starts_at' => Carbon::today()->addDay(),
        'ends_at' => Carbon::today()->addDays(4),
        'status' => CorporateReservationStatus::Cancelled->value,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $this->member->id,
    ]);

    $match = CorporateReservation::activeForMemberInWindow(
        corporateId: $this->corp->id,
        memberId: $this->member->id,
        propertyId: $this->property->id,
        checkin: Carbon::today()->addDays(2),
        checkout: Carbon::today()->addDays(3),
    );
    expect($match)->toBeNull();
});
