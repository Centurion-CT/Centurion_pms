<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\OperatorRole;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Services\Alerts\OperatorAlerts;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Notification bell shown in the tenant-auth sidebar. Polls every 30s
 * for events the front desk cares about - new guest requests, stay
 * extensions, check-ins, check-outs - and shows a red-dot count.
 *
 * Client-side chime: the wrapper element listens for a "count went
 * from 0 → N" transition and plays a subtle beep so the desk hears
 * the alert without needing to look.
 *
 * Scope:
 *   - Only rendered for operators whose role can see reservations
 *     (Manager, FrontDesk) - no bell for the housekeeping-only staff.
 *   - Scoped to the operator's current branch via PropertyContext so
 *     a Lagos-branch operator doesn't hear Abuja's notifications.
 */
class NotificationBell extends Component
{
    public bool $panelOpen = false;

    /**
     * Mark everything currently visible as seen. Bumps
     * operator.last_notifications_seen_at to now, which zeroes the
     * next-render count.
     */
    public function markAllSeen(): void
    {
        $operator = auth('web')->user();
        if (! $operator instanceof Operator) return;
        $operator->forceFill([
            'last_notifications_seen_at' => Carbon::now(),
        ])->save();
        $this->panelOpen = false;
    }

    public function togglePanel(): void
    {
        $this->panelOpen = ! $this->panelOpen;
    }

    public function render(OperatorAlerts $alerts): View
    {
        $operator = auth('web')->user();
        if (! $operator instanceof Operator) {
            return view('livewire.dashboard.notification-bell', [
                'items' => collect(), 'count' => 0,
            ]);
        }

        $since = $operator->last_notifications_seen_at ?? Carbon::now()->subDay();
        $propertyId = app(PropertyContext::class)->current()?->getKey();

        // Two queries; the bell aggregates both into a single list.
        // Scoped to the current branch so multi-branch orgs don't
        // hear cross-branch chimes.
        $requests = GuestRequest::query()
            ->with(['booking:id,booking_ref,guest_name', 'room:id,room_number'])
            ->where('created_at', '>', $since)
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Booking events since $since - activity_log rows on Booking
        // subject with events we care about at the desk. We use the
        // activity table directly so we don't have to add an events
        // table just for the bell.
        $bookingEvents = \DB::table('activity_log')
            ->where('subject_type', Booking::class)
            ->whereIn('event', ['extended', 'checked_in', 'checked_out'])
            ->where('created_at', '>', $since)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Enrich the bookings referenced by the events with the
        // guest name + booking ref so the panel can render them
        // without an N+1 lookup.
        $bookingIds = $bookingEvents->pluck('subject_id')->filter()->unique();
        $bookingLookup = $bookingIds->isNotEmpty()
            ? Booking::query()
                ->with('room:id,room_number,property_id')
                ->whereIn('id', $bookingIds)
                ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
                ->get()->keyBy('id')
            : collect();

        $items = collect();

        foreach ($requests as $r) {
            $items->push([
                'kind' => 'guest_request',
                'kind_label' => $r->kind->label(),
                'title' => $r->booking?->guest_name ?? 'Guest',
                'subtitle' => 'Rm '.($r->room?->room_number ?? '?').' · '.$r->kind->label(),
                'at' => $r->created_at,
                'url' => route('tenant.guest-requests.index'),
            ]);
        }

        // Pending geo-fence approvals - the desk needs eyes on these
        // right away since the guest is waiting on the other side of
        // their tap. Only pending rows chime; approved/rejected fall
        // off the bell naturally.
        $approvals = GuestActionApproval::query()
            ->with(['booking:id,guest_name', 'room:id,room_number,property_id'])
            ->where('status', 'pending')
            ->where('created_at', '>', $since)
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        foreach ($approvals as $a) {
            $items->push([
                'kind' => 'approval',
                'kind_label' => 'Approval - '.$a->kind,
                'title' => $a->booking?->guest_name ?? 'Guest',
                'subtitle' => 'Rm '.($a->room?->room_number ?? '?').' · score '.$a->coord_score,
                'at' => $a->created_at,
                'url' => route('tenant.approvals.index'),
            ]);
        }

        foreach ($bookingEvents as $ev) {
            $b = $bookingLookup->get((int) $ev->subject_id);
            if ($b === null) continue; // wrong branch, or missing
            $label = match ($ev->event) {
                'extended'    => 'Extended stay',
                'checked_in'  => 'Checked in',
                'checked_out' => 'Checked out',
                default       => 'Booking updated',
            };
            $items->push([
                'kind' => 'booking',
                'kind_label' => $label,
                'title' => $b->guest_name,
                'subtitle' => 'Rm '.($b->room?->room_number ?? '?').' · '.$b->booking_ref,
                'at' => Carbon::parse($ev->created_at),
                'url' => route('tenant.reservations.show', $b->booking_ref),
            ]);
        }

        // Route store-request + low-stock alerts to whoever should
        // attend to them. Delegated to a service so the dashboard
        // alerts card can reuse the same "who sees what" logic.
        $items = $items->concat($alerts->items($operator));

        $items = $items->sortByDesc('at')->take(20)->values();

        return view('livewire.dashboard.notification-bell', [
            'items' => $items,
            'count' => $items->count(),
        ]);
    }
}
