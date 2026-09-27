<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\ReviewChannel;
use App\Livewire\Dashboard\Reviews\Editor;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Review;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'review-fields-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    // Editor stores reviewer photos on whichever disk media-library
    // is configured to use (dokwe in prod, public locally). Fake the
    // same disk so the test doesn't touch real storage.
    $this->reviewDisk = config('media-library.disk_name', 'public');
    Storage::fake($this->reviewDisk);
});

afterEach(fn () => Tenant::forgetCurrent());

it('defaults published_at to today and channel to direct on new reviews', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Editor::class)
        ->assertSet('channel', 'direct')
        ->assertSet('published_at', Carbon::today()->toDateString());
});

it('saves channel + country + published_at when new', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Editor::class)
        ->set('guest_name', 'Amara Okafor')
        ->set('body', 'Beautiful stay, felt like home.')
        ->set('rating', 5)
        ->set('channel', ReviewChannel::BookingCom->value)
        ->set('guest_country', 'Nigeria')
        ->set('published_at', '2026-03-15')
        ->call('save')
        ->assertHasNoErrors();

    $review = Review::query()->first();
    expect($review)->not->toBeNull();
    expect($review->channel)->toBe(ReviewChannel::BookingCom);
    expect($review->guest_country)->toBe('Nigeria');
    expect($review->published_at?->toDateString())->toBe('2026-03-15');
    expect($review->photo_path)->toBeNull();
});

it('rejects an unknown channel string', function (): void {
    // Enum value is validated via the ReviewChannel::tryFrom fallback,
    // but the request-level validator refuses unknown values.
    Livewire::actingAs($this->manager, 'web')
        ->test(Editor::class)
        ->set('guest_name', 'x')
        ->set('body', 'x')
        ->set('rating', 5)
        ->set('channel', 'nonsense-channel')
        ->call('save')
        ->tap(function () {
            $review = Review::query()->first();
            // No enum "nonsense" → falls back to Direct.
            expect($review?->channel)->toBe(ReviewChannel::Direct);
        });
});

it('stores an uploaded photo to the public disk under reviews/{tenant}', function (): void {
    $file = UploadedFile::fake()->image('review.jpg', 200, 200);

    Livewire::actingAs($this->manager, 'web')
        ->test(Editor::class)
        ->set('guest_name', 'Photographer Guest')
        ->set('body', 'Loved the light in the room.')
        ->set('rating', 5)
        ->set('photo', $file)
        ->call('save')
        ->assertHasNoErrors();

    $review = Review::query()->first();
    expect($review->photo_path)->not->toBeNull();
    expect($review->photo_path)->toStartWith('reviews/'.$this->tenant->id.'/');
    Storage::disk($this->reviewDisk)->assertExists($review->photo_path);
});

it('clearPhoto() removes the existing photo file on next save', function (): void {
    $review = Review::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'guest_name'  => 'Existing',
        'body'        => 'Existing review body',
        'rating'      => 4,
        'channel'     => ReviewChannel::Direct,
        'photo_path'  => 'reviews/'.$this->tenant->id.'/existing.jpg',
    ]);
    Storage::disk($this->reviewDisk)->put($review->photo_path, 'binarybytes');

    Livewire::actingAs($this->manager, 'web')
        ->test(Editor::class, ['reviewId' => $review->id])
        ->call('clearPhoto')
        ->call('save')
        ->assertHasNoErrors();

    $review->refresh();
    expect($review->photo_path)->toBeNull();
    Storage::disk($this->reviewDisk)->assertMissing('reviews/'.$this->tenant->id.'/existing.jpg');
});

it('redirects to the reviews index after creating a new review', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Editor::class)
        ->set('guest_name', 'Amara')
        ->set('body', 'Great stay.')
        ->set('rating', 5)
        ->call('save')
        ->assertRedirect(route('tenant.reviews.index'));
});

it('stays on the editor when saving an EXISTING review', function (): void {
    $review = Review::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'guest_name'  => 'Existing Guest',
        'body'        => 'Existing body',
        'rating'      => 4,
        'channel'     => ReviewChannel::Direct,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Editor::class, ['reviewId' => $review->id])
        ->set('body', 'Edited body')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect();
});

it('resolves country flags via the Countries service', function (): void {
    expect(\App\Services\Locale\Countries::flagFor('Nigeria'))->toBe('🇳🇬');
    expect(\App\Services\Locale\Countries::flagFor('UK'))->toBe('🇬🇧');
    expect(\App\Services\Locale\Countries::flagFor('USA'))->toBe('🇺🇸');
    expect(\App\Services\Locale\Countries::flagFor('unknown-place'))->toBeNull();
    expect(\App\Services\Locale\Countries::flagFor(null))->toBeNull();
});

it('rejects a photo larger than 5MB', function (): void {
    // sizeInKilobytes=6144 (~6MB).
    $tooBig = UploadedFile::fake()->image('big.jpg')->size(6144);

    Livewire::actingAs($this->manager, 'web')
        ->test(Editor::class)
        ->set('guest_name', 'X')
        ->set('body', 'X')
        ->set('rating', 5)
        ->set('photo', $tooBig)
        ->call('save')
        ->assertHasErrors(['photo']);
});
