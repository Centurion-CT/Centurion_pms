<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /branches/{slug}: a link to one building. Bookable buildings go to their
 * apartments on the booking site; one with nothing to book yet gets a short
 * page with the contacts. Also serves the business's robots.txt and sitemap.
 *
 * Locked businesses see "temporarily unavailable" (HTTP 200, not 404, PRD
 * §4.2). Archived ones never reach this controller; the tenant finder 404s
 * them upstream.
 */
class PublicLandingController extends Controller
{
    public function show(Request $request, Property $property): View|Response|RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();

        if ($tenant->status === TenantStatus::Locked) {
            return response()->view('tenant.landing-locked', ['tenant' => $tenant], 200);
        }

        if ($property->is_listed_publicly
            && ($property->public_slug ?? '') !== ''
            && $property->rooms()->active()->exists()) {
            return app(\App\Http\Controllers\Marketplace\StaysShowController::class)(
                $request,
                (string) $property->public_slug,
                app(\App\Services\Marketplace\StaysListingQuery::class),
            );
        }

        return view('site.branch', ['branch' => $property]);
    }

    /**
     * Sitemap is intentionally NOT cached — small payload, cheap query, search
     * engines hit it rarely. Lists the landing page + every bookable
     * inventory unit appropriate to the property type (rooms for
     * stay-type, the workspaces browse for workspace-type, the units
     * browse for residential).
     */
    /**
     * Per-tenant robots.txt. Explicitly allows every crawler + points
     * at this tenant's sitemap so search engines and AI systems can
     * bootstrap without hunting for it. Operator/dashboard surfaces
     * live on separate hostnames so nothing here needs disallowing.
     */
    public function robots(): Response
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $host = $tenant->primaryHost();

        $body = "# {$tenant->name} booking site\n"
              . "# Open to search + AI citation crawlers so answers about\n"
              . "# {$tenant->name} get sourced from us.\n\n"
              . "User-agent: *\n"
              . "Allow: /\n\n"
              . "User-agent: GPTBot\n"
              . "Allow: /\n\n"
              . "User-agent: Google-Extended\n"
              . "Allow: /\n\n"
              . "User-agent: PerplexityBot\n"
              . "Allow: /\n\n"
              . "User-agent: ClaudeBot\n"
              . "Allow: /\n\n"
              . "User-agent: anthropic-ai\n"
              . "Allow: /\n\n"
              . "Sitemap: https://{$host}/sitemap.xml\n";

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap(): Response
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $host = $tenant->primaryHost();
        $base = 'https://'.$host;

        $urls = [['loc' => $base.'/', 'priority' => '1.0']];

        // Multi-branch: walk every property and emit both the branch
        // landing URL and its per-branch inventory. Legacy /rooms
        // stays in the sitemap for tenants that haven't grown beyond
        // one branch — the browse-rooms page still resolves cleanly.
        $branches = $tenant->properties()->orderBy('id')->get();
        foreach ($branches as $property) {
            $kind = $property->type?->kind() ?? 'stay';
            $branchBase = $base.'/branches/'.$property->slug;
            $urls[] = ['loc' => $branchBase.'/', 'priority' => '0.95'];

            if ($kind === 'stay') {
                $urls[] = ['loc' => $branchBase.'/rooms', 'priority' => '0.9'];
                foreach ($property->rooms()->active()->orderBy('id')->get() as $room) {
                    $urls[] = ['loc' => $branchBase.'/rooms/'.$room->id, 'priority' => '0.8'];
                }
            } elseif ($kind === 'workspace') {
                $urls[] = ['loc' => $branchBase.'/workspaces', 'priority' => '0.9'];
            } elseif ($kind === 'residential') {
                $urls[] = ['loc' => $branchBase.'/units', 'priority' => '0.9'];
            }
        }

        // Legacy top-level URLs for single-branch orgs — the routes
        // still resolve for backwards compat so bookmarks + old
        // confirmation-email deep links (/rooms/{id}) keep working.
        // Emit them alongside the branch-scoped equivalents so we
        // don't strand crawlers on either shape.
        if ($branches->count() === 1) {
            $first = $branches->first();
            $firstKind = $first?->type?->kind();
            if ($firstKind === 'stay') {
                $urls[] = ['loc' => $base.'/rooms', 'priority' => '0.7'];
                foreach ($first->rooms()->active()->orderBy('id')->get() as $room) {
                    $urls[] = ['loc' => $base.'/rooms/'.$room->id, 'priority' => '0.7'];
                }
            }
            if ($firstKind === 'workspace') $urls[] = ['loc' => $base.'/workspaces', 'priority' => '0.7'];
            if ($firstKind === 'residential') $urls[] = ['loc' => $base.'/units', 'priority' => '0.7'];
        }

        $xml = view('tenant.sitemap', ['urls' => $urls])->render();

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
