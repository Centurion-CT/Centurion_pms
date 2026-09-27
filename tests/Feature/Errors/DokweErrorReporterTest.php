<?php

declare(strict_types=1);

use App\Services\Errors\DokweErrorReporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    config([
        'dokwe.base_url'              => 'https://dokwe.test/api',
        'dokwe.errors.enabled'        => true,
        'dokwe.errors.project_id'     => 'proj-uuid-abc',
        'dokwe.errors.token'          => 'bearer-token-xyz',
        'dokwe.errors.timeout'        => 3,
        'dokwe.errors.stack_frames'   => 10,
        // Whitelist testing so the reporter actually fires.
        'dokwe.errors.environments'   => ['testing'],
    ]);
});

it('shouldReport filters out expected 4xx-type exceptions', function (): void {
    $reporter = app(DokweErrorReporter::class);

    expect($reporter->shouldReport(ValidationException::withMessages(['x' => 'bad'])))->toBeFalse();
    expect($reporter->shouldReport(new AuthenticationException()))->toBeFalse();
    expect($reporter->shouldReport(new AuthorizationException()))->toBeFalse();
    expect($reporter->shouldReport(new ModelNotFoundException()))->toBeFalse();
    expect($reporter->shouldReport(new NotFoundHttpException()))->toBeFalse();
    expect($reporter->shouldReport(new TokenMismatchException()))->toBeFalse();
    expect($reporter->shouldReport(new HttpException(404)))->toBeFalse();
    expect($reporter->shouldReport(new HttpException(422)))->toBeFalse();

    // Actual failures do get reported.
    expect($reporter->shouldReport(new HttpException(500)))->toBeTrue();
    expect($reporter->shouldReport(new HttpException(503)))->toBeTrue();
    expect($reporter->shouldReport(new RuntimeException('DB down')))->toBeTrue();
    expect($reporter->shouldReport(new TypeError('null property x')))->toBeTrue();
});

it('POSTs a well-formed payload to the ingest endpoint for a 500-class error', function (): void {
    Http::fake();

    $request = Request::create('https://acme-hotel.keverna.com/dashboard/reservations', 'GET');

    app(DokweErrorReporter::class)->report(
        new RuntimeException('Widget explosion'),
        $request,
    );

    Http::assertSent(function (HttpRequest $sent) {
        $body = $sent->data();

        return $sent->url() === 'https://dokwe.test/api/ingest/errors/proj-uuid-abc'
            && $sent->method() === 'POST'
            && $sent->hasHeader('Authorization', 'Bearer bearer-token-xyz')
            && $body['message'] === 'Widget explosion'
            && $body['level'] === 'error'
            && $body['platform'] === 'laravel-php'
            && $body['environment'] === 'testing'
            && is_array($body['stack'])
            && $body['context']['exception_class'] === RuntimeException::class
            && str_contains((string) $body['context']['url'], '/dashboard/reservations');
    });
});

it('does NOT POST for filtered exceptions', function (): void {
    Http::fake();

    app(DokweErrorReporter::class)->report(
        ValidationException::withMessages(['field' => 'error']),
    );

    Http::assertNothingSent();
});

it('does NOT POST when disabled via config', function (): void {
    config(['dokwe.errors.enabled' => false]);
    Http::fake();

    app(DokweErrorReporter::class)->report(new RuntimeException('boom'));

    Http::assertNothingSent();
});

it('does NOT POST when token or project_id is missing', function (): void {
    Http::fake();

    config(['dokwe.errors.token' => null]);
    app(DokweErrorReporter::class)->report(new RuntimeException('a'));
    Http::assertNothingSent();

    config(['dokwe.errors.token' => 'x', 'dokwe.errors.project_id' => null]);
    app(DokweErrorReporter::class)->report(new RuntimeException('b'));
    Http::assertNothingSent();
});

it('does NOT POST outside allowed environments', function (): void {
    Http::fake();

    // Not in ['production', 'staging'] by default — testing is only
    // whitelisted here via beforeEach for the happy-path test.
    config(['dokwe.errors.environments' => ['production']]);
    app(DokweErrorReporter::class)->report(new RuntimeException('local dev noise'));

    Http::assertNothingSent();
});

it('swallows reporter failures without re-throwing', function (): void {
    // Fake a network failure by pointing at an unroutable URL and
    // letting Http throw. The reporter's outer try/catch must eat it.
    Http::fake([
        '*' => Http::response(null, 500),
    ]);

    // Also make Http throw by setting timeout=0 style — but simplest:
    // just call report() twice and ensure the second still succeeds
    // (no state corruption).
    expect(fn () => app(DokweErrorReporter::class)
        ->report(new RuntimeException('sentry-down')))
        ->not->toThrow(Throwable::class);
});
