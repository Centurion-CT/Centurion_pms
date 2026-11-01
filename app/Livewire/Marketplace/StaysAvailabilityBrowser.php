<?php

declare(strict_types=1);

namespace App\Livewire\Marketplace;

use App\Models\Tenant;
use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Multitenancy\TenantScope;
use App\Services\Marketplace\StaysListingQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Livewire wrapper around the sticky availability search bar + the
 * category list on /stays/{slug}. Owns the from/to/adults/children
 * state; on "Check availability" (or any date sanitisation) it
 * re-runs categoryAvailability() and re-renders in-place without
 * a full page reload.
 *
 * State is #[Url]-synced so:
 *   * shareable URLs still work (bookmark + share preserves dates)
 *   * the initial mount seeds itself from ?from=&to=&adults=
 *   * subsequent guest edits update the URL bar without a nav
 *
 * The parent show.blade.php is still a plain Blade view - only
 * this section is Livewire-driven. The right-column book card is
 * unaffected: it initialises off the URL on page load; the guest's
 * primary flow is search-bar -> category Reserve, not the right
 * card, so a slight staleness there is acceptable.
 */
final class StaysAvailabilityBrowser extends Component
{
    public string $slug = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    #[Url]
    public int $adults = 1;

    #[Url]
    public int $children = 0;

    /**
     * "Cart passthrough" - when the checkout's "Add another room"
     * link brings the guest back here, it carries `?cart=X,Y` with
     * the existing cart contents. Reserve links on the category
     * cards below fold this list plus the newly-picked room into
     * `?rooms=X,Y,new` on the checkout URL, so a two-click stack
     * turns into a three-room cart cleanly.
     */
    #[Url]
    public string $cart = '';

    /**
     * Advisory message rendered above the availability bar when the
     * defaults were auto-shifted past today because the property is
     * fully booked. Empty when today was available OR the guest
     * arrived with URL-provided dates. Cleared on any manual date
     * edit so the message doesn't linger.
     */
    public string $auto_shift_notice = '';

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        // Drop URL-provided dates that fail the guards - same
        // "silent drop + clear the echo" behaviour StaysShowController
        // uses. Prevents a stale bookmark from pre-filling an invalid
        // range on mount. Guest edits (updatedFrom/updatedTo) auto-
        // correct instead of dropping because the intent is different:
        // they're actively picking, not being handed a bad pair.
        if (! $this->urlRangeIsValid()) {
            $this->from = '';
            $this->to   = '';
        }

