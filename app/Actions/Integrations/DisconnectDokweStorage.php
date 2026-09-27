<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\Tenant;

/**
 * Wipe the tenant's Dokwe Storage credentials. New uploads immediately
 * flip back to the platform's default `MEDIA_DISK`. Existing media
 * rows keep their own `disk` column — files uploaded while the
 * integration was connected continue to resolve against the tenant's
 * Dokwe space, unaffected by the disconnect.
 *
 * We deliberately don't purge the operator's bucket. If they want the
 * files gone, they can do it from their Dokwe dashboard directly —
 * that's their storage.
 */
class DisconnectDokweStorage
{
    public function handle(Tenant $tenant): void
    {
        $tenant->forceFill([
            'dokwe_storage_access_key'   => null,
            'dokwe_storage_secret_key'   => null,
            'dokwe_storage_bucket'       => null,
            'dokwe_storage_endpoint'     => null,
            'dokwe_storage_public_url'   => null,
            'dokwe_storage_region'       => 'us-east-1',
            'dokwe_storage_connected_at' => null,
        ])->save();
    }
}
