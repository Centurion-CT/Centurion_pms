<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Payment;
use App\Services\Reports\FinanceSnapshot;
use App\Services\Reports\RevenueForecast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Finance & revenue dashboard, managers, accountants, owners.
 *
 * Single page, driven by:
 *   - preset / custom range (billed + collected + refunds)
 *   - forecast horizon (30 / 60 / 90 days)
 *
 * All three live in the URL so an accountant can deep-link a specific
 * view when sharing with colleagues. Same preset vocabulary as
 * {@see \App\Livewire\Dashboard\Reports\Index} for muscle-memory
 * consistency across the two report surfaces.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    #[Url(as: 'preset', except: 'last_30d')]
    public string $preset = 'last_30d';

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    #[Url(as: 'horizon', except: 30)]
    public int $forecastHorizon = 30;

    public function setPreset(string $preset): void
    {
        $this->preset = $preset;
    }

    public function setHorizon(int $horizon): void
    {
        if (! in_array($horizon, [30, 60, 90], true)) {
            return;
        }
        $this->forecastHorizon = $horizon;
    }

    /**
     * Which KPI tile the operator clicked - drives the server-side
     * detail panel. Null when no panel is open. Loaded on demand so
     * we don't pay the query cost on every page render (the tables
     * below already hydrate the per-row breakdowns eagerly; these
     * KPI-level lists can be larger and are opened less often).
     *
     * Property is named `openKpiKey` (not `openKpi`) because Livewire's
     * `$wire` proxy collapses same-named property + method - the
     * property wins as a data getter and shadows the method, so
     * `$wire.openKpi(...)` throws "is not a function". Separate names
     * keep `wire:click="openKpi('billed')"` callable.
     */
    public ?string $openKpiKey = null;

    public function openKpi(string $key): void
    {
        if (! in_array($key, ['billed', 'collected', 'outstanding', 'refunded', 'forecast'], true)) {
            return;
        }
        $this->openKpiKey = $key;
    }

    public function closeKpi(): void
    {
        $this->openKpiKey = null;
    }

    public function render(FinanceSnapshot $snapshot, RevenueForecast $forecast): View
    {
        [$from, $to] = $this->dateRange();

        $kpis = $snapshot->kpis($from, $to, $this->forecastHorizon);
        $daily = $snapshot->dailySeries($from, $to);
        $channels = $snapshot->channelPerformance($from, $to);
        $inventory = $snapshot->inventoryBreakdown($from, $to);
        $ledger = $snapshot->transactionLedger($from, $to);
        $forecastSeries = $forecast->project($this->forecastHorizon);
        $posSummary = $snapshot->posSummary($from, $to);
        $posByOutlet = $snapshot->posByOutlet($from, $to);
        $posTopProducts = $snapshot->posTopProducts($from, $to);

        // Expenses in-window + a keyed-by-date daily series. Merged
        // into $daily so the Cash flow table can print an Expenses
        // column + a Net-of-day column without extra plumbing on the
        // view side.
        $expensesInWindow = $snapshot->expenseTotal($from, $to);
        $expenseDaily = collect($snapshot->dailyExpenseSeries($from, $to))->keyBy('date');
        $daily = array_map(function ($row) use ($expenseDaily) {
            $exp = (int) ($expenseDaily[$row['date']]['expenses'] ?? 0);
            $row['expenses'] = $exp;
            $row['dayNet'] = (int) $row['collected'] - (int) $row['refunded'] - $exp;
            return $row;
        }, $daily);
        $kpis['expenses'] = $expensesInWindow;
        $kpis['netProfit'] = (int) $kpis['collected'] - (int) $kpis['refunded'] - $expensesInWindow;

        $breakdowns = $this->buildBreakdowns($from, $to, $channels, $inventory);
        $breakdowns += $this->buildCashflowBreakdowns($daily);
        $breakdowns += $this->buildLedgerBreakdowns($ledger);

        // KPI-level breakdown is loaded on demand - pay the query
        // cost only when the operator clicks the tile, not on every
        // render. wire:loading on the click target surfaces the
        // spinner while the round-trip resolves.
        $kpiPanel = $this->openKpiKey !== null
            ? $this->buildKpiPanel($this->openKpiKey, $from, $to, $forecastSeries)
            : null;

        return view('livewire.dashboard.finance.index', [
            'kpis'      => $kpis,
            'daily'     => $daily,
            'channels'  => $channels,
            'topRooms'  => $inventory['topRooms'],
            'byCategory' => $inventory['byCategory'],
            'ledger'    => $ledger,
            'forecast'  => $forecastSeries,
            'from'      => $from,
            'to'        => $to,
            'presets'   => $this->presetOptions(),
            'posSummary' => $posSummary,
            'posByOutlet' => $posByOutlet,
            'posTopProducts' => $posTopProducts,
            'breakdowns' => $breakdowns,
            'kpiPanel' => $kpiPanel,
            'openKpi' => $this->openKpiKey,
        ]);
    }

    /**
     * Load the detail rows behind a KPI tile. Server-side so a
     * property with thousands of payments in a window doesn't get
     * dumped into every page load. The panel view branches on
     * `kind` (bookings vs payments) to render appropriately.
     *
     * @return array<string, mixed>|null
     */
    private function buildKpiPanel(string $key, Carbon $from, Carbon $to, array $forecastSeries): ?array
    {
        $moneyFmt = fn (int $n): string => '₦'.number_format($n);

        $bookingRow = fn (Booking $b): array => [
            'ref' => (string) $b->booking_ref,
            'guest' => (string) $b->guest_name,
            'room' => $b->room?->room_number ? 'Rm '.$b->room->room_number : '-',
            'checkin' => $b->checkin_date?->format('j M') ?? '-',
            'checkout' => $b->checkout_date?->format('j M') ?? '-',
            'nights' => (int) ($b->nights ?? 0),
            'amount' => (int) ($b->total_amount ?? 0),
            'url' => route('tenant.reservations.show', $b->booking_ref),
        ];

        $paymentRow = fn (Payment $p): array => [
            'id' => (int) $p->id,
            'ref' => (string) $p->paystack_reference,
            'guest' => (string) ($p->booking?->guest_name ?? '-'),
            'booking_ref' => (string) ($p->booking?->booking_ref ?? ''),
            'amount' => (int) $p->amount,
            'method' => ucfirst((string) ($p->method ?? 'paystack')),
            'paid_at' => $p->paid_at?->format('j M · H:i') ?? '-',
            'type' => (string) $p->type->value,
        ];

        $revenueStatuses = [
            BookingStatus::Confirmed->value,
            BookingStatus::OnHold->value,
            BookingStatus::CheckedIn->value,
            BookingStatus::CheckedOut->value,
        ];

        switch ($key) {
            case 'billed': {
                $bookings = Booking::query()
                    ->with(['room:id,room_number'])
                    ->whereIn('booking_status', $revenueStatuses)
                    ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
                    ->orderByDesc('checkin_date')
                    ->limit(200)
                    ->get();
                $total = (int) $bookings->sum('total_amount');
                return [
                    'title' => 'Billed',
                    'subtitle' => 'Bookings with check-in '.$from->format('j M').' → '.$to->format('j M'),
                    'kind' => 'bookings',
                    'kpis' => [
                        ['label' => 'Bookings', 'value' => (string) $bookings->count()],
                        ['label' => 'Total',    'value' => $moneyFmt($total)],
                    ],
                    'rows' => $bookings->map($bookingRow)->all(),
                ];
            }
            case 'collected': {
                $payments = Payment::query()
                    ->with(['booking:id,booking_ref,guest_name'])
                    ->where('status', PaymentStatus::Succeeded->value)
                    ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                    ->orderByDesc('paid_at')
                    ->limit(200)
                    ->get();
                $total = (int) $payments->sum('amount');
                return [
                    'title' => 'Cash collected',
                    'subtitle' => 'Succeeded payments · '.$from->format('j M').' → '.$to->format('j M'),
                    'kind' => 'payments',
                    'kpis' => [
                        ['label' => 'Payments', 'value' => (string) $payments->count()],
                        ['label' => 'Total',    'value' => $moneyFmt($total)],
                    ],
                    'rows' => $payments->map($paymentRow)->all(),
                ];
            }
            case 'refunded': {
                $payments = Payment::query()
                    ->with(['booking:id,booking_ref,guest_name'])
                    ->where('status', PaymentStatus::Refunded->value)
                    ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                    ->orderByDesc('paid_at')
                    ->limit(200)
                    ->get();
                $total = (int) $payments->sum('amount');
                return [
                    'title' => 'Refunds issued',
                    'subtitle' => 'Refund payments · '.$from->format('j M').' → '.$to->format('j M'),
                    'kind' => 'payments',
                    'kpis' => [
                        ['label' => 'Refunds', 'value' => (string) $payments->count()],
                        ['label' => 'Total',   'value' => $moneyFmt($total)],
                    ],
                    'rows' => $payments->map($paymentRow)->all(),
                ];
            }
            case 'outstanding': {
                // Point-in-time - open bookings with balance_due or
                // unsettled charges. Mirrors kpis()['outstanding']
                // math so the panel totals reconcile with the tile.
                $openStatuses = [
                    BookingStatus::Confirmed->value,
                    BookingStatus::OnHold->value,
                    BookingStatus::CheckedIn->value,
                ];
                $bookings = Booking::query()
                    ->with(['room:id,room_number'])
                    ->whereIn('booking_status', $openStatuses)
                    ->where(function ($q): void {
                        $q->where('balance_due', '>', 0)
                            ->orWhereExists(function ($sub): void {
                                $sub->from('booking_charges')
                                    ->whereColumn('booking_charges.booking_id', 'bookings.id')
                                    ->where('booking_charges.settled', false);
                            });
                    })
                    ->limit(200)
                    ->get();
                $total = (int) $bookings->sum(fn (Booking $b) => $b->outstandingTotal());
                return [
                    'title' => 'Outstanding',
                    'subtitle' => 'Open bookings with balance or unsettled charges',
                    'kind' => 'bookings',
                    'kpis' => [
                        ['label' => 'Bookings', 'value' => (string) $bookings->count()],
                        ['label' => 'Held',     'value' => $moneyFmt($total)],
                    ],
                    'rows' => $bookings->map(function (Booking $b) use ($bookingRow): array {
                        $row = $bookingRow($b);
                        // Override amount to show outstanding, not
                        // total - that's what this panel is about.
                        $row['amount'] = (int) $b->outstandingTotal();
                        return $row;
                    })->all(),
                ];
            }
            case 'forecast': {
                // On-books bookings landing in the forecast horizon
                // (today → today+horizon), from RevenueForecast's
                // series. Show bookings, not the projection.
                $today = Carbon::today();
                $horizonEnd = $today->copy()->addDays($this->forecastHorizon);
                $bookings = Booking::query()
                    ->with(['room:id,room_number'])
                    ->whereIn('booking_status', [
                        BookingStatus::Confirmed->value,
                        BookingStatus::OnHold->value,
                        BookingStatus::CheckedIn->value,
                    ])
                    ->whereBetween('checkin_date', [$today->toDateString(), $horizonEnd->toDateString()])
                    ->orderBy('checkin_date')
                    ->limit(200)
                    ->get();
                $total = (int) $bookings->sum('total_amount');
                return [
                    'title' => 'Forecast · '.$this->forecastHorizon.'d on-books',
                    'subtitle' => 'Confirmed / on-hold / in-house · '.$today->format('j M').' → '.$horizonEnd->format('j M'),
                    'kind' => 'bookings',
                    'kpis' => [
                        ['label' => 'Bookings', 'value' => (string) $bookings->count()],
                        ['label' => 'On-books', 'value' => $moneyFmt($total)],
                    ],
                    'rows' => $bookings->map($bookingRow)->all(),
                ];
            }
        }
        return null;
    }

    /**
     * Build a keyed map of "click-a-row" breakdowns for the Channel /
     * Category / Top-room tables. Each entry has a title, KPIs, and a
     * list of booking rows for the panel to render - computed once
     * per render so the panel opens without a wire trip.
     *
     * Booking scope matches the FinanceSnapshot revenue basis
     * (booking.total_amount) so the panel row totals reconcile with
     * the parent row's revenue column.
     *
     * @param  list<array{source:string, label:string}>  $channels
     * @param  array{topRooms:list<array{room_id:int, label:string}>, byCategory:list<array{id:int, name:string}>}  $inventory
     * @return array<string, array<string, mixed>>
     */
    private function buildBreakdowns(Carbon $from, Carbon $to, array $channels, array $inventory): array
    {
        $revenueStatuses = [
            BookingStatus::Confirmed->value,
            BookingStatus::OnHold->value,
            BookingStatus::CheckedIn->value,
            BookingStatus::CheckedOut->value,
        ];
        $bookings = Booking::query()
            ->with(['room:id,room_number,room_category_id', 'room.category:id,name'])
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('checkin_date')
            ->get();

        $mapRow = fn (Booking $b): array => [
            'ref' => (string) $b->booking_ref,
            'guest' => (string) $b->guest_name,
            'room' => $b->room?->room_number ? 'Rm '.$b->room->room_number : '-',
            'checkin' => $b->checkin_date?->format('j M') ?? '-',
            'checkout' => $b->checkout_date?->format('j M') ?? '-',
            'nights' => (int) ($b->nights ?? 0),
            'amount' => (int) ($b->total_amount ?? 0),
            'url' => route('tenant.reservations.show', $b->booking_ref),
        ];

        $out = [];

        // Channel breakdowns.
        foreach ($channels as $c) {
            $rows = $bookings
                ->filter(fn (Booking $b) => (string) ($b->source?->value ?? '') === $c['source'])
                ->values();
            if ($rows->isEmpty()) {
                continue;
            }
            $out['channel:'.$c['source']] = [
                'id' => 'channel:'.$c['source'],
                'title' => $c['label'],
                'subtitle' => 'Channel · '.$rows->count().' booking'.($rows->count() === 1 ? '' : 's'),
                'kpis' => [
                    ['label' => 'Bookings', 'value' => (string) $rows->count()],
                    ['label' => 'Revenue',  'value' => '₦'.number_format((int) $rows->sum('total_amount'))],
                    ['label' => 'Avg value','value' => '₦'.number_format((int) round(($rows->sum('total_amount')) / max(1, $rows->count())))],
                ],
                'bookings' => $rows->map($mapRow)->all(),
            ];
        }

        // Category breakdowns.
        foreach ($inventory['byCategory'] as $cat) {
            $rows = $bookings
                ->filter(fn (Booking $b) => (int) ($b->room?->room_category_id ?? 0) === (int) $cat['id'])
                ->values();
            if ($rows->isEmpty()) {
                continue;
            }
            $out['category:'.$cat['id']] = [
                'id' => 'category:'.$cat['id'],
                'title' => $cat['name'],
                'subtitle' => 'Room category · '.$rows->count().' booking'.($rows->count() === 1 ? '' : 's'),
                'kpis' => [
                    ['label' => 'Bookings', 'value' => (string) $rows->count()],
                    ['label' => 'Nights',   'value' => (string) (int) $rows->sum('nights')],
                    ['label' => 'Revenue',  'value' => '₦'.number_format((int) $rows->sum('total_amount'))],
                ],
                'bookings' => $rows->map($mapRow)->all(),
            ];
        }

        // Top-room breakdowns.
        foreach ($inventory['topRooms'] as $rm) {
            $rows = $bookings
                ->filter(fn (Booking $b) => (int) ($b->room_id ?? 0) === (int) $rm['id'])
                ->values();
            if ($rows->isEmpty()) {
                continue;
            }
            $out['room:'.$rm['id']] = [
                'id' => 'room:'.$rm['id'],
                'title' => $rm['label'],
                'subtitle' => 'Room · '.$rows->count().' booking'.($rows->count() === 1 ? '' : 's'),
                'kpis' => [
                    ['label' => 'Bookings', 'value' => (string) $rows->count()],
                    ['label' => 'Nights',   'value' => (string) (int) $rows->sum('nights')],
                    ['label' => 'Revenue',  'value' => '₦'.number_format((int) $rows->sum('total_amount'))],
                ],
                'bookings' => $rows->map($mapRow)->all(),
            ];
        }

        return $out;
    }

    /**
     * Group the daily series into ISO-week buckets so clicking a Cash
     * flow row opens a daily-rows breakdown for just that week. Same
     * grouping logic the cashflow-table blade uses to render the
     * parent rows, mirrored here so the keys line up.
     *
     * @param  list<array<string, mixed>>  $daily
     * @return array<string, array<string, mixed>>
     */
    private function buildCashflowBreakdowns(array $daily): array
    {
        $weeks = [];
        foreach ($daily as $d) {
            try {
                $c = Carbon::parse($d['date']);
            } catch (\Throwable) {
                continue;
            }
            $key = 'week:'.$c->isoWeekYear().'-W'.str_pad((string) $c->isoWeek(), 2, '0', STR_PAD_LEFT);
            if (! isset($weeks[$key])) {
                $weeks[$key] = [
                    'id' => $key,
                    'title' => $c->copy()->startOfWeek()->format('M j').' – '.$c->copy()->endOfWeek()->format('M j'),
                    'kind' => 'daily',
                    'daily' => [],
                    '__totals' => ['billed' => 0, 'collected' => 0, 'refunded' => 0],
                ];
            }
            $billed    = (int) ($d['billed'] ?? 0);
            $collected = (int) ($d['collected'] ?? 0);
            $refunded  = (int) ($d['refunded'] ?? 0);
            $weeks[$key]['__totals']['billed']    += $billed;
            $weeks[$key]['__totals']['collected'] += $collected;
            $weeks[$key]['__totals']['refunded']  += $refunded;
            $weeks[$key]['daily'][] = [
                'date' => $c->format('D, M j'),
                'billed' => $billed,
                'collected' => $collected,
                'refunded' => $refunded,
                'net' => $collected - $refunded,
            ];
        }
        foreach ($weeks as $key => $w) {
            $t = $w['__totals'];
            $net = $t['collected'] - $t['refunded'];
            $weeks[$key]['subtitle'] = 'Week · '.count($w['daily']).' day'.(count($w['daily']) === 1 ? '' : 's').' · Net ₦'.number_format($net);
            $weeks[$key]['kpis'] = [
                ['label' => 'Billed',    'value' => '₦'.number_format($t['billed'])],
                ['label' => 'Collected', 'value' => '₦'.number_format($t['collected'])],
                ['label' => 'Net',       'value' => '₦'.number_format($net)],
            ];
            unset($weeks[$key]['__totals']);
        }
        return $weeks;
    }

    /**
     * A single ledger row = one Payment. Panel shows guest / booking
     * context, a metadata card (reference, paid at, channel, booking
     * ref), and a link to open the reservation.
     *
     * @param  list<array<string, mixed>>  $ledger
     * @return array<string, array<string, mixed>>
     */
    private function buildLedgerBreakdowns(array $ledger): array
    {
        $out = [];
        foreach ($ledger as $row) {
            $id = 'ledger:'.$row['id'];
            $isRefund = ($row['status'] ?? '') === 'refunded';
            $paidAt = ! empty($row['paid_at'])
                ? Carbon::parse($row['paid_at'])->format('D, j M · H:i')
                : '-';
            $amountDisplay = ($isRefund ? '−' : '').'₦'.number_format((int) $row['amount']);

            $out[$id] = [
                'id' => $id,
                'kind' => 'ledger_detail',
                'title' => (string) ($row['guest_name'] ?? 'Payment'),
                'subtitle' => trim(
                    (string) ($row['source_label'] ?? '')
                    .(! empty($row['booking_ref']) ? ' · '.$row['booking_ref'] : ''),
                    ' ·',
                ) ?: 'Payment',
                'kpis' => [
                    ['label' => 'Amount', 'value' => $amountDisplay],
                    ['label' => 'Type',   'value' => strtoupper((string) ($isRefund ? 'refund' : ($row['type'] ?? '-')))],
                    ['label' => 'Status', 'value' => ucfirst((string) ($row['status'] ?? '-'))],
                ],
                'details' => array_values(array_filter([
                    ['label' => 'Reference', 'value' => (string) ($row['reference'] ?? '-')],
                    ['label' => 'Paid at',   'value' => $paidAt],
                    ['label' => 'Channel',   'value' => (string) ($row['source_label'] ?? '-')],
                    ! empty($row['booking_ref']) ? ['label' => 'Booking', 'value' => (string) $row['booking_ref']] : null,
                ])),
                'open_url' => ! empty($row['booking_ref'])
                    ? route('tenant.reservations.show', $row['booking_ref'])
                    : null,
                'open_label' => 'Open reservation',
            ];
        }
        return $out;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(): array
    {
        $today = Carbon::today();

        return match ($this->preset) {
            'today'      => [$today->copy(), $today->copy()],
            'yesterday'  => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last_7d'    => [$today->copy()->subDays(6), $today->copy()],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()],
            'last_month' => [$today->copy()->subMonth()->startOfMonth(), $today->copy()->subMonth()->endOfMonth()],
            'ytd'        => [$today->copy()->startOfYear(), $today->copy()],
            'custom'     => $this->customRange($today),
            default      => [$today->copy()->subDays(29), $today->copy()],   // last_30d
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function customRange(Carbon $today): array
    {
        try {
            $from = $this->customFrom !== '' ? Carbon::parse($this->customFrom)->startOfDay() : $today->copy()->subDays(29);
            $to = $this->customTo !== '' ? Carbon::parse($this->customTo)->startOfDay() : $today->copy();
        } catch (\Throwable) {
            return [$today->copy()->subDays(29), $today->copy()];
        }
        if ($to->lessThan($from)) {
            $to = $from->copy();
        }

        return [$from, $to];
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    private function presetOptions(): array
    {
        return [
            ['key' => 'today',      'label' => 'Today'],
            ['key' => 'yesterday',  'label' => 'Yesterday'],
            ['key' => 'last_7d',    'label' => 'Last 7 days'],
            ['key' => 'last_30d',   'label' => 'Last 30 days'],
            ['key' => 'this_month', 'label' => 'This month'],
            ['key' => 'last_month', 'label' => 'Last month'],
            ['key' => 'ytd',        'label' => 'Year to date'],
            ['key' => 'custom',     'label' => 'Custom range'],
        ];
    }
}
