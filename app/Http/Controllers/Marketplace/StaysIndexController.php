<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /stays: Keverna's marketplace search, now a permanent redirect to the
 * business's apartments on its booking site, keeping any dates the link
 * carried (docs/MULTITENANCY.md, step 5).
 */
final class StaysIndexController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $query = array_filter([
            'in' => $request->query('from', $request->query('in')),
            'out' => $request->query('to', $request->query('out')),
            'adults' => $request->query('adults'),
        ], fn ($v) => $v !== null && $v !== '');

        return redirect()->route('site.results', $query, 301);
    }
}
