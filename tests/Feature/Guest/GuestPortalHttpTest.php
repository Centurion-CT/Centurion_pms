<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Livewire\Guest\Cancel as GuestCancel;
use App\Livewire\Guest\PayBalance as GuestPayBalance;
use App\Livewire\Guest\RequestChange as GuestRequestChange;
use App\Livewire\Guest\Show as GuestShow;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Bookings\GuestPortalTokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'gphttp',
        'paystack_secret_key' => 'sk_op_tenant_key',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->live()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'booking_ref' => 'KV-ABC12345',
    ]);

    $issued = app(GuestPortalTokenService::class)->issue($this->booking);
    $this->token = $issued['plaintext'];
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

/* ---------- Show page (HTTP + Livewire) ---------- */

it('renders the show page when token is valid', function (): void {
    $response = $this->get('http://gphttp.keverna.test/guest/booking/'.$this->booking->booking_ref.'?token='.$this->token);

    $response->assertOk();
    $response->assertSeeLivewire(GuestShow::class);
    $response->assertSee($this->booking->booking_ref);
});

it('404s the show page with no token', function (): void {
    $response = $this->get('http://gphttp.keverna.test/guest/booking/'.$this->booking->booking_ref);

    $response->assertNotFound();
});

it('404s the show page with a wrong token', function (): void {
    $response = $this->get('http://gphttp.keverna.test/guest/booking/'.$this->booking->booking_ref.'?token=wrong');

    $response->assertNotFound();
});

it('404s the show page with a token issued for a different tenant', function (): void {
    Tenant::forgetCurrent();
    $other = Tenant::factory()->create(['slug' => 'gphttp-other', 'paystack_secret_key' => 'sk_other']);
    $other->makeCurrent();
    $otherProp = Property::factory()->live()->for($other)->create();
    $otherRoom = Room::factory()->for($other)->for($otherProp)->create();
    $otherBooking = Booking::factory()->for($other)->for($otherRoom)->confirmed()->create();
    $otherToken = app(GuestPortalTokenService::class)->issue($otherBooking)['plaintext'];
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    // Hitting our tenant's host with a foreign tenant's booking_ref + token.
    $response = $this->get('http://gphttp.keverna.test/guest/booking/'.$otherBooking->booking_ref.'?token='.$otherToken);

    $response->assertNotFound();
});

/* ---------- Cancel flow ---------- */

it('cancels a booking when guest confirms via Livewire', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'booking_ref' => 'KV-CANCEL01',
        'checkin_date' => '2030-08-20',
        'checkout_date' => '2030-08-22',
    ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2030-08-20', 'booking_id' => $booking->id]);
    $tok = app(GuestPortalTokenService::class)->issue($booking)['plaintext'];

    Livewire::withQueryParams(['token' => $tok])
        ->test(GuestCancel::class, ['bookingRef' => $booking->booking_ref])
        ->call('confirm')
        ->assertHasNoErrors();

    expect($booking->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
    expect(AvailabilityBlock::where('booking_id', $booking->id)->count())->toBe(0);
});

/* ---------- Balance pay flow ---------- */

it('initiates a balance payment from the portal and redirects to Paystack', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/BAL-PORTAL',
                'access_code' => 'ac_bal', 'reference' => 'kev_bal_portal',
            ],
        ]),
    ]);

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create([
        'total_amount' => 60_000,
        'deposit_amount' => 30_000,
        'balance_due' => 30_000,
    ]);
    $tok = app(GuestPortalTokenService::class)->issue($booking)['plaintext'];

    Livewire::withQueryParams(['token' => $tok])
        ->test(GuestPayBalance::class, ['bookingRef' => $booking->booking_ref])
        ->call('pay')
        ->assertRedirect('https://checkout.paystack.test/BAL-PORTAL');
});

it('surfaces a friendly error if balance is paid already', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'balance_due' => 0,
    ]);
    $tok = app(GuestPortalTokenService::class)->issue($booking)['plaintext'];

    $component = Livewire::withQueryParams(['token' => $tok])
        ->test(GuestPayBalance::class, ['bookingRef' => $booking->booking_ref])
        ->call('pay');

    expect($component->get('errorMessage'))->not->toBeNull();
});

/* ---------- Request-change flow ---------- */

it('records a date-change request when the form is submitted', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-08-10',
        'checkout_date' => '2030-08-12',
    ]);
    $tok = app(GuestPortalTokenService::class)->issue($booking)['plaintext'];

    Livewire::withQueryParams(['token' => $tok])
        ->test(GuestRequestChange::class, ['bookingRef' => $booking->booking_ref])
        ->set('requested_checkin', '2030-09-15')
        ->set('requested_checkout', '2030-09-17')
        ->set('guest_note', 'work trip')
        ->call('submit')
        ->assertHasNoErrors();

    $req = BookingChangeRequest::query()->where('booking_id', $booking->id)->first();
    expect($req)->not->toBeNull();
    expect($req->status)->toBe(BookingChangeRequest::STATUS_PENDING);
    expect($req->guest_note)->toBe('work trip');
});

it('rejects an out-of-order date-change form submission with a field error', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-08-01 10:00:00'));
    $tok = app(GuestPortalTokenService::class)->issue($this->booking)['plaintext'];

    Livewire::withQueryParams(['token' => $tok])
        ->test(GuestRequestChange::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('requested_checkin', '2030-09-10')
        ->set('requested_checkout', '2030-09-10')
        ->call('submit')
        ->assertHasErrors('requested_checkout');

    expect(BookingChangeRequest::count())->toBe(0);
});
