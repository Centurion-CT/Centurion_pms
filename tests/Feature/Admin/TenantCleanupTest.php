<?php

declare(strict_types=1);

use App\Actions\Admin\PurgeTenantBookings;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\CustomerLifecycle;
use App\Enums\OperatorRole;
use App\Livewire\Admin\Tenants\Cleanup as AdminTenantsCleanup;
use App\Models\Activity;
use App\Models\Admin;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Invoice;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Super-admin tool to purge test bookings from a tenant. Verifies:
 *   1. Action deletes booking + cascades + polymorphic activity + wallet txns.
 *   2. Non-super admins can't reach the page (403).
 *   3. Confirmation modal requires the exact tenant slug before firing.
 *   4. Bookings from OTHER tenants can't be reached by tampering with the id list.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'cleanup-target', 'name' => 'Cleanup Target Ltd']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    $this->superAdmin = Admin::factory()->super()->create();
    $this->supportAdmin = Admin::factory()->create(); // default role=support
});

afterEach(fn () => Tenant::forgetCurrent());

/* ---------- Action layer ---------- */

it('purges bookings + linked payments, charges, invoices, activity log and wallet txns', function (): void {
    $customer = Customer::create([
        'name' => 'Test Guest', 'email' => 'test@x.test', 'lifecycle' => CustomerLifecycle::Active,
    ]);
    app(WalletService::class)->topup(customer: $customer, amount: 30_000, description: 'top up');

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
        'total_amount' => 80_000,
    ]);

    $charge = BookingCharge::create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'created_by_operator_id' => $this->operator->id,
        'type' => BookingChargeType::FoodBeverage->value,
        'description' => 'dinner', 'amount' => 5_000,
    ]);
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'paystack_reference' => 'test-ref',
        'amount' => 20_000, 'method' => 'cash',
        'status' => \App\Enums\PaymentStatus::Succeeded,
        'type' => \App\Enums\PaymentType::Deposit,
        'paid_at' => Carbon::now(),
    ]);
    // Wallet transaction linked to the booking.
    app(WalletService::class)->spend(
        customer: $customer, amount: 15_000, description: 'test spend',
        links: ['booking_id' => $booking->id],
    );
    // Standalone activity log entry on the booking so we exercise the
    // polymorphic sweep.
    activity('booking')->performedOn($booking)->event('touched')->log('touched');

    // Sanity — the rows are there before the purge.
    expect(Booking::query()->find($booking->id))->not->toBeNull();
    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(1);
    expect(BookingCharge::query()->where('booking_id', $booking->id)->count())->toBe(1);
    expect(CustomerWalletTransaction::query()->withoutGlobalScope(TenantScope::class)
        ->where('booking_id', $booking->id)->count())->toBeGreaterThan(0);

    Tenant::forgetCurrent();
    $summary = app(PurgeTenantBookings::class)->handle(
        $this->tenant->fresh(), $this->superAdmin, [$booking->id],
    );

    expect($summary['bookings'])->toBe(1);
    expect($summary['payments_count'])->toBe(1);
    expect($summary['payments_amount'])->toBe(20_000);
    expect($summary['charges_count'])->toBe(1);

    $this->tenant->makeCurrent();
    expect(Booking::query()->find($booking->id))->toBeNull();
    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(0);
    expect(BookingCharge::query()->find($charge->id))->toBeNull();
    expect(CustomerWalletTransaction::query()->withoutGlobalScope(TenantScope::class)
        ->where('booking_id', $booking->id)->count())->toBe(0);
    // Polymorphic activity — subject rows tied to the booking are gone.
    expect(Activity::query()
        ->where('subject_type', Booking::class)
        ->where('subject_id', $booking->id)->count())->toBe(0);

    // A `tenant_bookings_purged` audit trail is written attributing the admin.
    $audit = Activity::query()
        ->where('event', 'tenant_bookings_purged')
        ->where('subject_type', Tenant::class)
        ->where('subject_id', $this->tenant->id)
        ->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect((int) $audit->causer_id)->toBe($this->superAdmin->id);
});

it('refuses to touch bookings that belong to a different tenant', function (): void {
    $other = Tenant::factory()->create(['slug' => 'other-house']);
    $otherProp = Property::factory()->for($other)->create();
    $otherCat = RoomCategory::factory()->for($other)->for($otherProp)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $otherRoom = Room::factory()->for($other)->for($otherProp)->create([
        'room_category_id' => $otherCat->id,
    ]);
    $foreignBooking = Booking::factory()->for($other)->for($otherRoom)->create([
        'guest_name' => 'Foreign Guest',
        'total_amount' => 50_000,
    ]);

    Tenant::forgetCurrent();
    expect(fn () => app(PurgeTenantBookings::class)->handle(
        $this->tenant->fresh(), $this->superAdmin, [$foreignBooking->id],
    ))->toThrow(RuntimeException::class);

    // The foreign booking survives.
    $other->makeCurrent();
    expect(Booking::query()->find($foreignBooking->id))->not->toBeNull();
});

