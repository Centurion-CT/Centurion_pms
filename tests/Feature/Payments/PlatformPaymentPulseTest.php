<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Events\PlatformPaymentPulse;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pulse-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'nights' => 2, 'room_total' => 100_000,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('broadcasts a padded, rounded pulse when a Succeeded payment is created', function (): void {
    Event::fake([PlatformPaymentPulse::class]);

    Payment::factory()->for($this->tenant)->for($this->booking)->succeeded()->create([
        'amount' => 100_000,
        'type' => PaymentType::Full,
    ]);

    Event::assertDispatched(PlatformPaymentPulse::class, function (PlatformPaymentPulse $e) {
        expect($e->paddedAmount)->toBe(150_000);       // 100_000 × 1.5
        expect($e->roundedAmount)->toBe(150_000);       // already on the ₦5k grid
        expect($e->category)->toBe('stays');
        return true;
    });
});

it('rounds the display amount to the nearest 5,000 naira for anonymity', function (): void {
    Event::fake([PlatformPaymentPulse::class]);

    Payment::factory()->for($this->tenant)->for($this->booking)->succeeded()->create([
        'amount' => 47_800, // × 1.5 = 71,700 → rounds to 70,000
        'type' => PaymentType::Deposit,
    ]);

    Event::assertDispatched(PlatformPaymentPulse::class, function (PlatformPaymentPulse $e) {
        expect($e->paddedAmount)->toBe(71_700);
        expect($e->roundedAmount)->toBe(70_000);
        return true;
    });
});

it('skips payments below the 5,000 naira floor', function (): void {
    Event::fake([PlatformPaymentPulse::class]);

    Payment::factory()->for($this->tenant)->for($this->booking)->succeeded()->create([
        'amount' => 4_900,
        'type' => PaymentType::Full,
    ]);

    Event::assertNotDispatched(PlatformPaymentPulse::class);
});

it('skips refunded, failed, and pending payments', function (): void {
    Event::fake([PlatformPaymentPulse::class]);

    foreach ([PaymentStatus::Failed, PaymentStatus::Refunded, PaymentStatus::Pending] as $status) {
        Payment::factory()->for($this->tenant)->for($this->booking)->create([
            'amount' => 100_000,
            'type' => PaymentType::Full,
            'status' => $status,
        ]);
    }

    Event::assertNotDispatched(PlatformPaymentPulse::class);
});

it('skips CorporateAr mirror payments so we do not double-count', function (): void {
    Event::fake([PlatformPaymentPulse::class]);

    Payment::factory()->for($this->tenant)->for($this->booking)->succeeded()->create([
        'amount' => 500_000,
        'type' => PaymentType::CorporateAr,
    ]);

    Event::assertNotDispatched(PlatformPaymentPulse::class);
});

it('fires when a pending payment transitions to succeeded', function (): void {
    $payment = Payment::factory()->for($this->tenant)->for($this->booking)->create([
        'amount' => 100_000,
        'type' => PaymentType::Full,
        'status' => PaymentStatus::Pending,
    ]);

    Event::fake([PlatformPaymentPulse::class]);
    $payment->update(['status' => PaymentStatus::Succeeded]);

    Event::assertDispatched(PlatformPaymentPulse::class);
});

it('maps payment type to the right marketing category', function (): void {
    Event::fake([PlatformPaymentPulse::class]);

    Payment::factory()->for($this->tenant)->for($this->booking)->succeeded()->create([
        'amount' => 25_000,
        'type' => PaymentType::PosSale,
    ]);
    Payment::factory()->for($this->tenant)->for($this->booking)->succeeded()->create([
        'amount' => 25_000,
        'type' => PaymentType::Extra,
    ]);

    $categories = [];
    Event::assertDispatched(PlatformPaymentPulse::class, function (PlatformPaymentPulse $e) use (&$categories) {
        $categories[] = $e->category;
        return true;
    });
    expect($categories)->toContain('outlet');
    expect($categories)->toContain('extras');
});
