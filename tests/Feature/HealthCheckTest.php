<?php

declare(strict_types=1);

/*
 * `/up` is dokwe's readiness probe. It must:
 *   - return 200 even before the tenant resolver has run, so a misconfigured
 *     tenant doesn't take the whole container down.
 *   - finish without touching the database, so a slow MySQL doesn't make
 *     dokwe think the pod is unhealthy.
 *
 * Laravel 11 wires /up via `health: '/up'` in bootstrap/app.php. The default
 * handler fires the `DiagnosingHealth` event — no listeners means an empty
 * 200 response. This test pins that behaviour so adding a listener that
 * touches the DB later is an obvious test failure.
 */

it('serves /up with 200 and no tenant resolution', function (): void {
    $this->get('/up')->assertStatus(200);
});

it('serves /up regardless of the host header', function (): void {
    // Tenant subdomains, the admin host, and the central host should all
    // surface a healthy /up — dokwe might probe via any of them.
    foreach (['acme.keverna.test', 'admin.keverna.test', 'keverna.test'] as $host) {
        $response = $this->withHeaders(['Host' => $host])->get('/up');
        expect($response->status())->toBe(200, "host {$host} returned {$response->status()}");
    }
});
