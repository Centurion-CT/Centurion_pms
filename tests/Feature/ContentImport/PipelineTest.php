<?php

declare(strict_types=1);

use App\Enums\ContentImportStatus;
use App\Jobs\RunPropertyContentImport;
use App\Models\Admin;
use App\Models\AiExtraction;
use App\Models\Operator;
use App\Models\Property;
use App\Models\PropertyContentImport;
use App\Models\Tenant;
use App\Notifications\PropertyContentReadyNotification;
use App\Services\ContentImport\GooglePlacesExtractor;
use App\Services\ContentImport\ImportBudget;
use App\Services\ContentImport\ListingContentExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'content-import-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'name' => 'Boring Placeholder',
        'description' => null,
    ]);
    Tenant::forgetCurrent();

    // Give the extractors a valid config so the "not configured" branch
    // doesn't trip. Real Anthropic / Google URLs are faked below.
    config()->set('services.content_import.anthropic.api_key', 'sk-test');
    config()->set('services.content_import.anthropic.model', 'claude-sonnet-4-6');
    config()->set('services.content_import.anthropic.base_url', 'https://api.anthropic.test');
    config()->set('services.content_import.google_places.api_key', 'gp-test');
});

it('runs Google Places + Claude Sonnet 4.6, lands a Ready row, logs audit rows', function (): void {
    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'places' => [[
                'displayName' => ['text' => 'Grand Palace Abuja'],
                'formattedAddress' => '5 Embassy Rd, Maitama, Abuja',
                'internationalPhoneNumber' => '+2348012345678',
                'rating' => 4.6,
                'userRatingCount' => 128,
                'editorialSummary' => ['text' => 'A boutique stay in Maitama with 24/7 power.'],
                'addressComponents' => [
                    ['types' => ['locality'], 'longText' => 'Abuja'],
                    ['types' => ['administrative_area_level_1'], 'longText' => 'FCT'],
                ],
                'regularOpeningHours' => ['weekdayDescriptions' => ['Mon-Sun: 24 hours']],
            ]],
        ], 200),
        'www.booking.com/*' => Http::response('<html><body>Booking listing HTML</body></html>', 200),
        'api.anthropic.test/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'description'    => 'Boutique five-room hotel with 24/7 power and fibre wifi.',
                    'amenities'      => ['Free Wi-Fi', 'Air conditioning', '24/7 backup power'],
                    'house_rules'    => ['No smoking', 'No parties'],
                    'check_in_time'  => '15:00',
                    'check_out_time' => '11:00',
                    'rating'         => 4.5,
                    'review_count'   => 87,
                ]),
            ]],
            'usage' => [
                'input_tokens' => 4200,
                'output_tokens' => 320,
                'cache_read_input_tokens' => 0,
            ],
        ], 200),
    ]);

    $import = PropertyContentImport::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'google_url'  => 'https://maps.app.goo.gl/xyz',
        'booking_url' => 'https://www.booking.com/hotel/ng/grand-palace-abuja.html',
        'status'      => ContentImportStatus::Pending,
    ]);

    (new RunPropertyContentImport($import->id))->handle(
        app(GooglePlacesExtractor::class),
        app(ListingContentExtractor::class),
    );

    $import->refresh();
    expect($import->status)->toBe(ContentImportStatus::Ready);
    expect($import->suggested_content)->toBeArray();
    expect($import->suggested_content['google']['name'])->toBe('Grand Palace Abuja');
    expect($import->suggested_content['booking']['description'])->toContain('Boutique five-room');

    $rows = AiExtraction::query()->where('import_id', $import->id)->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('provider')->all())->toEqualCanonicalizing(['google_places', 'anthropic_claude']);
    expect($rows->every(fn ($r) => $r->succeeded))->toBeTrue();
});

it('flips to Failed and captures error when every extractor breaks', function (): void {
    Http::fake([
        'places.googleapis.com/*' => Http::response('boom', 500),
    ]);

    $import = PropertyContentImport::query()->create([
        'tenant_id'   => $this->tenant->id,
        'property_id' => $this->property->id,
        'google_url'  => 'https://maps.app.goo.gl/broken',
        'status'      => ContentImportStatus::Pending,
    ]);

    (new RunPropertyContentImport($import->id))->handle(
        app(GooglePlacesExtractor::class),
        app(ListingContentExtractor::class),
    );

    $import->refresh();
    expect($import->status)->toBe(ContentImportStatus::Failed);
    expect($import->error_message)->toContain('Google');

    $errRow = AiExtraction::query()->where('import_id', $import->id)->first();
    expect($errRow)->not->toBeNull();
    expect($errRow->succeeded)->toBeFalse();
    expect($errRow->error)->toContain('500');
});

it('refuses new fetches when monthly spend exceeds the hard cap', function (): void {
    // Simulate ₦60,000 already spent this month (kobo = ×100).
    AiExtraction::query()->create([
        'provider'  => 'anthropic_claude',
        'model'     => 'claude-sonnet-4-6',
        'cost_kobo' => 6_000_000,
        'succeeded' => true,
        'ran_at'    => now(),
    ]);
    config()->set('services.content_import.hard_cap_kobo', 5_000_000);

    expect(fn () => app(ImportBudget::class)->assertWithinHardCap())
        ->toThrow(RuntimeException::class, 'hard cap');
});

it('approve() writes accepted fields to the property + notifies operators', function (): void {
    Notification::fake();

    $this->tenant->execute(function () {
        Operator::factory()->for($this->tenant)->create([
            'email' => 'gm@grandpalace.test',
        ]);
    });

    $import = PropertyContentImport::query()->create([
        'tenant_id'         => $this->tenant->id,
        'property_id'       => $this->property->id,
        'google_url'        => 'https://maps.app.goo.gl/xyz',
        'status'            => ContentImportStatus::Ready,
        'suggested_content' => [
            'google' => [
                'name'        => 'Grand Palace Abuja',
                'description' => 'A boutique stay in Maitama.',
                'city'        => 'Abuja',
                'state'       => 'FCT',
            ],
        ],
        'fetched_at' => now(),
    ]);

    $admin = Admin::factory()->super()->create();
    $this->actingAs($admin, 'admin');

    // Property writes go through the tenant-bound model — bind the
    // current tenant so the save doesn't silently no-op.
    $this->tenant->makeCurrent();

    $component = new \App\Livewire\Admin\PropertyContentImport\Show;
    $component->mount($this->tenant, $this->property->id);
    $component->accept = ['name' => true, 'description' => true, 'city' => true];
    $component->approve($import->id);

    $this->property->refresh();
    expect($this->property->name)->toBe('Grand Palace Abuja');
    expect($this->property->description)->toBe('A boutique stay in Maitama.');
    expect($this->property->city)->toBe('Abuja');

    $import->refresh();
    expect($import->status)->toBe(ContentImportStatus::Approved);
    expect($import->approved_at)->not->toBeNull();
    expect($import->notified_operator_at)->not->toBeNull();

    Notification::assertSentTo(
        Operator::query()->first(),
        PropertyContentReadyNotification::class,
    );
});
