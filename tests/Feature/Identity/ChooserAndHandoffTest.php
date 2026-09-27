<?php

declare(strict_types=1);

use App\Livewire\Central\Chooser;
use App\Livewire\Central\Reservations;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/**
 * @return array{IdentityAccount, list<IdentityOperatorLink>}
 */
function makeIdentityWithLinks(array $lastLoginByIndex = []): array
{
    $identity = IdentityAccount::create([
        'email' => 'chooser-'.uniqid().'@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Chooser',
    ]);

    $links = [];
    foreach ($lastLoginByIndex as $i => $lastLogin) {
        $tenant = Tenant::factory()->create(['slug' => 'ct-'.$i.'-'.uniqid()]);
        $tenant->makeCurrent();
        $operator = Operator::factory()->for($tenant)->create();
        Tenant::forgetCurrent();

        $links[] = IdentityOperatorLink::create([
            'identity_account_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'operator_id' => $operator->id,
            'last_login_at' => $lastLogin,
        ]);
    }

    return [$identity, $links];
}

it('requires auth on /choose', function (): void {
    $response = $this->get('http://keverna.test/choose');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/login');
});

it('renders one card per operator link, ordered by most recent last_login_at', function (): void {
    [$identity, $links] = makeIdentityWithLinks([
        now()->subDays(5),   // link 0 — older
        now()->subHours(2),  // link 1 — most recent
        null,                 // link 2 — never used → last
    ]);

    Livewire::actingAs($identity, 'identity')
        ->test(Chooser::class)
        ->assertOk()
        // Chooser's ordered link list — the middle-index one should come first.
        ->assertViewHas('links', function ($collection) use ($links) {
            $ids = $collection->pluck('id')->all();

            return $ids === [$links[1]->id, $links[0]->id, $links[2]->id];
        });
});

it('shows the empty state when no links exist', function (): void {
    [$identity, ] = makeIdentityWithLinks([]);

    Livewire::actingAs($identity, 'identity')
        ->test(Chooser::class)
        ->assertSee('No linked accounts yet.');
});

it('requires auth on /handoff/{link}', function (): void {
    $response = $this->get('http://keverna.test/handoff/1');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/login');
});

it('404s when the authenticated identity tries to open someone else\'s handoff link', function (): void {
    [$mine, ] = makeIdentityWithLinks([now()]);
    [, $otherLinks] = makeIdentityWithLinks([now()]);
    $strangerLink = $otherLinks[0];

    $response = $this->actingAs($mine, 'identity')
        ->get('http://keverna.test/handoff/'.$strangerLink->id);

    $response->assertNotFound();
});

it('stamps last_login_at on the link and redirects to the tenant sso consume URL', function (): void {
    [$identity, $links] = makeIdentityWithLinks([null]);
    $link = $links[0];
    $expectedHost = $link->tenant->slug.config('keverna.tenant_domain_suffix');

    expect($link->last_login_at)->toBeNull();

    $response = $this->actingAs($identity, 'identity')
        ->get('http://keverna.test/handoff/'.$link->id);

    $response->assertRedirect();
    // Redirect points at the tenant subdomain's /sso/consume path with
    // the signed query string.
    expect($response->headers->get('Location'))->toContain("://{$expectedHost}/sso/consume?");
    expect($link->fresh()->last_login_at)->not->toBeNull();
});

it('requires auth on /reservations', function (): void {
    $response = $this->get('http://keverna.test/reservations');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/login');
});

it('renders the reservations dashboard for an authenticated identity', function (): void {
    [$identity, ] = makeIdentityWithLinks([]);

    $this->actingAs($identity, 'identity')
        ->get('http://keverna.test/reservations')
        ->assertOk()
        ->assertSeeLivewire(Reservations::class);
});

it('logs out the identity via POST /logout', function (): void {
    [$identity, ] = makeIdentityWithLinks([]);

    $this->actingAs($identity, 'identity')
        ->post('http://keverna.test/logout')
        ->assertRedirect(route('home'));

    expect(auth('identity')->check())->toBeFalse();
});
