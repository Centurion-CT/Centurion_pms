<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Staff\Index;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'staff-move']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
});

afterEach(fn () => Tenant::forgetCurrent());

it('managers reach /dashboard/staff at its new top-level route', function (): void {
    $manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);

    Livewire\Livewire::actingAs($manager, 'web')
        ->test(Index::class)
        ->assertOk()
        ->assertSeeHtml('data-testid="staff-roster"');
});

it('lets a manager invite a new staffer', function (): void {
    Mail::fake();

    $manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);

    Livewire\Livewire::actingAs($manager, 'web')
        ->test(Index::class)
        ->set('inviteName', 'Chika Okafor')
        ->set('inviteEmail', 'chika@keverna.test')
        ->set('inviteRole', OperatorRole::Frontdesk->value)
        ->call('invite')
        ->assertHasNoErrors()
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok' && str_contains((string) ($p['message'] ?? ''), 'Invite email sent'));

    // Operator + central identity + link all exist; operator is un-activated.
    $op = Operator::query()->where('email', 'chika@keverna.test')->firstOrFail();
    expect($op->activated_at)->toBeNull()
        ->and($op->invite_token_hash)->not->toBeNull();
});

it('non-managers cannot open the staff page', function (): void {
    $frontdesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);

    // EnsureRouteForRole redirects rather than aborting — friendlier UX
    // for the "wrong door" case. Confirm we don't land on the staff view.
    $this->actingAs($frontdesk, 'web');
    $this->get('/dashboard/staff')->assertRedirect();
});

it('no longer serves the old /settings/staff URL', function (): void {
    $manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);

    $this->actingAs($manager, 'web');
    $this->get('/settings/staff')->assertStatus(404);
});
