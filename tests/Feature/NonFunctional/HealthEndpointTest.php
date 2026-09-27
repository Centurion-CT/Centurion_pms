<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Queue;

it('returns 200 with ok status when subsystems are healthy', function (): void {
    $response = $this->get('http://keverna.test/health');

    $response->assertOk();
    $response->assertJson([
        'status' => 'ok',
        'checks' => [
            'database' => ['ok' => true],
            'cache' => ['ok' => true],
            'storage' => ['ok' => true],
            'queue' => ['ok' => true],
        ],
    ]);
});

it('includes a timestamp in ISO-8601 format', function (): void {
    $response = $this->get('http://keverna.test/health');

    $data = $response->json();
    expect($data['timestamp'])->toMatch('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('returns 503 with degraded status when a subsystem fails', function (): void {
    // Point the cache driver at a name no driver is registered for — every
    // call into Cache::* throws, simulating a Redis outage.
    config()->set('cache.default', 'nonexistent-driver');

    $response = $this->get('http://keverna.test/health');

    $response->assertStatus(503);
    $data = $response->json();
    expect($data['status'])->toBe('degraded');
    expect($data['checks']['cache']['ok'])->toBeFalse();
    expect($data['checks']['cache']['error'])->not->toBeNull();
});

it('degrades when the queue backlog exceeds the threshold', function (): void {
    // Stub the facade so size() returns a value above the health limit.
    Queue::shouldReceive('size')->andReturn(999);

    $response = $this->get('http://keverna.test/health');

    $response->assertStatus(503);
    $data = $response->json();
    expect($data['status'])->toBe('degraded');
    expect($data['checks']['queue']['ok'])->toBeFalse();
    expect($data['checks']['queue']['error'])->toContain('999');
});
