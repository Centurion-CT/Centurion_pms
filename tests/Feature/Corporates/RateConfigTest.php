<?php

declare(strict_types=1);

use App\Enums\CorporateRateScope;
use App\Enums\CorporateRateType;
use App\Enums\CorporateStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Corporates\Show as CorpShow;
use App\Models\Corporate;
use App\Models\CorporateRate;
use App\Models\Operator;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Rate configuration surface on Corporate Show:
 *   - Category rates: pick a category, set % off / ₦ off / flat ₦
 *     price, optionally guard by min_nights.
 *   - Length-of-stay discounts: global % off with a min_nights floor,
 *     stacks with any category rate at booking-time resolution.
 * Owned-corps only; central corps stay read-only.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'corp-rates']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 50_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->corp = Corporate::query()->create([
        'slug' => 'rates-'.uniqid(),
        'name' => 'Owned Rates Corp',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@rates.test',
        'country' => 'NG',
        'created_by_tenant_id' => $this->tenant->id,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

// ── Category rates ───────────────────────────────────────────────

it('adds a percent-off category rate', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('newCategoryRate')
        ->set('categoryRateCategoryId', $this->cat->id)
        ->set('categoryRateType', 'percent_off')
        ->set('categoryRateValue', 15)
        ->call('saveCategoryRate')
        ->assertDispatched('notify');

    $rate = CorporateRate::query()->where('corporate_id', $this->corp->id)->first();
    expect($rate)->not->toBeNull();
    expect($rate->scope)->toBe(CorporateRateScope::RoomCategory);
    expect($rate->scope_id)->toBe($this->cat->id);
    expect($rate->rate_type)->toBe(CorporateRateType::PercentOff);
    expect((int) $rate->rate_value)->toBe(15);
    expect($rate->min_nights)->toBeNull();
});

it('adds a flat-price category rate with min nights', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('newCategoryRate')
        ->set('categoryRateCategoryId', $this->cat->id)
        ->set('categoryRateType', 'fixed_price')
        ->set('categoryRateValue', 35_000)
        ->set('categoryRateMinNights', 3)
        ->call('saveCategoryRate');

    $rate = CorporateRate::query()->where('corporate_id', $this->corp->id)->first();
    expect($rate->rate_type)->toBe(CorporateRateType::FixedPrice);
    expect((int) $rate->rate_value)->toBe(35_000);
    expect((int) $rate->min_nights)->toBe(3);
});

it('refuses a percent above 100', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('newCategoryRate')
        ->set('categoryRateCategoryId', $this->cat->id)
        ->set('categoryRateType', 'percent_off')
        ->set('categoryRateValue', 150)
        ->call('saveCategoryRate')
        ->assertDispatched('notify');

    expect(CorporateRate::query()->where('corporate_id', $this->corp->id)->count())->toBe(0);
});

it('deletes a category rate', function (): void {
    $rate = CorporateRate::query()->create([
        'corporate_id' => $this->corp->id,
        'scope' => CorporateRateScope::RoomCategory->value,
        'scope_id' => $this->cat->id,
        'rate_type' => CorporateRateType::PercentOff->value,
        'rate_value' => 10,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('deleteCategoryRate', $rate->id);

    expect(CorporateRate::query()->where('id', $rate->id)->exists())->toBeFalse();
});

// ── Length-of-stay discounts ─────────────────────────────────────

it('adds a length-of-stay discount tier', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('newLos')
        ->set('losMinNights', 7)
        ->set('losPercentOff', 10)
        ->set('losNotes', '1 week+ loyalty tier')
        ->call('saveLos');

    $rate = CorporateRate::query()->where('corporate_id', $this->corp->id)->first();
    expect($rate)->not->toBeNull();
    expect($rate->scope)->toBe(CorporateRateScope::Global);
    expect($rate->rate_type)->toBe(CorporateRateType::PercentOff);
    expect((int) $rate->rate_value)->toBe(10);
    expect((int) $rate->min_nights)->toBe(7);
    expect($rate->notes)->toBe('1 week+ loyalty tier');
});

it('refuses LoS min nights below 2', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('newLos')
        ->set('losMinNights', 1)
        ->set('losPercentOff', 10)
        ->call('saveLos')
        ->assertDispatched('notify');

    expect(CorporateRate::query()->where('corporate_id', $this->corp->id)->count())->toBe(0);
});

it('deletes a LoS tier', function (): void {
    $rate = CorporateRate::query()->create([
        'corporate_id' => $this->corp->id,
        'scope' => CorporateRateScope::Global->value,
        'scope_id' => null,
        'rate_type' => CorporateRateType::PercentOff->value,
        'rate_value' => 5,
        'min_nights' => 3,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('deleteLos', $rate->id);

    expect(CorporateRate::query()->where('id', $rate->id)->exists())->toBeFalse();
});

