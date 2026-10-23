<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\Push\FcmDriver;
use App\Services\Push\HostPush;
use App\Services\Push\LogDriver;
use App\Services\Push\NullDriver;
use App\Services\Push\PushDriver;
use App\Services\Push\PushMessage;
use App\Services\Push\PushResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/PushTestHelpers.php';

/**
 * FCM HTTP v1 driver: service-account JWT (RS256 via openssl_sign),
 * OAuth token cache, messages:send body, dead-token cleanup, and no
 * HTTP at all when unconfigured.
 */
beforeEach(function (): void {
    Cache::flush();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->publicKey = openssl_pkey_get_details($key)['key'];
    $this->account = json_encode([
        'type' => 'service_account',
        'client_email' => 'push@keverna-test.iam.gserviceaccount.com',
        'private_key' => $pem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]);
    $this->message = new PushMessage('payments', 'Payment received: ₦85,000', 'Chiamaka Obi paid by transfer · Room 203', 'booking:KV-1', 7, 'wuse', '42');
});

afterEach(fn () => Tenant::forgetCurrent());

function fakeFcm(array $sendResponses): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599, 'token_type' => 'Bearer']),
        'fcm.googleapis.com/*' => Http::sequence($sendResponses),
    ]);
}

it('signs a service-account JWT, caches the token and posts the v1 message', function (): void {
    fakeFcm([Http::response(['name' => 'projects/keverna-test/messages/1']), Http::response(['name' => 'projects/keverna-test/messages/2'])]);
    $driver = new FcmDriver('keverna-test', $this->account);

    expect($driver->available())->toBeTrue()
        ->and($driver->send($this->message, 'device-token-1', 'android'))->toBe(PushResult::Sent)
        ->and($driver->send($this->message, 'device-token-2', 'ios'))->toBe(PushResult::Sent);

    $oauth = collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), 'oauth2'))->values();
    expect($oauth)->toHaveCount(1); // cached for the second send

    /** @var Request $tokenRequest */
    $tokenRequest = $oauth[0][0];
    expect($tokenRequest['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');
    [$h, $c, $sig] = explode('.', $tokenRequest['assertion']);
    $b64 = fn (string $s) => base64_decode(strtr($s, '-_', '+/'));
    expect(json_decode($b64($h), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT']);
    $claims = json_decode($b64($c), true);
    expect($claims['iss'])->toBe('push@keverna-test.iam.gserviceaccount.com')
        ->and($claims['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')
        ->and($claims['aud'])->toBe('https://oauth2.googleapis.com/token')
        ->and($claims['exp'] - $claims['iat'])->toBe(3600)
        ->and(openssl_verify($h.'.'.$c, $b64($sig), $this->publicKey, OPENSSL_ALGO_SHA256))->toBe(1);

    Http::assertSent(function (Request $r): bool {
        if (! str_contains($r->url(), 'fcm.googleapis.com')) {
            return false;
        }

        return $r->url() === 'https://fcm.googleapis.com/v1/projects/keverna-test/messages:send'
            && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['token'] === 'device-token-1'
            && $r['message']['notification'] === ['title' => 'Payment received: ₦85,000', 'body' => 'Chiamaka Obi paid by transfer · Room 203']
            && $r['message']['data'] === ['kind' => 'payments', 'target' => 'booking:KV-1', 'property_id' => '7', 'tenant' => 'wuse', 'id' => '42']
            && $r['message']['android']['notification']['channel_id'] === 'keverna_host'
            && $r['message']['apns']['payload']['aps']['sound'] === 'default'
            && $r['message']['apns']['payload']['aps']['thread-id'] === 'payments';
    });
});

it('reads credentials from a file path', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, $this->account);
    fakeFcm([Http::response(['name' => 'x'])]);

    expect((new FcmDriver('keverna-test', $path))->send($this->message, 'tok', 'android'))->toBe(PushResult::Sent);
    @unlink($path);
});

it('reports a dead token as invalid and HostPush clears it', function (): void {
    fakeFcm([
        Http::response(['error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'status' => 'NOT_FOUND',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404),
        Http::response(['error' => ['code' => 400, 'message' => 'The registration token is not a valid FCM registration token', 'status' => 'INVALID_ARGUMENT',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT']]]], 400),
        Http::response(['error' => ['code' => 500, 'message' => 'Internal error', 'status' => 'INTERNAL']], 500),
        Http::response(['error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'status' => 'NOT_FOUND']], 404),
    ]);
    $driver = new FcmDriver('keverna-test', $this->account);
    app()->instance(PushDriver::class, $driver);

    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $op = Operator::factory()->for($tenant)->create(['role' => OperatorRole::Manager]);
    $dead = pushDevice($op, 'dead-token');
    $bad = pushDevice($op, 'bad-token');
    $flaky = pushDevice($op, 'flaky-token');
    $wrongProject = pushDevice($op, 'kept-token');

    $push = app(HostPush::class);
    expect($push->sendToDevice($dead, $this->message))->toBe(PushResult::InvalidToken)
        ->and($push->sendToDevice($bad, $this->message))->toBe(PushResult::InvalidToken)
        ->and($push->sendToDevice($flaky, $this->message))->toBe(PushResult::Failed)
        ->and($push->sendToDevice($wrongProject, $this->message))->toBe(PushResult::Failed);

    expect($dead->fresh()->push_token)->toBeNull()
        ->and($dead->fresh()->push_token_updated_at)->not->toBeNull()
        ->and($bad->fresh()->push_token)->toBeNull()
        ->and($flaky->fresh()->push_token)->toBe('flaky-token')
        ->and($wrongProject->fresh()->push_token)->toBe('kept-token');
});

it('retries once with a fresh access token on 401', function (): void {
    fakeFcm([Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401), Http::response(['name' => 'ok'])]);

    expect((new FcmDriver('keverna-test', $this->account))->send($this->message, 'tok', 'android'))->toBe(PushResult::Sent);
    expect(collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), 'oauth2')))->toHaveCount(2);
});

it('makes no HTTP call at all when unconfigured', function (): void {
    Http::fake();

    foreach ([new FcmDriver(null, $this->account), new FcmDriver('keverna-test', null), new FcmDriver('keverna-test', '/nope/missing.json'), new FcmDriver('keverna-test', '{"broken": true}')] as $driver) {
        expect($driver->available())->toBeFalse()
            ->and($driver->send($this->message, 'tok', 'android'))->toBe(PushResult::Skipped);
    }

    Http::assertNothingSent();
});

it('picks the driver from config', function (): void {
    config(['services.push.driver' => null, 'services.fcm.project_id' => null, 'services.fcm.credentials' => null]);
    expect(HostPush::makeDriver())->toBeInstanceOf(NullDriver::class);

    config(['services.fcm.project_id' => 'p', 'services.fcm.credentials' => $this->account]);
    expect(HostPush::makeDriver())->toBeInstanceOf(FcmDriver::class)
        ->and(HostPush::makeDriver()->available())->toBeTrue();

    config(['services.push.driver' => 'log']);
    expect(HostPush::makeDriver())->toBeInstanceOf(LogDriver::class);

    config(['services.push.driver' => 'null']);
    expect(HostPush::makeDriver())->toBeInstanceOf(NullDriver::class);
});

it('keeps em-dashes out of push copy', function (): void {
    $m = new PushMessage('bookings', 'New booking — website', 'A – B', 'booking:X', null, 't', '1');

    expect($m->title)->toBe('New booking - website')->and($m->body)->toBe('A - B')
        ->and($m->data()['property_id'])->toBe('');
});
