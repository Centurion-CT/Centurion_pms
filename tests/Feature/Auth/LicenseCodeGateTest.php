<?php

declare(strict_types=1);

use App\Livewire\Auth\Register;
use App\Models\LicenseCode;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Registration only proceeds when a valid, unused, non-expired
 * licence code is presented. The code burns to `status=used` on
 * successful register + links back to the operator + tenant.
 */
afterEach(fn () => Carbon::setTestNow());

it('rejects registration without any licence code', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Amara Okafor')
        ->set('email', 'amara@example.com')
        ->set('property_name', 'Test Property')
        ->set('phone', '+2348012345678')
        ->set('unit_count', 3)
        ->set('password', 'secret-pass-9')
        ->set('password_confirmation', 'secret-pass-9')
        ->call('register')
        ->assertHasErrors('license_code');

    expect(Tenant::query()->count())->toBe(0);
    expect(Operator::query()->count())->toBe(0);
});

it('rejects a made-up licence code', function (): void {
    Livewire::test(Register::class)
        ->set('name', 'Amara Okafor')
        ->set('email', 'amara@example.com')
        ->set('property_name', 'Test Property')
        ->set('phone', '+2348012345678')
        ->set('unit_count', 3)
        ->set('password', 'secret-pass-9')
        ->set('password_confirmation', 'secret-pass-9')
        ->set('license_code', 'KV-NOPE-NOPE-NOPE')
        ->call('register')
        ->assertHasErrors('license_code');

    expect(Tenant::query()->count())->toBe(0);
});

it('rejects a licence code that has already been used', function (): void {
    $code = LicenseCode::query()->create([
        'code'   => LicenseCode::generateCode(),
        'status' => 'used',
        'used_at' => Carbon::now(),
    ]);

    Livewire::test(Register::class)
        ->set('name', 'Amara Okafor')
        ->set('email', 'amara@example.com')
        ->set('property_name', 'Test Property')
        ->set('phone', '+2348012345678')
        ->set('unit_count', 3)
        ->set('password', 'secret-pass-9')
        ->set('password_confirmation', 'secret-pass-9')
        ->set('license_code', $code->code)
        ->call('register')
        ->assertHasErrors('license_code');
});

it('rejects an expired licence code', function (): void {
    $code = LicenseCode::query()->create([
        'code'       => LicenseCode::generateCode(),
        'status'     => 'unused',
        'expires_at' => Carbon::now()->subDay(),
    ]);

    Livewire::test(Register::class)
        ->set('name', 'Amara Okafor')
        ->set('email', 'amara@example.com')
        ->set('property_name', 'Test Property')
        ->set('phone', '+2348012345678')
        ->set('unit_count', 3)
        ->set('password', 'secret-pass-9')
        ->set('password_confirmation', 'secret-pass-9')
        ->set('license_code', $code->code)
        ->call('register')
        ->assertHasErrors('license_code');
});

it('accepts a valid unused code and burns it to used on success', function (): void {
    $code = LicenseCode::query()->create([
        'code'   => LicenseCode::generateCode(),
        'status' => 'unused',
    ]);

    Livewire::test(Register::class)
        ->set('name', 'Amara Okafor')
        ->set('email', 'amara-'.uniqid().'@example.com')
        ->set('property_name', 'Test Property')
        ->set('phone', '+2348012345678')
        ->set('unit_count', 3)
        ->set('password', 'secret-pass-9')
        ->set('password_confirmation', 'secret-pass-9')
        ->set('license_code', $code->code)
        ->call('register')
        ->assertHasNoErrors();

    $fresh = $code->fresh();
    expect($fresh->status)->toBe('used');
    expect($fresh->used_at)->not->toBeNull();
    expect($fresh->used_by_operator_id)->not->toBeNull();
    expect($fresh->used_by_tenant_id)->not->toBeNull();
});

it('seeds license_code from the ?code= URL param', function (): void {
    $code = LicenseCode::query()->create([
        'code'   => LicenseCode::generateCode(),
        'status' => 'unused',
    ]);

    Livewire::withQueryParams(['code' => $code->code])
        ->test(Register::class)
        ->assertSet('license_code', $code->code);
});
