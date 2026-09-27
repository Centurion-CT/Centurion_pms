<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\Properties\PropertyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Endpoint the dashboard sidebar's branch switcher POSTs to. Writes
 * the operator's `current_property_id` and bounces them back to the
 * page they were on (or to /dashboard as a fallback).
 *
 * Deliberately a controller, not a Livewire action — sidebar POSTs
 * from every route need to work without loading a specific Livewire
 * component into memory first.
 */
class BranchSwitchController extends Controller
{
    public function __invoke(Request $request, PropertyContext $context): RedirectResponse
    {
        $data = $request->validate([
            'property_id' => ['required', 'integer'],
            'return_to' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenant = Tenant::current();
        $property = Property::query()
            ->where('id', $data['property_id'])
            ->where('tenant_id', $tenant?->getKey())
            ->first();

        if ($property === null) {
            return redirect()->route('tenant.dashboard');
        }

        // switchTo() returns false when the operator doesn't have access
        // to this branch — the sidebar dropdown already hides
        // inaccessible branches, so a POST that lands here for one is
        // either a stale form or a tampering attempt. Silently bounce
        // without changing their context.
        $context->switchTo($property);

        // Redirect target is trusted only when same-host — a full URL
        // from another domain would be an open-redirect. Fall back to
        // the dashboard when nothing safe is available.
        $return = $data['return_to'] ?? null;
        $host = $request->getSchemeAndHttpHost();
        if ($return && str_starts_with($return, $host)) {
            return redirect($return);
        }

        return redirect()->route('tenant.dashboard');
    }
}
