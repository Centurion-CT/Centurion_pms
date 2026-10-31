<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysBookCheckout;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Livewire checkout smoke tests. Deep availability + pricing paths
 * live under CreateStaysBookingTest; this file pins that the wire
 * component boots for a listed slug, exposes the room picker + form,
 * and rejects the wrong slugs cleanly (404).
 */
beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.secret_key', 'sk_master_key');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'checkout-smoke',
        'paystack_subaccount_code' => 'ACCT_x',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true,
        'name' => 'Checkout Smoke Property',
    ]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active]);
    // The business stays current: these pages only run on its own address.
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders the checkout for a listed slug', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertOk()
        ->assertSee('Confirm and pay')
        ->assertSee('Your room')
        ->assertSee('Checkout Smoke Property');
});

it('404s for an off-market slug', function (): void {
    $this->property->forceFill(['is_listed_publicly' => false])->save();

    $this->get(route('marketplace.stays.book', ['slug' => $this->property->public_slug]))
        ->assertNotFound();
});

it('404s for a property with no active rooms', function (): void {
    $this->room->forceFill(['status' => RoomStatus::Inactive])->save();

    $this->get(route('marketplace.stays.book', ['slug' => $this->property->public_slug]))
        ->assertNotFound();
});

it('validates required guest info before hitting Paystack', function (): void {
    // Name + phone are required; email is optional (guest can skip
    // it + still book, receipts just don't go out over email). If
    // supplied, email must be email-shaped, tested separately.
    Http::fake();

    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('guestName', '')
        ->set('guestEmail', '')
        ->set('guestPhone', '')
        ->call('pay')
        ->assertHasErrors(['guestName', 'guestPhone'])
        ->assertHasNoErrors('guestEmail');

    Http::assertNothingSent();
});

it('rejects checkout-before-checkin', function (): void {
    Http::fake();

    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(5)->toDateString())
        ->set('checkout', Carbon::today()->addDays(2)->toDateString())
        ->set('guestName', 'X')
        ->set('guestEmail', 'x@example.test')
        ->set('guestPhone', '+2348030000000')
        ->call('pay')
        ->assertHasErrors(['checkout']);

    Http::assertNothingSent();
});

it('redirects to Paystack on a valid submit', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/live-flow',
                'access_code' => 'ac_x', 'reference' => 'kev_stays_x',
            ],
        ]),
    ]);

    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(5)->toDateString())
        ->set('guestName', 'Ada N')
        ->set('guestEmail', 'ada@example.test')
        ->set('guestPhone', '+2348030000000')
        ->call('pay')
        ->assertRedirect('https://checkout.paystack.test/live-flow');

    expect(Booking::query()->withoutGlobalScope(TenantScope::class)->count())->toBeGreaterThan(0);
});