/* ---------- Livewire wiring ---------- */

it('non-super admins get 403 hitting the cleanup page', function (): void {
    $response = $this->actingAs($this->supportAdmin, 'admin')
        ->get('http://admin.keverna.test/tenants/'.$this->tenant->slug.'/cleanup');
    $response->assertForbidden();
});

it('super admin can open the cleanup page + see the tenant bookings', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Junk Guest', 'total_amount' => 40_000,
    ]);
    Tenant::forgetCurrent();

    $response = $this->actingAs($this->superAdmin, 'admin')
        ->get('http://admin.keverna.test/tenants/'.$this->tenant->slug.'/cleanup');

    $response->assertOk();
    $response->assertSeeLivewire(AdminTenantsCleanup::class);
    $response->assertSee('Junk Guest');
});

it('purge refuses to fire when the confirmation slug does not match', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Still Here', 'total_amount' => 40_000,
    ]);
    Tenant::forgetCurrent();

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AdminTenantsCleanup::class, ['tenant' => $this->tenant])
        ->call('openConfirm', $booking->id)
        ->set('confirmSlug', 'wrong-slug')
        ->call('purge')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err');

    $this->tenant->makeCurrent();
    expect(Booking::query()->find($booking->id))->not->toBeNull();
});

it('purge fires when the correct slug is confirmed', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Bye', 'total_amount' => 40_000,
    ]);
    Tenant::forgetCurrent();

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AdminTenantsCleanup::class, ['tenant' => $this->tenant])
        ->call('openConfirm', $booking->id)
        ->set('confirmSlug', $this->tenant->slug)
        ->call('purge')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok');

    $this->tenant->makeCurrent();
    expect(Booking::query()->find($booking->id))->toBeNull();
});

it('openConfirm refuses to seed a booking from another tenant', function (): void {
    // URL-tampering guard at the Livewire boundary: if a support ticket
    // sends the admin a link with a foreign booking id, opening the
    // modal for it should fail rather than let them slug-retype and
    // delete a real property's row.
    $other = Tenant::factory()->create(['slug' => 'other-house-x']);
    $otherProp = Property::factory()->for($other)->create();
    $otherCat = RoomCategory::factory()->for($other)->for($otherProp)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $otherRoom = Room::factory()->for($other)->for($otherProp)->create([
        'room_category_id' => $otherCat->id,
    ]);
    $foreign = Booking::factory()->for($other)->for($otherRoom)->create([
        'guest_name' => 'Real Guest',
    ]);
    Tenant::forgetCurrent();

    Livewire::actingAs($this->superAdmin, 'admin')
        ->test(AdminTenantsCleanup::class, ['tenant' => $this->tenant])
        ->call('openConfirm', $foreign->id)
        ->assertSet('pendingId', null)
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err');

    $other->makeCurrent();
    expect(Booking::query()->find($foreign->id))->not->toBeNull();
});

/* ---------- Availability blocks ---------- */

it('cleans up availability blocks tied to the purged booking', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Block Owner',
    ]);
    AvailabilityBlock::create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->room->id,
        'booking_id' => $booking->id,
        'date' => Carbon::today()->toDateString(),
        'type' => 'booked',
        'reason' => 'reservation',
    ]);

    Tenant::forgetCurrent();
    app(PurgeTenantBookings::class)->handle($this->tenant->fresh(), $this->superAdmin, [$booking->id]);

    $this->tenant->makeCurrent();
    expect(AvailabilityBlock::query()->where('booking_id', $booking->id)->count())->toBe(0);
});

it('cleans up invoices tied to the purged booking', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Invoice Owner',
    ]);
    Invoice::create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'number' => 'INV-TEST-1',
        'issued_at' => Carbon::now(),
        'due_at' => Carbon::now()->addDays(7),
        'guest_name' => 'Invoice Owner',
        'subtotal' => 30_000,
        'total' => 30_000,
    ]);

    Tenant::forgetCurrent();
    app(PurgeTenantBookings::class)->handle($this->tenant->fresh(), $this->superAdmin, [$booking->id]);

    $this->tenant->makeCurrent();
    expect(Invoice::query()->where('booking_id', $booking->id)->count())->toBe(0);
});
