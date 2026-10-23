<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Staff\Index;
use App\Mail\Operators\OperatorInviteMail;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Staff page: housekeepers and porters get a phone + PIN for the staff
 * app, and can be added without an email address.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'app-pin']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager, 'activated_at' => now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('adds a housekeeper with phone and PIN and no email', function (): void {
    Mail::fake();

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->set('inviteName', 'Blessing Okafor')
        ->set('inviteRole', OperatorRole::HouseCleaner->value)
        ->assertSee('Staff app PIN')
        ->set('invitePhone', '0803 123 4567')
        ->set('invitePin', '2468')
        ->call('invite')
        ->assertHasNoErrors()
        ->assertSee('Staff app only');

    $op = Operator::query()->where('name', 'Blessing Okafor')->firstOrFail();
    expect($op->isAppOnly())->toBeTrue()
        ->and($op->activated_at)->not->toBeNull()
        ->and($op->checkStaffPin('2468'))->toBeTrue()
        ->and($op->phone)->toBe('0803 123 4567');
    Mail::assertNothingQueued();
});

it('still requires an email for other roles', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->set('inviteName', 'Ada')
        ->set('inviteRole', OperatorRole::Frontdesk->value)
        ->assertDontSee('Staff app PIN')
        ->call('invite')
        ->assertHasErrors(['inviteEmail' => 'required']);
});

it('needs a phone or an email for a housekeeper, and a PIN with the phone', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->set('inviteName', 'Musa')
        ->set('inviteRole', OperatorRole::Porter->value)
        ->call('invite')
        ->assertHasErrors(['invitePhone' => 'required'])
        ->set('invitePhone', '08055555555')
        ->call('invite')
        ->assertHasErrors(['invitePin' => 'required'])
        ->set('invitePin', '12')
        ->call('invite')
        ->assertHasErrors(['invitePin' => 'regex']);
});

it('emails the invite and saves the PIN when a housekeeper has an email', function (): void {
    Mail::fake();

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->set('inviteName', 'Chika Obi')
        ->set('inviteEmail', 'chika@example.com')
        ->set('inviteRole', OperatorRole::HouseCleaner->value)
        ->set('invitePhone', '08077777777')
        ->set('invitePin', '1357')
        ->call('invite')
        ->assertHasNoErrors();

    Mail::assertQueued(OperatorInviteMail::class);
    expect(Operator::query()->where('email', 'chika@example.com')->firstOrFail()->checkStaffPin('1357'))->toBeTrue();
});

it('sets or changes an app PIN from the roster', function (): void {
    $porter = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Porter, 'activated_at' => now()]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->assertSee('Set app PIN')
        ->call('setAppPin', $porter->id, '+234 805 000 0000', '9753')
        ->assertSee('PIN set');

    $porter->refresh();
    expect($porter->checkStaffPin('9753'))->toBeTrue()
        ->and($porter->phone)->toBe('+234 805 000 0000');
});

it('refuses a phone number another staff app user already has', function (): void {
    $a = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Porter, 'phone' => '08050000000']);
    $a->setStaffPin('1111');
    $b = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('setAppPin', $b->id, '+2348050000000', '2222');

    expect($b->refresh()->hasStaffPin())->toBeFalse();
});

it('does not give app PINs to other roles', function (): void {
    $fd = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('setAppPin', $fd->id, '08011111111', '1234');

    expect($fd->refresh()->hasStaffPin())->toBeFalse();
});
