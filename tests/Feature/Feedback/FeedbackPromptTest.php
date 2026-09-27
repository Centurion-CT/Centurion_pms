<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Feedback\Prompt;
use App\Models\FeedbackPromptDismissal;
use App\Models\FeedbackSubmission;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
    Auth::guard('web')->login($this->operator);
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders the trigger when the operator has never been asked', function (): void {
    Livewire::test(Prompt::class)
        ->assertViewHas('shouldRender', true);
});

it('does NOT re-render once the operator has submitted for this module', function (): void {
    FeedbackPromptDismissal::query()->create([
        'operator_id'  => $this->operator->id,
        'module'       => 'dashboard',
        'action'       => 'submitted',
        'dismissed_at' => Carbon::now()->subDays(90),
    ]);

    Livewire::test(Prompt::class)
        ->assertViewHas('shouldRender', false);
});

it('DOES re-render after 30 days when the operator skipped', function (): void {
    FeedbackPromptDismissal::query()->create([
        'operator_id'  => $this->operator->id,
        'module'       => 'dashboard',
        'action'       => 'skipped',
        'dismissed_at' => Carbon::now()->subDays(31),
    ]);

    Livewire::test(Prompt::class)
        ->assertViewHas('shouldRender', true);
});

it('does NOT re-render before 30 days when the operator skipped', function (): void {
    FeedbackPromptDismissal::query()->create([
        'operator_id'  => $this->operator->id,
        'module'       => 'dashboard',
        'action'       => 'skipped',
        'dismissed_at' => Carbon::now()->subDays(3),
    ]);

    Livewire::test(Prompt::class)
        ->assertViewHas('shouldRender', false);
});

it('persists a submission + upserts the dismissal on submit', function (): void {
    Livewire::test(Prompt::class)
        ->call('toggle')
        ->set('rating', 4)
        ->set('body', 'The rates calendar is great, could use a keyboard shortcut for New rate.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    $s = FeedbackSubmission::query()->first();
    expect($s->tenant_id)->toBe($this->tenant->id);
    expect($s->operator_id)->toBe($this->operator->id);
    expect($s->rating)->toBe(4);
    expect($s->body)->toContain('keyboard shortcut');

    $d = FeedbackPromptDismissal::query()->first();
    expect($d->action)->toBe('submitted');
});

it('records a skip dismissal without a submission', function (): void {
    Livewire::test(Prompt::class)
        ->call('toggle')
        ->call('skip')
        ->assertSet('open', false);

    expect(FeedbackSubmission::query()->count())->toBe(0);
    $d = FeedbackPromptDismissal::query()->first();
    expect($d->action)->toBe('skipped');
});

it('refreshForRoute swaps the module bucket + clears widget state on nav', function (): void {
    // Operator dismissed the 'dashboard' module.
    FeedbackPromptDismissal::query()->create([
        'operator_id' => $this->operator->id,
        'module' => 'dashboard',
        'action' => 'skipped',
        'dismissed_at' => Carbon::now(),
    ]);

    Livewire::test(Prompt::class)
        // Simulate booting on /dashboard first — shouldn't render (just dismissed).
        ->call('refreshForRoute', 'tenant.dashboard')
        ->assertSet('moduleKey', 'dashboard')
        ->assertViewHas('shouldRender', false)
        // Now navigate to /dashboard/rates via wire:navigate.
        ->call('refreshForRoute', 'tenant.rates.index')
        ->assertSet('moduleKey', 'rates')
        // 'rates' has no dismissal → prompt should re-render on the new page.
        ->assertViewHas('shouldRender', true)
        // Any in-progress typing from the previous module is wiped.
        ->assertSet('open', false)
        ->assertSet('rating', 0)
        ->assertSet('body', '')
        ->assertSet('submitted', false);
});

it('rejects a submission with no rating', function (): void {
    Livewire::test(Prompt::class)
        ->call('submit')
        ->assertHasErrors(['rating']);
});
