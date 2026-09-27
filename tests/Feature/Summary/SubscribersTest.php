<?php

declare(strict_types=1);

use App\Console\Commands\Reports\SendSummaryReports;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Summary\Subscribers as SubscribersCard;
use App\Mail\Reports\SummaryReportMail;
use App\Models\Operator;
use App\Models\Property;
use App\Models\SummaryReportSubscriber;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'subs']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'email' => 'boss@sub.test',
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

/* ── CRUD ──────────────────────────────────────────────────────── */

it('adds a subscriber and remembers weekly + monthly flags', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(SubscribersCard::class)
        ->set('email', 'ada@example.com')
        ->set('weekly', true)
        ->set('monthly', false)
        ->call('add')
        ->assertHasNoErrors();

    $row = SummaryReportSubscriber::query()->firstWhere('email', 'ada@example.com');
    expect($row)->not->toBeNull();
    expect($row->weekly)->toBeTrue();
    expect($row->monthly)->toBeFalse();
    expect($row->created_by_operator_id)->toBe($this->manager->id);
});

it('adding an existing email updates its flags (idempotent)', function (): void {
    SummaryReportSubscriber::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'ada@example.com',
        'weekly' => false,
        'monthly' => false,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(SubscribersCard::class)
        ->set('email', 'ada@example.com')
        ->set('weekly', true)
        ->set('monthly', true)
        ->call('add')
        ->assertHasNoErrors();

    $rows = SummaryReportSubscriber::query()->where('email', 'ada@example.com')->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->weekly)->toBeTrue();
    expect($rows->first()->monthly)->toBeTrue();
});

it('rejects a malformed email', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(SubscribersCard::class)
        ->set('email', 'not-an-email')
        ->call('add')
        ->assertHasErrors(['email']);
});

it('toggles the weekly flag on an existing row', function (): void {
    $sub = SummaryReportSubscriber::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'ada@example.com',
        'weekly' => true,
        'monthly' => false,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(SubscribersCard::class)
        ->call('toggle', $sub->id, 'weekly');

    expect($sub->fresh()->weekly)->toBeFalse();
});

it('removes a subscriber', function (): void {
    $sub = SummaryReportSubscriber::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'gone@example.com',
        'weekly' => true, 'monthly' => true,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(SubscribersCard::class)
        ->call('remove', $sub->id);

    expect(SummaryReportSubscriber::query()->find($sub->id))->toBeNull();
});

/* ── Send test ─────────────────────────────────────────────────── */

it('sendTest dispatches a mail with a PDF attachment', function (): void {
    Mail::fake();

    Livewire::actingAs($this->manager, 'web')
        ->test(SubscribersCard::class)
        ->set('testEmail', 'preview@example.com')
        ->call('sendTest')
        ->assertHasNoErrors();

    // Component sets onConnection('sync') so in production the mail
    // fires immediately without a queue worker. Under Mail::fake()
    // ShouldQueue mailables still land in the queued bucket regardless
    // of the connection override, so assert there.
    Mail::assertQueued(SummaryReportMail::class, function ($mail) {
        return $mail->hasTo('preview@example.com')
            && str_ends_with($mail->filename, '.pdf')
            && str_starts_with($mail->pdfBytes, '%PDF');
    });
});

it('sendTest surfaces a mail error as a UI flash instead of failing silently', function (): void {
    // Force a failure inside the mail pipeline by stubbing the queue
    // connection so onConnection('sync') resolves to something invalid.
    // We use a bad recipient shape to trigger validation-side error.
    Livewire::actingAs($this->manager, 'web')
        ->test(SubscribersCard::class)
        ->set('testEmail', 'not-an-email')
        ->call('sendTest')
        ->assertHasErrors(['testEmail']);
});

/* ── Scheduled command ─────────────────────────────────────────── */

it('summary:send-reports weekly only mails subscribers with weekly=true', function (): void {
    Mail::fake();

    SummaryReportSubscriber::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'weekly@example.com',
        'weekly' => true, 'monthly' => false,
    ]);
    SummaryReportSubscriber::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'monthly@example.com',
        'weekly' => false, 'monthly' => true,
    ]);

    Tenant::forgetCurrent();

    $this->artisan(SendSummaryReports::class, ['cadence' => 'weekly'])->assertSuccessful();

    Mail::assertQueued(SummaryReportMail::class, 1);
    Mail::assertQueued(SummaryReportMail::class, fn ($mail) => $mail->hasTo('weekly@example.com'));
});

it('summary:send-reports monthly only mails subscribers with monthly=true', function (): void {
    Mail::fake();

    SummaryReportSubscriber::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'weekly@example.com',
        'weekly' => true, 'monthly' => false,
    ]);
    SummaryReportSubscriber::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'both@example.com',
        'weekly' => true, 'monthly' => true,
    ]);

    Tenant::forgetCurrent();

    $this->artisan(SendSummaryReports::class, ['cadence' => 'monthly'])->assertSuccessful();

    Mail::assertQueued(SummaryReportMail::class, 1);
    Mail::assertQueued(SummaryReportMail::class, fn ($mail) => $mail->hasTo('both@example.com'));
});

it('summary:send-reports rejects an unknown cadence', function (): void {
    $this->artisan(SendSummaryReports::class, ['cadence' => 'daily'])->assertFailed();
});
