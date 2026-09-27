<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Topbar-wide search across every dashboard page. Matches a booking
 * by ref, guest name, email, or phone, and surfaces up to eight hits
 * inline so the operator can jump to a reservation from anywhere
 * without navigating to the Bookings module first.
 *
 * Search fires on an explicit submit (Enter or the button) via a
 * real <form wire:submit> — no live keystroke round-trips. Stops the
 * old confusing-on-prod flow where debounced responses landed after
 * the operator kept typing.
 *
 * Property scoping honours PropertyContext, on multi-branch tenants
 * the search only walks the branch the operator is currently viewing.
 * Falls back to unscoped tenant-wide when nothing is selected.
 */
class GlobalSearch extends Component
{
    public string $query = '';

    public bool $searched = false;

    public function runSearch(): void
    {
        $this->searched = mb_strlen(trim($this->query)) >= 2;
    }

    /**
     * Back-compat alias, in case a wire:navigate snapshot cached in
     * the browser still references the older method name after a
     * deploy. Livewire hits whatever the snapshot says, we point
     * both names at the same handler so nothing 500s while the SPA
     * catches up.
     */
    public function submit(string $q = ''): void
    {
        if ($q !== '') {
            $this->query = $q;
        }
        $this->runSearch();
    }

    public function clear(): void
    {
        $this->query = '';
        $this->searched = false;
    }

    /**
     * Bookings that match the current query. Returns an empty
     * collection when the query is under 2 chars so a stray key
     * press with a stale draft doesn't spam a full scan.
     */
    #[Computed]
    public function results()
    {
        $needle = trim($this->query);
        if (mb_strlen($needle) < 2) {
            return collect();
        }

        try {
            $propertyId = null;
            try {
                $propertyId = app(PropertyContext::class)->current()?->id;
            } catch (\Throwable $e) {
                // PropertyContext can trip on session/auth edges — fall
                // back to unscoped tenant-wide instead of 500'ing the
                // whole search request.
                report($e);
            }

            // Booking refs are stored uppercase (KV-XXXX). Uppercase
            // the needle for the ref match so an operator typing
            // "kv-3s8" still lands the reservation on Postgres (LIKE
            // is case-sensitive on default collations).
            $needleUpper = mb_strtoupper($needle);
            // For text fields (guest name/email/phone) drop to a lower
            // LIKE both sides so casing never blocks a match, at the
            // cost of skipping an index that wouldn't be there anyway.
            $needleLower = mb_strtolower($needle);

            return Booking::query()
                ->with(['room', 'room.property'])
                ->when(
                    $propertyId !== null,
                    fn (Builder $q) => $q->whereHas('room', fn (Builder $r) => $r->where('property_id', $propertyId))
                )
                ->where(function (Builder $q) use ($needleUpper, $needleLower, $propertyId): void {
                    $q->where('booking_ref', 'like', $needleUpper.'%')
                        ->orWhereRaw('LOWER(guest_name) like ?', ['%'.$needleLower.'%'])
                        ->orWhereRaw('LOWER(guest_email) like ?', [$needleLower.'%'])
                        ->orWhere('guest_phone', 'like', '%'.$needleLower.'%')
                        // Room number match: front desk types "210" and
                        // lands the guest currently in that room. Match
                        // is exact-prefix on the room number itself,
                        // scoped to the same property scope as the
                        // outer query so a "3" doesn't drag every room
                        // starting with 3 across every branch.
                        ->orWhereHas('room', function (Builder $r) use ($needleLower, $propertyId): void {
                            $r->where('room_number', 'like', $needleLower.'%');
                            if ($propertyId !== null) {
                                $r->where('property_id', $propertyId);
                            }
                        });
                })
                ->orderByDesc('checkin_date')
                ->limit(8)
                ->get();
        } catch (\Throwable $e) {
            report($e);
            return collect();
        }
    }

    /**
     * Focus-time browse: the two lists an operator most often
     * reaches for when they open the search WITHOUT a query.
     * Grouped by "In-house now" (checked-in) + "Arriving next"
     * (confirmed / on_hold with a check-in within the next 14
     * days). Each list is capped at 5 rows so the dropdown stays
     * one-glance readable.
     *
     * @return array{in_house: \Illuminate\Support\Collection, arriving: \Illuminate\Support\Collection}
     */
    #[Computed]
    public function browse(): array
    {
        try {
            $propertyId = null;
            try {
                $propertyId = app(PropertyContext::class)->current()?->id;
            } catch (\Throwable $e) {
                report($e);
            }

            $scope = fn (Builder $q) => $propertyId === null
                ? $q
                : $q->whereHas('room', fn (Builder $r) => $r->where('property_id', $propertyId));

            $today = Carbon::today();

            $inHouse = Booking::query()
                ->with(['room', 'room.property'])
                ->tap($scope)
                ->where('booking_status', BookingStatus::CheckedIn->value)
                ->orderBy('checkout_date')
                ->limit(5)
                ->get();

            $arriving = Booking::query()
                ->with(['room', 'room.property'])
                ->tap($scope)
                ->whereIn('booking_status', [
                    BookingStatus::Confirmed->value,
                    BookingStatus::OnHold->value,
                ])
                ->whereBetween('checkin_date', [
                    $today->toDateString(),
                    $today->copy()->addDays(14)->toDateString(),
                ])
                ->orderBy('checkin_date')
                ->limit(5)
                ->get();

            return ['in_house' => $inHouse, 'arriving' => $arriving];
        } catch (\Throwable $e) {
            report($e);
            return ['in_house' => collect(), 'arriving' => collect()];
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard.global-search');
    }
}
