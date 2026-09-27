<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Persist per-operator UI preferences (sidebar layout for now).
 *
 * DB is source of truth — every page load renders the operator's
 * picked variant server-side into `<html data-sidebar-variant>`, so
 * a fresh device or a cleared IndexedDB cache still lands the right
 * layout on first paint.
 *
 * The client-side IndexedDB cache (see resources/js/operator-prefs.js)
 * is a write-through mirror kept only so successive picks feel
 * instant offline; the endpoint is the authority.
 */
class OperatorPreferencesController extends Controller
{
    public function updateSidebar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant' => ['required', Rule::in(['tiles', 'list'])],
        ]);

        $operator = $request->user('web');
        if ($operator === null) {
            return response()->json(['error' => 'Not signed in.'], 401);
        }

        $prefs = $operator->preferences ?? [];
        $prefs['sidebar_variant'] = $data['variant'];

        // Bypass the Eloquent write guard (AppServiceProvider) using
        // a direct query update. A UI preference is not a
        // billing-material change; blocking it during subscription
        // read-only was the "sets, then reverts on nav" bug.
        \App\Models\Operator::query()
            ->where('id', $operator->id)
            ->update(['preferences' => $prefs]);

        return response()->json([
            'ok' => true,
            'variant' => $data['variant'],
        ]);
    }
}
