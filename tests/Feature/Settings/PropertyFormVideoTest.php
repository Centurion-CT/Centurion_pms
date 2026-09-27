<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Settings\PropertyForm;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'video-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('accepts a direct .mp4 URL', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('videoUrl', 'https://keverna-file.dokwe.com/tenant_9/tour.mp4')
        ->call('save')
        ->assertHasNoErrors('videoUrl');

    expect($this->property->fresh()->video_url)
        ->toBe('https://keverna-file.dokwe.com/tenant_9/tour.mp4');
});

it('accepts a Vimeo URL', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('videoUrl', 'https://vimeo.com/123456789')
        ->call('save')
        ->assertHasNoErrors('videoUrl');
});

it('still accepts a YouTube URL', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('videoUrl', 'https://www.youtube.com/watch?v=abcDEF12345')
        ->call('save')
        ->assertHasNoErrors('videoUrl');
});

it('rejects a random non-video URL', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('videoUrl', 'https://example.com/some-page')
        ->call('save')
        ->assertHasErrors(['videoUrl' => 'regex']);
});

it('exposes videoPreview describing the pasted URL', function (): void {
    $component = Livewire::actingAs($this->manager, 'web')
        ->test(PropertyForm::class)
        ->set('videoUrl', 'https://keverna-file.dokwe.com/tenant_9/tour.mp4');

    $preview = $component->instance()->videoPreview;
    expect($preview['kind'])->toBe('file');
    expect($preview['src'])->toBe('https://keverna-file.dokwe.com/tenant_9/tour.mp4');
});
