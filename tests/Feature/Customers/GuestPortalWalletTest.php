<?php

declare(strict_types=1);

use App\Enums\CustomerLifecycle;
use App\Enums\PaymentStatus;
use App\Livewire\Guest\Show as GuestShow;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Bookings\GuestPortalTokenService;
use App\Services\Customers\WalletService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'guest-wallet']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Std', 'base_rate' => 40_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->cat, 'category')->create();

    $this->customer = Customer::create([
        'name' => 'Portal Guest',
        'email' => 'portal@x.test',
        'lifecycle' => CustomerLifecycle::Active,
    ]);

    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->onHold(30_000)->create([
        'booking_ref' => 'KV-WALLET01',
        'total_amount' => 100_000,
        'balance_due' => 70_000,
        'customer_id' => $this->customer->id,
    ]);

    $issued = app(GuestPortalTokenService::class)->issue($this->booking);
    $this->token = $issued['plaintext'];
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/** Guest portal URL helper — mirrors the existing GuestPortalHttpTest pattern. */
function walletPortalUrl(Booking $b, string $token, string $host = 'guest-wallet'): string
{
    return 'http://'.$host.'.keverna.test/guest/booking/'.$b->booking_ref.'?token='.$token;
}

it('hides the wallet block when the guest has never had a wallet', function (): void {
    $response = $this->get(walletPortalUrl($this->booking, $this->token));

    $response->assertOk();
    $response->assertDontSee('data-testid="guest-wallet-block"', false);
});

it('shows the wallet balance to a guest with cash on file', function (): void {
    app(WalletService::class)->topup($this->customer, 40_000, 'seed');

    $response = $this->get(walletPortalUrl($this->booking, $this->token));

    $response->assertOk();
    $response->assertSee('data-testid="guest-wallet-block"', false);
    $response->assertSee('₦40,000');
});

it('hides the Pay-from-wallet button when balance is short', function (): void {
    // Wallet has 50k, outstanding is 70k — no button.
    app(WalletService::class)->topup($this->customer, 50_000, 'seed');

    $response = $this->get(walletPortalUrl($this->booking, $this->token));
    $response->assertOk();
    $response->assertDontSee('data-testid="guest-pay-from-wallet"', false);
});

it('shows the Pay-from-wallet button when balance covers outstanding', function (): void {
    app(WalletService::class)->topup($this->customer, 100_000, 'seed');

    $response = $this->get(walletPortalUrl($this->booking, $this->token));
    $response->assertOk();
    $response->assertSee('data-testid="guest-pay-from-wallet"', false);
});

it('payFromWallet settles the booking and flips payment_status', function (): void {
    app(WalletService::class)->topup($this->customer, 100_000, 'seed');

    // Prime request()->query('token') so mount() can resolve the token —
    // Livewire::test doesn't inherit HTTP query params otherwise.
    Livewire::withQueryParams(['token' => $this->token])
        ->test(GuestShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('payFromWallet')
        // Success dispatches a notify with kind=ok; no error modal.
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok');

    $this->booking->refresh();
    expect((int) $this->booking->balance_due)->toBe(0);
    expect($this->booking->payment_status)->toBe(PaymentStatus::Succeeded);

    $walletPayment = Payment::query()->where('booking_id', $this->booking->id)->where('method', 'wallet')->first();
    expect($walletPayment)->not->toBeNull();
    expect((int) $walletPayment->amount)->toBe(70_000);
    expect($walletPayment->reference_note)->toContain('guest');

    expect($this->customer->fresh()->walletBalance())->toBe(30_000);
});
