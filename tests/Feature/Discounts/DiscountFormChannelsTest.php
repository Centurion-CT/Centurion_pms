<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Discounts\Form;
use App\Models\Discount;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * The discount form's channel picker offers every BookingSource case
 * the operator might legitimately want to scope a rule to (Online,
 * Walk-in, Phone, WhatsApp, Booking.com, Airbnb, Manual, etc.). The
 * `channels.*` validator has to match — earlier it only accepted
 * ['online', 'manual'], so anything else came back as
 * "The selected channels.1 is invalid.".
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'discount-channels']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('accepts a mixed set of operator + OTA channels (regression for "channels.1 is invalid")', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->set('name', 'Weekend special')
        ->set('type', 'percent')
        ->set('value', 10)
        ->set('channels', [
            BookingSource::Online->value,
            BookingSource::Phone->value,
            BookingSource::WhatsApp->value,
            BookingSource::BookingCom->value,
            BookingSource::JumiaTravel->value,
            BookingSource::Manual->value,
        ])
        ->call('save')
        ->assertHasNoErrors('channels.*')
        ->assertRedirect(route('tenant.discounts.index'));

    $d = Discount::query()->where('name', 'Weekend special')->first();
    expect($d)->not->toBeNull();
    expect($d->channels)->toBe([
        'online', 'phone', 'whatsapp', 'booking_com', 'jumia_travel', 'manual',
    ]);
});

it('still rejects a channel value that is not a BookingSource case', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->set('name', 'Bad channel')
        ->set('type', 'percent')
        ->set('value', 5)
        ->set('channels', ['online', 'not_a_real_channel'])
        ->call('save')
        ->assertHasErrors('channels.1');

    expect(Discount::query()->where('name', 'Bad channel')->exists())->toBeFalse();
});

it('accepts an empty channels array (no channel restriction)', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Form::class)
        ->set('name', 'Everyone')
        ->set('type', 'percent')
        ->set('value', 5)
        ->set('channels', [])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('tenant.discounts.index'));

    $d = Discount::query()->where('name', 'Everyone')->first();
    expect($d)->not->toBeNull();
    expect($d->channels)->toBeNull(); // stored as null when empty
});
