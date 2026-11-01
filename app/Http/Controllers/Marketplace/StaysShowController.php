<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Services\Marketplace\StaysListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /stays/{slug}: Keverna's marketplace page for one building, now a
 * permanent redirect to that building's apartments on the business's
 * booking site. 404 when the slug isn't one of this business's
 * listed buildings.
 */
final class StaysShowController
{
    public function __invoke(Request $request, string $slug, StaysListingQuery $listings): RedirectResponse
    {
        $bundle = $listings->findBySlug($slug);
        if ($bundle === null) {
            throw new NotFoundHttpException;
        }

        // Keverna's marketplace page for one building. The booking site
        // (docs/MULTITENANCY.md, step 5) replaces it: old links land on
        // that building's apartments, keeping any dates they carried.
        $query = array_filter([
            'in' => $request->query('checkin', $request->query('in')),
            'out' => $request->query('checkout', $request->query('out')),
            'adults' => $request->query('adults'),
        ], fn ($v) => $v !== null && $v !== '');

        return redirect()->route('site.results', ['where' => [$bundle['property']->slug]] + $query, 301);
    }
}
