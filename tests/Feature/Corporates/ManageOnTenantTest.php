<?php

declare(strict_types=1);

use App\Enums\CorporateMemberStatus;
use App\Enums\CorporateStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Corporates\Form as CorpForm;
use App\Livewire\Dashboard\Corporates\Index as CorpIndex;
use App\Livewire\Dashboard\Corporates\Show as CorpShow;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Tenant-side Corporate management. A property Manager can register
 * a corporate + profile staff so those staff get corp-guaranteed
 * check-in on their reservations. Corps onboarded centrally are
 * read-only to keep tenant Managers from editing another party's
 * KYC.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'manage-corp']);
    $this->tenant->makeCurrent();
    Property::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('Manager registers a new corporate — tagged with created_by_tenant_id', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpForm::class)
        ->set('name', 'Acme Corp')
        ->set('primary_email', 'ops@acme.test')
        ->set('primary_phone', '+2348011111111')
        ->call('save');

    $corp = Corporate::query()->where('name', 'Acme Corp')->first();
    expect($corp)->not->toBeNull();
    expect((int) $corp->created_by_tenant_id)->toBe((int) $this->tenant->id);
    expect($corp->status)->toBe(CorporateStatus::Active);
});

it('rejects registration without a valid email', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpForm::class)
        ->set('name', 'Acme Corp')
        ->set('primary_email', 'not-an-email')
        ->call('save')
        ->assertDispatched('notify');

    expect(Corporate::query()->where('name', 'Acme Corp')->exists())->toBeFalse();
});

it('Manager profiles a staff member on an owned corp', function (): void {
    $corp = Corporate::query()->create([
        'slug' => 'own-'.uniqid(),
        'name' => 'Owned Corp',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@own.test',
        'country' => 'NG',
        'created_by_tenant_id' => $this->tenant->id,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $corp])
        ->call('newMember')
        ->set('memberName', 'Amaka Traveller')
        ->set('memberEmail', 'amaka@own.test')
        ->set('memberRole', 'member')
        ->call('saveMember')
        ->assertDispatched('notify');

    $m = CorporateMember::query()->where('email', 'amaka@own.test')->first();
    expect($m)->not->toBeNull();
    expect($m->status)->toBe(CorporateMemberStatus::Active);
    expect((int) $m->corporate_id)->toBe($corp->id);
});

it('toggle offboards then reactivates a member', function (): void {
    $corp = Corporate::query()->create([
        'slug' => 'toggle-'.uniqid(),
        'name' => 'Toggle Corp',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@toggle.test',
        'country' => 'NG',
        'created_by_tenant_id' => $this->tenant->id,
    ]);
    $m = CorporateMember::query()->create([
        'corporate_id' => $corp->id,
        'name' => 'Ada Member', 'email' => 'ada@toggle.test',
        'role' => 'member', 'status' => CorporateMemberStatus::Active->value,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $corp])
        ->call('toggleMemberStatus', $m->id);
    expect($m->refresh()->status)->toBe(CorporateMemberStatus::Offboarded);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $corp])
        ->call('toggleMemberStatus', $m->id);
    expect($m->refresh()->status)->toBe(CorporateMemberStatus::Active);
});

it('refuses to edit a centrally-onboarded corp from tenant side', function (): void {
    $corp = Corporate::query()->create([
        'slug' => 'central-'.uniqid(),
        'name' => 'Central Corp',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@central.test',
        'country' => 'NG',
        // No created_by_tenant_id — this is a central corp.
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpForm::class, ['corporate' => $corp])
        ->set('name', 'Renamed Corp')
        ->call('save')
        ->assertDispatched('notify');

    expect($corp->refresh()->name)->toBe('Central Corp');
});

it('Index shows a freshly-registered corp even with zero booking activity anywhere', function (): void {
    // Regression: buildRows used to short-circuit with an empty
    // collection when no booking / desk / membership / lease existed
    // at all, so a corp the Manager just registered vanished until it
    // did some business. The Index list must always include owned
    // corps regardless.
    $corp = Corporate::query()->create([
        'slug' => 'fresh-'.uniqid(),
        'name' => 'Fresh Registered',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@fresh.test',
        'country' => 'NG',
        'created_by_tenant_id' => $this->tenant->id,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpIndex::class)
        ->assertSee('Fresh Registered');
});

it('Show renders the Add staff CTA for an owned corp even with zero activity', function (): void {
    $corp = Corporate::query()->create([
        'slug' => 'own2-'.uniqid(),
        'name' => 'Fresh Owned',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@fresh.test',
        'country' => 'NG',
        'created_by_tenant_id' => $this->tenant->id,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $corp])
        ->assertSee('data-testid="new-member"', false)
        ->assertSee('Profiled staff');
});
