<?php

declare(strict_types=1);

use App\Services\Paystack\PaystackClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.paystack.secret_key', 'sk_test_master');
    config()->set('services.paystack.base_url', 'https://api.paystack.test');
});

it('refuses to construct with an empty secret key', function (): void {
    expect(fn () => new PaystackClient('', 'https://api.paystack.test'))
        ->toThrow(RuntimeException::class);
});

it('sends amount in kobo (NGN × 100) to /transaction/initialize', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://paystack.test/checkout/xyz',
                'access_code' => 'ac_123',
                'reference' => 'kev_sub_abc',
            ],
        ]),
    ]);

    $result = PaystackClient::forSubscriptions()->initializeTransaction([
        'email' => 'op@x.test',
        'amount_ngn' => 300_000,
        'reference' => 'kev_sub_abc',
        'callback_url' => 'https://keverna.test/checkout/return',
        'metadata' => ['subscription_id' => 42],
    ]);

    expect($result['authorization_url'])->toBe('https://paystack.test/checkout/xyz');
    expect($result['reference'])->toBe('kev_sub_abc');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.paystack.test/transaction/initialize'
            && $request['amount'] === 30_000_000   // 300,000 NGN in kobo
            && $request['reference'] === 'kev_sub_abc'
            && $request->header('Authorization')[0] === 'Bearer sk_test_master';
    });
});

it('throws when Paystack returns status=false', function (): void {
    Http::fake([
        'https://api.paystack.test/*' => Http::response(['status' => false, 'message' => 'boom']),
    ]);

    expect(fn () => PaystackClient::forSubscriptions()->initializeTransaction([
        'email' => 'op@x.test',
        'amount_ngn' => 300_000,
        'reference' => 'kev_sub_abc',
        'callback_url' => null,
        'metadata' => [],
    ]))->toThrow(RuntimeException::class);
});

it('verifies a transaction and converts kobo back to NGN', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/verify/kev_sub_abc' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success',
                'amount' => 30_000_000,
                'reference' => 'kev_sub_abc',
                'paid_at' => '2026-06-01T12:00:00.000Z',
                'metadata' => ['subscription_id' => 42],
            ],
        ]),
    ]);

    $result = PaystackClient::forSubscriptions()->verifyTransaction('kev_sub_abc');

    expect($result['status'])->toBe('success');
    expect($result['amount_ngn'])->toBe(300_000);
    expect($result['reference'])->toBe('kev_sub_abc');
    expect($result['metadata'])->toBe(['subscription_id' => 42]);
});

it('verifies a webhook signature with HMAC-SHA512(body, secret)', function (): void {
    $client = new PaystackClient('sk_test_master', 'https://api.paystack.test');
    $body = '{"event":"charge.success","data":{"reference":"kev_sub_abc"}}';
    $valid = hash_hmac('sha512', $body, 'sk_test_master');

    expect($client->verifyWebhookSignature($body, $valid))->toBeTrue();
    expect($client->verifyWebhookSignature($body, 'totally-wrong'))->toBeFalse();
    expect($client->verifyWebhookSignature($body.' tampered', $valid))->toBeFalse();
});

it('signature comparison is timing-safe (hash_equals)', function (): void {
    $client = new PaystackClient('sk_test_master', 'https://api.paystack.test');
    $body = 'whatever';
    $valid = hash_hmac('sha512', $body, 'sk_test_master');

    // Wrong-length signature must return false without throwing.
    expect($client->verifyWebhookSignature($body, ''))->toBeFalse();
    expect($client->verifyWebhookSignature($body, $valid.'extra'))->toBeFalse();
});

it('uses tenant key when constructed via forTenantKey()', function (): void {
    Http::fake([
        'https://api.paystack.test/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://paystack.test/co/tenant',
                'access_code' => 'ac_tenant',
                'reference' => 'booking_xyz',
            ],
        ]),
    ]);

    PaystackClient::forTenantKey('sk_test_tenant_xyz')->initializeTransaction([
        'email' => 'guest@x.test',
        'amount_ngn' => 50_000,
        'reference' => 'booking_xyz',
        'callback_url' => null,
        'metadata' => [],
    ]);

    Http::assertSent(fn ($r) => $r->header('Authorization')[0] === 'Bearer sk_test_tenant_xyz');
});
