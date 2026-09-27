<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Booking;
use App\Models\IdentityAccount;
use Illuminate\Support\Collection;

/**
 * Cross-tenant reservation aggregator for the Phase 2 guest dashboard
 * (docs/unified-login.md §3 "Guest dashboard scope v1").
 *
 * Walks every identity_guest_link on the identity, then reads every
 * Booking whose (tenant_id, customer_id) matches — intentionally
 * bypassing BelongsToTenant. The single-query WHERE-IN join is fast
 * because both columns are indexed via the identity_guest_links unique
 * constraint.
 *
 * Returned rows are annotated with a tenant marker so the view can
 * render "Maitama Residences · Room 4B" without a per-row join. Order
 * is upcoming-first (soonest check-in), then past (most recent first).
 */
final class GuestReservationLookup
{
    /**
     * @return array{upcoming: Collection<int, Booking>, past: Collection<int, Booking>}
     */
    public function forIdentity(IdentityAccount $identity): array
    {
        $links = $identity->guestLinks()->get(['tenant_id', 'customer_id']);
        if ($links->isEmpty()) {
            return ['upcoming' => collect(), 'past' => collect()];
        }

        // Build the (tenant_id, customer_id) filter as a compact OR set.
        // SQL-wise this is `WHERE (tenant_id, customer_id) IN (...)` in
        // MySQL, but SQLite's tuple support is spotty so we emit the
        // paired conditions explicitly.
        $query = Booking::query()->withoutGlobalScopes();
        $query->where(function ($q) use ($links): void {
            foreach ($links as $link) {
                $q->orWhere(function ($inner) use ($link): void {
                    $inner->where('tenant_id', $link->tenant_id)
                        ->where('customer_id', $link->customer_id);
                });
            }
        });

        $bookings = $query
            ->with(['tenant', 'room'])
            ->orderBy('checkin_date')
            ->get();

        $today = now()->startOfDay();

        return [
            'upcoming' => $bookings
                ->filter(fn (Booking $b) => $b->checkin_date->greaterThanOrEqualTo($today))
                ->values(),
            'past' => $bookings
                ->filter(fn (Booking $b) => $b->checkin_date->lessThan($today))
                ->sortByDesc('checkin_date')
                ->values(),
        ];
    }
}
