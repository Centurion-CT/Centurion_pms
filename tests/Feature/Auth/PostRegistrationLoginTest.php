<?php

declare(strict_types=1);

use App\Models\Operator;
use App\Models\Tenant;
use App\Services\PostRegistrationLoginLink;

it('logs the operator in and redirects to onboarding for a valid token', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'after-reg']);
    $operator = Operator::factory()->for($tenant)->create();

    $url = app(PostRegistrationLoginLink::class)->generate($tenant, $operator);
    $token = parse_url($url, PHP_URL_QUERY);
    parse_str((string) $token, $params);

    $response = $this->get('http://after-reg.keverna.test/auth/post-registration?token='.urlencode($params['token']));

    $response->assertRedirect('http://after-reg.keverna.test/onboarding');
    expect(auth()->guard('web')->user()?->id)->toBe($operator->id);
});

it('updates last_login_at when the token is consumed', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'logging-in']);
    $operator = Operator::factory()->for($tenant)->create(['last_login_at' => null]);

    $url = app(PostRegistrationLoginLink::class)->generate($tenant, $operator);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    $this->get('http://logging-in.keverna.test/auth/post-registration?token='.urlencode($params['token']));

    expect($operator->fresh()->last_login_at)->not->toBeNull();
});

it('rejects a token that has been tampered with', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'tamper-test']);
    Operator::factory()->for($tenant)->create();

    $response = $this->get('http://tamper-test.keverna.test/auth/post-registration?token=garbage');

    $response->assertForbidden();
    expect(auth()->guard('web')->check())->toBeFalse();
});

it('rejects a token issued for a different tenant', function (): void {
    $tenantA = Tenant::factory()->create(['slug' => 'first-co']);
    $tenantB = Tenant::factory()->create(['slug' => 'second-co']);
    $operatorOfA = Operator::factory()->for($tenantA)->create();

    $url = app(PostRegistrationLoginLink::class)->generate($tenantA, $operatorOfA);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    // Try to use A's token at B's subdomain.
    $response = $this->get('http://second-co.keverna.test/auth/post-registration?token='.urlencode($params['token']));

    $response->assertForbidden();
    expect(auth()->guard('web')->check())->toBeFalse();
});

it('rejects an expired token', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'expired']);
    $operator = Operator::factory()->for($tenant)->create();

    $url = app(PostRegistrationLoginLink::class)->generate($tenant, $operator);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    $this->travel(10)->minutes();

    $response = $this->get('http://expired.keverna.test/auth/post-registration?token='.urlencode($params['token']));

    $response->assertForbidden();
});

it('rejects an empty token query string', function (): void {
    Tenant::factory()->create(['slug' => 'no-token']);

    $response = $this->get('http://no-token.keverna.test/auth/post-registration');

    $response->assertForbidden();
});
