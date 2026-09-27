<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SearchCacheBookingResource;
use App\Models\Booking;
use App\Models\Operator;
use App\Services\Properties\PropertyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Feeds the dashboard's IndexedDB search cache (spec: Phase 1).
 *
 * Returns a rolling window of the reservations a front-desk operator
 * would actually search:
 *   * status IN (checked_in, confirmed, on_hold) within the operator's
 *     current property scope, OR
 *   * checkin_date between today-3d and today+30d
 *
 * Supports incremental sync via ?since=<iso>. The client tracks the
 * highest updated_at it has seen and passes it back on the next
 * pull; the server returns only changed rows plus a `deleted_ids`
 * list of ids that are no longer eligible for the cache (cancelled,
 * no-show, or scrolled past the +30d window).
 */
class SearchCacheController extends Controller
{
    private const PAGE_LIMIT = 500;

    private const WINDOW_BACK_DAYS = 3;

    private const WINDOW_FORWARD_DAYS = 30;

    /** Statuses eligible for the cache. */
    private const LIVE_STATUSES = [
        'checked_in',
        'confirmed',
        'on_hold',
    ];

    /** Statuses that trigger cache eviction on the client. */
    private const DEAD_STATUSES = [
        'cancelled',
        'no_show',
    ];

    public function __invoke(Request $request, PropertyContext $propertyContext): JsonResponse
    {
        /** @var Operator|null $operator */
        $operator = $request->user('web');
        abort_unless($operator instanceof Operator, 401);

        $since = $this->parseSince($request->query('since'));
        $propertyId = $this->resolvePropertyId($request, $operator, $propertyContext);

        [$from, $to] = $this->cacheWindow();

        $rows = $this->fetchLiveRows($propertyId, $since, $from, $to);
        $deletedIds = $this->fetchDeletedIds($propertyId, $since, $from, $to);

        return response()->json([
            'rows'         => SearchCacheBookingResource::collection($rows),
            'deleted_ids'  => $deletedIds,
            'server_time'  => Carbon::now()->toIso8601String(),
            'has_more'     => $rows->count() >= self::PAGE_LIMIT,
        ]);
    }

    private function parseSince(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePropertyId(
        Request $request,
        Operator $operator,
        PropertyContext $propertyContext,
    ): ?int {
        // Explicit ?property_id=X wins so a client that manages its
        // own branch selection (multi-branch admin) can override the
        // stored default without a switchTo round-trip.
        $requested = $request->query('property_id');
        if (is_numeric($requested)) {
            $id = (int) $requested;
            $accessible = $operator->accessibleBranchIds();
            // Empty list means "no restriction, all branches". Otherwise
            // check the requested id lives in the pivot set. Managers
            // always pass since accessibleBranchIds() returns [] for them.
            if ($accessible === [] || in_array($id, $accessible, true)) {
                return $id;
            }
        }

        return $propertyContext->current($operator)?->id;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function cacheWindow(): array
    {
        $today = Carbon::today();

        return [
            $today->copy()->subDays(self::WINDOW_BACK_DAYS),
            $today->copy()->addDays(self::WINDOW_FORWARD_DAYS),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Booking>
     */
    private function fetchLiveRows(?int $propertyId, ?Carbon $since, Carbon $from, Carbon $to)
    {
        return Booking::query()
            ->with(['room', 'room.property'])
            ->when(
                $propertyId !== null,
                fn (Builder $q) => $q->whereHas('room', fn (Builder $r) => $r->where('property_id', $propertyId))
            )
            ->where(function (Builder $q) use ($from, $to): void {
                $q->whereIn('booking_status', self::LIVE_STATUSES)
                    ->orWhereBetween('checkin_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->when($since !== null, fn (Builder $q) => $q->where('updated_at', '>', $since))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(self::PAGE_LIMIT)
            ->get();
    }

    /**
     * ids of bookings the client should evict from its cache. Only
     * populated on incremental pulls (since != null) — a cold
     * hydration doesn't need this because the client has nothing to
     * evict yet.
     *
     * @return array<int, int>
     */
    private function fetchDeletedIds(?int $propertyId, ?Carbon $since, Carbon $from, Carbon $to): array
    {
        if ($since === null) {
            return [];
        }

        return Booking::query()
            ->when(
                $propertyId !== null,
                fn (Builder $q) => $q->whereHas('room', fn (Builder $r) => $r->where('property_id', $propertyId))
            )
            ->where(function (Builder $q) use ($from, $to): void {
                // "Not eligible for the cache" — booking either
                // flipped to a dead status, or its check-in slid out
                // of the rolling window.
                $q->whereIn('booking_status', self::DEAD_STATUSES)
                    ->orWhere(function (Builder $inner) use ($from, $to): void {
                        $inner->whereNotIn('booking_status', self::LIVE_STATUSES)
                            ->where(function (Builder $window) use ($from, $to): void {
                                $window->where('checkin_date', '<', $from->toDateString())
                                    ->orWhere('checkin_date', '>', $to->toDateString());
                            });
                    });
            })
            ->where('updated_at', '>', $since)
            ->pluck('id')
            ->all();
    }
}
