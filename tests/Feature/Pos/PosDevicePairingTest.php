<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\PosDeviceSession;
use App\Models\PosPairingCode;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pair-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::PosStaff,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/**
 * @return array{0: string, 1: PosPairingCode}
 */
function mintPairing(Operator $op, array $overrides = []): array
{
    $plaintext = Str::random(32);
    $row = PosPairingCode::query()->create(array_merge([
        'operator_id' => $op->id,
        'code_hash' => hash('sha256', $plaintext),
        'expires_at' => now()->addSeconds(90),
        'ip' => '127.0.0.1',
        'user_agent' => 'PairTest/1.0',
    ], $overrides));

    return [$plaintext, $row];
}

it('claiming a valid code logs the operator in and mints a device session', function (): void {
    [$code, $row] = mintPairing($this->operator);

    $response = $this->get("/pos/pair/{$code}");

    $response->assertRedirect(route('tenant.pos.pick-outlet'));
    expect(PosDeviceSession::query()->where('operator_id', $this->operator->id)->count())->toBe(1);
    expect(auth('web')->id())->toBe($this->operator->id);
    expect(session('pos_device_session_id'))->not->toBeNull();

    $fresh = $row->fresh();
    expect($fresh->claimed_at)->not->toBeNull();
    expect($fresh->device_session_id)->not->toBeNull();
});

it('an expired code returns 410 and does not mint a session', function (): void {
    [$code, $row] = mintPairing($this->operator, ['expires_at' => now()->subMinute()]);

    $response = $this->get("/pos/pair/{$code}");

    $response->assertStatus(410);
    $response->assertSee('expired');
    expect(PosDeviceSession::query()->count())->toBe(0);
    expect($row->fresh()->claimed_at)->toBeNull();
    expect(auth('web')->check())->toBeFalse();
});

it('an already-claimed code returns 410', function (): void {
    [$code] = mintPairing($this->operator, ['claimed_at' => now()->subSecond()]);

    $this->get("/pos/pair/{$code}")->assertStatus(410);
});

it('an unknown code returns 404', function (): void {
    $this->get('/pos/pair/'.Str::random(32))->assertStatus(404);
});

it('a second attempt on the same code is rejected with 410 (single-use)', function (): void {
    [$code] = mintPairing($this->operator);

    $this->get("/pos/pair/{$code}")->assertRedirect();

    // Fresh guest session for the second attempt — a real second phone
    // wouldn't share cookies with the first.
    auth('web')->logout();
    session()->invalidate();

    $this->get("/pos/pair/{$code}")->assertStatus(410);
    expect(PosDeviceSession::query()->count())->toBe(1);
});

it('captures the phone user agent and paired IP on the device session', function (): void {
    [$code] = mintPairing($this->operator);

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'])
        ->get("/pos/pair/{$code}");

    $device = PosDeviceSession::query()->first();
    expect($device->device_label)->toBe('iPhone · Safari');
    expect($device->user_agent)->toContain('iPhone');
});
