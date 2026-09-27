<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Bookings\GuestPortalTokenService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'gp', 'name' => 'GP Tenant']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->live()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();
    $this->svc = app(GuestPortalTokenService::class);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('issues a token, hashes it, and returns plaintext + URL', function (): void {
    $issued = $this->svc->issue($this->booking);

    expect($issued['plaintext'])->toBeString()->not->toBeEmpty();
    expect($issued['token'])->toBeInstanceOf(BookingAccessToken::class);
    expect($issued['token']->token_hash)->toBe(hash('sha256', $issued['plaintext']));
    expect($issued['token']->expires_at)->not->toBeNull();
    expect($issued['url'])->toContain('/guest/booking/'.$this->booking->booking_ref);
    expect($issued['url'])->toContain('token='.$issued['plaintext']);
});

it('resolves the correct booking with the matching plaintext token', function (): void {
    $issued = $this->svc->issue($this->booking);

    $resolved = $this->svc->resolveBooking($this->booking->booking_ref, $issued['plaintext']);

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($this->booking->id);
});

it('returns null for a wrong token', function (): void {
    $this->svc->issue($this->booking);
    $resolved = $this->svc->resolveBooking($this->booking->booking_ref, 'totally-wrong');

    expect($resolved)->toBeNull();
});

it('returns null when no token has been issued', function (): void {
    expect($this->svc->resolveBooking($this->booking->booking_ref, 'anything'))->toBeNull();
});

it('returns null for an empty token or empty booking_ref', function (): void {
    $this->svc->issue($this->booking);
    expect($this->svc->resolveBooking($this->booking->booking_ref, ''))->toBeNull();
    expect($this->svc->resolveBooking('', 'anything'))->toBeNull();
});

it('returns null for a token past its 30-day TTL', function (): void {
    $issued = $this->svc->issue($this->booking);

    Carbon::setTestNow(Carbon::now()->addDays(GuestPortalTokenService::TOKEN_TTL_DAYS + 1));

    $resolved = $this->svc->resolveBooking($this->booking->booking_ref, $issued['plaintext']);
    expect($resolved)->toBeNull();
});

it('stamps last_used_at when a valid token is resolved', function (): void {
    $issued = $this->svc->issue($this->booking);
    expect($issued['token']->last_used_at)->toBeNull();

    $this->svc->resolveBooking($this->booking->booking_ref, $issued['plaintext']);

    expect($issued['token']->fresh()->last_used_at)->not->toBeNull();
});

it('denies a token from another tenant against a foreign booking_ref', function (): void {
    // Issue against tenant A.
    $issuedA = $this->svc->issue($this->booking);

    // Swap to tenant B and try to resolve A's booking_ref with A's plaintext.
    Tenant::forgetCurrent();
    $tenantB = Tenant::factory()->create(['slug' => 'gp-other']);
    $tenantB->makeCurrent();

    $resolved = $this->svc->resolveBooking($this->booking->booking_ref, $issuedA['plaintext']);

    // Booking row is found via global-scope lift, but the tenant-scoped token
    // query under tenant B's context can't see A's token row — null.
    expect($resolved)->toBeNull();
});

it('generates URLs using the tenant primary host', function (): void {
    $issued = $this->svc->issue($this->booking);
    expect($issued['url'])->toStartWith('https://'.$this->tenant->primaryHost().'/');
});
