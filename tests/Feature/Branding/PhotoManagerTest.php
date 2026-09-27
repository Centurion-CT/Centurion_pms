<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Branding\Photos;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('filesystems.default', 'public');
    config()->set('media-library.disk_name', 'public');
    Storage::fake('public');

    $this->tenant = Tenant::factory()->create(['slug' => 'photo-mgr']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/* ---------- Gallery upload (auto-fires from JS after staging) ---------- */

it('uploads gallery photos onto the property', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('gallery', [
            UploadedFile::fake()->image('a.jpg', 600, 400),
            UploadedFile::fake()->image('b.jpg', 600, 400),
        ])
        ->call('uploadGallery')
        ->assertHasNoErrors();

    expect($this->property->getMedia('gallery'))->toHaveCount(2);
});

it('caps gallery at 10 photos', function (): void {
    // Seed 10 via the same code path the UI takes so temp files don't
    // get garbage-collected mid-loop (which happens if we call
    // UploadedFile::fake()->image()->getRealPath() 10 times in one PHP
    // process without holding references).
    $seeds = [];
    for ($i = 1; $i <= 10; $i++) {
        $seeds[] = UploadedFile::fake()->image("seed{$i}.jpg", 200, 200);
    }
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('gallery', $seeds)
        ->call('uploadGallery');

    expect($this->property->fresh()->getMedia('gallery'))->toHaveCount(10);

    // Now try to push 2 more past the cap.
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('gallery', [
            UploadedFile::fake()->image('extra1.jpg', 200, 200),
            UploadedFile::fake()->image('extra2.jpg', 200, 200),
        ])
        ->call('uploadGallery');

    expect($this->property->fresh()->getMedia('gallery'))->toHaveCount(10);
});

/* ---------- Hero flag ---------- */

it('marks one gallery photo as hero exclusively', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('gallery', [
            UploadedFile::fake()->image('a.jpg', 200, 200),
            UploadedFile::fake()->image('b.jpg', 200, 200),
        ])
        ->call('uploadGallery');

    $media = $this->property->fresh()->getMedia('gallery');
    $first = $media[0];
    $second = $media[1];

    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->call('setGalleryHero', $first->id);

    expect((bool) $first->refresh()->getCustomProperty('is_hero'))->toBeTrue();
    expect((bool) $second->refresh()->getCustomProperty('is_hero'))->toBeFalse();

    // Flipping to the second unmarks the first.
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->call('setGalleryHero', $second->id);

    expect((bool) $first->refresh()->getCustomProperty('is_hero'))->toBeFalse();
    expect((bool) $second->refresh()->getCustomProperty('is_hero'))->toBeTrue();
});

/* ---------- Reorder ---------- */

it('persists drag-reorder of the gallery', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('gallery', [
            UploadedFile::fake()->image('a.jpg', 200, 200),
            UploadedFile::fake()->image('b.jpg', 200, 200),
            UploadedFile::fake()->image('c.jpg', 200, 200),
        ])
        ->call('uploadGallery');

    $media = $this->property->fresh()->getMedia('gallery');
    $reversed = $media->pluck('id')->reverse()->values()->all();

    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->call('reorderGallery', $reversed);

    $newOrder = $this->property->fresh()->getMedia('gallery')->pluck('id')->all();
    expect($newOrder)->toBe($reversed);
});

it('ignores foreign-tenant ids in reorder input', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('gallery', [UploadedFile::fake()->image('a.jpg', 200, 200)])
        ->call('uploadGallery');

    $ourId = $this->property->fresh()->getMedia('gallery')->first()->id;
    $spoofedId = $ourId + 9999;

    // Doesn't blow up; just ignores the bad id.
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->call('reorderGallery', [$spoofedId, $ourId])
        ->assertHasNoErrors();

    expect($this->property->fresh()->getMedia('gallery')->pluck('id')->all())
        ->toBe([$ourId]);
});

/* ---------- Bulk delete ---------- */

it('bulk-deletes selected gallery photos', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('gallery', [
            UploadedFile::fake()->image('a.jpg', 200, 200),
            UploadedFile::fake()->image('b.jpg', 200, 200),
            UploadedFile::fake()->image('c.jpg', 200, 200),
        ])
        ->call('uploadGallery');

    $ids = $this->property->fresh()->getMedia('gallery')->pluck('id')->all();

    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->call('deleteGalleryPhotos', [$ids[0], $ids[1]]);

    $remaining = $this->property->fresh()->getMedia('gallery');
    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->id)->toBe($ids[2]);
});

it('single-tile delete still works from the shared component', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('gallery', [UploadedFile::fake()->image('a.jpg', 200, 200)])
        ->call('uploadGallery');

    $id = $this->property->fresh()->getMedia('gallery')->first()->id;

    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->call('deleteGalleryPhoto', $id);

    expect($this->property->fresh()->getMedia('gallery'))->toHaveCount(0);
});

/* ---------- Logo (unchanged, sanity check) ---------- */

it('still uploads and removes the logo', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('logo', UploadedFile::fake()->image('brand.png', 300, 300))
        ->call('uploadLogo')
        ->assertHasNoErrors();

    expect($this->property->getFirstMedia('logo'))->not->toBeNull();

    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->call('deleteLogo');

    expect($this->property->fresh()->getFirstMedia('logo'))->toBeNull();
});

it('accepts a JPEG logo (operators often only have the JPG export)', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('logo', UploadedFile::fake()->image('brand.jpg', 300, 300))
        ->call('uploadLogo')
        ->assertHasNoErrors();

    expect($this->property->getFirstMedia('logo'))->not->toBeNull();
});

it('accepts a WebP logo', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('logo', UploadedFile::fake()->image('brand.webp', 300, 300))
        ->call('uploadLogo')
        ->assertHasNoErrors();

    expect($this->property->getFirstMedia('logo'))->not->toBeNull();
});

it('still rejects non-image logo uploads (e.g. PDF)', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(Photos::class)
        ->set('logo', UploadedFile::fake()->create('brand.pdf', 100, 'application/pdf'))
        ->call('uploadLogo')
        ->assertHasErrors('logo');

    expect($this->property->fresh()->getFirstMedia('logo'))->toBeNull();
});
