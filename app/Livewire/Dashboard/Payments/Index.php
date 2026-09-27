<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Payments;

use App\Enums\BookingSource;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Operator;
use App\Models\Payment;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;

    // ── Search ──────────────────────────────────────────────────────
    // Matches paystack_reference, booking_ref, guest_name, guest_email,
    // guest_phone, and room.room_number so the front desk can find a
    // payment by any surface they remember.
    #[Url(as: 'q', except: '')]
    public string $search = '';

    // ── Filters ─────────────────────────────────────────────────────
    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'channel', except: '')]
    public string $sourceFilter = '';

    #[Url(as: 'method', except: '')]
    public string $methodFilter = '';

    #[Url(as: 'staff', except: '')]
    public string $staffFilter = '';

    // ── Date range ──────────────────────────────────────────────────
    // Defaults to today so opening the page shows today's collections
    // first — the operationally common view for a front-desk manager
    // eyeballing "did we take the money we should have taken?".
    #[Url(as: 'from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'to', except: '')]
    public string $dateTo = '';

    // ── Sort ────────────────────────────────────────────────────────
    // paid_at | amount | staff. Direction is fixed per column so the
    // UI can stay a simple clickable header without three-state toggle.
    #[Url(as: 'sort', except: 'paid_at')]
    public string $sortBy = 'paid_at';

    #[Url(as: 'dir', except: 'desc')]
    public string $sortDir = 'desc';

    // ── Detail panel ────────────────────────────────────────────────
    public ?int $openPaymentId = null;

    public function mount(): void
    {
        if ($this->dateFrom === '') {
            $this->dateFrom = Carbon::today()->toDateString();
        }
        if ($this->dateTo === '') {
            $this->dateTo = Carbon::today()->toDateString();
        }
    }

    public function updated($property): void
    {
        // Any filter or search change resets pagination so the operator
        // isn't stranded on page 5 after tightening the query.
        if (in_array($property, [
            'search', 'statusFilter', 'typeFilter', 'sourceFilter',
            'methodFilter', 'staffFilter', 'dateFrom', 'dateTo',
            'sortBy', 'sortDir',
        ], true)) {
            $this->resetPage();
        }
    }

    public function toggleSort(string $column): void
    {
        // Click same header → flip direction; click new header → set
        // it as active and default to sensible direction (desc for
        // dates + amounts, asc for staff names).
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';

            return;
        }
        $this->sortBy = $column;
        $this->sortDir = $column === 'staff' ? 'asc' : 'desc';
    }

    public function resetFiltersToToday(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->typeFilter = '';
        $this->sourceFilter = '';
        $this->methodFilter = '';
        $this->staffFilter = '';
        $this->dateFrom = Carbon::today()->toDateString();
        $this->dateTo = Carbon::today()->toDateString();
        $this->sortBy = 'paid_at';
        $this->sortDir = 'desc';
        $this->resetPage();
    }

    public function openDetail(int $paymentId): void
    {
        $this->openPaymentId = $paymentId;
    }

    public function closeDetail(): void
    {
        $this->openPaymentId = null;
    }

    public function render(): View
    {
        $query = $this->buildQuery();
        $payments = $query->orderBy(...$this->sortExpression())
            ->orderByDesc('payments.id')
            ->paginate(20);

        // KPIs use the SAME filtered window as the table so the "total
        // collected" tile answers "in this window" — not a fixed
        // rolling 30-day figure that would confuse an operator who
        // just typed a date range.
        $windowStart = $this->dateFrom !== '' ? Carbon::parse($this->dateFrom)->startOfDay() : null;
        $windowEnd = $this->dateTo !== '' ? Carbon::parse($this->dateTo)->endOfDay() : null;

        $propertyId = app(PropertyContext::class)->current()?->getKey();

        $kpiScope = Payment::query();
        if ($windowStart) $kpiScope->where('paid_at', '>=', $windowStart);
        if ($windowEnd) $kpiScope->where('paid_at', '<=', $windowEnd);
        if ($propertyId !== null) {
            // Corp AR payments are tenant-wide, not property-scoped —
            // count them for every branch's KPI so the tile matches
            // what the corp ledger shows.
            $kpiScope->where(function ($q) use ($propertyId): void {
                $q->whereHas('booking.room', fn ($r) => $r->where('property_id', $propertyId))
                    ->orWhereNotNull('corporate_id');
            });
        }

        $settled = (int) (clone $kpiScope)
            ->where('status', PaymentStatus::Succeeded->value)->sum('amount');
        $refunded = (int) (clone $kpiScope)
            ->where('status', PaymentStatus::Refunded->value)->sum('amount');
        $pending = Payment::query()
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('booking.room', fn ($r) => $r->where('property_id', $pid)))
            ->where('status', PaymentStatus::Pending->value)
            ->count();
        $netPayout = $settled - $refunded;

        // By-method breakdown — same shape byChannel had, replacing it
        // as the more useful "how did we take the cash" cut for the
        // front-desk manager.
        $byMethod = (clone $kpiScope)
            ->where('status', PaymentStatus::Succeeded->value)
            ->toBase()
            ->selectRaw("COALESCE(method, 'paystack') as method, COUNT(id) as count, SUM(amount) as amount")
            ->groupBy('method')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => [
                'method' => (string) $r->method,
                'label' => self::methodLabel((string) $r->method),
                'count' => (int) $r->count,
                'amount' => (int) $r->amount,
                'share' => $settled > 0 ? round(((int) $r->amount) / $settled * 100, 1) : 0.0,
            ])
            ->values()
            ->all();

        // Detail panel data — fetched only when open so we don't pay
        // the JOIN cost on every render.
        $openPayment = null;
        if ($this->openPaymentId !== null) {
            $openPayment = Payment::query()
                ->with([
                    'booking.room.category',
                    'booking.appliedDiscounts',
                    'booking.manualDiscountBy',
                    'booking.payments.collectedBy',
                    'collectedBy',
                    'corporate:id,name,slug',
                    'corporatePayment:id,reference,note,receipt_path,amount_naira',
                    'corporatePayment.allocations.invoice:id,number,total,amount_paid',
                ])
                ->find($this->openPaymentId);
        }

        // Staff picker: any operator on this tenant who has actually
        // collected a payment in the current window; keeps the dropdown
        // short + relevant.
        $staffOptions = Operator::query()
            ->whereIn('id', function ($sub) use ($windowStart, $windowEnd) {
                $sub->select('collected_by_operator_id')->from('payments')
                    ->whereNotNull('collected_by_operator_id');
                if ($windowStart) $sub->where('paid_at', '>=', $windowStart);
                if ($windowEnd) $sub->where('paid_at', '<=', $windowEnd);
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        // Method-breakdown panel payloads. Each Settled-by-method row
        // dispatches `payments-method-open` with an id like
        // `method:cash` and the shared Alpine panel opens with the
        // list of payments that made up that method's total. Capped
        // at 100 rows per method — enough for a scan, and if the
        // operator needs more they can filter the main table.
        $methodBreakdowns = $this->buildMethodBreakdowns($kpiScope, $byMethod);

        return view('livewire.dashboard.payments.index', [
            'payments' => $payments,
            'kpis' => [
                'settled' => $settled,
                'refunded' => $refunded,
                'net_payout' => $netPayout,
                'pending_count' => $pending,
            ],
            'byMethod' => $byMethod,
            'openPayment' => $openPayment,
            'statuses' => PaymentStatus::cases(),
            'types' => PaymentType::cases(),
            'sourceCases' => BookingSource::cases(),
            'methods' => ['cash', 'transfer', 'card', 'pos', 'other', 'paystack', 'wallet'],
            'staffOptions' => $staffOptions,
            'methodBreakdowns' => $methodBreakdowns,
        ]);
    }

    /**
     * Build a keyed map (`method:cash`, `method:pos`, ...) of the
     * payments that rolled up into each Settled-by-method row.
     * Payments are already filtered to the current window via
     * $kpiScope; we group in memory + cap per-method rows so the
     * payload stays reasonable even in a heavy day.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Payment>  $kpiScope
     * @param  array<int, array<string, mixed>>  $byMethod
     * @return array<string, array<string, mixed>>
     */
    private function buildMethodBreakdowns(\Illuminate\Database\Eloquent\Builder $kpiScope, array $byMethod): array
    {
        if ($byMethod === []) {
            return [];
        }
        $rows = (clone $kpiScope)
            ->where('status', PaymentStatus::Succeeded->value)
            ->with(['booking:id,booking_ref,guest_name', 'collectedBy:id,name'])
            ->orderByDesc('paid_at')
            ->get();

        $out = [];
        foreach ($byMethod as $m) {
            $matches = $rows->filter(fn (Payment $p) => (string) ($p->method ?? 'paystack') === $m['method']);
            $sample = $matches->take(100)->map(fn (Payment $p) => [
                'id' => (int) $p->id,
                'ref' => (string) $p->paystack_reference,
                'guest' => (string) ($p->booking?->guest_name ?? '—'),
                'booking_ref' => (string) ($p->booking?->booking_ref ?? ''),
                'amount' => (int) $p->amount,
                'paid_at' => $p->paid_at?->format('j M · H:i') ?? '—',
                'staff' => (string) ($p->collectedBy?->name ?? '—'),
                'type' => (string) $p->type->value,
            ])->values()->all();

            $out['method:'.$m['method']] = [
                'id' => 'method:'.$m['method'],
                'title' => $m['label'],
                'subtitle' => 'Payment method · '.$m['count'].' payment'.($m['count'] === 1 ? '' : 's'),
                'kpis' => [
                    ['label' => 'Payments', 'value' => (string) $m['count']],
                    ['label' => 'Amount',   'value' => '₦'.number_format($m['amount'])],
                    ['label' => 'Share',    'value' => $m['share'].'%'],
                ],
                'payments' => $sample,
                'truncated' => $matches->count() > count($sample),
                'total_rows' => $matches->count(),
            ];
        }
        return $out;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Payment>
     */
    private function buildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Payment::query()
            ->with([
                'booking.room.category',
                'booking.appliedDiscounts',
                'collectedBy',
                'membershipSubscription.customer',
                'membershipSubscription.business',
                'membershipSubscription.plan',
                'corporate:id,name,slug',
                'corporatePayment:id,reference',
            ])
            ->select('payments.*');

        // Scope to the operator's current branch — Payment reaches
        // property via booking→room, so a whereHas covers both cases
        // (payments linked to a booking within this branch) without
        // dropping the rare pending payments that have no room row yet.
        // Membership payments are OR'd in — a payment tied to a
        // membership_subscription belongs on this branch's Payments
        // page when the underlying business is scoped to this branch,
        // or when it's not property-scoped at all (nullable).
        //
        // Corporate AR payments (booking_id NULL, corporate_id set) are
        // tenant-wide, not property-scoped: a corp settles their AR at
        // the tenant level regardless of which branch the folio was on.
        // Include them in every branch view so the cash tile matches
        // what the corp ledger already shows.
        $propertyId = app(PropertyContext::class)->current()?->getKey();
        if ($propertyId !== null) {
            $query->where(function ($q) use ($propertyId): void {
                $q->whereHas('booking.room', fn ($r) => $r->where('property_id', $propertyId))
                  ->orWhereHas('membershipSubscription.business', fn ($b) => $b
                      ->where(function ($bb) use ($propertyId): void {
                          $bb->where('property_id', $propertyId)
                             ->orWhereNull('property_id');
                      }))
                  ->orWhereNotNull('corporate_id');
            });
        }

        if ($this->dateFrom !== '') {
            $query->where('paid_at', '>=', Carbon::parse($this->dateFrom)->startOfDay());
        }
        if ($this->dateTo !== '') {
            $query->where('paid_at', '<=', Carbon::parse($this->dateTo)->endOfDay());
        }

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('payments.paystack_reference', 'like', $like)
                    ->orWhereHas('booking', fn ($b) => $b
                        ->where('booking_ref', 'like', $like)
                        ->orWhere('guest_name', 'like', $like)
                        ->orWhere('guest_email', 'like', $like)
                        ->orWhere('guest_phone', 'like', $like)
                        ->orWhereHas('room', fn ($r) => $r->where('room_number', 'like', $like)))
                    ->orWhereHas('membershipSubscription', fn ($s) => $s
                        ->where('member_ref', 'like', $like)
                        ->orWhereHas('customer', fn ($c) => $c
                            ->where('name', 'like', $like)
                            ->orWhere('phone', 'like', $like)
                            ->orWhere('email', 'like', $like)))
                    ->orWhereHas('corporate', fn ($c) => $c->where('name', 'like', $like));
            });
        }

        if ($this->statusFilter !== '') $query->where('status', $this->statusFilter);
        if ($this->typeFilter !== '') $query->where('type', $this->typeFilter);
        if ($this->sourceFilter !== '') {
            $query->whereHas('booking', fn ($b) => $b->where('source', $this->sourceFilter));
        }
        if ($this->methodFilter !== '') {
            // Paystack payments left the `method` column NULL (Paystack
            // is inferred from paystack_reference). Every other tag
            // is a literal method value.
            if ($this->methodFilter === 'paystack') {
                $query->whereNull('method');
            } else {
                $query->where('method', $this->methodFilter);
            }
        }
        if ($this->staffFilter !== '') {
            $query->where('collected_by_operator_id', (int) $this->staffFilter);
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sortExpression(): array
    {
        $dir = $this->sortDir === 'asc' ? 'asc' : 'desc';
        return match ($this->sortBy) {
            'amount' => ['payments.amount', $dir],
            'staff' => ['collected_by_operator_id', $dir],
            default => ['payments.paid_at', $dir],
        };
    }

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Cash',
            'transfer' => 'Bank transfer',
            'card' => 'Card',
            'pos' => 'POS terminal',
            'other' => 'Other',
            'wallet' => 'Wallet',
            'paystack' => 'Paystack',
            default => ucfirst($method),
        };
    }

    /**
     * Attribute a discount amount to one specific Payment row so the
     * Discount column tells the truth. Booking-level manual_discount_amount
     * is a running total across a booking's lifetime - showing it on
     * every payment (or even just on the type=Full row) misrepresents
     * which payment the discount actually applied to.
     *
     * Rules:
     *   - type=Extra payments correspond to a settled BookingCharge.
     *     If SettleCharges wrote the Payment, its paystack_reference
     *     starts with "charge-{id}-...". Parse the id, look up the
     *     charge, and extract the "· ₦N discount applied" trail our
     *     new extend flow stamps.
     *   - type=Full payments cover the booking's initial total. Show
     *     the discount ONLY if the booking was created with a manual
     *     discount already baked in (i.e., the operator applied it at
     *     booking creation, not later via ApplyPostCheckinDiscount).
     *     Detected via the `created` activity_log row's stamped
     *     attributes.manual_discount_amount.
     *   - Everything else: 0 (nothing to attribute, render em-dash).
     */
    public static function discountForPayment(Payment $payment): int
    {
        if ($payment->type === PaymentType::Extra) {
            $ref = (string) $payment->paystack_reference;
            if (preg_match('/^charge-(\d+)-/', $ref, $m) === 1) {
                $charge = \App\Models\BookingCharge::query()
                    ->withoutGlobalScope(\App\Multitenancy\TenantScope::class)
                    ->find((int) $m[1]);
                if ($charge !== null
                    && preg_match('/₦([\d,]+)\s+discount\s+applied/i', (string) $charge->description, $dm) === 1) {
                    return (int) str_replace(',', '', $dm[1]);
                }
            }

            return 0;
        }

        if ($payment->type === PaymentType::Full && $payment->booking_id !== null) {
            $created = \App\Models\Activity::query()
                ->withoutGlobalScope('tenant')
                ->where('subject_type', \App\Models\Booking::class)
                ->where('subject_id', $payment->booking_id)
                ->where('event', 'created')
                ->orderBy('id')
                ->first();
            if ($created !== null) {
                $props = $created->properties ?? collect();
                $attrs = is_array($props)
                    ? ($props['attributes'] ?? [])
                    : $props->get('attributes', []);
                return (int) ($attrs['manual_discount_amount'] ?? 0);
            }
        }

        return 0;
    }
}
