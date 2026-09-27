<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\StockModel;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the "central store" surfaces on Property.stock_model.
 *
 * A tenant whose current property runs on `per_department` inventory
 * doesn't have a central store; the /dashboard/store/* URLs (daily
 * sheet, receive, issue, store products) + the purchasing surfaces
 * that live under role:store are meaningless in that mode. The
 * sidebar already hides those entries, but bookmarks, old links,
 * and typed URLs still land here. Rather than 404 (which reads as a
 * broken app), redirect to /dashboard/store/off, which renders an
 * inline explainer with a one-click path to enable central-store
 * mode in property settings.
 *
 * Trusts auth:web + tenant resolution to have run first.
 */
class EnsureCentralStoreMode
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::current();
        $property = $tenant?->property;
        $mode = $property?->stock_model ?? StockModel::StoreAndRequisition;

        if (! $mode->usesCentralStore()) {
            return redirect()->route('tenant.store.off');
        }

        return $next($request);
    }
}
