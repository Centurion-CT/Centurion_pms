<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\AvailabilityBlock;
use App\Models\Operator;
use App\Models\Tenant;

require_once __DIR__.'/HostTestHelpers.php';

/**
 * host.role gate, GET host/session, GET host/properties,
 * POST host/properties/{id}/switch and the X-Keverna-Property override.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'host-access', 'name' => 'Palm Hotels']);
    $this->tenant->makeCurrent();

    $this->ikoyi = hostBranch($this->tenant, 'Ikoyi', 4, '1');
    $this->abuja = hostBranch($this->tenant, 'Abuja', 2, '2');

    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager, 'name' => 'Chidi Okafor', 'email' => 'chidi@example.com',
    ]);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk, 'name' => 'Ngozi Eze',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('refuses roles the host app does not serve with 403 role_not_supported', function (string $role): void {
    $op = Operator::factory()->for($this->tenant)->create(['role' => $role]);

    $this->getJson(hostUrl($this->tenant, 'session'), hostHeaders($op))
        ->assertStatus(403)
        ->assertJsonPath('error', 'role_not_supported');
    $this->getJson(hostUrl($this->tenant, 'today'), hostHeaders($op))
        ->assertStatus(403)
        ->assertJsonPath('error', 'role_not_supported');
})->with(['house_cleaner', 'porter', 'supervisor', 'pos_staff', 'stock_keeper']);

it('lets manager, frontdesk and reservation officer in', function (string $role): void {
    $op = Operator::factory()->for($this->tenant)->create(['role' => $role]);

    $this->getJson(hostUrl($this->tenant, 'session'), hostHeaders($op))->assertOk();
})->with(['manager', 'frontdesk', 'reservation_officer']);

it('requires a bearer token', function (): void {
    $this->getJson(hostUrl($this->tenant, 'session'))->assertStatus(401);
});

it('returns the session payload for a manager with owner capability', function (): void {
    $response = $this->getJson(hostUrl($this->tenant, 'session'), hostHeaders($this->manager));

    $response->assertOk()->assertExactJson(['data' => [
        'operator' => [
            'id' => $this->manager->id,
            'name' => 'Chidi Okafor',
            'email' => 'chidi@example.com',
            'phone' => $this->manager->phone,
            'role' => 'manager',
            'role_label' => 'Manager',
            'initials' => 'CO',
        ],
        'tenant' => ['id' => $this->tenant->id, 'name' => 'Palm Hotels', 'slug' => 'host-access'],
        // No current_property_id yet: first accessible branch by id.
        'property' => ['id' => $this->ikoyi['property']->id, 'name' => 'Ikoyi', 'city' => 'Lagos'],
        'capabilities' => [
            'owner' => true, 'create_booking' => true, 'take_payment' => true, 'approve' => true, 'finance' => true,
        ],
        'properties_count' => 2,
    ]]);
});

it('does not give frontdesk the owner capability', function (): void {
    $this->getJson(hostUrl($this->tenant, 'session'), hostHeaders($this->frontdesk))
        ->assertOk()
        ->assertJsonPath('data.capabilities.owner', false)
        ->assertJsonPath('data.capabilities.finance', false)
        ->assertJsonPath('data.capabilities.create_booking', true)
        ->assertJsonPath('data.operator.role_label', 'Front desk');
});

it('gives frontdesk with the finance.view grant the owner capability', function (): void {
    $this->frontdesk->forceFill(['permissions' => ['finance.view']])->save();

    $this->getJson(hostUrl($this->tenant, 'session'), hostHeaders($this->frontdesk))
        ->assertOk()
        ->assertJsonPath('data.capabilities.owner', true)
        ->assertJsonPath('data.capabilities.finance', true);
});

it('lists the branches the operator can access with tonight occupancy', function (): void {
    // One room sold tonight in Ikoyi.
    AvailabilityBlock::factory()->booked()->for($this->tenant)->create([
        'room_id' => $this->ikoyi['rooms'][0]->id,
        'date' => today()->toDateString(),
    ]);

    $response = $this->getJson(hostUrl($this->tenant, 'properties'), hostHeaders($this->manager));

    $response->assertOk();
    expect($response->json('data'))->toBe([
        [
            'id' => $this->ikoyi['property']->id, 'name' => 'Ikoyi', 'city' => 'Lagos', 'state' => 'Lagos',
            'rooms_total' => 4, 'occupied_tonight' => 1, 'occupancy_pct' => 25, 'is_current' => true,
        ],
        [
            'id' => $this->abuja['property']->id, 'name' => 'Abuja', 'city' => 'Lagos', 'state' => 'Lagos',
            'rooms_total' => 2, 'occupied_tonight' => 0, 'occupancy_pct' => 0, 'is_current' => false,
        ],
    ]);
});

it('only lists the branches a restricted operator is assigned to', function (): void {
    $this->frontdesk->branches()->attach($this->abuja['property']->id);

    $response = $this->getJson(hostUrl($this->tenant, 'properties'), hostHeaders($this->frontdesk));

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$this->abuja['property']->id]);
    $this->getJson(hostUrl($this->tenant, 'session'), hostHeaders($this->frontdesk))
        ->assertJsonPath('data.property.id', $this->abuja['property']->id)
        ->assertJsonPath('data.properties_count', 1);
});

it('switches branch and persists it on the operator', function (): void {
    $headers = hostHeaders($this->manager);
    $abujaId = $this->abuja['property']->id;

    $this->postJson(hostUrl($this->tenant, "properties/{$abujaId}/switch"), [], $headers + ['Idempotency-Key' => '01JABCDEFGHJKMNPQRSTVWXYZ0'])
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('property.id', $abujaId)
        ->assertJsonPath('property.is_current', true);

    expect($this->manager->fresh()->current_property_id)->toBe($abujaId);
    $this->getJson(hostUrl($this->tenant, 'session'), $headers)->assertJsonPath('data.property.id', $abujaId);
});

it('refuses to switch to a branch the operator cannot access', function (): void {
    $this->frontdesk->branches()->attach($this->ikoyi['property']->id);
    $abujaId = $this->abuja['property']->id;

    $this->postJson(hostUrl($this->tenant, "properties/{$abujaId}/switch"), [], hostHeaders($this->frontdesk) + ['Idempotency-Key' => '01JABCDEFGHJKMNPQRSTVWXYZ1'])
        ->assertStatus(403)
        ->assertJsonPath('error', 'property_not_accessible');
    expect($this->frontdesk->fresh()->current_property_id)->toBeNull();

    // Unknown id answers the same.
    $this->postJson(hostUrl($this->tenant, 'properties/999999/switch'), [], hostHeaders($this->frontdesk) + ['Idempotency-Key' => '01JABCDEFGHJKMNPQRSTVWXYZ2'])
        ->assertStatus(403);
});

it('needs an Idempotency-Key to switch', function (): void {
    $this->postJson(hostUrl($this->tenant, 'properties/'.$this->abuja['property']->id.'/switch'), [], hostHeaders($this->manager))
        ->assertStatus(400);
});

it('lets X-Keverna-Property override the branch for one request only', function (): void {
    $headers = hostHeaders($this->manager);
    $abujaId = $this->abuja['property']->id;

    $this->getJson(hostUrl($this->tenant, 'session'), $headers + ['X-Keverna-Property' => (string) $abujaId])
        ->assertOk()
        ->assertJsonPath('data.property.id', $abujaId);

    // Not persisted.
    expect($this->manager->fresh()->current_property_id)->toBeNull();
    $this->getJson(hostUrl($this->tenant, 'session'), $headers)
        ->assertJsonPath('data.property.id', $this->ikoyi['property']->id);
});

it('refuses an X-Keverna-Property the operator cannot access', function (): void {
    $this->frontdesk->branches()->attach($this->ikoyi['property']->id);

    $this->getJson(hostUrl($this->tenant, 'today'), hostHeaders($this->frontdesk, ['X-Keverna-Property' => (string) $this->abuja['property']->id]))
        ->assertStatus(403)
        ->assertJsonPath('error', 'property_not_accessible');
});

it('refuses an X-Keverna-Property from another tenant', function (): void {
    $other = Tenant::factory()->create(['slug' => 'host-access-other']);
    $other->makeCurrent();
    $foreign = hostBranch($other, 'Foreign', 1)['property'];
    $this->tenant->makeCurrent();

    $this->getJson(hostUrl($this->tenant, 'session'), hostHeaders($this->manager, ['X-Keverna-Property' => (string) $foreign->id]))
        ->assertStatus(403)
        ->assertJsonPath('error', 'property_not_accessible');
});
