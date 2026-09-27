<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Corporate;

use App\Models\Booking;
use App\Models\DeskBooking;
use App\Models\Lease;
use App\Models\Membership;
use App\Multitenancy\TenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Unified list of every booking-shaped thing the corporate paid for -
 * short stays, desk sessions, memberships, leases. We do NOT use
 * Livewire pagination here because the rows come from four queries
 * merged in PHP; the volume is small enough (one corporate's history)
 * to render flat and lean on user-side scrolling.
 *
 * Members (non-admin) see only their own rows. Admins see everything.
 */
#[Layout('layouts.corporate-portal')]
class Bookings extends Component
{
    #[Url(as: 'type')]
    public string $typeFilter = '';

    public function render(): View
    {
        $member = auth('corporate')->user();
        $corporate = $member->corporate;
        $restrictToSelf = $member->role->value !== 'admin';

        $applyScope = function ($q) use ($corporate, $restrictToSelf, $member): void {
            $q->withoutGlobalScope(TenantScope::class)
                ->where('corporate_id', $corporate->id);
            if ($restrictToSelf) {
                $q->where('corporate_member_id', $member->id);
            }
        };

        $rows = new Collection;

        if ($this->typeFilter === '' || $this->typeFilter === 'stay') {
            $bookings = Booking::query()->tap($applyScope)
                ->with(['room.property', 'corporateMember'])
                ->latest('id')->limit(100)->get();
            foreach ($bookings as $b) {
                $rows->push((object) [
                    'kind'     => 'Stay',
                    'ref'      => $b->booking_ref,
                    'when'     => $b->checkin_date,
                    'subject'  => $b->room?->property?->name ?? '-',
                    'detail'   => $b->room?->name ?? '-',
                    'member'   => $b->corporateMember?->name,
                    'amount'   => (int) $b->total_amount,
                    'status'   => $b->booking_status->value,
                    'created'  => $b->created_at,
                ]);
            }
        }

        if ($this->typeFilter === '' || $this->typeFilter === 'desk') {
            $desks = DeskBooking::query()->tap($applyScope)
                ->with(['workspace.property', 'corporateMember'])
                ->latest('id')->limit(100)->get();
            foreach ($desks as $d) {
                $rows->push((object) [
                    'kind'    => 'Desk',
                    'ref'     => $d->reference,
                    'when'    => $d->start_at,
                    'subject' => $d->workspace?->property?->name ?? '-',
                    'detail'  => $d->workspace?->name ?? '-',
                    'member'  => $d->corporateMember?->name,
                    'amount'  => (int) $d->amount_naira,
                    'status'  => $d->status->value,
                    'created' => $d->created_at,
                ]);
            }
        }

        if ($this->typeFilter === '' || $this->typeFilter === 'membership') {
            $mems = Membership::query()->tap($applyScope)
                ->with(['workspace.property', 'plan', 'corporateMember'])
                ->latest('id')->limit(100)->get();
            foreach ($mems as $m) {
                $rows->push((object) [
                    'kind'    => 'Membership',
                    'ref'     => 'M-'.$m->id,
                    'when'    => $m->started_at,
                    'subject' => $m->workspace?->property?->name ?? '-',
                    'detail'  => $m->plan?->name ?? '-',
                    'member'  => $m->corporateMember?->name,
                    'amount'  => (int) $m->amount_naira,
                    'status'  => $m->status->value,
                    'created' => $m->created_at,
                ]);
            }
        }

        if ($this->typeFilter === '' || $this->typeFilter === 'lease') {
            $leases = Lease::query()->tap($applyScope)
                ->with(['unit.property', 'corporateMember'])
                ->latest('id')->limit(100)->get();
            foreach ($leases as $l) {
                $rows->push((object) [
                    'kind'    => 'Lease',
                    'ref'     => $l->reference,
                    'when'    => $l->started_at,
                    'subject' => $l->unit?->property?->name ?? '-',
                    'detail'  => $l->unit?->name ?? '-',
                    'member'  => $l->corporateMember?->name,
                    'amount'  => (int) $l->annual_rent_naira,
                    'status'  => $l->status->value,
                    'created' => $l->created_at,
                ]);
            }
        }

        // Newest first across the union.
        $sorted = $rows->sortByDesc(fn ($r) => $r->created)->values();

        return view('livewire.portal.corporate.bookings', [
            'rows'           => $sorted,
            'isAdmin'        => ! $restrictToSelf,
        ]);
    }
}
