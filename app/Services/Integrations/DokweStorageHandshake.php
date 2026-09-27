<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Exceptions\DokweStorageConnectionException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the live put+exists+delete round trip against Dokwe Storage to
 * prove a candidate set of credentials works before we persist them.
 *
 * Extracted from {@see \App\Actions\Integrations\ConnectDokweStorage}
 * as its own class solely so tests can mock the network hop without
 * routing a real AWS SDK call through `Storage::build()` (which
 * bypasses `Storage::fake()`).
 */
class DokweStorageHandshake
{
    /**
     * Verify that the S3-compatible endpoint at `$config['endpoint']`
     * accepts + serves + deletes a small sentinel object using the
     * provided credentials. Throws on any failure with the underlying
     * error surfaced.
     *
     * @param  array<string, mixed>  $config  Same shape as `TenantMediaDisk::transientDiskConfig()`.
     */
    public function verify(array $config): void
    {
        $sentinel = '.keverna-connectivity-'.Str::lower(Str::random(12)).'.txt';
        $payload = 'ok-'.time();

        try {
            $disk = Storage::build($config);
            $disk->put($sentinel, $payload);
            if (! $disk->exists($sentinel)) {
                throw new DokweStorageConnectionException(
                    'Wrote the healthcheck file but Dokwe reports it missing — '
                    .'the bucket may not exist or the key lacks GetObject.',
                );
            }
            $disk->delete($sentinel);
        } catch (DokweStorageConnectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DokweStorageConnectionException(
                'Dokwe rejected the credentials: '.trim($e->getMessage()),
                previous: $e,
            );
        }
    }
}
