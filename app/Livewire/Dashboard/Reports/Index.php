<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Reports;

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Operator;
use App\Services\Reports\ReportSnapshot;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Property reports, single page, five tabs (revenue / check-in /
 * occupancy / inventory / staff). Date-range driven for all but
 * inventory (current state).
 *
 * Tab + filters live in the URL so operators can deep-link a specific
 * cut (e.g. "/dashboard/reports?tab=staff&dept=housekeeping&preset=last_7d")
 * for a shift review.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'tab', except: 'revenue')]
    public string $tab = 'revenue';

    #[Url(as: 'preset', except: 'last_30d')]
    public string $preset = 'last_30d';

    /** Custom-range start (YYYY-MM-DD). Only used when preset = 'custom'. */
    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    /** Custom-range end (YYYY-MM-DD). Only used when preset = 'custom'. */
    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    // ── Staff-activity tab filters ──────────────────────────────
    #[Url(as: 'dept', except: '')]
    public string $departmentFilter = '';

    #[Url(as: 'staff', except: '')]
    public string $staffFilter = '';

    #[Url(as: 'log', except: '')]
    public string $logNameFilter = '';

    /**
     * Role → department mapping. Kept in one place so the filter,
     * the dropdown, and the badge on the row all read from the same
     * grouping.
     *
     * @return array<string, array{label: string, roles: list<OperatorRole>}>
     */
    public static function departments(): array
    {
        return [
            'management'   => ['label' => 'Management',    'roles' => [OperatorRole::Manager, OperatorRole::ReservationOfficer]],
            'front_desk'   => ['label' => 'Front desk',    'roles' => [OperatorRole::Frontdesk]],
            'housekeeping' => ['label' => 'Housekeeping',  'roles' => [OperatorRole::HouseCleaner, OperatorRole::Porter, OperatorRole::Supervisor]],
            'pos'          => ['label' => 'POS',           'roles' => [OperatorRole::PosStaff]],
            'stock'        => ['label' => 'Stock & inventory', 'roles' => [OperatorRole::InventoryClerk, OperatorRole::StockKeeper]],
        ];
    }

    /**
     * Reverse-lookup: given a role, which department key does it
     * belong to? Used to slap a department badge on each row.
     */
    public static function departmentForRole(?OperatorRole $role): ?string
    {
        if ($role === null) {
            return null;
        }
        foreach (self::departments() as $key => $dept) {
            if (in_array($role, $dept['roles'], true)) {
                return $key;
            }
        }
        return null;
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['revenue', 'checkin', 'occupancy', 'inventory', 'staff'], true)) {
            return;
        }
        $this->tab = $tab;
    }

    public function setPreset(string $preset): void
    {
        $this->preset = $preset;
    }

    // Reset pagination whenever a filter that affects the list changes.
    public function updatedDepartmentFilter(): void { $this->resetPage(); $this->staffFilter = ''; }
    public function updatedStaffFilter(): void { $this->resetPage(); }
    public function updatedLogNameFilter(): void { $this->resetPage(); }
    public function updatedPreset(): void { $this->resetPage(); }
    public function updatedCustomFrom(): void { $this->resetPage(); }
    public function updatedCustomTo(): void { $this->resetPage(); }

    public function render(ReportSnapshot $snapshot): View
    {
        [$from, $to] = $this->dateRange();

        $viewData = [
            'from' => $from,
            'to' => $to,
            'presets' => $this->presetOptions(),
            'departments' => self::departments(),
        ];

        if ($this->tab === 'staff') {
            $viewData['activityRows'] = $this->activityPage($from, $to);
            $viewData['staffOptions'] = $this->staffOptions($from, $to);
            $viewData['logNameOptions'] = $this->logNameOptions($from, $to);
            $viewData['staffKpis'] = $this->staffKpis($from, $to);
            $viewData['exportUrl'] = $this->exportUrl($from, $to);
        } else {
            $viewData['reportData'] = match ($this->tab) {
                'checkin'   => $snapshot->checkin($from, $to),
                'occupancy' => $snapshot->occupancy($from, $to),
                'inventory' => $snapshot->inventory(),
                default     => $snapshot->revenue($from, $to),
            };
            // Click-a-row breakdown payloads for the tabs where the
            // rows summarise into a list of bookings (Revenue tab's
            // bySource / byCategory / topRooms; Check-in tab's
            // byCategory). Skipped for Occupancy + Inventory — those
            // roll up rooms rather than bookings.
            $viewData['breakdowns'] = in_array($this->tab, ['revenue', 'checkin'], true)
                ? $this->buildBreakdowns($this->tab, $viewData['reportData'], $from, $to)
                : [];
        }

        return view('livewire.dashboard.reports.index', $viewData);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function buildBreakdowns(string $tab, array $data, Carbon $from, Carbon $to): array
    {
        // Bookings that the two tabs group over. Loaded once + filtered
        // in-memory per grouping so a category with 200 bookings + a
        // channel with 50 don't fire 5 queries between them.
        $revenueStatuses = [
            BookingStatus::Confirmed->value,
            BookingStatus::OnHold->value,
            BookingStatus::CheckedIn->value,
            BookingStatus::CheckedOut->value,
            BookingStatus::NoShow->value,
            BookingStatus::Cancelled->value,
        ];
        $bookings = Booking::query()
            ->with(['room:id,room_number,room_category_id', 'room.category:id,name'])
            ->whereIn('booking_status', $revenueStatuses)
            ->whereBetween('checkin_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('checkin_date')
            ->get();

        $mapBooking = fn (Booking $b): array => [
            'ref' => (string) $b->booking_ref,
            'guest' => (string) $b->guest_name,
            'room' => $b->room?->room_number ? 'Rm '.$b->room->room_number : '—',
            'checkin' => $b->checkin_date?->format('j M') ?? '—',
            'checkout' => $b->checkout_date?->format('j M') ?? '—',
            'nights' => (int) ($b->nights ?? 0),
            'amount' => (int) ($b->total_amount ?? $b->room_total ?? 0),
            'status' => (string) ($b->booking_status?->value ?? ''),
            'url' => route('tenant.reservations.show', $b->booking_ref),
        ];

        $out = [];

        if ($tab === 'revenue') {
            foreach (($data['bySource'] ?? []) as $c) {
                $rows = $bookings
                    ->filter(fn (Booking $b) => (string) ($b->source?->value ?? '') === $c['source']
                        && in_array($b->booking_status?->value, ['confirmed', 'on_hold', 'checked_in', 'checked_out'], true))
                    ->values();
                if ($rows->isEmpty()) { continue; }
                $out['channel:'.$c['source']] = [
                    'id' => 'channel:'.$c['source'],
                    'title' => (string) $c['label'],
                    'subtitle' => 'Channel · '.$rows->count().' booking'.($rows->count() === 1 ? '' : 's'),
                    'kpis' => [
                        ['label' => 'Bookings', 'value' => (string) $rows->count()],
                        ['label' => 'Revenue',  'value' => '₦'.number_format((int) $c['revenue'])],
                        ['label' => 'Share',    'value' => $c['share'].'%'],
                    ],
                    'bookings' => $rows->map($mapBooking)->all(),
                ];
            }

            foreach (($data['byCategory'] ?? []) as $cat) {
                $rows = $bookings
                    ->filter(fn (Booking $b) => (int) ($b->room?->room_category_id ?? 0) === (int) $cat['id']
                        && in_array($b->booking_status?->value, ['confirmed', 'on_hold', 'checked_in', 'checked_out'], true))
                    ->values();
                if ($rows->isEmpty()) { continue; }
                $out['revcat:'.$cat['id']] = [
                    'id' => 'revcat:'.$cat['id'],
                    'title' => (string) $cat['name'],
                    'subtitle' => 'Room category · '.$rows->count().' booking'.($rows->count() === 1 ? '' : 's'),
                    'kpis' => [
                        ['label' => 'Bookings', 'value' => (string) $rows->count()],
                        ['label' => 'Nights',   'value' => (string) (int) $cat['nights']],
                        ['label' => 'Revenue',  'value' => '₦'.number_format((int) $cat['revenue'])],
                    ],
                    'bookings' => $rows->map($mapBooking)->all(),
                ];
            }

            foreach (($data['topRooms'] ?? []) as $rm) {
                $rows = $bookings
                    ->filter(fn (Booking $b) => (int) ($b->room_id ?? 0) === (int) $rm['id']
                        && in_array($b->booking_status?->value, ['confirmed', 'on_hold', 'checked_in', 'checked_out'], true))
                    ->values();
                if ($rows->isEmpty()) { continue; }
                $out['revroom:'.$rm['id']] = [
                    'id' => 'revroom:'.$rm['id'],
                    'title' => (string) $rm['label'],
                    'subtitle' => 'Room · '.$rows->count().' booking'.($rows->count() === 1 ? '' : 's'),
                    'kpis' => [
                        ['label' => 'Bookings', 'value' => (string) $rows->count()],
                        ['label' => 'Revenue',  'value' => '₦'.number_format((int) $rm['revenue'])],
                    ],
                    'bookings' => $rows->map($mapBooking)->all(),
                ];
            }
        }

        if ($tab === 'checkin') {
            foreach (($data['byCategory'] ?? []) as $cat) {
                $rows = $bookings
                    ->filter(fn (Booking $b) => (int) ($b->room?->room_category_id ?? 0) === (int) $cat['id'])
                    ->values();
                if ($rows->isEmpty()) { continue; }
                $out['checkincat:'.$cat['id']] = [
                    'id' => 'checkincat:'.$cat['id'],
                    'title' => (string) $cat['name'],
                    'subtitle' => 'Check-in performance · '.$rows->count().' booking'.($rows->count() === 1 ? '' : 's'),
                    'kpis' => [
                        ['label' => 'Checked in', 'value' => (string) (int) $cat['checked_in']],
                        ['label' => 'No-shows',   'value' => (string) (int) $cat['no_shows']],
                        ['label' => 'Rate',       'value' => $cat['checkin_rate'].'%'],
                    ],
                    'bookings' => $rows->map($mapBooking)->all(),
                ];
            }
        }

        return $out;
    }

    /**
     * Paginated activity_log rows caused by operators in the window,
     * respecting the department / staff / log-name filters.
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    private function activityPage(Carbon $from, Carbon $to): LengthAwarePaginator
    {
        return $this->activityQuery($from, $to)
            ->with(['causer', 'subject'])
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * Shared query builder for the tab's list + the CSV export
     * (through the controller URL, which mirrors these filters).
     *
     * @return \Illuminate\Database\Eloquent\Builder<Activity>
     */
    private function activityQuery(Carbon $from, Carbon $to)
    {
        $q = Activity::query()
            ->where('causer_type', Operator::class)
            ->whereNotNull('causer_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        if ($this->logNameFilter !== '') {
            $q->where('log_name', $this->logNameFilter);
        }

        if ($this->staffFilter !== '' && ctype_digit($this->staffFilter)) {
            $q->where('causer_id', (int) $this->staffFilter);
        } elseif ($this->departmentFilter !== '' && isset(self::departments()[$this->departmentFilter])) {
            $roleValues = array_map(
                fn (OperatorRole $r) => $r->value,
                self::departments()[$this->departmentFilter]['roles'],
            );
            $opIds = Operator::query()->whereIn('role', $roleValues)->pluck('id')->all();
            $q->whereIn('causer_id', $opIds ?: [0]);
        }

        return $q;
    }

    /**
     * Dropdown source: operators who actually caused an activity row
     * in the current window (optionally further filtered to a
     * department). Dynamic so the picker doesn't drown the operator
     * in staff who did nothing this period.
     *
     * @return array<int, array{id:int, name:string, role:string, department:?string}>
     */
    private function staffOptions(Carbon $from, Carbon $to): array
    {
        $causerIds = Activity::query()
            ->where('causer_type', Operator::class)
            ->whereNotNull('causer_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->distinct()
            ->pluck('causer_id')
            ->all();

        if ($causerIds === []) {
            return [];
        }

        $ops = Operator::query()->whereIn('id', $causerIds)->orderBy('name')->get();

        if ($this->departmentFilter !== '' && isset(self::departments()[$this->departmentFilter])) {
            $roles = self::departments()[$this->departmentFilter]['roles'];
            $ops = $ops->filter(fn (Operator $o) => in_array($o->role, $roles, true))->values();
        }

        return $ops->map(fn (Operator $o) => [
            'id' => (int) $o->id,
            'name' => (string) $o->name,
            'role' => $o->role?->label() ?? '—',
            'department' => self::departmentForRole($o->role),
        ])->all();
    }

    /**
     * @return list<string>
     */
    private function logNameOptions(Carbon $from, Carbon $to): array
    {
        return Activity::query()
            ->where('causer_type', Operator::class)
            ->whereNotNull('causer_id')
            ->whereNotNull('log_name')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{total:int, staff:int, top_event:?string}
     */
    private function staffKpis(Carbon $from, Carbon $to): array
    {
        $base = $this->activityQuery($from, $to);
        $total = (clone $base)->count();
        $staff = (clone $base)->distinct('causer_id')->count('causer_id');
        $topEvent = (clone $base)
            ->selectRaw('event, COUNT(*) as c')
            ->whereNotNull('event')
            ->groupBy('event')
            ->orderByDesc('c')
            ->limit(1)
            ->value('event');

        return [
            'total' => (int) $total,
            'staff' => (int) $staff,
            'top_event' => $topEvent ? (string) $topEvent : null,
        ];
    }

    private function exportUrl(Carbon $from, Carbon $to): string
    {
        return route('tenant.reports.staff-activity.export', array_filter([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'dept' => $this->departmentFilter !== '' ? $this->departmentFilter : null,
            'staff' => $this->staffFilter !== '' ? $this->staffFilter : null,
            'log' => $this->logNameFilter !== '' ? $this->logNameFilter : null,
        ]));
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
