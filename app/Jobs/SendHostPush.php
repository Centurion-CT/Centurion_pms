<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Push\HostPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds and sends one host push trigger (docs/host-app/PHASE-5.md).
 * Dispatched by {@see HostPush} after the triggering transaction
 * commits, with the trigger's tenant made current for the dispatch so
 * the (default) tenant-aware queue binds it when the job runs, even
 * when the trigger came from a central Paystack webhook. Never throws:
 * failures are reported, the caller's request is unaffected even on
 * the sync queue.
 */
class SendHostPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $event,
        public readonly array $payload,
        public readonly ?int $actorId = null,
    ) {}

    public function handle(HostPush $push): void
    {
        try {
            $tenant = Tenant::current();
            if ($tenant === null || (int) $tenant->id !== $this->tenantId) {
                $tenant = Tenant::query()->find($this->tenantId);
                if ($tenant === null) {
                    return;
                }
                $tenant->makeCurrent();
            }
            $push->handle($tenant, $this->event, $this->payload, $this->actorId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
