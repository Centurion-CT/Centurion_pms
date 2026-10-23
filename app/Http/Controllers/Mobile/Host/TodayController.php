<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Host;

use App\Enums\GuestRequestKind;
use App\Models\Booking;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Property;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Reports\FinanceSnapshot;
use App\Services\Reports\ReportSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * GET host/today: the host app's Today tab (screens 05 + 06).
 *
 * No new maths: every number is read from the services behind the
 * web dashboard, scoped to the branch `host.role` bound for this
 * request (PropertyContext request binding):
 *   - arrivals / departures / in-house / owed: {@see DashboardSnapshot}
 *     (same snapshot + 60s cache as web Today; `?fresh=1` bypasses it
 *     for pull-to-refresh)
 *   - collected today / revenue series: {@see FinanceSnapshot::dailySeries()}
 *   - occupancy tonight / ADR: {@see ReportSnapshot::occupancy()}
 *   - requests: the same open / urgent definition as the web Guest
 *     requests header cards
 *   - approvals: pending GuestActionApproval rows for the branch, as
 *     /dashboard/approvals + the notification bell count them.
 */
class TodayController extends HostController
{
    private const LIST_LIMIT = 8;

    /** Kinds the web Guest requests page counts as urgent. */
    private const URGENT_KINDS = [GuestRequestKind::Complaint, GuestRequestKind::Transfer];

    public function __invoke(Request $request, DashboardSnapshot $snapshot): JsonResponse
    {
        $operator = $this->operator($request);
        $property = $this->property($request);
        $today = Carbon::today();
        $todayStr = $today->toDateString();

        $data = $snapshot->build(bypass: $request->boolean('fresh'));
        $pulse = $data['pulse'];

        /** @var Collection<int, Booking> $assigned */
        $assigned = $data['today']['arrivals'];
        /** @var Collection<int, Booking> $unassigned */
        $unassigned = $data['today']['unassignedArrivals'];
        /** @var Collection<int, Booking> $inHouse */
        $inHouse = $data['today']['inHouse'];

        // Leaving = booked to leave today + still in past their
        // checkout (overdue). Overdue first: they're the urgent ones.
        $leaving = $inHouse
            ->filter(fn (Booking $b) => $b->checkout_date !== null && $b->checkout_date->toDateString() <= $todayStr)
            ->sortBy(fn (Booking $b) => [$b->checkout_date->toDateString(), (string) $b->guest_name])
            ->values();
        $overdueCount = $leaving->filter(fn (Booking $b) => $b->checkout_date->toDateString() < $todayStr)->count();

        $requests = GuestRequest::query()
            ->where('status', 'open')
            ->whereHas('room', fn ($r) => $r->where('property_id', $property->id));

        $finance = app(FinanceSnapshot::class);
        $collectedToday = (int) ($finance->dailySeries($today, $today)[0]['collected'] ?? 0);

        return response()->json([
            'data' => [
                'date' => $todayStr,
                'greeting_name' => $this->firstName($operator),
                'counts' => [
                    'arriving' => (int) $pulse['arriving'],
                    'arriving_unassigned' => (int) $pulse['unassigned_today'],
                    'leaving' => $leaving->count(),
                    'leaving_overdue' => $overdueCount,
                    'in_house' => (int) $pulse['in_house'],
                    'rooms_total' => $data['heatmap']['rooms']->count(),
                    'requests_open' => (clone $requests)->count(),
                    'requests_urgent' => (clone $requests)
                        ->whereIn('kind', array_map(fn ($k) => $k->value, self::URGENT_KINDS))
                        ->count(),
                ],
                'money' => [
                    'collected_today' => $collectedToday,
                    'owed_total' => (int) $pulse['outstanding'],
                    'owed_stays' => (int) ($pulse['outstanding_stays'] ?? 0),
                ],
                'arrivals' => $this->arrivals($assigned, $unassigned),
                'departures' => $leaving->take(self::LIST_LIMIT)->map(fn (Booking $b) => [
                    'ref' => (string) $b->booking_ref,
                    'guest_name' => (string) $b->guest_name,
                    'room_number' => $b->room?->room_number,
                    'balance_due' => \App\Services\Payments\BookingPayments::owed($b),
                    'overdue' => $b->checkout_date->toDateString() < $todayStr,
                ])->values(),
                'owner' => self::isOwner($operator) ? $this->ownerBlock($property, $finance, $today, $operator) : null,
            ],
        ]);
    }

