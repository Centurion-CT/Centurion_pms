<?php

declare(strict_types=1);

use App\Enums\CustomerLifecycle;
use App\Livewire\Central\Reservations;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\IdentityAccount;
use App\Models\IdentityGuestLink;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Identity\GuestReservationLookup;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * BookingFactory's default `tenant_id => Tenant::factory()` creates a
 * fresh tenant on every call, overriding makeCurrent() context. Force
 * tenant_id + room_id explicitly so each booking actually lands on the
 * right tenant, and reuse a Room created in-context.
 */
function createBookingOn(Tenant $tenant, Customer $customer, string $checkin, ?Room $room = null): Booking
{
    $tenant->makeCurrent();
    $room ??= Room::factory()->create();
    $booking = Booking::factory()->confirmed()->create([
        'tenant_id' => $tenant->id,
        'room_id' => $room->id,
        'customer_id' => $customer->id,
        'checkin_date' => $checkin,
        'checkout_date' => date('Y-m-d', strtotime($checkin.' +2 days')),
    ]);
    Tenant::forgetCurrent();

    return $booking;
}

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/**
 * Create identity linked to N tenants, each with their own customer and
 * a set of bookings. Returns [identity, [tenantSlug => [customer, bookings]]].
 */
function makeMultiTenantGuest(array $spec): IdentityAccount
{
    $identity = IdentityAccount::create([
        'email' => 'multi-'.uniqid().'@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Multi',
    ]);

    foreach ($spec as $slug => $checkins) {
        $tenant = Tenant::factory()->create(['slug' => $slug, 'name' => ucfirst($slug).' Residences']);
        $tenant->makeCurrent();
        $customer = Customer::create([
            'name' => 'Multi',
            'email' => $identity->email,
            'lifecycle' => CustomerLifecycle::Prospect,
        ]);
        $room = Room::factory()->create();
        Tenant::forgetCurrent();

        foreach ($checkins as $checkin) {
            createBookingOn($tenant, $customer, $checkin, $room);
        }

        IdentityGuestLink::create([
            'identity_account_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'claimed_at' => now(),
        ]);
    }

    return $identity;
}

it('aggregates bookings across tenants and buckets them into upcoming / past', function (): void {
    $identity = makeMultiTenantGuest([
        'lookup-a' => [now()->addDays(10)->toDateString(), now()->subDays(20)->toDateString()],
        'lookup-b' => [now()->addDays(3)->toDateString()],
        'lookup-c' => [now()->subDays(50)->toDateString()],
    ]);

    ['upcoming' => $upcoming, 'past' => $past] = (new GuestReservationLookup)->forIdentity($identity);

    expect($upcoming)->toHaveCount(2);
    expect($past)->toHaveCount(2);

    // Upcoming sorted by check-in ascending (soonest first).
    expect($upcoming->first()->tenant->slug)->toBe('lookup-b');
    // Past sorted by check-in descending (most recent first).
    expect($past->first()->tenant->slug)->toBe('lookup-a');
});

it('returns empty collections when the identity has no guest links', function (): void {
    $identity = IdentityAccount::create([
        'email' => 'empty@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Empty',
    ]);

    ['upcoming' => $upcoming, 'past' => $past] = (new GuestReservationLookup)->forIdentity($identity);

    expect($upcoming)->toBeEmpty();
    expect($past)->toBeEmpty();
});

it('renders the reservations for the signed-in identity', function (): void {
    $identity = makeMultiTenantGuest([
        'ui-a' => [now()->addDays(5)->toDateString()],
    ]);

    Livewire::actingAs($identity, 'identity')
        ->test(Reservations::class)
        ->assertOk()
        ->assertSee('Ui-a Residences')
        ->assertViewHas('upcoming', fn ($collection) => $collection->count() === 1);
});

it('shows the empty-state copy when the identity has no reservations', function (): void {
    $identity = IdentityAccount::create([
        'email' => 'ui-empty@x.test',
        'password' => Hash::make('pw'),
        'name' => 'UiEmpty',
    ]);

    Livewire::actingAs($identity, 'identity')
        ->test(Reservations::class)
        ->assertOk()
        ->assertSee('Nothing here yet.');
});

it('does not surface reservations from tenants the identity has not claimed', function (): void {
    // Identity has a link on lookup-only-a; there are bookings on
    // lookup-unlinked-b under the same customer email but with no link.
    $identity = IdentityAccount::create([
        'email' => 'iso@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Iso',
    ]);

    $tenantLinked = Tenant::factory()->create(['slug' => 'iso-linked']);
    $tenantUnlinked = Tenant::factory()->create(['slug' => 'iso-unlinked']);

    $tenantLinked->makeCurrent();
    $linkedCustomer = Customer::create(['name' => 'Iso', 'email' => 'iso@x.test', 'lifecycle' => CustomerLifecycle::Prospect]);
    Tenant::forgetCurrent();
    createBookingOn($tenantLinked, $linkedCustomer, now()->addDay()->toDateString());

    $tenantUnlinked->makeCurrent();
    $strangerCustomer = Customer::create(['name' => 'Iso', 'email' => 'iso@x.test', 'lifecycle' => CustomerLifecycle::Prospect]);
    Tenant::forgetCurrent();
    createBookingOn($tenantUnlinked, $strangerCustomer, now()->addDay()->toDateString());

    // Only link the first tenant — the second stays unclaimed.
    IdentityGuestLink::create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $tenantLinked->id,
        'customer_id' => $linkedCustomer->id,
        'claimed_at' => now(),
    ]);

    ['upcoming' => $upcoming] = (new GuestReservationLookup)->forIdentity($identity);

    expect($upcoming)->toHaveCount(1);
    expect($upcoming->first()->tenant_id)->toBe($tenantLinked->id);
});
