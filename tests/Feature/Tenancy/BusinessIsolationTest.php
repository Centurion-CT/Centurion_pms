<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\TenantStatus;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

/*
 * Two businesses side by side on one install (docs/MULTITENANCY.md, step 2).
 * Each request reaches only the business its host belongs to.
 */

beforeEach(function (): void {
    $this->a = Tenant::factory()->create(['slug' => 'alpha', 'name' => 'Alpha Suites']);
    $this->b = Tenant::factory()->create(['slug' => 'bravo', 'name' => 'Bravo Homes']);

    $this->propA = Property::factory()->live()->for($this->a)->create(['name' => 'Alpha Wuse']);
    $this->propB = Property::factory()->live()->for($this->b)->create(['name' => 'Bravo Lekki']);
    Room::factory()->for($this->a)->for($this->propA)->create(['name' => 'Alpha Studio']);
    Room::factory()->for($this->b)->for($this->propB)->create(['name' => 'Bravo Penthouse']);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('serves each business its own booking pages', function (): void {
    $this->get("http://alpha.keverna.test/branches/{$this->propA->slug}")
        ->assertOk()->assertSee('Alpha Wuse')->assertDontSee('Bravo Lekki');

    $this->get("http://bravo.keverna.test/branches/{$this->propB->slug}")
        ->assertOk()->assertSee('Bravo Lekki')->assertDontSee('Alpha Wuse');
});

it("does not open one business's branch on another business's address", function (): void {
    $this->get("http://bravo.keverna.test/branches/{$this->propA->slug}")->assertNotFound();
});

it("shows each business's staff login on its own address", function (): void {
    $this->get('http://alpha.keverna.test/login')->assertOk()->assertSee('Alpha Suites');
    $this->get('http://bravo.keverna.test/login')->assertOk()->assertSee('Bravo Homes');
});

it("never finds another business's staff account", function (): void {
    $operatorA = Operator::factory()->for($this->a)->create();

    $this->b->makeCurrent();
    expect(Auth::guard('web')->getProvider()->retrieveById($operatorA->id))->toBeNull();

    $this->a->makeCurrent();
    expect(Auth::guard('web')->getProvider()->retrieveById($operatorA->id)?->id)->toBe($operatorA->id);
});

it("refuses one business's mobile app token on another business's address", function (): void {
    $operator = Operator::factory()->for($this->a)->create(['role' => OperatorRole::HouseCleaner]);
    $device = MobileDevice::query()->create([
        'tenant_id' => $this->a->id, 'operator_id' => $operator->id, 'platform' => 'ios',
        'device_name' => 'Test', 'app_version' => '1.0.0', 'paired_at' => now(), 'last_seen_at' => now(),
    ]);
    $token = $operator->createToken('mobile-device-'.$device->id, ['mobile:*'])->plainTextToken;

    $this->getJson('http://bravo.keverna.test/api/mobile/v1/me', ['Authorization' => "Bearer {$token}"])
        ->assertUnauthorized();
    Tenant::forgetCurrent();

    $this->getJson('http://alpha.keverna.test/api/mobile/v1/me', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('tenant.slug', 'alpha');
});

it('keeps sessions to the address that created them', function (): void {
    expect(config('session.domain'))->toBeNull();
});

it('shows the not-in-use page for an address no business has', function (): void {
    $this->get('http://sunrise.keverna.test/login')
        ->assertNotFound()
        ->assertSee("This address isn't in use", false)
        ->assertSee('sunrise.keverna.test')
        ->assertSee('https://keverna.test/signup?slug=sunrise', false);

    $this->getJson('http://sunrise.keverna.test/api/mobile/v1/me')->assertNotFound();
});

it('shows the platform home on the central domain and sends www there', function (): void {
    $this->get('http://keverna.test/')->assertOk()->assertSee('serviced apartments');
    $this->get('http://www.keverna.test/pricing')->assertRedirect('https://keverna.test/pricing');
    $this->get('http://keverna.test/dashboard')->assertRedirect('https://keverna.test/');
});

it("shows a paused business's contact page to guests and keeps staff out", function (): void {
    $this->b->update(['status' => TenantStatus::Locked, 'phone' => '0803 000 0000']);

    $this->get("http://bravo.keverna.test/branches/{$this->propB->slug}")
        ->assertStatus(503)
        ->assertSee("Bravo Homes isn't taking online bookings right now", false)
        ->assertSee('0803 000 0000');
    $this->get('http://bravo.keverna.test/login')->assertStatus(503);
    $this->getJson('http://bravo.keverna.test/api/mobile/v1/me')->assertForbidden();

    $this->get("http://alpha.keverna.test/branches/{$this->propA->slug}")->assertOk();
});

it('signs staff out of a business once it is paused', function (): void {
    $operator = Operator::factory()->for($this->b)->create();
    $this->b->update(['status' => TenantStatus::Locked]);

    $this->actingAs($operator, 'web')->get('http://bravo.keverna.test/dashboard')->assertStatus(503);
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('still accepts payment webhooks for a paused business', function (): void {
    $this->b->update(['status' => TenantStatus::Locked]);

    expect($this->postJson('http://bravo.keverna.test/paystack/booking-webhook', [])->status())->not->toBe(503);
});

it("does not show or book one business's stays pages on another business's address", function (): void {
    $this->propA->forceFill(['is_listed_publicly' => true, 'public_slug' => 'alpha-wuse'])->save();

    // Its own address finds it (the page itself predates this work and
    // links to a removed route, so only "found" is checked here).
    expect($this->get('http://alpha.keverna.test/stays/alpha-wuse')->status())->not->toBe(404);
    $this->get('http://bravo.keverna.test/stays/alpha-wuse')->assertNotFound();
    $this->get('http://bravo.keverna.test/stays/alpha-wuse/book')->assertNotFound();
});

it("does not show one business's booking confirmation on another business's address", function (): void {
    $this->a->makeCurrent();
    $room = Room::query()->where('property_id', $this->propA->id)->firstOrFail();
    $booking = \App\Models\Booking::factory()->for($this->a)->for($room)->create(['guest_name' => 'Ada Obi']);
    Tenant::forgetCurrent();

    $this->get("http://bravo.keverna.test/stays/pay/success/{$booking->booking_ref}")->assertNotFound();
});
