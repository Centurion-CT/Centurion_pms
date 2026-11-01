<?php

declare(strict_types=1);

namespace App\Services\Multitenancy;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * Resolves the storage disk used by Spatie MediaLibrary for a given
 * tenant. When the tenant has connected their own Dokwe Storage space
 * via the Integrations page, we shadow the platform default with a
 * `tenant_media` disk pointing at their bucket. When
 * they haven't, MediaLibrary keeps using `MEDIA_DISK` and everything
 * lands in the shared platform space.
 *
 * Applied by the {@see \App\Multitenancy\Tasks\UseTenantMediaDisk}
 * switch task each time a business becomes current, in requests and on
 * queue workers alike. The task resets the disk first, since Laravel
 * keeps a built disk for the life of the process.
 */
class TenantMediaDisk
{
    /**
     * The runtime disk key registered under `filesystems.disks.*`.
     * Kept private so callers go through this service and can't
     * accidentally use the disk when it wasn't configured.
     */
    public const DISK_NAME = 'tenant_media';

    /**
     * Register the tenant's Dokwe space as `filesystems.disks.tenant_media`
     * and repoint `media-library.disk_name` at it. No-op if the tenant
     * hasn't connected the integration.
     */
    public function configure(Tenant $tenant): void
    {
        if (! $tenant->hasDokweStorage()) {
            return;
        }

        Config::set('filesystems.disks.'.self::DISK_NAME, [
            'driver'                  => 's3',
            'key'                     => (string) $tenant->dokwe_storage_access_key,
            'secret'                  => (string) $tenant->dokwe_storage_secret_key,
            'region'                  => (string) ($tenant->dokwe_storage_region ?: 'us-east-1'),
            'bucket'                  => (string) $tenant->dokwe_storage_bucket,
            'endpoint'                => (string) $tenant->dokwe_storage_endpoint,
            'url'                     => (string) $tenant->dokwe_storage_public_url,
            'use_path_style_endpoint' => true, // required by Dokwe / MinIO
            'throw'                   => false,
            'report'                  => false,
            'visibility'              => 'public',
        ]);

        Config::set('media-library.disk_name', self::DISK_NAME);
    }

    /**
     * A display-only snapshot for the Integrations UI. Never returns
     * secrets. Used to render "Connected to <bucket> since <date>"
     * status blocks without exposing the access/secret keys.
     *
     * @return array{connected: bool, bucket: ?string, endpoint: ?string, public_url: ?string, region: ?string, connected_at: ?Carbon}
     */
    public function describe(?Tenant $tenant): array
    {
        if ($tenant === null) {
            return ['connected' => false, 'bucket' => null, 'endpoint' => null, 'public_url' => null, 'region' => null, 'connected_at' => null];
        }

        return [
            'connected'    => $tenant->hasDokweStorage(),
            'bucket'       => $tenant->dokwe_storage_bucket,
            'endpoint'     => $tenant->dokwe_storage_endpoint,
            'public_url'   => $tenant->dokwe_storage_public_url,
            'region'       => $tenant->dokwe_storage_region,
            'connected_at' => $tenant->dokwe_storage_connected_at,
        ];
    }

    /**
     * Build a transient disk config array for a *candidate* set of
     * credentials — used by ConnectDokweStorage to run the pre-save
     * handshake without ever writing to the tenant model. Mirrors the
     * shape of `configure()` above; kept separate so the two paths
     * can't drift silently.
     *
     * @return array<string, mixed>
     */
    public function transientDiskConfig(
        string $accessKey,
        string $secretKey,
        string $bucket,
        string $endpoint,
        string $publicUrl,
        string $region = 'us-east-1',
    ): array {
        return [
            'driver'                  => 's3',
            'key'                     => $accessKey,
            'secret'                  => $secretKey,
            'region'                  => $region !== '' ? $region : 'us-east-1',
            'bucket'                  => $bucket,
            'endpoint'                => $endpoint,
            'url'                     => $publicUrl,
            'use_path_style_endpoint' => true,
            'throw'                   => true, // want exceptions during handshake
            'report'                  => false,
            'visibility'              => 'public',
        ];
    }
}