    /**
     * @param  Collection<int, Booking>  $assigned
     * @param  Collection<int, Booking>  $unassigned
     * @return list<array<string, mixed>>
     */
    private function arrivals(Collection $assigned, Collection $unassigned): array
    {
        // No arrival-time column exists yet (eta is always null), so
        // "by time" falls back to guest name for a stable order.
        $rows = $assigned->concat($unassigned)
            ->sortBy(fn (Booking $b) => (string) $b->guest_name)
            ->take(self::LIST_LIMIT)
            ->values();

        $rows->loadMissing(['room.category:id,name', 'roomCategory:id,name']);

        return $rows->map(function (Booking $b): array {
            $balance = \App\Services\Payments\BookingPayments::owed($b);

            return [
                'ref' => (string) $b->booking_ref,
                'guest_name' => (string) $b->guest_name,
                'room_number' => $b->room?->room_number,
                'category' => $b->room?->category?->name ?? $b->roomCategory?->name,
                'eta' => null,
                'balance_due' => $balance,
                'status' => $b->booking_status->value,
                'status_label' => $b->booking_status->label(),
                'chip' => $this->chip($b, $balance),
            ];
        })->all();
    }

    /**
     * Payment chip for an arrival:
     *   info "Company pays"  - corporate bill-to stay
     *   late "₦X overdue"    - balance still owed past its deadline
     *   due  "₦X due"        - balance still owed
     *   ok   "Paid"          - nothing owed
     *
     * @return array{label: string, tone: string}
     */
    private function chip(Booking $b, int $balance): array
    {
        if ($b->corporate_id !== null) {
            return ['label' => 'Company pays', 'tone' => 'info'];
        }
        if ($balance > 0 && $b->balance_due_at !== null && $b->balance_due_at->isPast()) {
            return ['label' => '₦'.number_format($balance).' overdue', 'tone' => 'late'];
        }
        if ($balance > 0) {
            return ['label' => '₦'.number_format($balance).' due', 'tone' => 'due'];
        }

        return ['label' => 'Paid', 'tone' => 'ok'];
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerBlock(Property $property, FinanceSnapshot $finance, Carbon $today, ?Operator $operator = null): array
    {
        // Cash basis (succeeded minus refunded by paid_at), the same
        // definition the web Summary "Total revenue" and dashboard
        // revenue charts use, scoped to this branch. Shared with the
        // owner home ({@see OwnerReports}) so both always agree.
        $owner = app(\App\Services\Owner\OwnerReports::class);
        $week = $owner->revenueWeek([(int) $property->id], $today);
        $weekFrom = $today->copy()->subDays(6);

        // Tonight: rooms booked for tonight or still occupied (incl.
        // overdue guests) / active rooms; same numbers as host/properties.
        $tonight = $this->tonightByProperty(collect([$property]));
        $roomsTotal = (int) ($tonight['rooms'][$property->id] ?? 0);
        $occupied = min($roomsTotal, (int) ($tonight['occupied'][$property->id] ?? 0));

        // ADR over the same 7 days: room revenue / room-nights on
        // stays checking in in the window (web Reports definition).
        $rates = app(ReportSnapshot::class)->occupancy($weekFrom->copy(), $today->copy());

        return [
            'revenue_week' => $week['revenue_week'],
            'revenue_week_change_pct' => $week['revenue_week_change_pct'],
            'series_7d' => $week['series_7d'],
            'occupancy_tonight_pct' => $roomsTotal > 0 ? (int) round($occupied / $roomsTotal * 100) : 0,
            'adr_week' => (int) $rates['adr'],
            'approvals_waiting' => $owner->approvalsWaiting([(int) $property->id], $operator),
        ];
    }

    private function firstName(Operator $operator): string
    {
        $parts = preg_split('/\s+/', trim((string) $operator->name)) ?: [];

        return (string) ($parts[0] ?? '');
    }
}
