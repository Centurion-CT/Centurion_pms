<?php

declare(strict_types=1);

use App\Models\Property;
use App\Models\RateRule;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()
        ->for($this->tenant)
        ->for($this->property)
        ->create(['base_rate' => 25000]);
    $this->room = Room::factory()
        ->for($this->tenant)
        ->for($this->property)
        ->for($this->category, 'category')
        ->create();

    $this->calc = app(PricingCalculator::class);
});

afterEach(fn () => Tenant::forgetCurrent());

it('prices a stay at base_rate when no marketplace request is threaded', function (): void {
    // Front-desk flow: no request, rules should be ignored.
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => $this->category->id,
        'rate_ngn'         => 18000,
        'starts_on'        => Carbon::parse('2026-10-01'),
        'ends_on'          => Carbon::parse('2026-10-31'),
        'is_active'        => true,
    ]);

    $br = $this->calc->calculate(
        $this->room,
        Carbon::parse('2026-10-10'),
        Carbon::parse('2026-10-13'),
    );

    // 3 nights × 25k base — front-desk sees no rule discount.
    expect($br->subtotal)->toBe(75000);
});

it('applies the rate rule per night when a marketplace request is threaded', function (): void {
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => $this->category->id,
        'rate_ngn'         => 20000,
        'starts_on'        => Carbon::parse('2026-10-01'),
        'ends_on'          => Carbon::parse('2026-10-31'),
        'is_active'        => true,
    ]);

    $br = $this->calc->calculate(
        $this->room,
        Carbon::parse('2026-10-10'),
        Carbon::parse('2026-10-13'),
        marketplaceRequest: Request::create('/', 'GET'),
    );

    // 3 nights × 20k rule.
    expect($br->subtotal)->toBe(60000);
});

it('mixes rule + base per night when the stay straddles the rule window', function (): void {
    // Surge Nov 13-15.
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => $this->category->id,
        'rate_ngn'         => 32000,
        'starts_on'        => Carbon::parse('2026-11-13'),
        'ends_on'          => Carbon::parse('2026-11-15'),
        'is_active'        => true,
    ]);

    // Stay Nov 11 → 15 = nights on 11, 12, 13, 14
    // 11 = base 25k, 12 = base 25k, 13 = surge 32k, 14 = surge 32k
    $br = $this->calc->calculate(
        $this->room,
        Carbon::parse('2026-11-11'),
        Carbon::parse('2026-11-15'),
        marketplaceRequest: Request::create('/', 'GET'),
    );

    expect($br->subtotal)->toBe(25000 + 25000 + 32000 + 32000);
    expect($br->nights)->toBe(4);
});
