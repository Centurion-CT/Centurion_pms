<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Reviews\Editor;
use App\Livewire\Dashboard\Reviews\Index;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'rev-dash']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->live()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create(['name' => 'Suite A']);
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/* ---------- Index ---------- */

it('lists property-wide and per-room reviews together', function (): void {
    Review::factory()->for($this->tenant)->for($this->property)->create(['guest_name' => 'Property Guest']);
    Review::factory()->for($this->tenant)->for($this->property)->forRoom($this->room)->create(['guest_name' => 'Room Guest']);

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->assertSee('Property Guest')
        ->assertSee('Room Guest');
});

it('filters to property-only reviews', function (): void {
    Review::factory()->for($this->tenant)->for($this->property)->create(['guest_name' => 'Property Guest']);
    Review::factory()->for($this->tenant)->for($this->property)->forRoom($this->room)->create(['guest_name' => 'Room Guest']);

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->set('scopeFilter', 'property')
        ->assertSee('Property Guest')
        ->assertDontSee('Room Guest');
});

it('filters to a specific room', function (): void {
    $otherRoom = Room::factory()->for($this->tenant)->for($this->property)->create();
    Review::factory()->for($this->tenant)->for($this->property)->forRoom($this->room)->create(['guest_name' => 'For Suite A']);
    Review::factory()->for($this->tenant)->for($this->property)->forRoom($otherRoom)->create(['guest_name' => 'Other Room']);

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->set('scopeFilter', (string) $this->room->id)
        ->assertSee('For Suite A')
        ->assertDontSee('Other Room');
});

it('excludes hidden reviews when includeHidden is false', function (): void {
    Review::factory()->for($this->tenant)->for($this->property)->create(['guest_name' => 'Visible Guest']);
    Review::factory()->for($this->tenant)->for($this->property)->hidden()->create(['guest_name' => 'Hidden Guest']);

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->set('includeHidden', false)
        ->assertSee('Visible Guest')
        ->assertDontSee('Hidden Guest');
});

it('toggles a review\'s displayed state', function (): void {
    $review = Review::factory()->for($this->tenant)->for($this->property)->create(['is_displayed' => true]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->call('toggleDisplayed', $review->id);

    expect($review->fresh()->is_displayed)->toBeFalse();
});

it('deletes a review', function (): void {
    $review = Review::factory()->for($this->tenant)->for($this->property)->create();

    Livewire::actingAs($this->operator, 'web')
        ->test(Index::class)
        ->call('delete', $review->id);

    expect(Review::query()->find($review->id))->toBeNull();
});

/* ---------- Editor ---------- */

it('creates a property-wide review', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Editor::class)
        ->set('guest_name', 'New Guest')
        ->set('body', 'Lovely stay.')
        ->set('rating', 5)
        ->set('room_id', null)
        ->set('is_displayed', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok'
            && ($p['title'] ?? null) === 'Review saved');

    $review = Review::query()->where('guest_name', 'New Guest')->first();
    expect($review)->not->toBeNull();
    expect($review->room_id)->toBeNull();
    expect($review->property_id)->toBe($this->property->id);
});

it('creates a per-room review', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Editor::class)
        ->set('guest_name', 'Suite Guest')
        ->set('body', 'Suite A was great.')
        ->set('rating', 5)
        ->set('room_id', $this->room->id)
        ->call('save');

    $review = Review::query()->where('guest_name', 'Suite Guest')->first();
    expect($review->room_id)->toBe($this->room->id);
});

it('loads an existing review for editing', function (): void {
    $review = Review::factory()->for($this->tenant)->for($this->property)->forRoom($this->room)->create([
        'guest_name' => 'Edit Me', 'body' => 'old body', 'rating' => 4,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(Editor::class, ['reviewId' => $review->id])
        ->assertSet('guest_name', 'Edit Me')
        ->assertSet('rating', 4)
        ->assertSet('room_id', $this->room->id);
});

it('validates rating range', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Editor::class)
        ->set('guest_name', 'A')
        ->set('body', 'b')
        ->set('rating', 0)
        ->call('save')
        ->assertHasErrors('rating');
});

it('does not let an operator load a foreign tenant\'s review', function (): void {
    Tenant::forgetCurrent();
    $other = Tenant::factory()->create();
    $other->makeCurrent();
    $otherProp = Property::factory()->live()->for($other)->create();
    $foreignReview = Review::factory()->for($other)->for($otherProp)->create();
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    $response = $this->actingAs($this->operator, 'web')
        ->get('http://rev-dash.keverna.test/dashboard/reviews/'.$foreignReview->id.'/edit');

    $response->assertNotFound();
});
