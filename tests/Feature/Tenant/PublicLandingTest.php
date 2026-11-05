<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;

/* --------------------------------------------------------------------------
 *  Status branching (PRD §4.2 / §6.4.1)
 * ------------------------------------------------------------------------ */

it('says booking opens soon when the property is not yet live', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'not-live', 'name' => 'Not Live Co']);
    Property::factory()->for($tenant)->create(['is_live' => false]);

    $response = $this->get(landingUrl('not-live.keverna.test'));

    $response->assertOk();
    $response->assertSee('Online booking here opens soon');
});

it('shows the booking page at the root, not the staff sign-in, before anything is bookable', function (): void {
    Tenant::factory()->create(['slug' => 'no-prop', 'name' => 'No Property Co']);

    $this->get('http://no-prop.keverna.test/')->assertOk()->assertSee('Online booking opens soon');
});

it('shows a live building with no rooms on the booking site, with its contacts', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'live-co', 'name' => 'Live Co', 'phone' => '0803 000 0000']);
    Property::factory()->live()->for($tenant)->create(['name' => 'Live Property']);

    $this->get(landingUrl('live-co.keverna.test'))
        ->assertOk()
        ->assertSee('site-header', false)
        ->assertSee('Live Property')
        ->assertSee('Call 0803 000 0000')
        ->assertSee('Runs on Centurion');
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
    $response->assertSee('site-header', false);
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

it("uses the business's colour on the building's page", function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'colour-co']);
    Property::factory()->live()->for($tenant)->create(['primary_color' => '#0f6b5c']);

    $this->get(landingUrl('colour-co.keverna.test'))->assertSee('--action: #0f6b5c', false);
});

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
