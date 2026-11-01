<?php

declare(strict_types=1);

use App\Actions\Domains\CheckOwnDomain;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
 * A business's own domain (docs/MULTITENANCY.md, step 4): guests move to
 * it only while it answers for the business over HTTPS; staff and
 * payment paths keep working on both addresses.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withCustomDomain('grandpalace.ng')->create(['slug' => 'grandpalace']);
    $this->branch = Property::factory()->live()->for($this->tenant)->create(['name' => 'Grand Palace Wuse']);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('sends booking pages on the platform address to the live own domain', function (): void {
    $this->get("http://grandpalace.keverna.test/branches/{$this->branch->slug}?ref=ig")
        ->assertStatus(301)
        ->assertRedirect("https://grandpalace.ng/branches/{$this->branch->slug}?ref=ig");
});

it('serves the booking site on the own domain itself', function (): void {
    $this->get("http://grandpalace.ng/branches/{$this->branch->slug}")
        ->assertOk()->assertSee('Grand Palace Wuse');
});

it('keeps staff login and the ping on both addresses', function (): void {
    $this->get('http://grandpalace.keverna.test/login')->assertOk();
    $this->get('http://grandpalace.ng/login')->assertOk();
    $this->get('http://grandpalace.keverna.test/__tenant-ping')->assertOk();
});

it('does not move guests while the own domain is not live', function (): void {
    $this->tenant->forceFill(['custom_domain_live_at' => null])->save();

    $this->get("http://grandpalace.keverna.test/branches/{$this->branch->slug}")->assertOk();
});

it('stops sending guests to the own domain after three misses in a row, and resumes when it answers', function (): void {
    Http::fake(['https://grandpalace.ng/__tenant-ping' => Http::sequence()
        ->push('', 502)->push('', 502)->push('', 502)
        ->push(['tenant_id' => $this->tenant->id]),
    ]);
    $check = app(CheckOwnDomain::class);

    $check->handle($this->tenant);
    $check->handle($this->tenant);
    expect($this->tenant->refresh()->ownDomainIsLive())->toBeTrue();

    $check->handle($this->tenant);
    expect($this->tenant->refresh()->ownDomainIsLive())->toBeFalse()
        ->and($this->tenant->primaryHost())->toBe('grandpalace.keverna.test');

    expect($check->handle($this->tenant))->toBeTrue();
    expect($this->tenant->refresh()->ownDomainIsLive())->toBeTrue()
        ->and($this->tenant->custom_domain_failed_checks)->toBe(0);
});

it('does not count an answer for another business', function (): void {
    Http::fake(['https://grandpalace.ng/__tenant-ping' => Http::response(['tenant_id' => 999])]);
    $this->tenant->forceFill(['custom_domain_live_at' => null])->save();

    expect(app(CheckOwnDomain::class)->handle($this->tenant))->toBeFalse()
        ->and($this->tenant->refresh()->ownDomainIsLive())->toBeFalse();
});

it('checks every verified own domain from the hourly command', function (): void {
    $other = Tenant::factory()->create(['slug' => 'other', 'custom_domain' => 'other.ng', 'domain_verified_at' => now()]);
    Tenant::factory()->create(['slug' => 'unverified', 'custom_domain' => 'pending.ng']);
    Http::fake([
        'https://grandpalace.ng/*' => Http::response(['tenant_id' => $this->tenant->id]),
        'https://other.ng/*' => Http::response(['tenant_id' => $other->id]),
        '*' => Http::response('', 500),
    ]);

    Artisan::call('domains:check-own');

    expect($other->refresh()->ownDomainIsLive())->toBeTrue();
    Http::assertSentCount(2);
});
