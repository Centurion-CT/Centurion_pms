<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Exceptions\DokweStorageConnectionException;
use App\Models\Tenant;
use App\Services\Integrations\DokweStorageHandshake;
use App\Services\Multitenancy\TenantMediaDisk;

/**
 * Validate the operator-supplied Dokwe Storage credentials with a live
 * S3 round trip, then persist them onto the Tenant model.
 *
 *   1. Build a transient `s3` disk with the candidate credentials
 *      (never touches config; never persisted).
 *   2. put+exists+delete a tiny sentinel file. If any step throws, we
 *      re-raise as {@see DokweStorageConnectionException} carrying
 *      Dokwe's own error message. No credentials touch the DB.
 *   3. On success: encrypt and store on the tenant, stamp
 *      `dokwe_storage_connected_at`.
 *
 * Follows the pattern of {@see \App\Actions\Paystack\CreatePaystackSubaccount}:
 * live handshake first, persistence only when the external service
 * has explicitly accepted the credentials.
 */
class ConnectDokweStorage
{
    public function __construct(
        private readonly TenantMediaDisk $resolver,
        private readonly DokweStorageHandshake $handshake,
    ) {}

    public function handle(
        Tenant $tenant,
        string $accessKey,
        string $secretKey,
        string $bucket,
        string $endpoint,
        string $publicUrl,
        string $region = 'us-east-1',
    ): void {
        $accessKey = trim($accessKey);
        $secretKey = trim($secretKey);
        $bucket = trim($bucket);
        $endpoint = rtrim(trim($endpoint), '/');
        $publicUrl = rtrim(trim($publicUrl), '/');
        $region = trim($region) !== '' ? trim($region) : 'us-east-1';

        if ($accessKey === '' || $secretKey === '' || $bucket === '' || $endpoint === '' || $publicUrl === '') {
            throw new DokweStorageConnectionException(
                'All fields are required to connect a Dokwe Storage space.',
            );
        }

        // Path-style S3 endpoints are hostnames; refuse the common typo
        // of pasting the file/CDN URL into the S3 field or vice versa
        // (they diverge on the -s3 vs -file prefix).
        if (! str_contains($endpoint, '-s3.') && ! str_starts_with($endpoint, 'http')) {
            throw new DokweStorageConnectionException(
                'Endpoint must be the S3 API URL (looks like https://<space>-s3.dokwe.com).',
            );
        }

        $this->handshake->verify($this->resolver->transientDiskConfig(
            accessKey: $accessKey,
            secretKey: $secretKey,
            bucket: $bucket,
            endpoint: $endpoint,
            publicUrl: $publicUrl,
            region: $region,
        ));

        $tenant->forceFill([
            'dokwe_storage_access_key'   => $accessKey,
            'dokwe_storage_secret_key'   => $secretKey,
            'dokwe_storage_bucket'       => $bucket,
            'dokwe_storage_endpoint'     => $endpoint,
            'dokwe_storage_public_url'   => $publicUrl,
            'dokwe_storage_region'       => $region,
            'dokwe_storage_connected_at' => now(),
        ])->save();
    }
}
