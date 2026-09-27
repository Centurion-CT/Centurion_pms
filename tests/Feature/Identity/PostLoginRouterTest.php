<?php

declare(strict_types=1);

use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\Identity\PostLoginRouter;
use Illuminate\Support\Facades\Hash;

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/**
 * Build an identity linked to N operators across N tenants. Returns the
 * identity + the list of link ids in creation order.
 *
 * @return array{IdentityAccount, list<int>}
 */
function makeIdentityWithOperatorLinks(int $count): array
{
    $identity = IdentityAccount::create([
        'email' => 'test-'.uniqid().'@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Test',
    ]);

    $linkIds = [];
    for ($i = 0; $i < $count; $i++) {
        $tenant = Tenant::factory()->create();
        $tenant->makeCurrent();
        $operator = Operator::factory()->for($tenant)->create();
        Tenant::forgetCurrent();

        $link = IdentityOperatorLink::create([
            'identity_account_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'operator_id' => $operator->id,
        ]);
        $linkIds[] = $link->id;
    }

    return [$identity, $linkIds];
}

it('routes a single-operator, no-guest identity to handoff', function (): void {
    [$identity, $linkIds] = makeIdentityWithOperatorLinks(1);

    $result = (new PostLoginRouter)->resolve($identity);

    expect($result['kind'])->toBe('handoff');
    expect($result['link_id'])->toBe($linkIds[0]);
});

it('routes a multi-tenant operator to the chooser', function (): void {
    [$identity, ] = makeIdentityWithOperatorLinks(2);

    $result = (new PostLoginRouter)->resolve($identity);

    expect($result['kind'])->toBe('choose');
});

it('routes an identity with zero links to the chooser (empty-state case)', function (): void {
    $identity = IdentityAccount::create([
        'email' => 'lonely@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Solo',
    ]);

    $result = (new PostLoginRouter)->resolve($identity);

    expect($result['kind'])->toBe('choose');
});

it('honours preferred_link_id when set', function (): void {
    [$identity, $linkIds] = makeIdentityWithOperatorLinks(3);

    // Prefer the second tenant.
    $identity->forceFill(['preferred_link_id' => $linkIds[1]])->save();
    $identity->refresh();

    $result = (new PostLoginRouter)->resolve($identity);

    expect($result['kind'])->toBe('handoff');
    expect($result['link_id'])->toBe($linkIds[1]);
});

it('falls through to the normal rule when preferred_link_id points at a link belonging to a different identity', function (): void {
    [$identity, ] = makeIdentityWithOperatorLinks(2);
    [, $otherLinkIds] = makeIdentityWithOperatorLinks(1);

    // Someone else's link id. FK still holds (the row exists), but the
    // link isn't in $identity->operatorLinks(), so the router refuses
    // to route through it and falls through to the normal rules.
    $identity->forceFill(['preferred_link_id' => $otherLinkIds[0]])->save();
    $identity->refresh();

    $result = (new PostLoginRouter)->resolve($identity);

    // $identity has 2 operator links → normal rule sends them to /choose.
    expect($result['kind'])->toBe('choose');
});
