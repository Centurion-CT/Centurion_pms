<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Services\Marketplace\StaysListingQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Keverna Stays marketplace - guest-facing search + listing page.
 *
 * URL contract (backwards-compatible with the previous /search stub):
 *   /stays?q=<city or name>&from=YYYY-MM-DD&to=YYYY-MM-DD&adults=2&children=0&budget=<int>
 *
 * Uses a plain controller (not Livewire) because filter changes are
 * whole-page reloads with new query params - shareable URLs matter
 * more here than sub-request interactivity. Livewire wraps the room
 * picker on the property-detail page in the next packet.
 */
final class StaysIndexController
{
    public function __invoke(Request $request, StaysListingQuery $listings): View
    {
        $q = trim((string) $request->query('q', ''));
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $adults = max(0, (int) $request->query('adults', 0));
        $children = max(0, (int) $request->query('children', 0));
        $budget = max(0, (int) $request->query('budget', 0));
        $guests = $adults + $children;

        $results = $listings->search([
            'query'     => $q,
            'guests'    => $guests,
            'max_price' => $budget,
        ], $request);
        $cities = $listings->cities();

        return view('marketplace.stays.index', [
            'q'         => $q,
            'from'      => $from,
            'to'        => $to,
            'adults'    => $adults,
            'children'  => $children,
            'guests'    => $guests,
            'budget'    => $budget,
            'results'   => $results,
            'cities'    => $cities,
        ]);
    }
}
