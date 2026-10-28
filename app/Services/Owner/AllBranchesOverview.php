<?php

declare(strict_types=1);

namespace App\Services\Owner;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Review;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Reports\FinanceSnapshot;
use App\Support\Dashboard\TonightHouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The All branches page (docs: design/all-branches): money, tonight's
 * rooms, a row per branch, what needs attention and live activity for
 * every branch of the hotel at once.
 *
 * No money or occupancy maths lives here. Figures come from:
 *  - {@see FinanceSnapshot}   collected / refunded / billed / expenses /
 *                             payment methods / booking channels / owed
 *                             (web Finance), pinned with forProperties()
 *  - {@see DashboardSnapshot} each branch's arrivals, departures,
 *                             in-house and room states (web Today)
 *  - {@see TonightHouse}      tonight's occupancy, the same bars and
 *                             percentage as each branch's dashboard
 * Sums of the per-branch rows equal the all-branches totals because
 * every figure uses the same branch filter.
 */
class AllBranchesOverview
{
    public const PERIODS = ['today', 'week', 'month', 'last_month', 'custom'];

    /** Longest custom window: daily bars stay readable up to ~3 months. */
    public const MAX_CUSTOM_DAYS = 92;

    public function __construct(
        private readonly FinanceSnapshot $finance,
        private readonly DashboardSnapshot $dashboard,
    ) {}

    /**
     * The reporting window and the same-length window just before it.
     *
     * @return array{from: Carbon, to: Carbon, prev_from: Carbon, prev_to: Carbon, label: string}
     */
    public static function window(string $period, ?string $from = null, ?string $to = null, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        [$start, $end, $label] = match ($period) {
            'today' => [$today->copy(), $today->copy(), 'today'],
            'week' => [$today->copy()->startOfWeek(Carbon::MONDAY), $today->copy(), 'this week'],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay(),
                'in '.$today->copy()->subMonthNoOverflow()->format('F'),
            ],
            'custom' => self::customRange($from, $to, $today),
            default => [$today->copy()->startOfMonth(), $today->copy(), 'this month'],
        };

        // Compare with the same stretch before: yesterday, last week to
        // the same weekday, last month to the same date (OwnerReports'
        // rule); otherwise the same number of days just before.
        if (in_array($period, ['today', 'week', 'month'], true)) {
            [, , $prevFrom, $prevTo] = OwnerReports::periodWindow($period, $today);
        } elseif ($period === 'last_month') {
            $prevFrom = $start->copy()->subMonthNoOverflow()->startOfMonth();
            $prevTo = $prevFrom->copy()->endOfMonth()->startOfDay();
        } else {
            $days = (int) $start->diffInDays($end) + 1;
            $prevFrom = $start->copy()->subDays($days);
            $prevTo = $start->copy()->subDay();
        }

        return [
            'from' => $start,
            'to' => $end,
            'prev_from' => $prevFrom,
            'prev_to' => $prevTo,
            'label' => $label,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private static function customRange(?string $from, ?string $to, Carbon $today): array
    {
        try {
            $start = Carbon::parse((string) $from)->startOfDay();
            $end = Carbon::parse((string) $to)->startOfDay();
        } catch (Throwable) {
            return [$today->copy()->startOfMonth(), $today->copy(), 'this month'];
        }
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) + 1 > self::MAX_CUSTOM_DAYS) {
            $start = $end->copy()->subDays(self::MAX_CUSTOM_DAYS - 1);
        }

