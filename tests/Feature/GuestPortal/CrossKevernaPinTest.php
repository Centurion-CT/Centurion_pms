<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\GuestPortal\RoomPortal;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Customers\CustomerLinker;
use App\Services\GuestPortal\GuestPinService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * A guest's PIN is Keverna-wide — set once by phone number, works
 * at every Keverna property they're currently checked into.
 */
afterEach(fn () => Tenant::forgetCurrent());

it('a PIN set at Tenant A verifies at Tenant B for the same guest phone', function (): void {
    // Two entirely different tenants (different orgs on Keverna).
    $orgA = Tenant::factory()->create(['slug' => 'org-a']);
    $orgA->makeCurrent();
    Subscription::factory()->for($orgA)->trial()->create();
    $propA = Property::factory()->for($orgA)->create();
    $catA = RoomCategory::factory()->for($orgA)->for($propA)->create(['capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1]);
    $roomA = Room::factory()->for($orgA)->for($propA)->for($catA, 'category')->create();
    Operator::factory()->for($orgA)->create(['role' => OperatorRole::Manager]);
    $bookingA = Booking::factory()->for($orgA)->for($roomA)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Wanderer',
        'guest_phone' => '+2348012345678',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    // Sets a global PIN via the portal flow.
    Livewire::test(RoomPortal::class, ['qrSlug' => $roomA->qr_slug])
        ->set('phone', '08012345678')
        ->call('submitPhone')
        ->set('pin', '9271')
        ->set('pinConfirm', '9271')
        ->call('submitPinSet')
        ->assertSet('step', 'home');

    // A single row in the global PIN table.
    expect(DB::table('guest_global_pins')->count())->toBe(1);
    Tenant::forgetCurrent();

    // Same person, different Keverna operator entirely.
    $orgB = Tenant::factory()->create(['slug' => 'org-b']);
    $orgB->makeCurrent();
    Subscription::factory()->for($orgB)->trial()->create();
    $propB = Property::factory()->for($orgB)->create();
    $catB = RoomCategory::factory()->for($orgB)->for($propB)->create(['capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 50_000, 'min_nights' => 1]);
    $roomB = Room::factory()->for($orgB)->for($propB)->for($catB, 'category')->create();
    Operator::factory()->for($orgB)->create(['role' => OperatorRole::Manager]);
    Booking::factory()->for($orgB)->for($roomB)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Wanderer',
        'guest_phone' => '2348012345678', // different format on file, same person
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    // Same PIN, entered at a completely different tenant — flow
    // goes straight to pin_enter (skipping pin_set) because the
    // global lookup finds an existing PIN. Verifying it lands the
    // guest on the home tile.
    Livewire::test(RoomPortal::class, ['qrSlug' => $roomB->qr_slug])
        ->set('phone', '08012345678')
        ->call('submitPhone')
        ->assertSet('step', 'auth.pin_enter')
        ->set('pin', '9271')
        ->call('submitPinEnter')
        ->assertSet('step', 'home');
});

it('phone normalisation matches all Nigerian formats to the same PIN row', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'phone-norm']);
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $customer = \App\Models\Customer::create(['tenant_id' => $tenant->id, 'name' => 'X', 'phone' => '+2348012345678']);

    $svc = app(GuestPinService::class);
    $svc->set('+2348012345678', '1122');

    // All these should verify.
    expect($svc->verify('+2348012345678', '1122', $customer, $property))->toBeTrue();
    expect($svc->verify('2348012345678', '1122', $customer, $property))->toBeTrue();
    expect($svc->verify('08012345678', '1122', $customer, $property))->toBeTrue();
    expect($svc->verify('0801-234-5678', '1122', $customer, $property))->toBeTrue();
});

it('legacy per-property PIN still verifies + mirrors to global on success', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'legacy-mirror']);
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $customer = \App\Models\Customer::create(['tenant_id' => $tenant->id, 'name' => 'Legacy Guest', 'phone' => '+2348011111111']);

    // Simulate a guest who set their PIN before the cross-Keverna
    // refactor — only in the legacy per-property table, nothing in
    // the global table.
    $customer->setPinFor($property, '4321');
    expect(DB::table('guest_global_pins')->count())->toBe(0);

    $svc = app(GuestPinService::class);
    expect($svc->verify('08011111111', '4321', $customer, $property))->toBeTrue();

    // Mirrored to global — next scan anywhere uses the fast path.
    expect(DB::table('guest_global_pins')->count())->toBe(1);
});

it('auto-locks after 5 wrong PIN attempts', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'lock-test']);
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $customer = \App\Models\Customer::create(['tenant_id' => $tenant->id, 'name' => 'Locker', 'phone' => '+2347099999999']);

    $svc = app(GuestPinService::class);
    $svc->set('07099999999', '1234');

    // Five wrong tries.
    for ($i = 0; $i < 5; $i++) {
        expect($svc->verify('07099999999', '0000', $customer, $property))->toBeFalse();
    }

    // Correct PIN NOW fails because the row is locked for 15 min.
    expect($svc->verify('07099999999', '1234', $customer, $property))->toBeFalse();
});
