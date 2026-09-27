<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\RoomStatus;
use App\Enums\TenantStatus;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Workspace;
use App\Models\WorkspacePlan;
use App\Services\Bookings\RoomAllocator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Public landing page for a tenant's property (PRD §6.4.1).
 *
 * Renders one of three states:
 *  - Active|Trial|Grace + property.is_live = true: full landing
 *  - Active|Trial|Grace + property.is_live = false: "Coming Soon"
 *  - Locked: "Temporarily unavailable" (HTTP 200, not 404 — PRD §4.2)
 *
 * Archived tenants never reach this controller; DomainTenantFinder 404s them
 * upstream so a parked listing isn't browsable.
 *
 * Cache: rendered HTML is cached for 5 minutes per tenant id. The cache key
 * isn't path-aware because there's only one `/` page per tenant; the
 * underlying tenancy cache prefix (Spatie's PrefixCacheTask) ensures
 * cross-tenant key collisions are impossible.
 */
class PublicLandingController extends Controller
{
    private const CACHE_TTL_SECONDS = 300; // 5 minutes per PRD §10

    public function show(Request $request, ?Property $property = null): View|Response
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();

        if ($tenant->status === TenantStatus::Locked) {
            return response()->view('tenant.landing-locked', ['tenant' => $tenant], 200);
        }

        // Every tenant subdomain root now serves the marketplace stays
        // detail page for the tenant's public property. This is the
        // property's canonical landing — same view whether the guest
        // arrives from Keverna Stays or the tenant's own domain — so
        // we don't build parallel marketing chrome. Multi-branch
        // tenants still see the branches chooser below.
        if ($property === null) {
            $singleBranch = $tenant->properties()->orderBy('id')->take(2)->get();
            if ($singleBranch->count() === 1
                && $singleBranch->first()->is_listed_publicly
                && ($singleBranch->first()->public_slug ?? '') !== '') {
                return app(\App\Http\Controllers\Marketplace\StaysShowController::class)(
                    $request,
                    (string) $singleBranch->first()->public_slug,
                    app(\App\Services\Marketplace\StaysListingQuery::class),
                );
            }
        }

        // Branch-scoped route (/branches/{slug}) — dispatch to the same
        // marketplace stays view so multi-branch tenants get identical
        // chrome per branch. The nav's branches picker relies on this
        // to actually link somewhere useful.
        if ($property !== null
            && $property->is_listed_publicly
            && ($property->public_slug ?? '') !== '') {
            return app(\App\Http\Controllers\Marketplace\StaysShowController::class)(
                $request,
                (string) $property->public_slug,
                app(\App\Services\Marketplace\StaysListingQuery::class),
            );
        }

        // Multi-branch chooser at the org root — only when the caller
        // hit "/" (no property in the URL) and the tenant has more than
        // one property. A single-branch tenant still gets the landing
        // as if nothing changed, so the pre-multi-property URL
        // (citistay.keverna.com/) keeps working for every existing org.
        $branches = $tenant->properties()->orderBy('id')->get();
        if ($property === null && $branches->count() > 1) {
            return response()->view('tenant.branches-chooser', [
                'tenant' => $tenant,
                'branches' => $branches,
            ], 200);
        }

        $property ??= $branches->first();
        if ($property === null || ! $property->is_live) {
            return response()->view('tenant.landing-coming-soon', [
                'tenant' => $tenant,
                'property' => $property,
            ], 200);
        }

        $cacheKey = $this->cacheKey($tenant, $property);
        $payload = cache()->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildPayload($tenant, $property),
        );
        // The switcher (guest-facing) needs the full branches list so
        // the dropdown can render — not part of the per-property payload
        // cache since it's cheap and org-level.
        $payload['branches'] = $branches;

        return response()
            ->view('tenant.landing', $payload, 200)
            ->header('X-Cache-Key', $cacheKey);
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

        $body = "# Keverna-hosted booking site\n"
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

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Tenant $tenant, Property $property): array
    {
        $kind = $property->type?->kind() ?? 'stay';

        // Inventory loaded per property kind — stay-type tenants get
        // rooms, workspace-type get workspaces + plans, residential
        // gets available units. Always pass all three keys (others
        // default to empty Collections) so the landing template can
        // safely reference any of them.
        $rooms = $kind === 'stay'
            ? $property->rooms()->active()->orderBy('id')->get()
            : new Collection;

        // For stay-type properties the landing now surfaces categories
        // (not individual rooms) so guests see "Deluxe — 2 rooms left"
        // instead of one card per physical unit. Availability is
        // computed for *today* (the only context the landing has
        // without a date picker); the /rooms page handles date-driven
        // search.
        $categories = $kind === 'stay'
            ? $this->buildCategoryInventory($property)
            : new Collection;

        $workspaces = $kind === 'workspace'
            ? Workspace::query()->active()->orderBy('name')->get()
            : new Collection;

        $plans = $kind === 'workspace'
            ? WorkspacePlan::query()->active()->orderBy('price_naira')->get()
            : new Collection;

        $units = $kind === 'residential'
            ? Unit::query()->whereIn('status', [UnitStatus::Available->value, UnitStatus::Leased->value])->orderBy('name')->get()
            : new Collection;

        $reviews = Review::query()
            ->where('property_id', $property->id)
            ->displayed()
            ->get();

        $logoMedia = $property->getFirstMedia('logo');
        $logoUrl = $logoMedia?->getUrl();

        // Hero carousel source — PRD §10 OTA-style. Order of preference:
        //   1. Property's own `gallery` collection (operator-curated slideshow)
        //   2. Room gallery photos as a last-resort fallback (stay-type only)
        //
        // Prior to 2026-07-22 the fallback also read from a separate
        // single-image `hero` collection; that collection was collapsed
        // into `gallery` (with `custom_properties.is_hero=true`) by
        // migration 2026_07_22_100000_migrate_property_hero_to_gallery.
        //
        // Each slide carries both the full URL and a tiny placeholder
        // URL so the template can render a blur-up. Falls back to the
        // same URL twice when the placeholder conversion hasn't been
        // generated for that media (pre-feature uploads).
        $toSlide = function ($media): array {
            return [
                'url'         => $media->getUrl('full'),
                'placeholder' => $media->hasGeneratedConversion('placeholder')
                    ? $media->getUrl('placeholder')
                    : $media->getUrl('full'),
            ];
        };

        $galleryMedia = $property->getMedia('gallery')->sortBy(function ($m) {
            // Any `is_hero` slide bubbles to the front of the carousel
            // so the operator's chosen featured shot leads.
            return $m->getCustomProperty('is_hero') ? 0 : 1;
        })->values();

        $heroSlides = $galleryMedia->map($toSlide)->values();
        if ($heroSlides->isEmpty() && $kind === 'stay') {
            $heroSlides = $rooms->flatMap(fn ($r) => $r->getMedia('gallery')->take(2))
                ->unique('id')
                ->take(5)
                ->map($toSlide)
                ->values();
        }

        // heroUrl was previously used by callers relying on a single
        // fallback image; every consumer now reads $heroSlides. Kept as
        // the first slide's URL for backwards compatibility with any
        // blade partial that still references it.
        $heroUrl = $heroSlides->first()['url'] ?? null;

        // Inventory pricing summary — used for hero badges + stats strip.
        // For stay properties this is per-night room base rate; for
        // workspaces it's the cheapest plan; for residential it's the
        // lowest annual rent.
        [$priceFrom, $priceTo, $pricePer] = match ($kind) {
            'stay' => [
                $rooms->min('base_rate'),
                $rooms->max('base_rate'),
                'night',
            ],
            'workspace' => [
                $plans->min('price_naira'),
                $plans->max('price_naira'),
                $plans->isNotEmpty() ? $plans->first()->billing_interval->value : 'month',
            ],
            'residential' => [
                $units->min('annual_rent_naira'),
                $units->max('annual_rent_naira'),
                'year',
            ],
        };

        // Nav/hero copy + CTA target swap per kind so the public surface
        // matches the property's actual business.
        [$browseRoute, $browseLabel, $navInventoryLabel, $inventoryNoun] = match ($kind) {
            'stay'        => ['tenant.rooms.browse',      'Book a stay',          'Rooms',      'rooms'],
            'workspace'   => ['tenant.workspaces.browse', 'See plans',            'Workspaces', 'workspaces'],
            'residential' => ['tenant.units.browse',      'Browse homes',         'Homes',      'units'],
        };

        $rating = $property->ratingSummary();

        return [
            'tenant'             => $tenant,
            'property'           => $property,
            'kind'               => $kind,
            'rooms'              => $rooms,
            'categories'         => $categories,
            'workspaces'         => $workspaces,
            'plans'              => $plans,
            'units'              => $units,
            'reviews'            => $reviews,
            'heroUrl'            => $heroUrl,
            'heroSlides'         => $heroSlides,
            'logoUrl'            => $logoUrl,
            'canonicalUrl'       => 'https://'.$tenant->primaryHost().'/',
            'ogImageUrl'         => ($heroSlides->first()['url'] ?? null) ?? $heroUrl,
            'siteName'           => $property->name,
            'priceFrom'          => $priceFrom,
            'priceTo'            => $priceTo,
            'pricePer'           => $pricePer,
            'rating'             => $rating,
            'browseRoute'        => $browseRoute,
            'browseLabel'        => $browseLabel,
            'navInventoryLabel'  => $navInventoryLabel,
            'inventoryNoun'      => $inventoryNoun,
        ];
    }

    /**
     * Build the landing's category inventory cards for a stay-type
     * property. Each entry carries the {@see RoomCategory}, its hero
     * image, today's free-unit count, and the total active count so
     * the template can render "X of Y rooms left" / "Fully booked".
     *
     * @return Collection<int, object>
     */
    private function buildCategoryInventory(Property $property): Collection
    {
        /** @var RoomAllocator $allocator */
        $allocator = app(RoomAllocator::class);

        $today = Carbon::today();
        $tomorrow = $today->copy()->addDay();

        return $property->roomCategories()
            ->active()
            ->orderBy('base_rate')
            ->get()
            ->map(function (RoomCategory $category) use ($allocator, $today, $tomorrow): object {
                $total = $allocator->activeCount($category);
                $available = $total > 0
                    ? $allocator->countAvailable($category, $today, $tomorrow)
                    : 0;

                return (object) [
                    'model'     => $category,
                    'hero'      => $category->heroImage(),
                    'total'     => $total,
                    'available' => $available,
                ];
            })
            ->filter(fn (object $e) => $e->total > 0)
            ->values();
    }

    private function cacheKey(Tenant $tenant, Property $property): string
    {
        // Bust the cache whenever ANY media touched by the landing page
        // moves — property-level (hero/logo/gallery) and room-category-
        // level (category gallery photos that drive the inventory grid).
        //
        // The Media table carries `tenant_id` on every row (see
        // 2026_05_23_074954_add_tenant_id_to_media_table). Filtering by
        // it covers both the property AND every model owned by the
        // tenant — categories, rooms, units, workspaces — without
        // needing a separate query per relation.
        $propStamp = $property->updated_at?->timestamp ?? 0;

        $maxMedia = \Spatie\MediaLibrary\MediaCollections\Models\Media::query()
            ->where('tenant_id', $tenant->id)
            ->max('updated_at');

        $mediaStamp = $maxMedia !== null
            ? \Illuminate\Support\Carbon::parse($maxMedia)->timestamp
            : 0;

        return "landing:{$tenant->id}:{$property->id}:{$propStamp}:m{$mediaStamp}";
    }
}
