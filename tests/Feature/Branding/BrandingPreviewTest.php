<?php

declare(strict_types=1);

use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'brand-prev']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->live()->for($this->tenant)->create([
        'primary_color' => '#222222',
        'tagline' => 'Saved tagline',
        'font_theme' => 'inter_playfair',
    ]);
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('renders the landing page with session overrides applied', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->withSession([
            'branding_preview.'.$this->operator->id => [
                'tenant_id' => $this->tenant->id,
                'primary_color' => '#ff00aa',
                'tagline' => 'Live preview tagline',
                'font_theme' => 'lora_lora',
                'section_visibility' => [
                    'amenities' => false, 'map' => true, 'reviews' => true, 'contact_block' => true,
                ],
                'whatsapp_button_enabled' => false,
                'whatsapp_button_prefill' => null,
            ],
        ])
        ->get('http://brand-prev.keverna.test/__branding-preview');

    $response->assertOk();
    $response->assertSee('Live preview tagline');
    $response->assertSee('#ff00aa');
});

it('falls back to the saved branding when no session overrides exist', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://brand-prev.keverna.test/__branding-preview');

    $response->assertOk();
    $response->assertSee('Saved tagline');
});

it('ignores session overrides that name a different tenant', function (): void {
    $foreignTenantId = $this->tenant->id + 99;

    $response = $this->actingAs($this->operator, 'web')
        ->withSession([
            'branding_preview.'.$this->operator->id => [
                'tenant_id' => $foreignTenantId,
                'primary_color' => '#000000',
                'tagline' => 'Should not appear',
                'font_theme' => 'inter_playfair',
                'section_visibility' => [],
                'whatsapp_button_enabled' => false,
                'whatsapp_button_prefill' => null,
            ],
        ])
        ->get('http://brand-prev.keverna.test/__branding-preview');

    $response->assertOk();
    $response->assertDontSee('Should not appear');
    $response->assertSee('Saved tagline');
});

it('requires authentication — guest hits the tenant login redirect', function (): void {
    $response = $this->get('http://brand-prev.keverna.test/__branding-preview');

    // Guest is bounced to the tenant login route by the auth:web middleware.
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/login');
});

it('does not allow operator A on tenant X to inject overrides into tenant Y\'s preview', function (): void {
    Tenant::forgetCurrent();
    $tenantY = Tenant::factory()->create(['slug' => 'brand-prev-y']);
    $tenantY->makeCurrent();
    Property::factory()->live()->for($tenantY)->create([
        'tagline' => 'Tenant Y saved tagline',
    ]);
    $operatorY = Operator::factory()->for($tenantY)->create();

    $response = $this->actingAs($operatorY, 'web')
        ->withSession([
            // Session key matches operator A (this->operator from tenant X);
            // operator Y isn't authenticated under that id, so the controller
            // looks up `branding_preview.{Y->id}` and finds nothing.
            'branding_preview.'.$this->operator->id => [
                'tenant_id' => $this->tenant->id,
                'tagline' => 'Cross-tenant injection attempt',
                'primary_color' => '#000000',
                'font_theme' => 'inter_playfair',
                'section_visibility' => [],
                'whatsapp_button_enabled' => false,
                'whatsapp_button_prefill' => null,
            ],
        ])
        ->get('http://brand-prev-y.keverna.test/__branding-preview');

    $response->assertOk();
    $response->assertDontSee('Cross-tenant injection attempt');
    $response->assertSee('Tenant Y saved tagline');
});
