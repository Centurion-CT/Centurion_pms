<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/* --------------------------------------------------------------------------
 *  Status branching (PRD §4.2 / §6.4.1)
 * ------------------------------------------------------------------------ */

it('shows "Coming Soon" when the property is not yet live', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'not-live', 'name' => 'Not Live Co']);
    Property::factory()->for($tenant)->create(['is_live' => false]);

    $response = $this->get(landingUrl('not-live.keverna.test'));

    $response->assertOk();
    $response->assertSee('Not Live Co');
    $response->assertSeeText('coming soon');
});

it('sends the root to the staff sign-in', function (): void {
    Tenant::factory()->create(['slug' => 'no-prop', 'name' => 'No Property Co']);

    $this->get('http://no-prop.keverna.test/')->assertRedirect(route('tenant.login'));
});

it('shows the full landing page when the property is live', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'live-co', 'name' => 'Live Co']);
    Property::factory()->live()->for($tenant)->create([
        'name' => 'Live Property',
        'tagline' => 'A sunny rooftop pool',
        'description' => 'Welcome to our property.',
    ]);

    $response = $this->get(landingUrl('live-co.keverna.test'));

    $response->assertOk();
    $response->assertSeeHtml('data-testid="hero"');
    $response->assertSee('Live Property');
    $response->assertSee('A sunny rooftop pool');
    $response->assertSeeHtml('data-testid="footer"');
    $response->assertSee('Powered by');
});

it('shows "temporarily unavailable" with HTTP 200 (not 404) for a Locked tenant (PRD §4.2)', function (): void {
    Tenant::factory()->locked()->create([
        'slug' => 'paused-co',
        'name' => 'Paused Co',
        'email' => 'ops@paused.test',
    ]);

    $response = $this->get(landingUrl('paused-co.keverna.test'));

    $response->assertOk();
    $response->assertSee('Paused Co is temporarily unavailable');
});

it('does NOT serve the landing for an Archived tenant — DomainTenantFinder 404s upstream', function (): void {
    Tenant::factory()->archived()->create(['slug' => 'gone-co']);

    $response = $this->get(landingUrl('gone-co.keverna.test'));

    $response->assertNotFound();
});

it('renders the landing for a tenant in Grace (still operational, just past expiry)', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'grace-co',
        'name' => 'Grace Co',
        'status' => TenantStatus::Grace,
    ]);
    Property::factory()->live()->for($tenant)->create();

    $response = $this->get(landingUrl('grace-co.keverna.test'));

    $response->assertOk();
    $response->assertSeeHtml('data-testid="hero"');
});

/* --------------------------------------------------------------------------
 *  Room gallery + reviews
 * ------------------------------------------------------------------------ */

it('renders one room card per Active room', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'rooms-listed']);
    $property = Property::factory()->live()->for($tenant)->create();
    Room::factory()->for($tenant)->for($property)->create(['name' => 'Suite A']);
    Room::factory()->for($tenant)->for($property)->create(['name' => 'Suite B']);
    Room::factory()->for($tenant)->for($property)->inactive()->create(['name' => 'Hidden Suite']);

    $response = $this->get(landingUrl('rooms-listed.keverna.test'));

    $response->assertOk();
    $response->assertSee('Suite A');
    $response->assertSee('Suite B');
    $response->assertDontSee('Hidden Suite');
});

it('shows the "Rooms coming soon" empty state when no active rooms exist', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'no-rooms-live']);
    Property::factory()->live()->for($tenant)->create();

    $response = $this->get(landingUrl('no-rooms-live.keverna.test'));

    $response->assertOk();
    $response->assertSeeHtml('data-testid="no-rooms"');
});

it('renders only is_displayed=true reviews', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'reviews-displayed']);
    $property = Property::factory()->live()->for($tenant)->create();
    Review::factory()->for($tenant)->for($property)->create(['body' => 'Loved my stay']);
    Review::factory()->for($tenant)->for($property)->hidden()->create(['body' => 'Hidden review']);

    $response = $this->get(landingUrl('reviews-displayed.keverna.test'));

    $response->assertOk();
    $response->assertSee('Loved my stay');
    $response->assertDontSee('Hidden review');
});

it('hides the reviews section when section_visibility.reviews = false', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'reviews-off']);
    $property = Property::factory()->live()->for($tenant)->create([
        'section_visibility' => ['reviews' => false],
    ]);
    Review::factory()->for($tenant)->for($property)->create(['body' => 'Should not show']);

    $response = $this->get(landingUrl('reviews-off.keverna.test'));

    $response->assertOk();
    $response->assertDontSee('Should not show');
    $response->assertDontSeeHtml('data-testid="reviews-section"');
});

/* --------------------------------------------------------------------------
 *  Branding + WhatsApp button
 * ------------------------------------------------------------------------ */

it('renders the property primary_color into the CSS', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'brand-color']);
    Property::factory()->live()->for($tenant)->create(['primary_color' => '#ff5500']);

    $response = $this->get(landingUrl('brand-color.keverna.test'));

    $response->assertOk();
    $response->assertSee('--primary: #ff5500');
});

