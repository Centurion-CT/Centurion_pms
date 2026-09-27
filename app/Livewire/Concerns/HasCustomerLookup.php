<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\CorporateMemberStatus;
use App\Models\CorporateMember;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;

/**
 * Customer typeahead for any operator-facing reservation form.
 *
 * Components opt in by adding `use HasCustomerLookup;`. The host
 * component must expose `string $guest_name`, `string $guest_email`,
 * `string $guest_phone` as public properties, the trait writes into
 * them when an existing customer is picked.
 *
 * Usage in a Blade view:
 *   - Bind a search input to `wire:model.live.debounce.250ms="customerSearch"`
 *   - Iterate `$this->customerMatches` for the suggestions dropdown
 *   - Call `pickCustomer($id)` from each suggestion's click handler
 *   - When `$selectedCustomerId` is non-null, show a "picked" pill and
 *     give the operator a way to clear it via `clearPickedCustomer()`
 *
 * The CustomerLinker still re-resolves by email at booking-creation
 * time, so a picked customer + edited name still ends up attached to
 * the right Customer record. We don't plumb the picked id through to
 * CreateBooking, keeps the action contract narrow.
 */
trait HasCustomerLookup
{
    public string $customerSearch = '';

    public ?int $selectedCustomerId = null;

    /**
     * Best-match customers for the current search term. Caps at 6 so
     * the dropdown stays scannable. Ordered by recent activity so the
     * front-desk's "regulars" surface first.
     *
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerMatches(): Collection
    {
        $term = trim($this->customerSearch);
        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        $like = '%'.$term.'%';

        // Phase 4 CRM: typeahead only surfaces customers from the
        // operator's current branch. A walk-in at Property A won't
        // accidentally pick + link to a customer created at Property B.
        return Customer::query()->forCurrentProperty()
            ->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->orderByRaw('last_booking_at IS NULL, last_booking_at DESC')
            ->orderBy('name')
            ->limit(6)
            ->get();
    }

    /**
     * Best-match customer (used when host wants to render a pill).
     */
    #[Computed]
    public function pickedCustomer(): ?Customer
    {
        if ($this->selectedCustomerId === null) {
            return null;
        }

        // Also branch-scoped: a picked id from a stale session that
        // no longer belongs to the operator's current branch resolves
        // to null instead of leaking cross-branch state.
        return Customer::query()->forCurrentProperty()
            ->with('wallet')
            ->find($this->selectedCustomerId);
    }

    /**
     * Live wallet balance of the picked customer (kobo). Zero when no
     * customer is picked, or when they don't have a wallet yet.
     *
     * Reservation forms use this to conditionally offer a "Pay from
     * wallet" settlement option, no need to duplicate the balance
     * lookup in every host component.
     */
    #[Computed]
    public function pickedCustomerWalletBalance(): int
    {
        $c = $this->pickedCustomer();

        return $c !== null ? (int) $c->walletBalance() : 0;
    }

    public function pickCustomer(int $customerId): void
    {
        $customer = Customer::query()->find($customerId);
        if ($customer === null) {
            return;
        }

        $this->selectedCustomerId = $customer->id;
        $this->guest_name = (string) $customer->name;
        $this->guest_email = (string) $customer->email;
        $this->guest_phone = (string) $customer->phone;
        $this->customerSearch = '';
    }

    public function clearPickedCustomer(): void
    {
        $this->selectedCustomerId = null;
        $this->guest_name = '';
        $this->guest_email = '';
        $this->guest_phone = '';
        $this->customerSearch = '';
    }

    /**
     * Corporate members whose email OR phone contains the search term.
     * Rendered next to (or in) the customer suggestions dropdown so
     * the operator sees "Ada Traveller · Acme Corp · +234…" without
     * needing to remember that walk-in was on the corp roster. The
     * booking auto-links via CorporateBookingService::attachToBooking
     * once the phone or email lands on the reservation regardless of
     * whether the operator picks the suggestion here.
     *
     * @return SupportCollection<int, CorporateMember>
     */
    #[Computed]
    public function corporateMemberMatches(): SupportCollection
    {
        $term = trim($this->customerSearch);
        if (mb_strlen($term) < 2) {
            return new SupportCollection;
        }
        $like = '%'.$term.'%';
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        // Name / email hits are still SQL LIKE — safe because those
        // don't have the country-code / leading-zero mismatch that
        // phone numbers do. Phone matching filters in PHP after the
        // fact using the same last-9-digit tail the resolver uses
        // (Nigerian subscriber-number length, minus country code),
        // so "+2348011111111" collides with the operator's "08011111111"
        // typing regardless of format.
        $tail = $digits !== '' ? substr($digits, -9) : '';

        return CorporateMember::query()
            ->with('corporate:id,name,status')
            ->where('status', CorporateMemberStatus::Active->value)
            ->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            })
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->when($tail !== '' && strlen($tail) >= 4, function ($rows) use ($tail): SupportCollection {
                // Union in phone-tail matches when the search looks
                // like a number.
                $phoneMatches = CorporateMember::query()
                    ->with('corporate:id,name,status')
                    ->where('status', CorporateMemberStatus::Active->value)
                    ->whereNotNull('phone')
                    ->get()
                    ->filter(function (CorporateMember $m) use ($tail): bool {
                        $memberDigits = preg_replace('/\D+/', '', (string) $m->phone) ?? '';
                        if (strlen($memberDigits) < strlen($tail)) {
                            return false;
                        }
                        return substr($memberDigits, -strlen($tail)) === $tail;
                    });
                return $rows->concat($phoneMatches)->unique('id')->values();
            })
            // Filter down to members with a live parent Corporate —
            // an orphaned or paused corp's members shouldn't tempt the
            // operator with a suggestion that can't actually resolve.
            ->filter(fn (CorporateMember $m) => $m->corporate?->status?->isLive() === true)
            ->values()
            ->take(4);
    }

    /**
     * When the operator taps a corp-member suggestion, mirror the
     * pick-customer behaviour: fill guest_name / email / phone from
     * the member. We do NOT set selectedCustomerId — that field is
     * reserved for standalone Customer records; CorporateBookingService
     * writes corporate_id + corporate_member_id at booking-create time
     * from the email/phone lookup, so we don't need to plumb it here.
     */
    public function pickCorporateMember(int $memberId): void
    {
        $m = CorporateMember::query()->find($memberId);
        if ($m === null) {
            return;
        }
        $this->guest_name = (string) $m->name;
        $this->guest_email = (string) $m->email;
        $this->guest_phone = (string) $m->phone;
        $this->customerSearch = '';
    }
}
