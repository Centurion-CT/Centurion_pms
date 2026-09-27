<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Corporates;

use App\Models\Booking;
use App\Models\Corporate;
use App\Models\DeskBooking;
use App\Models\Lease;
use App\Models\Membership;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operator-facing view of every Keverna Corporate that's done
 * business at *this* property. Read-only, onboarding,
 * KYC + wallet are owned by Keverna platform admin.
 *
 * Aggregates across all four bookable types so a corporate that's
 * done one stay + two memberships + one lease appears once with
 * combined GMV.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    public bool $outstandingPanelOpen = false;

    public function updatedSearch(): void
    {
        // No pagination here, the union is small (corporates with at
        // least one booking at this tenant).
    }

    public function openOutstandingPanel(): void
    {
        $this->outstandingPanelOpen = true;
    }

    public function closeOutstandingPanel(): void
    {
        $this->outstandingPanelOpen = false;
    }

    public function render(): View
    {
        $tenantId = (int) (Tenant::current()?->id ?? 0);

        $rows = $this->buildRows($tenantId);

        $needle = trim($this->search);
        if ($needle !== '') {
            $like = mb_strtolower($needle);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower((string) $r->name), $like));
        }

        return view('livewire.dashboard.corporates.index', [
            'rows'  => $rows->values(),
            'stats' => [
                'corporates'  => $rows->count(),
                'gmv'         => (int) $rows->sum('gmv'),
                'last30d'     => (int) $rows->sum('gmv_30d'),
                'outstanding' => (int) $rows->sum('outstanding'),
            ],
        ]);
    }

    /**
     * Per-corp outstanding balance: sum of unpaid Issued invoices +
     * sum of CheckedIn/CheckedOut booking balances that no Issued/
     * Paid invoice (per-booking OR consolidated via InvoiceLine)
     * covers yet.
     *
     * Two aggregate queries so the list stays snappy for tenants with
     * dozens of corps and thousands of bookings — no N+1 walk.
     *
     * @return array<int, int>  corporate_id => outstanding_naira
     */
    private function outstandingByCorp(int $tenantId): array
    {
        // 1. Unpaid invoices, direct via invoices.corporate_id.
        //    Both Issued (untouched) and Partial (some payment landed
        //    but balance owed) are outstanding AR. Backfilled + written
        //    on all new invoices, so the direct column is authoritative
        //    for this aggregation.
        $invoiceOwed = DB::table('invoices')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['issued', 'partial'])
            ->whereNotNull('corporate_id')
            ->whereRaw('total - amount_paid > 0')
            ->groupBy('corporate_id')
            ->selectRaw('corporate_id, SUM(total - amount_paid) AS owed')
            ->pluck('owed', 'corporate_id')
            ->all();

        // 2. Booking balances the corp still owes for but where no
        //    Issued/Paid invoice — per-booking OR consolidated via
        //    InvoiceLine.source_id — covers the stay yet.
        $stayOwed = DB::table('bookings')
            ->where('bookings.tenant_id', $tenantId)
            ->whereNotNull('bookings.corporate_id')
            ->where('bookings.balance_due', '>', 0)
            ->whereIn('bookings.booking_status', ['checked_in', 'checked_out'])
            ->whereNotExists(fn ($q) => $q->from('invoices')
                ->whereColumn('invoices.booking_id', 'bookings.id')
                ->whereIn('invoices.status', ['issued', 'partial', 'paid']))
            ->whereNotExists(fn ($q) => $q->from('invoice_lines')
                ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
                ->whereColumn('invoice_lines.source_id', 'bookings.id')
                ->where('invoice_lines.source_type', Booking::class)
                ->whereIn('invoices.status', ['issued', 'partial', 'paid']))
            ->groupBy('bookings.corporate_id')
            ->selectRaw('bookings.corporate_id, SUM(bookings.balance_due) AS owed')
            ->pluck('owed', 'corporate_id')
            ->all();

        $totals = [];
        foreach ($invoiceOwed as $corpId => $amount) {
            $totals[(int) $corpId] = (int) $amount;
        }
        foreach ($stayOwed as $corpId => $amount) {
            $totals[(int) $corpId] = ($totals[(int) $corpId] ?? 0) + (int) $amount;
        }
        return $totals;
    }

    /**
     * One row per corporate that has done business here. Joins the
     * four bookable subject types in PHP, volumes are small (per
     * tenant), so a 4-query merge is simpler than an N-way SQL UNION.
     *
     * @return Collection<int, object>
     */
    private function buildRows(int $tenantId): Collection
    {
        if ($tenantId === 0) {
            return new Collection;
        }

        $outstandingByCorp = $this->outstandingByCorp($tenantId);

        $byCorp = [];

        $bump = function (int $corporateId, int $amount, ?\Illuminate\Support\Carbon $when, ?int $memberId, string $kind) use (&$byCorp): void {
            $byCorp[$corporateId] ??= [
                'corporate_id'    => $corporateId,
                'gmv'             => 0,
                'gmv_30d'         => 0,
                'count'           => 0,
                'count_by_kind'   => ['stay' => 0, 'desk' => 0, 'membership' => 0, 'lease' => 0],
                'last_activity'   => null,
                'member_ids'      => [],
            ];
            $byCorp[$corporateId]['gmv']                  += $amount;
            $byCorp[$corporateId]['count']                += 1;
            $byCorp[$corporateId]['count_by_kind'][$kind] += 1;
            if ($when !== null) {
                $existing = $byCorp[$corporateId]['last_activity'];
                if ($existing === null || $when->greaterThan($existing)) {
                    $byCorp[$corporateId]['last_activity'] = $when;
                }
                if ($when->greaterThanOrEqualTo(now()->subDays(30))) {
                    $byCorp[$corporateId]['gmv_30d'] += $amount;
                }
            }
            if ($memberId !== null) {
                $byCorp[$corporateId]['member_ids'][$memberId] = true;
            }
        };

        // Each query is tenant-scoped via the BelongsToTenant trait on
        // the source model, no manual where(tenant_id) needed.
        Booking::query()
            ->whereNotNull('corporate_id')
            ->select(['corporate_id', 'corporate_member_id', 'total_amount', 'checkin_date', 'created_at'])
            ->orderByDesc('id')->limit(2000)->get()
            ->each(fn ($r) => $bump(
                (int) $r->corporate_id,
                (int) $r->total_amount,
                $r->checkin_date ?? $r->created_at,
                $r->corporate_member_id !== null ? (int) $r->corporate_member_id : null,
                'stay',
            ));

        DeskBooking::query()
            ->whereNotNull('corporate_id')
            ->select(['corporate_id', 'corporate_member_id', 'amount_naira', 'start_at', 'created_at'])
            ->orderByDesc('id')->limit(2000)->get()
            ->each(fn ($r) => $bump(
                (int) $r->corporate_id,
                (int) $r->amount_naira,
                $r->start_at ?? $r->created_at,
                $r->corporate_member_id !== null ? (int) $r->corporate_member_id : null,
                'desk',
            ));

        Membership::query()
            ->whereNotNull('corporate_id')
            ->select(['corporate_id', 'corporate_member_id', 'amount_naira', 'started_at', 'created_at'])
            ->orderByDesc('id')->limit(2000)->get()
            ->each(fn ($r) => $bump(
                (int) $r->corporate_id,
                (int) $r->amount_naira,
                $r->started_at ?? $r->created_at,
                $r->corporate_member_id !== null ? (int) $r->corporate_member_id : null,
                'membership',
            ));

        Lease::query()
            ->whereNotNull('corporate_id')
            ->select(['corporate_id', 'corporate_member_id', 'annual_rent_naira', 'started_at', 'created_at'])
            ->orderByDesc('id')->limit(2000)->get()
            ->each(fn ($r) => $bump(
                (int) $r->corporate_id,
                (int) $r->annual_rent_naira,
                $r->started_at ?? $r->created_at,
                $r->corporate_member_id !== null ? (int) $r->corporate_member_id : null,
                'lease',
            ));

        // No early-return here: even with zero booking activity, a
        // tenant Manager might have JUST registered a corp for future
        // walk-ins. That row needs to render so they can profile staff
        // + edit. We fall through to the owned-corp append below.
        $corporates = $byCorp === []
            ? collect()
            : Corporate::query()
                ->whereIn('id', array_keys($byCorp))
                ->get()
                ->keyBy('id');

        $rows = collect($byCorp)
            ->map(function (array $agg) use ($corporates, $tenantId, $outstandingByCorp): object {
                $corp = $corporates->get((int) $agg['corporate_id']);
                $corpId = (int) $agg['corporate_id'];

                return (object) [
                    'corporate'     => $corp,
                    'corporate_id'  => $corpId,
                    'name'          => $corp?->name ?? 'Unknown',
                    'slug'          => $corp?->slug,
                    'kyc_status'    => $corp?->kyc_status?->value,
                    'status'        => $corp?->status?->value,
                    'gmv'           => (int) $agg['gmv'],
                    'gmv_30d'       => (int) $agg['gmv_30d'],
                    'count'         => (int) $agg['count'],
                    'count_by_kind' => $agg['count_by_kind'],
                    'last_activity' => $agg['last_activity'],
                    'members_count' => count($agg['member_ids']),
                    'outstanding'   => (int) ($outstandingByCorp[$corpId] ?? 0),
                    'is_owned'      => (int) ($corp?->created_by_tenant_id ?? 0) === $tenantId,
                ];
            });

        // Include corps this tenant registered themselves that haven't
        // done business yet — they're editable + need to appear so the
        // Manager can profile staff.
        $existingIds = $rows->pluck('corporate_id')->all();
        $ownedNoActivity = Corporate::query()
            ->where('created_by_tenant_id', $tenantId)
            ->whereNotIn('id', $existingIds)
            ->get();
        foreach ($ownedNoActivity as $corp) {
            $rows->push((object) [
                'corporate'     => $corp,
                'corporate_id'  => (int) $corp->id,
                'name'          => $corp->name,
                'slug'          => $corp->slug,
                'kyc_status'    => $corp->kyc_status?->value,
                'status'        => $corp->status?->value,
                'gmv'           => 0,
                'gmv_30d'       => 0,
                'count'         => 0,
                'count_by_kind' => ['stay' => 0, 'desk' => 0, 'membership' => 0, 'lease' => 0],
                'last_activity' => null,
                'members_count' => $corp->members()->count(),
                'outstanding'   => (int) ($outstandingByCorp[(int) $corp->id] ?? 0),
                'is_owned'      => true,
            ]);
        }

        // Debtors float above paid-up corps so an operator scanning
        // the list sees who owes what first. Ties within each bucket
        // fall back to all-time GMV (the previous default sort).
        return $rows
            ->sortBy([
                ['outstanding', 'desc'],
                ['gmv', 'desc'],
            ])
            ->values();
    }
}
