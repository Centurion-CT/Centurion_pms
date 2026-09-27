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
use App\Services\Marketplace\CreateStaysBooking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Guest email is optional at the marketplace checkout. Guest can
 * skip it + still complete the booking; a phone-derived sentinel
 * gets stamped on the booking row so bookings.guest_email (NOT NULL)
 * stays populated + downstream mail listeners can recognise a
 * "no email on file" address and skip sending.
 */
beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.secret_key', 'sk_master_key');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'optional-email',
        'paystack_subaccount_code' => 'ACCT_opt',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true, 'name' => 'Optional Email Suites',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders the email field as optional with a receipt hint', function (): void {
    // assertSee HTML-escapes by default; the copy contains an
    // apostrophe so pass escape=false to match the raw source.
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertSee('(optional)')
        ->assertSee('data-testid="checkout-email-hint"', false)
        ->assertSee("We'll send booking confirmation + receipt to this email if you add one.", false);
});

it('does NOT include the email input\'s required attribute in the markup', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug]);
    $html = $c->html();
    // Rip out just the email input line + assert the required
    // attribute is missing on it. Cheaper than a full DOM parse.
    preg_match('/<input[^>]*data-testid="checkout-guest-email"[^>]*>/', $html, $m);
    expect($m[0] ?? '')->not->toContain('required');
});

it('lets the Pay button light up when name + phone are set, email left blank', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('guestName', 'Ada N.')
        ->set('guestEmail', '')  // blank OK
        ->set('guestPhone', '+2348030000000');

    expect($c->instance()->isContactComplete())->toBeTrue();
    $c->assertDontSee('Fill your details to continue');
});

it('still rejects a mal-formed email when the guest DOES type one', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('guestName', 'Ada N.')
        ->set('guestPhone', '+2348030000000')
        ->set('guestEmail', 'not-an-email')
        ->call('pay')
        ->assertHasErrors('guestEmail');
});

it('CreateStaysBooking stamps a phone-derived noreceipt sentinel when email is blank', function (): void {
    Http::fake([
        'https://api.paystack.test/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'x', 'access_code' => 'y', 'reference' => 'z'],
        ]),
    ]);

    $result = app(CreateStaysBooking::class)->handle([
        'property_slug'      => $this->property->public_slug,
        'room_id'            => $this->room->id,
        'guest_name'         => 'Ada Nwokolo',
        'guest_email'        => '',
        'guest_phone'        => '+234 803 000 0000',
        'adults'             => 1, 'children' => 0,
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(4)->toDateString(),
        'payment_preference' => 'full',
    ], callbackUrl: 'http://central.test/callback');

    $booking = Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('booking_ref', $result['booking_ref'])
        ->firstOrFail();

    expect($booking->guest_email)->toStartWith('noreceipt-');
    expect($booking->guest_email)->toContain('2348030000000');   // digits from phone
    expect($booking->guest_email)->toEndWith('@stays.keverna.local');
});

it('CreateStaysBooking keeps the guest\'s email verbatim when they DID supply one', function (): void {
    Http::fake([
        'https://api.paystack.test/*' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'x', 'access_code' => 'y', 'reference' => 'z'],
        ]),
    ]);

    $result = app(CreateStaysBooking::class)->handle([
        'property_slug'      => $this->property->public_slug,
        'room_id'            => $this->room->id,
        'guest_name'         => 'Ada Nwokolo',
        'guest_email'        => 'ada@example.test',
        'guest_phone'        => '+2348030000000',
        'adults'             => 1, 'children' => 0,
        'checkin'            => Carbon::today()->addDays(3)->toDateString(),
        'checkout'           => Carbon::today()->addDays(4)->toDateString(),
        'payment_preference' => 'full',
    ], callbackUrl: 'http://central.test/callback');

    $booking = Booking::query()
        ->withoutGlobalScope(TenantScope::class)
        ->where('booking_ref', $result['booking_ref'])
        ->firstOrFail();

    expect($booking->guest_email)->toBe('ada@example.test');
    expect($booking->guest_email)->not->toStartWith('noreceipt-');
});