it('renders the WhatsApp button only when enabled AND tenant has a phone', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'wa-on',
        'phone' => '+2348012345678',
    ]);
    Property::factory()->live()->for($tenant)->create([
        'whatsapp_button_enabled' => true,
        'whatsapp_button_prefill' => 'Hi from your website',
    ]);

    $response = $this->get(landingUrl('wa-on.keverna.test'));

    $response->assertOk();
    $response->assertSeeHtml('data-testid="whatsapp-button"');
    $response->assertSee('wa.me/2348012345678');
});

it('hides the WhatsApp button when whatsapp_button_enabled = false', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'wa-off', 'phone' => '+2348000000000']);
    Property::factory()->live()->for($tenant)->create(['whatsapp_button_enabled' => false]);

    $response = $this->get(landingUrl('wa-off.keverna.test'));

    $response->assertDontSeeHtml('data-testid="whatsapp-button"');
});

/* --------------------------------------------------------------------------
 *  SEO — OG + canonical
 * ------------------------------------------------------------------------ */

it('emits Open Graph meta tags with the property name', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'og-test']);
    Property::factory()->live()->for($tenant)->create([
        'name' => 'OG Test Property',
        'tagline' => 'The best view in town',
    ]);

    $response = $this->get(landingUrl('og-test.keverna.test'));

    $response->assertSee('property="og:title"', escape: false);
    $response->assertSee('OG Test Property', escape: false);
    $response->assertSee('property="og:description"', escape: false);
    $response->assertSee('property="og:url"', escape: false);
    $response->assertSee('twitter:card', escape: false);
});

it('canonical points to the subdomain when no custom domain is set', function (): void {
    Tenant::factory()->create(['slug' => 'canon-sub'])
        ->property()->save(Property::factory()->live()->make());

    $response = $this->get(landingUrl('canon-sub.keverna.test'));

    $response->assertSee('rel="canonical" href="https://canon-sub.keverna.test/"', escape: false);
});

it('canonical points to the verified custom domain when set', function (): void {
    $tenant = Tenant::factory()
        ->withCustomDomain('reservations.brand.com')
        ->create(['slug' => 'canon-custom']);
    Property::factory()->live()->for($tenant)->create();

    $response = $this->get(landingUrl('reservations.brand.com'));

    $response->assertSee('rel="canonical" href="https://reservations.brand.com/"', escape: false);
});

/* --------------------------------------------------------------------------
 *  Sitemap.xml
 * ------------------------------------------------------------------------ */

it('serves a sitemap.xml that lists the landing + every active room', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'sitemap-test']);
    $property = Property::factory()->live()->for($tenant)->create();
    $a = Room::factory()->for($tenant)->for($property)->create();
    $b = Room::factory()->for($tenant)->for($property)->create();
    Room::factory()->for($tenant)->for($property)->inactive()->create();

    $response = $this->get('http://sitemap-test.keverna.test/sitemap.xml');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $response->assertSee('<loc>https://sitemap-test.keverna.test/</loc>', escape: false);
    $response->assertSee('<loc>https://sitemap-test.keverna.test/rooms/'.$a->id.'</loc>', escape: false);
    $response->assertSee('<loc>https://sitemap-test.keverna.test/rooms/'.$b->id.'</loc>', escape: false);
});

/* --------------------------------------------------------------------------
 *  Cache + cross-tenant isolation
 * ------------------------------------------------------------------------ */

it('caches the rendered landing page for 5 minutes per tenant', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'cache-test']);
    Property::factory()->live()->for($tenant)->create(['name' => 'Cached Property']);

    Cache::flush();

    $first = $this->get(landingUrl('cache-test.keverna.test'));
    $first->assertOk();
    $first->assertHeader('X-Cache-Key');
    $key = $first->headers->get('X-Cache-Key');

    expect(Cache::has($key))->toBeTrue();
});

it('cache key embeds the property updated_at timestamp so edits bust the cache', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'cache-bust']);
    $property = Property::factory()->live()->for($tenant)->create(['name' => 'Bust Property']);

    $key = $this->get(landingUrl('cache-bust.keverna.test'))->headers->get('X-Cache-Key');

    // The cache key shape is `landing:{tenant}:{property}:{timestamp}` so a
    // change to property.updated_at deterministically produces a new key on
    // the next request — bust-on-edit without an explicit cache flush.
    expect($key)->toMatch('/^landing:'.$tenant->id.':'.$property->id.':\d+$/');
    expect($key)->toContain((string) $property->updated_at->timestamp);
});

it('does NOT leak another tenant\'s rooms into the gallery (cross-tenant isolation)', function (): void {
    $tenantA = Tenant::factory()->create(['slug' => 'iso-a']);
    $propA = Property::factory()->live()->for($tenantA)->create();
    Room::factory()->for($tenantA)->for($propA)->create(['name' => 'Mine Suite']);

    $tenantB = Tenant::factory()->create();
    $propB = Property::factory()->live()->for($tenantB)->create();
    Room::factory()->for($tenantB)->for($propB)->create(['name' => 'Theirs Suite']);

    $response = $this->get(landingUrl('iso-a.keverna.test'));

    $response->assertOk();
    $response->assertSee('Mine Suite');
    $response->assertDontSee('Theirs Suite');
});
