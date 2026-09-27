<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\Review;
use App\Multitenancy\TenantScope;
use App\Services\Marketplace\StaysListingQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Property detail page under /stays/{slug}. Carries dates + guest
 * count forward in the URL so the "Book" CTA on each room can drop
 * the guest straight into the (Packet 4) booking form with a fully-
 * seeded context.
 *
 * 404s when:
 *   - the slug doesn't resolve to any property
 *   - the property opted out of the marketplace (off-market URLs are
 *     not addressable)
 *   - the property has zero active rooms (nothing to book, so the
 *     detail page would be a dead end)
 */
final class StaysShowController
{
    public function __invoke(Request $request, string $slug, StaysListingQuery $listings): View|\Illuminate\Http\RedirectResponse
    {
        $bundle = $listings->findBySlug($slug);
        if ($bundle === null) {
            throw new NotFoundHttpException();
        }

        // Canonical URL for every property is its tenant subdomain
        // (or a custom domain that CNAMEs to it). Redirect the central
        // marketplace URL to the subdomain root so guests + search
        // engines converge on one URL per property. Skip when we're
        // already serving from the subdomain — PublicLandingController
        // dispatches straight to this controller during `/` render.
        $centralDomain = strtolower((string) env('CENTRAL_DOMAIN', ''));
        $currentHost = strtolower($request->getHost());
        if ($centralDomain !== '' && $currentHost === $centralDomain) {
            $subdomain = $bundle['property']->tenant?->primaryHost();
            if ($subdomain) {
                $scheme = $request->isSecure() ? 'https' : 'http';
                $port = $request->getPort();
                $portSuffix = ($port === 80 || $port === 443 || $port === null) ? '' : ':'.$port;
                return redirect()->away($scheme.'://'.$subdomain.$portSuffix.'/', 301);
            }
        }

        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $adults = max(0, (int) $request->query('adults', 0));
        $children = max(0, (int) $request->query('children', 0));

        // Availability filter is only applied when both dates parse
        // AND satisfy every guard:
        //   * check-in >= today  (no past dates)
        //   * check-out >  check-in  (never same day, never reversed)
        // Any violation is silently dropped + the dates are wiped
        // from the render context so the page loads with a clean
        // form + no misleading "N units left" for a bogus range.
        $checkinCarbon = null;
        $checkoutCarbon = null;
        try {
            if ($from !== '' && $to !== '') {
                $ci = Carbon::parse($from)->startOfDay();
                $co = Carbon::parse($to)->startOfDay();
                $today = Carbon::today();
                if ($ci->greaterThanOrEqualTo($today) && $co->greaterThan($ci)) {
                    $checkinCarbon = $ci;
                    $checkoutCarbon = $co;
                }
            }
        } catch (Throwable) {
            // Leave both null; the availability helper treats that
            // as "no date filter" and marks everything available.
        }

        // Clear the URL-echoed dates on the render context when the
        // range failed the guards - the sticky form re-renders with
        // empty fields instead of pre-filling an invalid range.
        if ($checkinCarbon === null) {
            $from = '';
            $to = '';
        }

        // Seed the right-column sticky book card with the same
        // smart defaults the Livewire availability browser uses
        // (see StaysAvailabilityBrowser::seedSmartDefaults) so the
        // two forms open on the same dates. Skips when the guest
        // arrived with URL-provided dates — their intent wins.
        if ($from === '' && $to === '') {
            $seed = $listings->earliestAvailableSingleNight((int) $bundle['property']->id);
            if ($seed !== null) {
                $from = $seed['starts_on']->toDateString();
                $to   = $seed['ends_on']->toDateString();
                $checkinCarbon = $seed['starts_on'];
                $checkoutCarbon = $seed['ends_on'];
            }
        }

        // Guest-facing reviews. Same cross-tenant read as the listing
        // query - Review carries BelongsToTenant so the scope has to
        // be bypassed explicitly here too.
        $reviews = Review::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('property_id', $bundle['property']->id)
            ->displayed()
            ->limit(24)
            ->get();

        $ratingAvg = $reviews->avg('rating');
        $ratingCount = $reviews->count();

        // Resolve the "from" price with rate rules applied so the
        // sticky book card matches the per-category rows below (the
        // Livewire browser already resolves; the sidebar was showing
        // the raw base_rate and drifting out of sync). Uses today's
        // check-in — the guest hasn't picked anything yet in the
        // no-dates state, and once they pick, the Livewire form
        // owns the follow-up render.
        $resolver = app(\App\Services\Marketplace\RateResolver::class);
        $fromPriceBase = (int) $bundle['from_price'];
        $fromPriceResolved = $fromPriceBase;
        // Also track whether ANY category picked up a rule — used to
        // gate the "prices can shift as you search" advisory modal.
        $hasActiveRule = false;
        foreach ($bundle['rooms'] as $r) {
            if ($r->category === null) continue;
            $info = $resolver->resolve($r->category, $request, $checkinCarbon);
            $resolved = (int) $info['rate'];
            if ($info['rule'] !== null) $hasActiveRule = true;
            if ($resolved > 0 && $resolved < $fromPriceResolved) {
                $fromPriceResolved = $resolved;
            }
        }

        return view('marketplace.stays.show', [
            'property'          => $bundle['property'],
            'rooms'             => $bundle['rooms'],
            'from_price'        => $fromPriceResolved,
            'from_price_base'   => $fromPriceBase,
            'has_active_rate_rule' => $hasActiveRule,
            'reviews'           => $reviews,
            'rating_avg'  => $ratingAvg !== null ? round((float) $ratingAvg, 2) : null,
            'rating_count' => $ratingCount,
            'from'        => $from,
            'to'          => $to,
            'adults'      => $adults,
            'children'    => $children,
            'guests'      => $adults + $children,
        ]);
    }
}
