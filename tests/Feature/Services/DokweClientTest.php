<?php

declare(strict_types=1);

use App\Services\Dokwe\DokweClient;
use App\Services\Dokwe\DokweException;
use Illuminate\Support\Facades\Http;

/*
 * Pins DokweClient's behaviour against the contract documented in
 * docs/dokwe-integration.md. The dokwe API itself is exercised by
 * dokwe's own test suite — here we only verify the client's edge
 * handling (idempotency, error code propagation, polling, recovery
 * from 409).
 */

const PROJECT = '9b22e799-a6eb-43db-841e-8715451d5a91';
const BASE = 'https://dokwe.test/api';

function client(): DokweClient
{
    return new DokweClient(
        baseUrl: BASE,
        apiKey: 'dk_pak_test',
        projectId: PROJECT,
    );
}

it('rejects construction with a missing api key', function (): void {
    new DokweClient(BASE, '', PROJECT);
})->throws(DokweException::class, 'DOKWE_API_KEY');

it('rejects construction with a missing project id', function (): void {
    new DokweClient(BASE, 'dk_pak_test', '');
})->throws(DokweException::class, 'DOKWE_PROJECT_ID');

it('attaches a domain and returns the new dokwe id', function (): void {
    Http::fake([
        BASE . '/domains/' . PROJECT => Http::response([
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'projectId' => PROJECT,
            'domain' => 'acme.keverna.com',
            'type' => 'custom',
            'verified' => false,
            'sslStatus' => 'pending',
        ], 201),
    ]);

    $id = client()->attachDomain('acme.keverna.com');

    expect($id)->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
    Http::assertSent(fn ($req) => $req->method() === 'POST'
        && $req->url() === BASE . '/domains/' . PROJECT
        && $req['domain'] === 'acme.keverna.com'
    );
});

it('treats 409 as success by listing and matching the existing id', function (): void {
    Http::fake([
        BASE . '/domains/' . PROJECT => Http::sequence()
            ->push(['message' => 'Domain already configured'], 409)
            ->push([
                ['id' => 'existing-id', 'projectId' => PROJECT, 'domain' => 'acme.keverna.com', 'type' => 'custom', 'verified' => true, 'sslStatus' => 'provisioned'],
            ], 200),
    ]);

    $id = client()->attachDomain('acme.keverna.com');

    expect($id)->toBe('existing-id');
});

it('propagates dokwe error codes verbatim onto DokweException', function (): void {
    Http::fake([
        BASE . '/domains/' . PROJECT => Http::response([
            'message' => 'This workspace requires two-factor authentication',
            'code' => 'WORKSPACE_REQUIRES_2FA',
        ], 403),
    ]);

    try {
        client()->attachDomain('acme.keverna.com');
        $this->fail('expected DokweException');
    } catch (DokweException $e) {
        expect($e->statusCode)->toBe(403)
            ->and($e->errorCode)->toBe('WORKSPACE_REQUIRES_2FA')
            ->and($e->isClientError())->toBeTrue();
    }
});

it('detach swallows a 404 (already gone)', function (): void {
    Http::fake([
        BASE . '/domains/' . PROJECT . '/missing-id' => Http::response(['message' => 'not found'], 404),
    ]);

    client()->detach('missing-id'); // no throw
    Http::assertSent(fn ($req) => $req->method() === 'DELETE');
});

it('getDomain finds an attached row by id', function (): void {
    Http::fake([
        BASE . '/domains/' . PROJECT => Http::response([
            ['id' => 'id-a', 'projectId' => PROJECT, 'domain' => 'a.keverna.com', 'type' => 'auto', 'verified' => true, 'sslStatus' => 'provisioned'],
            ['id' => 'id-b', 'projectId' => PROJECT, 'domain' => 'b.keverna.com', 'type' => 'custom', 'verified' => false, 'sslStatus' => 'pending'],
        ], 200),
    ]);

    $row = client()->getDomain('id-b');

    expect($row->domain)->toBe('b.keverna.com')
        ->and($row->isProvisioned())->toBeFalse();
});

it('reports isProvisioned only when verified AND ssl provisioned', function (): void {
    Http::fake([
        BASE . '/domains/' . PROJECT . '/id-a/verify' => Http::response([
            'id' => 'id-a', 'projectId' => PROJECT, 'domain' => 'a.keverna.com',
            'type' => 'custom', 'verified' => true, 'sslStatus' => 'provisioned',
        ], 200),
    ]);

    $row = client()->verify('id-a');

    expect($row->isProvisioned())->toBeTrue()
        ->and($row->isFailed())->toBeFalse();
});
