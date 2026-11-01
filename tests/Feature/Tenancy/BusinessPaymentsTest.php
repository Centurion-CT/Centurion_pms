<?php

declare(strict_types=1);

use App\Actions\Bookings\InitiateBookingPayment;
use App\Enums\BookingStatus;
use App\Enums\CorporateMemberStatus;
use App\Enums\CorporateStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\Corporate;
use App\Models\CorporateWalletTopup;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Corporates\TopupService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * Each business's online payments, side by side (docs/MULTITENANCY.md,
 * step 5): the Paystack account they go through, where the guest comes
 * back to, and the platform webhook that hands notifications on.
 */

beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.secret_key', 'sk_platform');
    Event::fake([BookingConfirmed::class]);

    // Alpha pays through a subaccount on the platform account; Bravo has
    // its own Paystack account.
    $this->a = Tenant::factory()->create(['slug' => 'alpha', 'paystack_subaccount_code' => 'ACCT_alpha']);
    $this->b = Tenant::factory()->create(['slug' => 'bravo', 'paystack_secret_key' => 'sk_bravo']);
    $this->payA = paymentFor($this->a, 'kev_book_alpha_1');
    $this->payB = paymentFor($this->b, 'kev_book_bravo_1');
});

afterEach(fn () => Tenant::forgetCurrent());

function paymentFor(Tenant $tenant, string $reference): Payment
{
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create();
    $room = Room::factory()->for($tenant)->for($property)->create();
    $booking = Booking::factory()->for($tenant)->for($room)->create([
        'total_amount' => 40_000, 'balance_due' => 0, 'booking_status' => BookingStatus::Pending,
    ]);
    $payment = Payment::factory()->for($tenant)->for($booking)->create([
        'amount' => 40_000, 'type' => PaymentType::Full, 'status' => PaymentStatus::Pending,
        'paystack_reference' => $reference,
    ]);
    Tenant::forgetCurrent();

    return $payment;
}

function paystackSucceeds(string $reference): void
{
    Http::fake(['https://api.paystack.test/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 4_000_000, 'reference' => $reference, 'paid_at' => '2026-10-31T09:00:00+01:00'],
    ])]);
}

function signedPost(string $url, array $payload, string $key): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return [$url, $body, hash_hmac('sha512', $body, $key)];
}

it('sends guests back to the payment return page on the business they booked with', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/x', 'access_code' => 'ac', 'reference' => 'r'],
    ])]);
    $this->b->makeCurrent();
    onBusinessHost('bravo');
    $booking = Booking::query()->findOrFail($this->payB->booking_id);
    $booking->forceFill(['booking_status' => BookingStatus::Pending, 'payment_status' => PaymentStatus::Pending])->save();

    app(InitiateBookingPayment::class)->handle($booking);

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/transaction/initialize')
        && $r['callback_url'] === 'http://bravo.keverna.test/stays/pay/callback'
        && $r->hasHeader('Authorization', 'Bearer sk_bravo'));
});

it("confirms a payment on its own business's return page, checked with that business's account", function (): void {
    paystackSucceeds('kev_book_bravo_1');

    $this->get('http://bravo.keverna.test/stays/pay/callback?reference=kev_book_bravo_1')
        ->assertRedirectContains('/stays/pay/success/');

    expect($this->payB->refresh()->status)->toBe(PaymentStatus::Succeeded);
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer sk_bravo'));
});

it("does not confirm another business's payment on this business's return page", function (): void {
    paystackSucceeds('kev_book_alpha_1');

    $this->get('http://bravo.keverna.test/stays/pay/callback?reference=kev_book_alpha_1')
        ->assertRedirectContains('reason=unknown-reference');

    Http::assertNothingSent();
    expect($this->payA->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('hands a platform webhook to the business the payment belongs to', function (): void {
    [$url, $body, $sig] = signedPost('http://keverna.test/paystack/webhook', [
        'event' => 'charge.success',
        'data' => ['reference' => 'kev_book_alpha_1', 'amount' => 4_000_000, 'paid_at' => '2026-10-31T09:00:00+01:00'],
    ], 'sk_platform');

    $this->call('POST', $url, [], [], [], ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk()->assertJson(['ok' => true, 'confirmed' => true]);

    expect($this->payA->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and(Tenant::current())->toBeNull();
});

it('refuses a platform webhook with a bad signature, and ignores references that are not ours', function (): void {
    [$url, $body, $sig] = signedPost('http://keverna.test/paystack/webhook', [
        'event' => 'charge.success', 'data' => ['reference' => 'kev_book_alpha_1', 'amount' => 4_000_000],
    ], 'sk_someone_else');
    $this->call('POST', $url, [], [], [], ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertStatus(401);

    [$url, $body, $sig] = signedPost('http://keverna.test/paystack/webhook', [
        'event' => 'charge.success', 'data' => ['reference' => 'SUB-elsewhere', 'amount' => 100],
    ], 'sk_platform');
    $this->call('POST', $url, [], [], [], ['HTTP_X-PAYSTACK-SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk()->assertJson(['ignored' => 'unknown reference']);

    expect($this->payA->refresh()->status)->toBe(PaymentStatus::Pending);
});

it("pays a corporate top-up into the corporate's own business account", function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/t', 'access_code' => 'ac', 'reference' => 'r'],
    ])]);
    $this->a->makeCurrent();
    onBusinessHost('alpha');
    $corp = Corporate::query()->create([
        'slug' => 'dangote', 'name' => 'Dangote', 'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@dangote.ng', 'country' => 'NG',
    ]);
    $admin = $corp->members()->create(['name' => 'Tobi', 'email' => 'tobi@dangote.ng', 'status' => CorporateMemberStatus::Active->value]);

    app(TopupService::class)->initiate($corp, $admin, 50_000);

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/transaction/initialize')
        && $r['subaccount'] === 'ACCT_alpha'
        && $r['callback_url'] === 'http://alpha.keverna.test/corporates/wallet/callback');
    expect(CorporateWalletTopup::query()->count())->toBe(1);
});