        // Seed smart defaults when no URL dates are set:
        //   * Today is available (some category free) → today + tomorrow
        //   * Today is fully booked → next available day + that day + 1,
        //     with an advisory message telling the guest why they landed
        //     on a future date.
        if ($this->from === '' && $this->to === '') {
            $this->seedSmartDefaults();
        }
    }

    /**
     * Look up the earliest single-night calendar day (within a 30-day
     * window from today) where at least one category has an available
     * unit. Sets from/to to that day + 1. When today isn't the winner,
     * stamps an auto_shift_notice explaining the jump.
     */
    private function seedSmartDefaults(): void
    {
        $property = Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('public_slug', $this->slug)
            ->where('tenant_id', Tenant::current()?->getKey()) // this business only (docs/MULTITENANCY.md)
            ->where('is_listed_publicly', true)
            ->first();
        if ($property === null) return;

        $seed = app(StaysListingQuery::class)->earliestAvailableSingleNight((int) $property->id);
        if ($seed === null) {
            // Fully booked for the whole probe window — still hand
            // the guest a valid pair.
            $fallback = Carbon::today()->addDays(30);
            $this->from = $fallback->toDateString();
            $this->to   = $fallback->copy()->addDay()->toDateString();
            $this->auto_shift_notice = "This property is fully booked for the next 30 days. Try picking dates further out.";
            return;
        }

        $this->from = $seed['starts_on']->toDateString();
        $this->to   = $seed['ends_on']->toDateString();
        $shift = (int) $seed['shift_days'];
        if ($shift > 0) {
            $this->auto_shift_notice = $shift === 1
                ? "We're fully booked today, so we've moved your dates to tomorrow."
                : "We're fully booked for the next {$shift} nights. Showing availability from ".$seed['starts_on']->format('D, M j').".";
        }
    }

    private function urlRangeIsValid(): bool
    {
        if ($this->from === '' || $this->to === '') return false;
        try {
            $ci = Carbon::parse($this->from)->startOfDay();
            $co = Carbon::parse($this->to)->startOfDay();
        } catch (\Throwable) {
            return false;
        }
        return $ci->greaterThanOrEqualTo(Carbon::today())
            && $co->greaterThan($ci);
    }

    /**
     * Auto-advance the checkout when the guest picks a check-in.
     * Only overwrites when the checkout is empty OR on/before the
     * new check-in - a manually-picked longer stay is preserved.
     */
    public function updatedFrom(string $value): void
    {
        [$from, $to] = $this->normaliseRange($value, $this->to);
        $this->from = $from;
        $this->to   = $to;
        // Any manual date change: the "we moved you" advisory no longer
        // applies (the guest is now in control of the dates).
        $this->auto_shift_notice = '';
    }

    /**
     * If the guest types a checkout that's not strictly after
     * check-in, snap it to check-in +1. Cheaper to guard here
     * than to render the search form in a persistently invalid
     * state while they figure it out.
     */
    public function updatedTo(string $value): void
    {
        [$from, $to] = $this->normaliseRange($this->from, $value);
        $this->from = $from;
        $this->to   = $to;
        $this->auto_shift_notice = '';
    }

    /**
     * Button-driven refresh - no-op action so the wire:loading
     * targets fire while the render pass completes. Also runs a
     * final sanitise pass in case the guest typed a wrong value
     * that the browser's native picker accepted (e.g. some
     * non-Chrome browsers accept out-of-range dates on input).
     */
    public function submit(): void
    {
        [$from, $to] = $this->normaliseRange($this->from, $this->to);
        $this->from = $from;
        $this->to   = $to;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normaliseRange(string $rawFrom, string $rawTo): array
    {
        $today = Carbon::today();
        $tomorrow = $today->copy()->addDay();

        // Empty check-in short-circuits to "no filter" - the guest
        // hasn't picked anything yet, page shows total-units per
        // category rather than N-of-N-left.
        if ($rawFrom === '') return ['', ''];

        try {
            $ci = Carbon::parse($rawFrom)->startOfDay();
        } catch (\Throwable) {
            return ['', ''];
        }
        if ($ci->lessThan($today)) $ci = $today->copy();

        try {
            $co = $rawTo !== ''
                ? Carbon::parse($rawTo)->startOfDay()
                : $ci->copy()->addDay();
        } catch (\Throwable) {
            $co = $ci->copy()->addDay();
        }
        if ($co->lessThanOrEqualTo($ci)) $co = $ci->copy()->addDay();

        return [$ci->toDateString(), $co->toDateString()];
    }

    public function render(): View
    {
        // Cross-tenant property lookup (central-domain route, no
        // tenant current). Same withoutGlobalScope pattern the show
        // controller + query service use.
        $property = Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('public_slug', $this->slug)
            ->where('tenant_id', Tenant::current()?->getKey()) // this business only (docs/MULTITENANCY.md)
            ->where('is_listed_publicly', true)
            ->with(['media', 'tenant'])
            ->first();

        $checkin = null;
        $checkout = null;
        if ($this->from !== '' && $this->to !== '') {
            try {
                $checkin = Carbon::parse($this->from)->startOfDay();
                $checkout = Carbon::parse($this->to)->startOfDay();
            } catch (\Throwable) {}
        }

        $categories = $property !== null
            ? app(StaysListingQuery::class)->categoryAvailability(
                (int) $property->id, $checkin, $checkout,
                request(),
            )
            : collect();

        $bookBase = $property !== null
            ? route('marketplace.stays.book', ['slug' => $property->public_slug])
            : '#';

        // Reserve links carry the current dates + guest count
        // forward so /book opens on the right room + doesn't ask
        // the guest to retype.
        $qs = fn (array $extra = []) => http_build_query(array_filter(array_merge([
            'from' => $this->from, 'to' => $this->to,
            'adults' => $this->adults ?: null,
            'children' => $this->children ?: null,
        ], $extra), fn ($v) => $v !== null && $v !== ''));

        return view('livewire.marketplace.stays-availability-browser', [
            'property'      => $property,
            'categories'    => $categories,
            'bookBase'      => $bookBase,
            'qs'            => $qs,
            'hasDateFilter' => $this->from !== '' && $this->to !== '',
        ]);
    }
}
