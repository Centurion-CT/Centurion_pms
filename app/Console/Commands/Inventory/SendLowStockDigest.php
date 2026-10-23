<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use App\Enums\OperatorRole;
use App\Mail\Inventory\LowStockDigestMail;
use App\Models\Operator;
use App\Models\Product;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Push\HostPush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Daily digest — emails each tenant's Managers a list of stock-tracked
 * products whose on-hand quantity has fallen to or below their
 * per-product low_stock_threshold.
 *
 * Tenants with no low-stock items get no email. Tenants with no Managers
 * (still onboarding, or all admins removed) are silently skipped.
 *
 * Runs cross-tenant in a single pass — TenantScope is lifted; the
 * per-tenant grouping is done in-process.
 */
class SendLowStockDigest extends Command
{
    protected $signature = 'inventory:send-low-stock-digest';

    protected $description = 'Email managers a daily digest of products at or below their low-stock threshold';

    public function handle(): int
    {
        $tenants = 0;
        $emailed = 0;
        $pushed = 0;

        $rows = Product::query()
            ->withoutGlobalScope(TenantScope::class)
            // No tenant is current here, so the fail-closed TenantScope
            // must be lifted on the relations too (it used to blank them).
            ->with([
                'outlet' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)->select(['id', 'name', 'property_id']),
                'store' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)->select(['id', 'name', 'property_id']),
            ])
            ->where('active', true)
            ->where('track_stock', true)
            ->where('low_stock_threshold', '>', 0)
            ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold')
            ->get()
            ->groupBy('tenant_id');

        foreach ($rows as $tenantId => $products) {
            $tenant = Tenant::query()->find($tenantId);
            if (! $tenant) {
                continue;
            }

            $items = $products
                ->sortBy(fn (Product $p) => [$p->outlet?->name ?? '', $p->name])
                ->values()
                ->map(fn (Product $p) => [
                    'outlet' => $p->outlet?->name ?? '—',
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'on_hand' => $p->quantity_on_hand,
                    'threshold' => $p->low_stock_threshold,
                ])
                ->all();

            // Host app: one low-stock push per branch (docs/host-app/PHASE-5.md).
            $pushed += app(HostPush::class)->lowStock($tenant, $products);

            $managers = Operator::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('role', OperatorRole::Manager->value)
                ->whereNotNull('email')
                ->pluck('email')
                ->all();

            if (empty($managers)) {
                continue;
            }

            Mail::to($managers)->queue(new LowStockDigestMail($tenant, $items));
            $tenants++;
            $emailed += count($managers);
        }

        $this->components->info("Low-stock digest sent — tenants={$tenants} recipients={$emailed} pushes={$pushed}");

        return self::SUCCESS;
    }
}
