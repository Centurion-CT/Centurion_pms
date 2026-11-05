<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Platform\StaysDirectory;
use App\Site\Stay;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * centurionpms.com/stays: guests browse every listed business and go to
 * its own site to book (App\Platform\StaysDirectory). A plain GET page, so
 * every search has a shareable URL.
 */
final class StaysController
{
    private const PER_PAGE = 24;

    public function __invoke(Request $request, StaysDirectory $directory): View
    {
        $query = trim((string) $request->query('q', ''));
        $city = trim((string) $request->query('city', ''));
        $guests = max(0, min(Stay::MAX_ADULTS, (int) $request->query('adults', 0)));
        $stay = (string) $request->query('in', '') !== ''
            ? Stay::from((string) $request->query('in'), (string) $request->query('out', ''), max(1, $guests))
            : null;

        $results = $directory->search($query, $city, $guests, $stay);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('platform.stays', [
            'query' => $query,
            'city' => $city,
            'guests' => $guests,
            'stay' => $stay,
            'cities' => $directory->cities(),
            'stays' => (new LengthAwarePaginator($results->forPage($page, self::PER_PAGE), $results->count(), self::PER_PAGE, $page, ['path' => $request->url()]))->withQueryString(),
            'carry' => array_filter($stay !== null ? $stay->query() : ['adults' => $guests ?: null]),
        ]);
    }
}
