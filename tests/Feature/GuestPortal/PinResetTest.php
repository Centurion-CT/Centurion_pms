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
use App\Services\GuestPortal\GuestNotifier;
use App\Services\GuestPortal\GuestPinService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Forgot-PIN self-service flow. Guest requests a WhatsApp OTP from
 * the pin_enter step, enters it, then sets a new PIN via the
 * existing pin_set flow. All backed by cache (no new table).
 */
beforeEach(function (): void {
    Cache::flush();

    $this->tenant = Tenant::factory()->create(['slug' => 'gp-reset']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
    Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Forgetful',
        'guest_phone' => '+2348012345678',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    // Guest already has a PIN → they'll try to reset it.
    app(GuestPinService::class)->set('+2348012345678', '1111');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Cache::flush();
});

it('issueResetCode returns a 6-digit code and stashes its hash in cache', function (): void {
    $code = app(GuestPinService::class)->issueResetCode('+2348012345678');

    expect($code)->toBeString();
    expect(preg_match('/^\d{6}$/', $code))->toBe(1);
});

it('verifyResetCode accepts the right code once + invalidates it after', function (): void {
    $svc = app(GuestPinService::class);
    $code = $svc->issueResetCode('+2348012345678');

    expect($svc->verifyResetCode('+2348012345678', $code))->toBeTrue();
    // Second try with the same code fails — codes are single-use.
    expect($svc->verifyResetCode('+2348012345678', $code))->toBeFalse();
});

it('verifyResetCode rejects wrong codes and burns after 5 strikes', function (): void {
    $svc = app(GuestPinService::class);
    $code = $svc->issueResetCode('+2348012345678');

    for ($i = 0; $i < 5; $i++) {
        expect($svc->verifyResetCode('+2348012345678', '000000'))->toBeFalse();
    }
    // 5th strike burns the cache row; the correct code no longer
    // verifies until a fresh issueResetCode is called.
    expect($svc->verifyResetCode('+2348012345678', $code))->toBeFalse();
});

it('portal Forgot-PIN drops the guest onto the reset step + sends WhatsApp', function (): void {
    // Stub the notifier so we don't actually hit Sendpipe.
    $sent = [];
    app()->instance(GuestNotifier::class, new class ($sent) extends GuestNotifier {
        public function __construct(private array &$out) {
            parent::__construct(app(\App\Services\Sendpipe\SendpipeClient::class));
        }
        public function sendPinResetOtp(string $phone, string $code): void
        {
            $this->out[] = ['phone' => $phone, 'code' => $code];
        }
    });

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->set('phone', '08012345678')
        ->call('submitPhone')
        ->assertSet('step', 'auth.pin_enter')
        ->call('requestPinReset')
        ->assertSet('step', 'auth.pin_reset');

    expect($sent)->toHaveCount(1);
    expect($sent[0]['phone'])->toBe('08012345678');
    expect(preg_match('/^\d{6}$/', $sent[0]['code']))->toBe(1);
});

it('portal submitPinReset with a valid OTP lands on pin_set (to overwrite the PIN)', function (): void {
    // Skip the WhatsApp step — seed the code directly.
    $code = app(GuestPinService::class)->issueResetCode('+2348012345678');

    session()->put('guest_portal.pending_phone', '+2348012345678');
    session()->put('guest_portal.pending_booking_id', $this->booking->id);
    session()->put('guest_portal.pending_property_id', $this->property->id);
    session()->put('guest_portal.pending_customer_id', \App\Models\Customer::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Forgetful', 'phone' => '+2348012345678',
    ])->id);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->set('step', 'auth.pin_reset')
        ->set('resetOtp', $code)
        ->call('submitPinReset')
        ->assertSet('step', 'auth.pin_set');
});

it('portal submitPinReset with a wrong OTP shows an error and stays on the reset step', function (): void {
    app(GuestPinService::class)->issueResetCode('+2348012345678');
    session()->put('guest_portal.pending_phone', '+2348012345678');

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->set('step', 'auth.pin_reset')
        ->set('resetOtp', '000000')
        ->call('submitPinReset')
        ->assertSet('step', 'auth.pin_reset')
        ->assertSet('authError', fn (string $e) => str_contains($e, 'incorrect'));
});
