<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\PropertyType;
use App\Livewire\Dashboard\GlobalSearch;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Property;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Matching rules of the dashboard topbar search
 * ({@see GlobalSearch}), shared with the host
 * app search (GET host/search) so both find the same things.
 *
 *  - Bookings: ref prefix (uppercased), guest name contains, guest
 *    email prefix, guest phone contains, room number prefix (room in
 *    the same branch). Newest check-in first.
 *  - Guests (CRM customers): name contains, email prefix, phone
 *    contains; scoped to the current branch (forCurrentProperty).
 *    Most recent stay first. Only when the operator can see the
 *    customers module.
 */
class DashboardSearch
{
    public const MIN_LENGTH = 2;

    /**
     * @param  (Closure(Builder<Booking>): mixed)|null  $branchScope  how bookings are tied to the
     *                                                                branch. Default (web): bookings whose room is in $propertyId.
     * @return Collection<int, Booking>
     */
    public function bookings(string $needle, ?int $propertyId, int $limit = 8, ?Closure $branchScope = null): Collection
    {
        $needle = trim($needle);
        if (mb_strlen($needle) < self::MIN_LENGTH) {
            return collect();
        }

        // Refs are stored uppercase (KV-XXXX): uppercase for the ref
        // match so LIKE stays case-insensitive on Postgres; lower both
        // sides for the text fields.
        $needleUpper = mb_strtoupper($needle);
        $needleLower = mb_strtolower($needle);

        $branchScope ??= fn (Builder $q) => $q->whereHas('room', fn (Builder $r) => $r->where('property_id', $propertyId));

        return Booking::query()
            ->with(['room', 'room.property'])
            ->when($propertyId !== null, fn (Builder $q) => $branchScope($q))
            ->where(function (Builder $q) use ($needleUpper, $needleLower, $propertyId): void {
                $q->where('booking_ref', 'like', $needleUpper.'%')
                    ->orWhereRaw('LOWER(guest_name) like ?', ['%'.$needleLower.'%'])
                    ->orWhereRaw('LOWER(guest_email) like ?', [$needleLower.'%'])
                    ->orWhere('guest_phone', 'like', '%'.$needleLower.'%')
                    // Room number: exact-prefix, same branch.
                    ->orWhereHas('room', function (Builder $r) use ($needleLower, $propertyId): void {
                        $r->where('room_number', 'like', $needleLower.'%');
                        if ($propertyId !== null) {
                            $r->where('property_id', $propertyId);
                        }
                    });
            })
            ->orderByDesc('checkin_date')
            ->limit($limit)
            ->get();
    }

    /**
     * CRM guests matching the needle (caller checks module visibility).
     *
     * @return Collection<int, Customer>
     */
    public function customers(string $needle, int $limit = 5): Collection
    {
        $needle = mb_strtolower(trim($needle));
        if (mb_strlen($needle) < self::MIN_LENGTH) {
            return collect();
        }

        return Customer::query()
            ->forCurrentProperty()
            ->where(function (Builder $q) use ($needle): void {
                $q->whereRaw('LOWER(name) like ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(email) like ?', [$needle.'%'])
                    ->orWhere('phone', 'like', '%'.$needle.'%');
            })
            ->orderByDesc('last_booking_at')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Same visibility rule the sidebar uses: the operator's role (plus
     * module grants) allows the module AND the branch's property type
     * exposes it.
     */
    public function canSeeModule(?Operator $operator, string $module, ?Property $property): bool
    {
        if (! $operator instanceof Operator || ! $operator->canAccessModule($module)) {
            return false;
        }

        $type = $property?->type ?? PropertyType::Hotel;

        return $type->exposesModule($module);
    }
}
