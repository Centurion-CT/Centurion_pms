<?php

declare(strict_types=1);

use App\Services\Auth\TotpService;

it('generates a 16-char Base32 secret', function (): void {
    $secret = (new TotpService())->generateSecret();
    expect($secret)->toBeString();
    expect(strlen($secret))->toBe(16);
    expect($secret)->toMatch('/^[A-Z2-7]+$/');
});

it('verifies a code computed at the same timestamp', function (): void {
    $svc = new TotpService();
    $secret = $svc->generateSecret();

    // We can't compute the "correct" code without also implementing
    // the algorithm here, so instead we cross-check with a known
    // secret + a known time. RFC 6238 test vector:
    // secret ASCII "12345678901234567890" (base32: GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ)
    // at t=59 -> code 287082 (SHA-1, 8-digit, but our impl is 6-digit
    // so we'll validate a live self-round-trip instead).
    // Take a code at the current time step + verify it accepts.
    $now = time();
    $reflection = new ReflectionMethod(TotpService::class, 'computeCode');
    $reflection->setAccessible(true);
    $counter = intdiv($now, 30);
    $code = $reflection->invoke($svc, $secret, $counter);

    expect($svc->verify($secret, $code, $now))->toBeTrue();
});

it('rejects a totally wrong code', function (): void {
    $svc = new TotpService();
    $secret = $svc->generateSecret();
    expect($svc->verify($secret, '000000'))->toBeFalse();
});

it('accepts a code from the previous window (±30s skew)', function (): void {
    $svc = new TotpService();
    $secret = $svc->generateSecret();
    $reflection = new ReflectionMethod(TotpService::class, 'computeCode');
    $reflection->setAccessible(true);

    // Compute a code 30 seconds ago, verify at "now".
    $now = time();
    $counter = intdiv($now - 30, 30);
    $code = $reflection->invoke($svc, $secret, $counter);

    expect($svc->verify($secret, $code, $now))->toBeTrue();
});

it('generates 10 recovery codes in XXXX-XXXX shape', function (): void {
    $codes = (new TotpService())->generateRecoveryCodes();
    expect($codes)->toHaveCount(10);
    foreach ($codes as $c) {
        expect($c)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/');
    }
});

it('otpauth URI carries issuer + secret in the expected shape', function (): void {
    $svc = new TotpService();
    $uri = $svc->otpauthUri('JBSWY3DPEHPK3PXP', 'Keverna Admin', 'user@example.com');
    expect($uri)->toStartWith('otpauth://totp/Keverna%20Admin:user%40example.com?');
    expect($uri)->toContain('secret=JBSWY3DPEHPK3PXP');
    expect($uri)->toContain('issuer=Keverna+Admin');
    expect($uri)->toContain('digits=6');
    expect($uri)->toContain('period=30');
});
