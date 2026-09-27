<?php

declare(strict_types=1);

use App\Mail\Subscriptions\RenewalReminderMail;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
});

it('queues a 30-day reminder for a subscription expiring in 30 days', function (): void {
    $tenant = Tenant::factory()->create(['email' => 'op@x.test']);
    $tenant->makeCurrent();
    Subscription::factory()
        ->for($tenant)
        ->create(['expires_at' => Carbon::today()->addDays(30)]);
    Tenant::forgetCurrent();

    $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();

    Mail::assertQueued(RenewalReminderMail::class, function (RenewalReminderMail $m) {
        return $m->daysBefore === 30 && $m->hasTo('op@x.test');
    });
});

it('queues exactly one reminder at each of 30, 14, 7, 1 days', function (): void {
    foreach ([30, 14, 7, 1] as $days) {
        $tenant = Tenant::factory()->create();
        $tenant->makeCurrent();
        Subscription::factory()
            ->for($tenant)
            ->create(['expires_at' => Carbon::today()->addDays($days)]);
        Tenant::forgetCurrent();
    }

    $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();

    Mail::assertQueued(RenewalReminderMail::class, 4);
});

it('does NOT queue reminders for subscriptions expiring 15 days out (no milestone match)', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    Subscription::factory()
        ->for($tenant)
        ->create(['expires_at' => Carbon::today()->addDays(15)]);
    Tenant::forgetCurrent();

    $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('is idempotent — re-running on the same day does not re-send', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    Subscription::factory()
        ->for($tenant)
        ->create(['expires_at' => Carbon::today()->addDays(7)]);
    Tenant::forgetCurrent();

    $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();
    $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();

    Mail::assertQueued(RenewalReminderMail::class, 1);
});

it('progressively sends 30, 14, 7, 1 day reminders as time passes (PRD §4.2)', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $expires = Carbon::today()->addDays(30);
    Subscription::factory()->for($tenant)->create(['expires_at' => $expires]);
    Tenant::forgetCurrent();

    $this->artisan('subscriptions:send-renewal-reminders');
    Mail::assertQueued(RenewalReminderMail::class, fn ($m) => $m->daysBefore === 30);

    Carbon::setTestNow($expires->copy()->subDays(14));
    $this->artisan('subscriptions:send-renewal-reminders');
    Mail::assertQueued(RenewalReminderMail::class, fn ($m) => $m->daysBefore === 14);

    Carbon::setTestNow($expires->copy()->subDays(7));
    $this->artisan('subscriptions:send-renewal-reminders');
    Mail::assertQueued(RenewalReminderMail::class, fn ($m) => $m->daysBefore === 7);

    Carbon::setTestNow($expires->copy()->subDay());
    $this->artisan('subscriptions:send-renewal-reminders');
    Mail::assertQueued(RenewalReminderMail::class, fn ($m) => $m->daysBefore === 1);

    Mail::assertQueued(RenewalReminderMail::class, 4);
});

it('skips reminders for already-expired subscriptions', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    Subscription::factory()
        ->for($tenant)
        ->expired()
        ->create();
    Tenant::forgetCurrent();

    $this->artisan('subscriptions:send-renewal-reminders')->assertSuccessful();
    Mail::assertNothingQueued();
});
