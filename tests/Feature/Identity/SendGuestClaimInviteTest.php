<?php

declare(strict_types=1);

use App\Enums\CustomerLifecycle;
use App\Events\BookingConfirmed;
use App\Listeners\SendGuestClaimInvite;
use App\Mail\Identity\GuestClaimInviteMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GuestClaimToken;
use App\Models\IdentityAccount;
use App\Models\IdentityGuestLink;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

afterEach(function (): void {
    Tenant::forgetCurrent();
});

function makeConfirmedBooking(string $guestEmail, string $tenantSlug = 'inv'): Booking
{
    $tenant = Tenant::factory()->create(['slug' => $tenantSlug]);
    $tenant->makeCurrent();
    $booking = Booking::factory()->confirmed()->create(['guest_email' => $guestEmail]);
    Tenant::forgetCurrent();

    return $booking;
}

it('sends the claim invite and writes a ledger row on first confirmation', function (): void {
    Mail::fake();
    $booking = makeConfirmedBooking('firsttime@x.test');

    app(SendGuestClaimInvite::class)->handle(new BookingConfirmed($booking));

    Mail::assertQueued(GuestClaimInviteMail::class, function ($mail) {
        return in_array('firsttime@x.test', array_column($mail->to, 'address'), true);
    });

    $token = GuestClaimToken::query()->where('email', 'firsttime@x.test')->firstOrFail();
    expect($token->consumed_at)->toBeNull();
    expect($token->origin_tenant_id)->toBe($booking->tenant_id);
});

it('skips send when the guest already has a linked identity_guest_link', function (): void {
    Mail::fake();

    $tenant = Tenant::factory()->create(['slug' => 'already-in']);
    $tenant->makeCurrent();
    $customer = Customer::create([
        'name' => 'Guest',
        'email' => 'onboard@x.test',
        'lifecycle' => CustomerLifecycle::Prospect,
    ]);
    Tenant::forgetCurrent();

    $identity = IdentityAccount::create([
        'email' => 'onboard@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Onboard',
    ]);
    IdentityGuestLink::create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'claimed_at' => now(),
    ]);

    $booking = makeConfirmedBooking('onboard@x.test', 'already-in-2');
    app(SendGuestClaimInvite::class)->handle(new BookingConfirmed($booking));

    Mail::assertNotQueued(GuestClaimInviteMail::class);
    expect(GuestClaimToken::count())->toBe(0);
});

it('skips send when a token was issued in the dedupe window', function (): void {
    Mail::fake();
    $booking = makeConfirmedBooking('recent@x.test', 'recent-1');

    // First invite → sends.
    app(SendGuestClaimInvite::class)->handle(new BookingConfirmed($booking));
    Mail::assertQueuedCount(1);

    // A second booking confirms — but we're inside the dedupe window.
    $booking2 = makeConfirmedBooking('recent@x.test', 'recent-2');
    app(SendGuestClaimInvite::class)->handle(new BookingConfirmed($booking2));

    // Still just the one email.
    Mail::assertQueuedCount(1);
    expect(GuestClaimToken::count())->toBe(1);
});

it('sends a new invite once the dedupe window has passed', function (): void {
    Mail::fake();
    $booking = makeConfirmedBooking('spaced@x.test', 'spaced-1');

    app(SendGuestClaimInvite::class)->handle(new BookingConfirmed($booking));

    // Rewind the earlier token's issued_at to be older than the dedupe window.
    GuestClaimToken::query()->update(['issued_at' => now()->subDays(31)]);

    $booking2 = makeConfirmedBooking('spaced@x.test', 'spaced-2');
    app(SendGuestClaimInvite::class)->handle(new BookingConfirmed($booking2));

    Mail::assertQueuedCount(2);
    expect(GuestClaimToken::count())->toBe(2);
});

it('does nothing when the booking has no guest_email', function (): void {
    Mail::fake();
    $tenant = Tenant::factory()->create(['slug' => 'nomail']);
    $tenant->makeCurrent();
    $booking = Booking::factory()->confirmed()->create(['guest_email' => '']);
    Tenant::forgetCurrent();

    app(SendGuestClaimInvite::class)->handle(new BookingConfirmed($booking));

    Mail::assertNothingQueued();
    expect(GuestClaimToken::count())->toBe(0);
});
