<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /api/mobile/v1/staff/login — phone number + staff PIN for the
 * housekeeper / porter app.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pin-hotel', 'name' => 'Centurion Apartments']);
    $this->tenant->makeCurrent();
    $this->cleaner = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::HouseCleaner, 'name' => 'Blessing Okafor', 'phone' => '+234 803 123 4567',
    ]);
    $this->cleaner->setStaffPin('2468');
    Tenant::forgetCurrent();
    RateLimiter::clear('mobile-staff-login:'.sha1('127.0.0.1|08031234567'));
});

afterEach(fn () => Tenant::forgetCurrent());

function staffLoginUrl(): string
{
    return 'http://'.config('keverna.central_domain').'/api/mobile/v1/staff/login';
}

function staffLoginBody(array $extra = []): array
{
    return array_merge([
        'phone' => '0803 123 4567',
        'pin' => '2468',
        'platform' => 'android',
        'device_name' => 'Tecno Spark',
        'app_version' => '2.0.0+2',
    ], $extra);
}

it('signs in with phone and PIN in any common format', function (string $phone): void {
    $res = $this->postJson(staffLoginUrl(), staffLoginBody(['phone' => $phone]))->assertOk();

    expect($res->json('token'))->toBeString()
        ->and($res->json('base_url'))->toStartWith('http')
        ->and($res->json('operator.first_name'))->toBe('Blessing')
        ->and($res->json('operator.role'))->toBe('house_cleaner')
        ->and($res->json('tenant.name'))->toBe('Centurion Apartments')
        ->and(MobileDevice::query()->withoutGlobalScopes()->where('operator_id', $this->cleaner->id)->count())->toBe(1);
})->with(['08031234567', '+2348031234567', '234 803 123 4567', '8031234567']);

it('gives the same answer for a wrong PIN and an unknown phone', function (): void {
    $this->postJson(staffLoginUrl(), staffLoginBody(['pin' => '1111']))
        ->assertStatus(401)->assertJsonPath('error', 'invalid_credentials');
    $this->postJson(staffLoginUrl(), staffLoginBody(['phone' => '09099999999']))
        ->assertStatus(401)->assertJsonPath('error', 'invalid_credentials');
});

it('turns away roles that are not housekeepers or porters', function (): void {
    $this->tenant->makeCurrent();
    $fd = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk, 'phone' => '08050000000']);
    $fd->setStaffPin('1357');
    Tenant::forgetCurrent();

    $this->postJson(staffLoginUrl(), staffLoginBody(['phone' => '08050000000', 'pin' => '1357']))
        ->assertStatus(403)->assertJsonPath('error', 'staff_only');
});

it('refuses a suspended cleaner', function (): void {
    $this->cleaner->forceFill(['suspended_at' => now()])->save();

    $this->postJson(staffLoginUrl(), staffLoginBody())->assertStatus(403)->assertJsonPath('error', 'suspended');
});

it('rate limits repeated wrong PINs', function (): void {
    foreach (range(1, 6) as $_) {
        $this->postJson(staffLoginUrl(), staffLoginBody(['pin' => '0000']))->assertStatus(401);
    }
    $this->postJson(staffLoginUrl(), staffLoginBody())->assertStatus(429);
});

it('lets the token reach the staff API', function (): void {
    $token = $this->postJson(staffLoginUrl(), staffLoginBody())->json('token');

    $this->getJson(
        'http://'.$this->tenant->slug.'.'.config('keverna.central_domain').'/api/mobile/v1/staff/session',
        ['Authorization' => 'Bearer '.$token],
    )->assertOk()->assertJsonPath('data.operator.name', 'Blessing Okafor');
});

it('never exposes the PIN hash', function (): void {
    expect($this->cleaner->toArray())->not->toHaveKey('staff_pin_hash');
});
