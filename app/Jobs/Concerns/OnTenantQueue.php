<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

/**
 * Marker trait — applied to any queueable job whose work is logically
 * scoped to a single tenant. Pushes the job onto the `tenants` Horizon
 * queue (see config/horizon.php) so a noisy tenant's backlog can't
 * stall platform-critical jobs running on the `default` queue.
 *
 * Apply by `use OnTenantQueue;` inside the job — the constructor hook
 * handles routing without each job having to call ->onQueue() manually.
 */
trait OnTenantQueue
{
    public function viaTenantsQueue(): string
    {
        return 'tenants';
    }
}
