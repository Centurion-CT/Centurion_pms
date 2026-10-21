<?php

declare(strict_types=1);

use App\Http\Middleware\ForceHttpsInProduction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    app()->detectEnvironment(fn () => 'production');
});

afterEach(function (): void {
    app()->detectEnvironment(fn () => 'testing');
});

function runForceHttps(Request $request): Response
{
    return (new ForceHttpsInProduction())->handle($request, fn () => new Response('ok'));
}

it('301s plain http to https in production by default', function (): void {
    $response = runForceHttps(Request::create('http://hotel.test/dashboard?x=1'));

    expect($response->getStatusCode())->toBe(301)
        ->and($response->headers->get('Location'))->toBe('https://hotel.test/dashboard?x=1');
});

it('passes plain http through when FORCE_HTTPS is off', function (): void {
    config()->set('keverna.force_https', false);

    $response = runForceHttps(Request::create('http://hotel.test/dashboard'));

    expect($response->getStatusCode())->toBe(200);
});

it('passes https through', function (): void {
    $response = runForceHttps(Request::create('https://hotel.test/dashboard'));

    expect($response->getStatusCode())->toBe(200);
});
