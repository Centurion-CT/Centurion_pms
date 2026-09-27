<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysBookCheckout;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Every Keverna Stays surface a guest lands on must ship the brand
 * favicon in <head>. Missing favicons on a marketplace look
 * broken - browser tab defaults to the default globe icon +
 * scraper cards (WhatsApp / Slack) fall back to hostname text.
 * Test the include on each rendered surface.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'favicon-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true,
        'name' => 'Favicon Test Property',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('ships the favicon on /stays', function (): void {
    $this->get(route('marketplace.stays.index'))
        ->assertOk()
        ->assertSee('favicon.svg', false)
        ->assertSee('favicon.ico', false);
});

it('ships the favicon on /stays/{slug}', function (): void {
    $this->get(route('marketplace.stays.show', ['slug' => $this->property->public_slug]))
        ->assertOk()
        ->assertSee('favicon.svg', false)
        ->assertSee('favicon.ico', false);
});

it('ships the favicon on the checkout page', function (): void {
    // Livewire::test() renders the component body only + skips
    // the surrounding layout, so favicon assertions have to go
    // through a real HTTP request that emits the layout's <head>.
    $this->get(route('marketplace.stays.book', ['slug' => $this->property->public_slug]))
        ->assertOk()
        ->assertSee('favicon.svg', false);
});

it('ships the favicon on the pay-success page', function (): void {
    Tenant::forgetCurrent();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed, 'total_amount' => 30_000,
    ]);

    $this->get(route('marketplace.stays.pay.success', ['booking_ref' => $booking->booking_ref]))
        ->assertOk()
        ->assertSee('favicon.svg', false);
});

it('ships the favicon on the pay-failed page', function (): void {
    $this->get(route('marketplace.stays.pay.failed', ['reason' => 'not-success']))
        ->assertOk()
        ->assertSee('favicon.svg', false);
});
