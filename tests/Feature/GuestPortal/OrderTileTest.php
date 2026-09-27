<?php

declare(strict_types=1);

use App\Actions\GuestPortal\InitiatePortalPayment;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\PosSaleStatus;
use App\Livewire\GuestPortal\RoomPortal;
use App\Models\Booking;
use App\Models\GuestPortalIntent;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Order tile — pick outlet + products from real POS catalog,
 * charge-to-room OR pay by card via Paystack.
 */
beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
    config()->set('services.paystack.callback_url', 'http://gp-order.keverna.test/room/portal/pay/callback');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'gp-order',
        'paystack_secret_key' => 'sk_test',
    ]);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();

    $this->bar = Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Bar', 'type' => OutletType::Bar, 'active' => true,
    ]);
    $this->beer = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Beer', 'price' => 1500, 'track_stock' => false, 'active' => true,
    ]);
    $this->wine = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'outlet_id' => $this->bar->id,
        'name' => 'Wine', 'price' => 3000, 'track_stock' => false, 'active' => true,
    ]);

    Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Orderer',
        'guest_phone' => '+2348022222222',
        'guest_email' => 'orderer@example.com',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('openTile order snaps to the first active outlet with products', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'order')
        ->assertSet('orderOutletId', $this->bar->id);
});

it('bumpCart clamps + persists product quantities', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'order')
        ->call('bumpCart', $this->beer->id, 1)
        ->call('bumpCart', $this->beer->id, 1)
        ->call('bumpCart', $this->wine->id, 3)
        ->call('bumpCart', $this->wine->id, -1)
        ->assertSet('orderCart', [
            $this->beer->id => 2,
            $this->wine->id => 2,
        ]);
});

it('charge-to-room creates a completed PosSale on the outlet with tender room', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'order')
        ->call('bumpCart', $this->beer->id, 2) // 2 × ₦1,500 = ₦3,000
        ->call('bumpCart', $this->wine->id, 1) // ₦3,000
        ->set('orderNotes', 'chilled please')
        ->call('submitOrderChargeToRoom')
        ->assertSet('step', 'home');

    $sale = PosSale::query()->latest('id')->firstOrFail();
    expect($sale->status)->toBe(PosSaleStatus::Completed);
    expect((int) $sale->total)->toBe(6_000);
    expect((int) $sale->outlet_id)->toBe($this->bar->id);
    expect((int) $sale->booking_id)->toBe($this->booking->id);
    expect($sale->notes)->toContain('chilled please');
});

it('pay-by-card creates a Draft sale + Paystack intent + redirects', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.test/pay-order', 'access_code' => 'ac', 'reference' => 'r'],
    ])]);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'order')
        ->call('bumpCart', $this->beer->id, 4) // ₦6,000
        ->call('submitOrderPayByCard')
        ->assertRedirect('https://checkout.paystack.test/pay-order');

    $sale = PosSale::query()->latest('id')->firstOrFail();
    expect($sale->status)->toBe(PosSaleStatus::Draft);
    expect((int) $sale->total)->toBe(6_000);
    // booking_id null on card path — sale is not going on the folio.
    expect($sale->booking_id)->toBeNull();

    $intent = GuestPortalIntent::query()->latest('id')->firstOrFail();
    expect($intent->purpose)->toBe(GuestPortalIntent::PURPOSE_ORDER);
    expect((int) $intent->payload['pos_sale_id'])->toBe($sale->id);
});

it('callback completes the Draft sale with tender card', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'x', 'access_code' => 'ac', 'reference' => 'r'],
    ])]);

    // Kick off the order via the tile so a Draft sale + intent exist.
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'order')
        ->call('bumpCart', $this->wine->id, 2) // ₦6,000
        ->call('submitOrderPayByCard');

    $sale = PosSale::query()->latest('id')->firstOrFail();
    $intent = GuestPortalIntent::query()->latest('id')->firstOrFail();
    $reference = $intent->payment->paystack_reference;

    Http::fake(['https://api.paystack.test/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 600_000, 'reference' => $reference, 'paid_at' => Carbon::now()->toIso8601String()],
    ])]);

    $this->get('http://gp-order.keverna.test/room/portal/pay/callback?reference='.$reference);

    $sale = $sale->fresh();
    expect($sale->status)->toBe(PosSaleStatus::Completed);
    // Payment row's pos_sale_id was set by the resolver.
    expect((int) $intent->payment->fresh()->pos_sale_id)->toBe($sale->id);
});
