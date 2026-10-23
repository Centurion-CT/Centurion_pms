<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Livewire\Dashboard\NotificationBell;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Services\Alerts\OperatorAlerts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The operator's merged notification feed: the web sidebar bell
 * ({@see NotificationBell}) and the host app
 * Alerts list both read it.
 *
 * Two sources:
 *  - Activity since operator.last_notifications_seen_at (default: last
 *    24h), branch-scoped: new guest requests, pending geo-fence
 *    approvals, booking extended / checked in / checked out events,
 *    plus store-request + low-stock alerts ({@see OperatorAlerts}).
 *    All of it counts as unread until "mark all read" bumps
 *    last_notifications_seen_at.
 *  - Laravel database notifications (booking confirmed / cancelled),
 *    unread while read_at is null, markable one at a time.
 */
class NotificationFeed
{
    public const ACTIVITY_LIMIT = 20;

    public function __construct(private readonly OperatorAlerts $alerts) {}

    /**
     * @return Builder<DatabaseNotification>
     */
    public function databaseNotifications(Operator $operator): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $operator->getMorphClass())
            ->where('notifiable_id', $operator->id);
    }

    /**
     * Activity items new since the operator last cleared the bell,
     * newest first, capped at 20. Each item carries a stable `ref`.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function activityItems(Operator $operator, ?int $propertyId): Collection
    {
        $since = $operator->last_notifications_seen_at ?? Carbon::now()->subDay();

        $requests = GuestRequest::query()
            ->with(['booking:id,booking_ref,guest_name', 'room:id,room_number'])
            ->where('created_at', '>', $since)
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Booking events since $since, straight from the activity log.
        $bookingEvents = DB::table('activity_log')
            ->where('subject_type', Booking::class)
            ->whereIn('event', ['extended', 'checked_in', 'checked_out'])
            ->where('created_at', '>', $since)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

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
                'ref' => 'request:'.$r->id,
                'kind' => 'guest_request',
                'kind_label' => $r->kind->label(),
                'title' => $r->booking?->guest_name ?? 'Guest',
                'subtitle' => 'Rm '.($r->room?->room_number ?? '?').' · '.$r->kind->label(),
                'booking_ref' => $r->booking?->booking_ref,
                'at' => $r->created_at,
                'url' => route('tenant.guest-requests.index'),
            ]);
        }

        // Pending geo-fence approvals: only pending rows chime.
        $approvals = GuestActionApproval::query()
            ->with(['booking:id,guest_name,booking_ref', 'room:id,room_number,property_id'])
            ->where('status', 'pending')
            ->where('created_at', '>', $since)
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('room', fn ($r) => $r->where('property_id', $pid)))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        foreach ($approvals as $a) {
            $items->push([
                'ref' => 'approval:'.$a->id,
                'kind' => 'approval',
                'kind_label' => 'Approval - '.$a->kind,
                'title' => $a->booking?->guest_name ?? 'Guest',
                'subtitle' => 'Rm '.($a->room?->room_number ?? '?').' · score '.$a->coord_score,
                'booking_ref' => $a->booking?->booking_ref,
                'at' => $a->created_at,
                'url' => route('tenant.approvals.index'),
            ]);
        }

        foreach ($bookingEvents as $ev) {
            $b = $bookingLookup->get((int) $ev->subject_id);
            if ($b === null) {
                continue; // wrong branch, or missing
            }
            $label = match ($ev->event) {
                'extended' => 'Extended stay',
                'checked_in' => 'Checked in',
                'checked_out' => 'Checked out',
                default => 'Booking updated',
            };
            $items->push([
                'ref' => 'activity:'.$ev->id,
                'kind' => 'booking',
                'event' => $ev->event,
                'kind_label' => $label,
                'title' => $b->guest_name,
                'subtitle' => 'Rm '.($b->room?->room_number ?? '?').' · '.$b->booking_ref,
                'booking_ref' => $b->booking_ref,
                'at' => Carbon::parse($ev->created_at),
                'url' => route('tenant.reservations.show', $b->booking_ref),
            ]);
        }

        // Store-request + low-stock alerts, routed by role.
        $items = $items->concat($this->alerts->items($operator)->map(function (array $i): array {
            $i['ref'] ??= 'alert:'.substr(sha1(($i['kind'] ?? '').'|'.($i['title'] ?? '').'|'.($i['subtitle'] ?? '').'|'.($i['at']?->getTimestamp() ?? 0)), 0, 16);

            return $i;
        }));

        return $items->sortByDesc('at')->take(self::ACTIVITY_LIMIT)->values();
    }

    /**
     * Database notifications, newest first, read or not.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function databaseItems(Operator $operator, int $limit = 10, int $offset = 0): Collection
    {
        return $this->databaseNotifications($operator)
            ->latest('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(function (DatabaseNotification $n): array {
                $data = $n->data ?? [];

                return [
                    'kind' => 'notification',
                    'source' => 'database',
                    'id' => (string) $n->id,
                    'event' => $data['event'] ?? null,
                    'title' => $data['title'] ?? 'Notification',
                    'message' => (string) ($data['message'] ?? ''),
                    'booking_ref' => $data['booking_ref'] ?? null,
                    'unread' => $n->read_at === null,
                    'at' => $n->created_at,
                ];
            });
    }

    public function databaseUnread(Operator $operator): int
    {
        return $this->databaseNotifications($operator)->whereNull('read_at')->count();
    }

    /**
     * The merged bell feed: activity (all unread) + the latest database
     * notifications, sorted by time, with the badge count.
     *
     * @return array{items: Collection<int, array<string, mixed>>, count: int}
     */
    public function build(Operator $operator, ?int $propertyId, int $databaseLimit = 10): array
    {
        $activity = $this->activityItems($operator, $propertyId);
        $activityCount = $activity->count();

        $dbUnread = 0;
        $dbItems = collect();
        try {
            $dbUnread = $this->databaseUnread($operator);
            $dbItems = $this->databaseItems($operator, $databaseLimit);
        } catch (Throwable $e) {
            report($e);
        }

        $items = $activity
            ->map(fn (array $i) => $i + ['source' => 'activity', 'unread' => true])
            ->concat($dbItems)
            ->sortByDesc(fn (array $i) => $i['at']?->getTimestamp() ?? 0)
            ->values();

        return ['items' => $items, 'count' => $activityCount + $dbUnread];
    }

    /** Badge count only (activity since last seen + unread database). */
    public function unreadCount(Operator $operator, ?int $propertyId): int
    {
        $dbUnread = 0;
        try {
            $dbUnread = $this->databaseUnread($operator);
        } catch (Throwable $e) {
            report($e);
        }

        return $this->activityItems($operator, $propertyId)->count() + $dbUnread;
    }

    /**
     * "Mark all read": bump last_notifications_seen_at (clears the
     * activity items) and mark every database notification read.
     */
    public function markAllSeen(Operator $operator): void
    {
        $operator->forceFill([
            'last_notifications_seen_at' => Carbon::now(),
        ])->save();
        $this->databaseNotifications($operator)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);
    }

    /**
     * Flip database notifications to read. Scoped to the operator so
     * ids belonging to someone else are a silent no-op.
     *
     * @param  list<string>  $ids
     */
    public function markRead(Operator $operator, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->databaseNotifications($operator)
            ->whereKey($ids)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);
    }
}
