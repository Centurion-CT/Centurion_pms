<?php

declare(strict_types=1);

use App\Models\Property;
use App\Models\RateRule;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Marketplace\RateResolver;
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
    $this->resolver = app(RateResolver::class);
});

afterEach(fn () => Tenant::forgetCurrent());

it('falls back to the category base rate when no rule matches', function (): void {
    $out = $this->resolver->resolve($this->category, Request::create('/', 'GET'));

    expect($out['rate'])->toBe(25000);
    expect($out['base_rate'])->toBe(25000);
    expect($out['rule'])->toBeNull();
});

it('applies an active rule covering today with no other constraints', function (): void {
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => $this->category->id,
        'label'            => 'Promo',
        'rate_ngn'         => 18000,
        'starts_on'        => Carbon::today()->subDay(),
        'ends_on'          => Carbon::today()->addDay(),
        'is_active'        => true,
    ]);

    $out = $this->resolver->resolve($this->category, Request::create('/', 'GET'));
    expect($out['rate'])->toBe(18000);
    expect($out['base_rate'])->toBe(25000);
    expect($out['rule'])->not->toBeNull();
});

it('honours the mobile-only device gate', function (): void {
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => $this->category->id,
        'rate_ngn'         => 15000,
        'starts_on'        => Carbon::today()->subDay(),
        'ends_on'          => Carbon::today()->addDay(),
        'devices'          => ['mobile'],
        'is_active'        => true,
    ]);

    $desktop = Request::create('/', 'GET');
    $desktop->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X)');
    $mobile = Request::create('/', 'GET');
    $mobile->headers->set('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)');

    expect($this->resolver->resolve($this->category, $desktop)['rate'])->toBe(25000);
    expect($this->resolver->resolve($this->category, $mobile)['rate'])->toBe(15000);
});

it('honours the Google-referrer gate', function (): void {
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => $this->category->id,
        'rate_ngn'         => 20000,
        'starts_on'        => Carbon::today()->subDay(),
        'ends_on'          => Carbon::today()->addDay(),
        'referrers'        => ['google'],
        'is_active'        => true,
    ]);

    $direct = Request::create('/', 'GET');
    $google = Request::create('/', 'GET');
    $google->headers->set('Referer', 'https://www.google.com/search?q=grand+palace');

    expect($this->resolver->resolve($this->category, $direct)['rate'])->toBe(25000);
    expect($this->resolver->resolve($this->category, $google)['rate'])->toBe(20000);
});

it('applies rules in descending priority order and skips inactive ones', function (): void {
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => $this->category->id,
        'rate_ngn'         => 22000,
        'starts_on'        => Carbon::today()->subDay(),
        'ends_on'          => Carbon::today()->addDay(),
        'priority'         => 10,
        'is_active'        => true,
    ]);
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => $this->category->id,
        'rate_ngn'         => 12000, // higher priority WOULD win, but inactive
        'starts_on'        => Carbon::today()->subDay(),
        'ends_on'          => Carbon::today()->addDay(),
        'priority'         => 100,
        'is_active'        => false,
    ]);
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => null, // property-wide but lower priority
        'rate_ngn'         => 21000,
        'starts_on'        => Carbon::today()->subDay(),
        'ends_on'          => Carbon::today()->addDay(),
        'priority'         => 5,
        'is_active'        => true,
    ]);

    $out = $this->resolver->resolve($this->category, Request::create('/', 'GET'));
    expect($out['rate'])->toBe(22000);
});

it('sums per-night rates across a multi-night stay', function (): void {
    // Fri + Sat surge; Sun back to base. Three-night check-in on Fri.
    $friday = Carbon::parse('2026-10-02'); // Fri
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => $this->category->id,
        'label'            => 'Weekend surge',
        'rate_ngn'         => 32000,
        'starts_on'        => $friday,
        'ends_on'          => $friday->copy()->addDay(), // Fri + Sat
        'is_active'        => true,
    ]);

    $stay = $this->resolver->resolveStay(
        $this->category,
        Request::create('/', 'GET'),
        $friday,
        $friday->copy()->addDays(3), // Fri, Sat, Sun nights
    );

    expect($stay['nights'])->toBe(3);
    expect($stay['base_total'])->toBe(25000 * 3);
    // Fri 32k + Sat 32k + Sun 25k.
    expect($stay['total'])->toBe(32000 + 32000 + 25000);
    expect($stay['nightly'])->toHaveCount(3);
    expect($stay['nightly'][0]['rate'])->toBe(32000);
    expect($stay['nightly'][2]['rate'])->toBe(25000);
});

it('returns zero nights when checkout is not after checkin', function (): void {
    $day = Carbon::parse('2026-10-05');
    $stay = $this->resolver->resolveStay(
        $this->category,
        Request::create('/', 'GET'),
        $day,
        $day,
    );
    expect($stay['nights'])->toBe(0);
    expect($stay['total'])->toBe(0);
});

it('ignores a rule whose date range does not include today', function (): void {
    RateRule::query()->create([
        'tenant_id'        => $this->tenant->id,
        'property_id'      => $this->property->id,
        'room_category_id' => $this->category->id,
        'rate_ngn'         => 10000,
        'starts_on'        => Carbon::today()->addDays(3),
        'ends_on'          => Carbon::today()->addDays(5),
        'is_active'        => true,
    ]);

    $out = $this->resolver->resolve($this->category, Request::create('/', 'GET'));
    expect($out['rate'])->toBe(25000);
});