        return [$start, $end, self::rangeLabel($start, $end)];
    }

    /** "1–28 Oct", "28 Sep – 4 Oct", "28 Oct". */
    public static function rangeLabel(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('j M');
        }
        if ($from->isSameMonth($to)) {
            return $from->format('j').'–'.$to->format('j M');
        }

        return $from->format('j M').' – '.$to->format('j M');
    }

    /**
     * @param  Collection<int, Property>  $properties  every branch, oldest first
     * @return array<string, mixed>
     */
    public function build(Collection $properties, string $period, ?string $from = null, ?string $to = null): array
    {
        $win = self::window($period, $from, $to);
        $ids = $properties->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $finance = $this->finance->forProperties($ids);
        $today = Carbon::today();

        $kpis = $finance->kpis($win['from'], $win['to']);
        $expenses = $finance->expenseTotal($win['from'], $win['to']);
        $collected = (int) $kpis['collected'];
        $previous = (int) array_sum(array_column($finance->dailySeries($win['prev_from'], $win['prev_to']), 'collected'));
        $refunded = (int) $kpis['refunded'];

        $branches = [];
        $owedRows = collect();
        $attention = ['overdue' => [], 'approvals' => [], 'requests' => [], 'unsold' => [], 'reviews' => []];

        foreach ($properties as $p) {
            $id = (int) $p->id;
            $snap = $this->dashboard->buildFor($id);
            $house = TonightHouse::from($snap);
            $branchFinance = $this->finance->forProperties([$id]);
            $series = $branchFinance->dailySeries($win['from'], $win['to']);
            $owed = $branchFinance->owedBreakdown(5);

            $overdue = collect($snap['today']['inHouse'] ?? [])
                ->filter(fn (Booking $b) => $b->checkout_date !== null && $b->checkout_date->lt($today))
                ->sortBy('checkout_date')
                ->values();
            $approvals = (int) GuestActionApproval::query()
                ->where('status', 'pending')
                ->whereHas('room', fn ($r) => $r->where('property_id', $id))
                ->count();
            $requests = GuestRequest::query()
                ->with('room:id,room_number')
                ->where('status', 'open')
                ->whereHas('room', fn ($r) => $r->where('property_id', $id))
                ->orderBy('created_at')
                ->get(['id', 'room_id', 'kind', 'created_at']);
            $reviews = (int) Review::query()
                ->where('property_id', $id)
                ->whereNull('responded_at')
                ->where('created_at', '>=', $today->copy()->subDays(6)->startOfDay())
                ->count();
            $unsold = (int) ($house['counts']['free'] + $house['counts']['dirty']);

            $name = (string) $p->name;
            if ($overdue->isNotEmpty()) {
                $attention['overdue'][] = ['branch' => $name, 'count' => $overdue->count(), 'first' => $overdue->first()];
            }
            if ($approvals > 0) {
                $attention['approvals'][] = ['branch' => $name, 'count' => $approvals];
            }
            if ($requests->isNotEmpty()) {
                $attention['requests'][] = ['branch' => $name, 'count' => $requests->count(), 'first' => $requests->first()];
            }
            if ($unsold > 0 && $house['total'] > 0) {
                $attention['unsold'][] = ['branch' => $name, 'count' => $unsold, 'pct' => $house['pct']];
            }
            if ($reviews > 0) {
                $attention['reviews'][] = ['branch' => $name, 'count' => $reviews];
            }

            foreach ($owed['rows'] as $row) {
                $owedRows->push($row + ['branch' => $name]);
            }

            $pulse = $snap['pulse'] ?? [];
            $branches[] = [
                'property' => $p,
                'name' => $name,
                'sub' => trim(implode(' · ', array_filter([$p->city, $house['total'].' '.($house['total'] === 1 ? 'room' : 'rooms')]))),
                'house' => $house,
                'arriving' => (int) ($pulse['arriving'] ?? 0),
                'leaving' => (int) ($pulse['departing'] ?? 0),
                'in_house' => (int) ($pulse['in_house'] ?? 0),
                'collected' => (int) array_sum(array_column($series, 'collected')),
                'owed' => (int) $owed['total'],
                'owed_stays' => (int) $owed['stays'],
                'owed_companies' => (int) $owed['companies'],
                'attention' => $overdue->count() + $approvals + $requests->count() + $reviews,
            ];
        }

        $roomsTotal = (int) array_sum(array_map(fn ($b) => $b['house']['total'], $branches));
        $roomsTaken = (int) array_sum(array_map(fn ($b) => $b['house']['occupied'], $branches));
        $owedTotal = (int) array_sum(array_column($branches, 'owed'));
        $owedStays = (int) array_sum(array_column($branches, 'owed_stays'));
        $owedCompanies = (int) array_sum(array_column($branches, 'owed_companies'));
        $owedCount = $owedStays + $owedCompanies;
        $owedTop = $owedRows->sortByDesc('amount')->values();

        $daily = [];
        foreach ($finance->dailySeries($win['from'], $win['to']) as $row) {
            $daily[] = ['date' => $row['date'], 'amount' => (int) $row['collected']];
        }

        $methods = collect($finance->collectedByMethod($win['from'], $win['to']))
            ->filter(fn ($m) => (int) $m['amount'] > 0)
            ->sortByDesc('amount')->values();
        $methodsTotal = max(1, (int) $methods->sum('amount'));
        $channels = collect($finance->channelPerformance($win['from'], $win['to']))
            ->filter(fn ($c) => (int) $c['revenue'] > 0)
            ->sortByDesc('revenue')->values();

        return [
            'window' => $win,
            'branches' => $branches,
            'money' => [
                'collected' => $collected,
                'collected_previous' => $previous,
                'collected_change_pct' => $previous > 0 ? round(($collected - $previous) / $previous * 100, 1) : null,
                'previous_label' => self::rangeLabel($win['prev_from'], $win['prev_to']),
                'billed' => (int) $kpis['billed'],
                'owed' => $owedTotal,
                'owed_stays' => $owedStays,
                'owed_companies' => $owedCompanies,
                'refunded' => $refunded,
                'refunds_count' => (int) $kpis['refundsCount'],
                'expenses' => $expenses,
                'net' => $collected - $refunded - $expenses,
            ],
            'tonight' => [
                'total' => $roomsTotal,
                'occupied' => $roomsTaken,
                'pct' => $roomsTotal > 0 ? (int) round($roomsTaken / $roomsTotal * 100) : 0,
                // In-house + arriving = occupied (TonightHouse's rule);
                // everything else, leaving-today rooms included, is free.
                'in_house' => (int) array_sum(array_map(fn ($b) => $b['house']['counts']['inhouse'] + $b['house']['counts']['overdue'], $branches)),
                'arriving' => (int) array_sum(array_map(fn ($b) => $b['house']['counts']['arriving'] + $b['house']['counts']['booked'], $branches)),
                'free' => $roomsTotal - $roomsTaken,
            ],
            'attention' => $this->attentionItems($attention),
            'daily' => $daily,
            'methods' => $methods->map(fn ($m) => $m + ['pct' => (int) round((int) $m['amount'] / $methodsTotal * 100)])->all(),
            'methods_total' => (int) $methods->sum('amount'),
            'channels' => $channels->all(),
            'channels_total' => (int) $channels->sum('revenue'),
            'owed_top' => $owedTop->take(5)->values()->all(),
            'owed_rest_count' => max(0, $owedCount - min(5, $owedTop->count())),
            'owed_rest_amount' => max(0, $owedTotal - (int) $owedTop->take(5)->sum('amount')),
        ];
    }

    /**
     * One row per kind of problem, each tagged with the branches it is
     * in and how many, most urgent first.
     *
     * @param  array<string, list<array<string, mixed>>>  $a
     * @return list<array{kind: string, title: string, subtitle: string, tags: list<array{branch: string, count: int}>}>
     */
    private function attentionItems(array $a): array
    {
        $items = [];
        $tags = fn (array $rows) => array_map(fn ($r) => ['branch' => $r['branch'], 'count' => (int) $r['count']], $rows);
        $sum = fn (array $rows) => (int) array_sum(array_column($rows, 'count'));

        if ($a['overdue'] !== []) {
            $n = $sum($a['overdue']);
            /** @var Booking $b */
            $b = collect($a['overdue'])->sortBy(fn ($r) => $r['first']->checkout_date)->first()['first'];
            $room = $b->room?->room_number !== null ? ', Room '.$b->room->room_number : '';
            $items[] = [
                'kind' => 'overdue',
                'title' => self::plural($n, 'guest past check-out', 'guests past check-out'),
                'subtitle' => ($n === 1 ? '' : 'Longest: ').$b->guest_name.$room.', was due out '.$b->checkout_date->format('j M'),
                'tags' => $tags($a['overdue']),
            ];
        }
        if ($a['approvals'] !== []) {
            $items[] = [
                'kind' => 'approvals',
                'title' => self::plural($sum($a['approvals']), 'approval waiting', 'approvals waiting'),
                'subtitle' => 'Guest requests held for a manager\'s decision',
                'tags' => $tags($a['approvals']),
            ];
        }
        if ($a['requests'] !== []) {
            /** @var GuestRequest $r */
            $r = collect($a['requests'])->sortBy(fn ($row) => $row['first']->created_at)->first()['first'];
            $items[] = [
                'kind' => 'requests',
                'title' => self::plural($sum($a['requests']), 'open guest request', 'open guest requests'),
                'subtitle' => 'Oldest: '.mb_strtolower($r->kind->label()).', Room '.($r->room?->room_number ?? '?').', waiting '.$r->created_at->diffForHumans(null, true),
                'tags' => $tags($a['requests']),
            ];
        }
        if ($a['unsold'] !== []) {
            $items[] = [
                'kind' => 'unsold',
                'title' => self::plural($sum($a['unsold']), 'room unsold tonight', 'rooms unsold tonight'),
                'subtitle' => count($a['unsold']) === 1
                    ? $a['unsold'][0]['pct'].'% full. A same-day deal could fill '.($a['unsold'][0]['count'] === 1 ? 'it.' : 'them.')
                    : 'Across '.count($a['unsold']).' branches. A same-day deal could fill them.',
                'tags' => $tags($a['unsold']),
            ];
        }
        if ($a['reviews'] !== []) {
            $items[] = [
                'kind' => 'reviews',
                'title' => self::plural($sum($a['reviews']), 'new review to answer', 'new reviews to answer'),
                'subtitle' => 'From the last 7 days',
                'tags' => $tags($a['reviews']),
            ];
        }

        return $items;
    }

    /**
     * Latest money and front desk events across the branches, newest
     * first: payments and refunds, new bookings, check-ins, check-outs,
     * extensions and guest requests.
     *
     * @param  Collection<int, Property>  $properties
     * @return list<array{at: Carbon, title: string, subtitle: string, branch: string, amount: ?int, booking_ref: ?string}>
     */
    public function activity(Collection $properties, int $limit = 8): array
    {
        $ids = $properties->pluck('id')->map(fn ($id) => (int) $id)->all();
        $names = $properties->mapWithKeys(fn (Property $p) => [(int) $p->id => (string) $p->name])->all();
        $branchOf = fn (?int $pid) => $pid !== null ? ($names[$pid] ?? null) : null;
        $items = collect();

        $payments = Payment::query()
            ->with(['booking:id,booking_ref,guest_name,room_id', 'booking.room:id,room_number,property_id',
                'posSale:id,outlet_id', 'posSale.outlet:id,property_id', 'collectedBy:id,name'])
            ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::Refunded->value])
            ->whereNotNull('paid_at')
            ->where(function ($q) use ($ids): void {
                $q->whereHas('booking.room', fn ($r) => $r->whereIn('property_id', $ids))
                    ->orWhereHas('posSale.outlet', fn ($o) => $o->whereIn('property_id', $ids));
            })
            ->latest('paid_at')
            ->limit($limit)
            ->get();
        foreach ($payments as $pay) {
            $branch = $branchOf($pay->booking?->room?->property_id ?? $pay->posSale?->outlet?->property_id);
            if ($branch === null) {
                continue;
            }
            $refund = $pay->status === PaymentStatus::Refunded;
            $who = $pay->collectedBy?->name ? 'taken by '.explode(' ', trim($pay->collectedBy->name))[0] : null;
            $items->push([
                'at' => $pay->paid_at,
                'title' => $refund ? 'Refund' : ($pay->pos_sale_id !== null && $pay->booking_id === null
                    ? 'Bar and restaurant sale'
                    : 'Payment by '.mb_strtolower(PaymentMethod::labelFor($pay->method))),
                'subtitle' => implode(' · ', array_filter([
                    $pay->booking?->guest_name,
                    $pay->booking?->room?->room_number !== null ? 'Room '.$pay->booking->room->room_number : null,
                    $who,
                ])),
                'branch' => $branch,
                'amount' => $refund ? -(int) $pay->amount : (int) $pay->amount,
                'booking_ref' => $pay->booking?->booking_ref,
            ]);
        }

        $bookings = Booking::query()
            ->with('room:id,room_number,property_id')
            ->whereHas('room', fn ($r) => $r->whereIn('property_id', $ids))
            ->whereNotIn('booking_status', [BookingStatus::Cancelled->value])
            ->latest('created_at')
            ->limit($limit)
            ->get(['id', 'booking_ref', 'guest_name', 'room_id', 'source', 'checkin_date', 'checkout_date', 'total_amount', 'created_at']);
        foreach ($bookings as $b) {
            $nights = $b->checkin_date && $b->checkout_date ? (int) $b->checkin_date->diffInDays($b->checkout_date) : 0;
            $items->push([
                'at' => $b->created_at,
                'title' => 'New booking',
                'subtitle' => implode(' · ', array_filter([
                    $b->guest_name,
                    $b->source?->label(),
                    $b->checkin_date?->format('j M').($nights > 0 ? ', '.$nights.' '.($nights === 1 ? 'night' : 'nights') : ''),
                ])),
                'branch' => $branchOf($b->room?->property_id) ?? '',
                'amount' => null,
                'booking_ref' => $b->booking_ref,
            ]);
        }

        $events = DB::table('activity_log')
            ->where('subject_type', Booking::class)
            ->whereIn('event', ['checked_in', 'checked_out', 'extended'])
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get(['id', 'event', 'subject_id', 'created_at']);
        $eventBookings = Booking::query()
            ->with('room:id,room_number,property_id')
            ->whereIn('id', $events->pluck('subject_id')->filter()->unique())
            ->whereHas('room', fn ($r) => $r->whereIn('property_id', $ids))
            ->get(['id', 'booking_ref', 'guest_name', 'room_id'])
            ->keyBy('id');
        foreach ($events as $ev) {
            $b = $eventBookings->get((int) $ev->subject_id);
            if ($b === null) {
                continue;
            }
            $items->push([
                'at' => Carbon::parse($ev->created_at),
                'title' => match ($ev->event) {
                    'checked_in' => 'Checked in',
                    'checked_out' => 'Checked out',
                    default => 'Stay extended',
                },
                'subtitle' => $b->guest_name.($b->room?->room_number !== null ? ' · Room '.$b->room->room_number : ''),
                'branch' => $branchOf($b->room?->property_id) ?? '',
                'amount' => null,
                'booking_ref' => $b->booking_ref,
            ]);
        }

        $requests = GuestRequest::query()
            ->with(['room:id,room_number,property_id', 'booking:id,booking_ref'])
            ->whereHas('room', fn ($r) => $r->whereIn('property_id', $ids))
            ->latest('created_at')
            ->limit($limit)
            ->get();
        foreach ($requests as $r) {
            $items->push([
                'at' => $r->created_at,
                'title' => 'Guest request',
                'subtitle' => 'Room '.($r->room?->room_number ?? '?').' · '.$r->kind->label(),
                'branch' => $branchOf($r->room?->property_id) ?? '',
                'amount' => null,
                'booking_ref' => $r->booking?->booking_ref,
            ]);
        }

        return $items->filter(fn ($i) => $i['at'] !== null && $i['branch'] !== '')
            ->sortByDesc(fn ($i) => $i['at']->getTimestamp())
            ->take($limit)
            ->values()
            ->all();
    }

    /** ₦181.2m, ₦6.85m, ₦240k, ₦900: two decimals below ₦10m. */
    public static function short(int $amount): string
    {
        $trim = fn (float $n, int $dp): string => rtrim(rtrim(number_format($n, $dp, '.', ''), '0'), '.');
        $sign = $amount < 0 ? '−' : '';
        $a = abs($amount);

        return $sign.match (true) {
            $a >= 10_000_000 => '₦'.$trim($a / 1_000_000, 1).'m',
            $a >= 1_000_000 => '₦'.$trim($a / 1_000_000, 2).'m',
            $a >= 1_000 => '₦'.$trim($a / 1_000, 1).'k',
            default => '₦'.$a,
        };
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }
}
