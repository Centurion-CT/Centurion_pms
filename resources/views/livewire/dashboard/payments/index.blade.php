@php
    use App\Livewire\Dashboard\Payments\Index as PaymentsIndex;
@endphp

<div class="lp-dash">

    {{-- Centered spinner while openDetail loads a transaction into
         the side panel. Teleported to body so parent overflow/transform
         can't clip it. Only shows during the openDetail round-trip. --}}
    <template x-teleport="body">
    <div wire:loading wire:target="openDetail"
         data-testid="payments-detail-loader"
         style="position: fixed; top: 50%; left: 50%;
                transform: translate(-50%, -50%);
                z-index: 90; pointer-events: none;">
        <svg width="44" height="44" viewBox="0 0 44 44" fill="none"
             style="animation: payments-loader-spin 0.9s linear infinite;">
            <circle cx="22" cy="22" r="18" stroke="rgba(20,17,12,0.14)" stroke-width="3"></circle>
            <path d="M22 4 a18 18 0 0 1 18 18"
                  stroke="var(--color-accent-deep)"
                  stroke-width="3" stroke-linecap="round" fill="none"></path>
        </svg>
        <style>@@keyframes payments-loader-spin { to { transform: rotate(360deg); } }</style>
    </div>
    </template>


    {{-- ── Hero: title + Net payout side panel ─────────────
         Right side shows the net payout for the current window
         so the operator sees the till result at a glance. --}}
    <section class="lp-res-idx-hero" data-testid="payments-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Business</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Payments
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Every payment tied to a booking, cash, transfer, POS, Paystack. Filter by window and reconcile against the till without leaving the dashboard.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.payments.export', request()->query()) }}"
                       data-testid="payments-export"
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M8 2v9M4 7l4 4 4-4M2 13h12"/></svg>
                        Export CSV
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Net payout in window">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Net payout</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ (int) $kpis['net_payout'] > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};"
                       data-testid="payments-hero-net">
                        {{ money((int) $kpis['net_payout']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        {{ money((int) $kpis['settled']) }} in
                        @if ((int) $kpis['refunded'] > 0)
                            · {{ money((int) $kpis['refunded']) }} refunded
                        @endif
                        @if ((int) $kpis['pending_count'] > 0)
                            · {{ (int) $kpis['pending_count'] }} pending
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Pulse strip (scoped to filtered window) ──────────────── --}}
    @php
        $rangeSub = $dateFrom === $dateTo
            ? \Illuminate\Support\Carbon::parse($dateFrom)->toFormattedDateString()
            : \Illuminate\Support\Carbon::parse($dateFrom)->format('j M').' → '
              .\Illuminate\Support\Carbon::parse($dateTo)->format('j M');
        $payTiles = [
            [
                'label' => 'Settled',   'sub' => $rangeSub.' · cash in',
                'value' => (int) $kpis['settled'],   'money' => true,
                'dot' => 'var(--color-accent-deep)', 'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Refunded',  'sub' => 'in window · back to guests',
                'value' => (int) $kpis['refunded'],  'money' => true,
                'dot' => $kpis['refunded'] > 0 ? '#B85C42' : 'var(--color-accent-deep)',
                'color' => $kpis['refunded'] > 0 ? '#B85C42' : 'var(--color-ink)',
            ],
            [
                'label' => 'Net payout', 'sub' => 'settled minus refunds',
                'value' => (int) $kpis['net_payout'], 'money' => true,
                'dot' => 'var(--color-accent-deep)', 'color' => 'var(--color-accent-deep)',
                'accent' => true,
            ],
            [
                'label' => 'Pending',   'sub' => 'waiting on Paystack',
                'value' => (int) $kpis['pending_count'], 'money' => false,
                'dot' => $kpis['pending_count'] > 0 ? '#a36318' : 'var(--color-accent-deep)',
                'color' => $kpis['pending_count'] > 0 ? '#a36318' : 'var(--color-ink)',
            ],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-2 lg:grid-cols-4" data-testid="payment-kpis">
        @foreach ($payTiles as $tile)
            <div class="lp-dash-card"
                 style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;
                        @if (! empty($tile['accent'])) background: var(--color-accent-soft); @endif">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: {{ $tile['color'] }};">
                        @if ($tile['money']){{ money((int) $tile['value']) }}@else{{ number_format((int) $tile['value']) }}@endif
                    </p>
                    <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                       style="color: var(--color-ink-mute); margin-top: 2px;">
                        {{ $tile['label'] }}
                        <span style="text-transform: none; letter-spacing: 0;
                                     color: var(--color-ink-mute);
                                     font-size: 11px; margin-left: 4px;">
                            {{ $tile['sub'] }}
                        </span>
                    </p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Filters (chip system) ────────────────────────────────────
         Ramp/Linear pattern — a search input + a horizontal chip strip
         showing only the filters the operator has actually set, plus a
         "+ Add filter" popover for everything inactive. Retires the
         5-across <select> grid that made this page read as a form
         instead of a query.

         Wired through the same Livewire props (methodFilter, staffFilter,
         etc.) so the URL contract + existing tests keep working. --}}
    @php
        // Build filter definitions inline so the wire prop names,
        // human labels, and current display strings all live one hop
        // from the component. The component itself only needs
        // Livewire's $set() to change each value.
        $methodOptions = [];
        foreach ($methods as $m) {
            $methodOptions[] = ['value' => $m, 'label' => PaymentsIndex::methodLabel($m)];
        }
        $staffOptionsList = [['value' => '', 'label' => 'Anyone']];
        foreach ($staffOptions as $s) {
            $staffOptionsList[] = ['value' => (string) $s->id, 'label' => $s->name];
        }
        $statusOptions = [];
        foreach ($statuses as $s) {
            $statusOptions[] = ['value' => $s->value, 'label' => ucfirst($s->value)];
        }
        $typeOptions = [];
        foreach ($types as $t) {
            $typeOptions[] = ['value' => $t->value, 'label' => ucfirst($t->value)];
        }
        $sourceOptions = [];
        foreach ($sourceCases as $c) {
            $sourceOptions[] = ['value' => $c->value, 'label' => $c->label()];
        }

        $staffDisplay = null;
        if ($staffFilter !== '' && $staffFilter !== null) {
            $staffDisplay = $staffOptions->firstWhere('id', (int) $staffFilter)?->name ?? 'Staff';
        }

        $paymentsFilterDefs = [
            [
                'id' => 'methodFilter',
                'label' => 'Method',
                'value' => $methodFilter,
                'default' => '',
                'display' => $methodFilter !== '' ? PaymentsIndex::methodLabel($methodFilter) : null,
                'options' => $methodOptions,
            ],
            [
                'id' => 'staffFilter',
                'label' => 'Staff',
                'value' => (string) $staffFilter,
                'default' => '',
                'display' => $staffDisplay,
                'options' => array_slice($staffOptionsList, 1), // drop "Anyone"
            ],
            [
                'id' => 'statusFilter',
                'label' => 'Status',
                'value' => $statusFilter,
                'default' => '',
                'display' => $statusFilter !== '' ? ucfirst((string) $statusFilter) : null,
                'options' => $statusOptions,
            ],
            [
                'id' => 'typeFilter',
                'label' => 'Type',
                'value' => $typeFilter,
                'default' => '',
                'display' => $typeFilter !== '' ? ucfirst((string) $typeFilter) : null,
                'options' => $typeOptions,
            ],
            [
                'id' => 'sourceFilter',
                'label' => 'Channel',
                'value' => $sourceFilter,
                'default' => '',
                'display' => $sourceFilter !== ''
                    ? (collect($sourceCases)->firstWhere('value', $sourceFilter)?->label() ?? $sourceFilter)
                    : null,
                'options' => $sourceOptions,
            ],
        ];
    @endphp

    <section class="lp-dash-card">
        <div class="flex flex-wrap items-center justify-between gap-3" style="margin-bottom: 12px;">
            <input type="search"
                   wire:model.live.debounce.300ms="search"
                   data-testid="payments-search"
                   class="lp-input"
                   style="max-width: 320px; width: 100%;"
                   placeholder="Name, phone, room, ref…">

            <div class="flex items-center gap-3">
                <label class="text-[11.5px]" style="color: var(--color-ink-soft); display: inline-flex; gap: 6px; align-items: center;">
                    From
                    <input type="date" wire:model.live="dateFrom"
                           data-testid="payments-from" class="lp-input"
                           style="padding: 4px 8px; font-size: 12px;">
                </label>
                <label class="text-[11.5px]" style="color: var(--color-ink-soft); display: inline-flex; gap: 6px; align-items: center;">
                    To
                    <input type="date" wire:model.live="dateTo"
                           data-testid="payments-to" class="lp-input"
                           style="padding: 4px 8px; font-size: 12px;">
                </label>
                <button type="button" wire:click="resetFiltersToToday"
                        data-testid="payments-reset"
                        class="text-[12px]"
                        style="background: transparent; border: 0; cursor: pointer;
                               color: var(--color-accent-deep); text-decoration: underline;">
                    Reset to today
                </button>
            </div>
        </div>

        <x-dash-filter-chips :filters="$paymentsFilterDefs" />
    </section>

    {{-- Method-breakdown panel — client-side, opens over the page
         when a Settled-by-method row is clicked. --}}
    @if (! empty($methodBreakdowns ?? []))
        @include('livewire.dashboard.payments._method-panel', ['methodBreakdowns' => $methodBreakdowns])
    @endif

    {{-- ── Settled by method ────────────────────────────── --}}
    @if (! empty($byMethod))
        <section class="lp-dash-card" data-testid="payments-by-method" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">Settled by method</span>
                <span class="lp-dash-card-meta">In the current window</span>
            </div>
            @php $topMethod = collect($byMethod)->max('amount') ?: 1; @endphp
            <ul class="lp-ledger lp-channel-stack">
                @foreach ($byMethod as $row)
                    @php
                        $isLeader = (int) $row['amount'] === (int) $topMethod && (int) $topMethod > 0;
                        $tone = $isLeader ? 'paid' : 'info';
                        $methodTone = match ($row['method']) {
                            'cash'     => 'warn',
                            'transfer' => 'info',
                            'card', 'paystack', 'pos' => 'paid',
                            default    => 'muted',
                        };
                    @endphp
                    <li class="lp-ledger-item lp-channel-row"
                        data-testid="payments-method-row-{{ $row['method'] }}"
                        x-on:click="$dispatch('payments-method-open', { id: 'method:{{ $row['method'] }}' })"
                        tabindex="0"
                        x-on:keydown.enter.prevent="$dispatch('payments-method-open', { id: 'method:{{ $row['method'] }}' })"
                        x-on:keydown.space.prevent="$dispatch('payments-method-open', { id: 'method:{{ $row['method'] }}' })">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $methodTone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <span class="lp-ledger-desc" style="font-weight: 600; color: var(--color-ink);">
                                    {{ $row['label'] }}
                                </span>
                                @if ($isLeader)
                                    <span class="lp-tag lp-tag--confirmed" style="font-size: 9.5px;">Top</span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                <span class="lp-mono">{{ number_format($row['count']) }}</span>
                                {{ Str::plural('payment', (int) $row['count']) }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                <span class="lp-channel-bar" aria-hidden>
                                    <span class="lp-channel-bar-fill" style="width: {{ $row['share'] }}%;"></span>
                                </span>
                                <span class="lp-mono" style="color: var(--color-ink-soft);">{{ $row['share'] }}%</span>
                                share
                            </p>
                        </div>
                        <div class="lp-ledger-right">
                            <span class="lp-invoice-total">{{ money($row['amount']) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ── Payments table ───────────────────────────────────────── --}}
    @if ($payments->isEmpty())
        <section class="lp-dash-card" data-testid="no-payments" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'wallet',
                'title' => 'No payments match those filters.',
                'sub'   => 'Try widening the date range or clearing filters.',
                'ctaLabel'   => 'Reset to today',
                'ctaClickJs' => "\$wire.resetFiltersToToday()",
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">Transactions</span>
                <div class="lp-payments-sort" data-testid="payments-sort-cluster">
                    <span class="lp-rate-sub-label" style="margin-bottom: 0;">Sort</span>
                    @foreach ([
                        'paid_at' => 'Paid',
                        'amount' => 'Amount',
                        'staff' => 'Staff',
                    ] as $key => $label)
                        <button type="button" wire:click="toggleSort('{{ $key }}')"
                                data-testid="payments-sort-{{ $key === 'paid_at' ? 'paid' : $key }}"
                                class="lp-res-action {{ $sortBy === $key ? 'lp-res-action--accent' : 'lp-res-action--ghost' }}"
                                style="padding: 4px 10px; font-size: 11px;">
                            {{ $label }}
                            @if ($sortBy === $key)
                                <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden><path d="{{ $sortDir === 'asc' ? 'M2 6l3-3 3 3' : 'M2 4l3 3 3-3' }}"/></svg>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            <ul class="lp-ledger" data-testid="payments-table">
                @foreach ($payments as $p)
                    @php
                        $isRefund = $p->status->value === 'refunded';
                        $statusClass = match ($p->status->value) {
                            'succeeded' => 'lp-tag--confirmed',
                            'pending'   => 'lp-tag--pending',
                            'failed'    => 'lp-tag--cancelled',
                            'refunded'  => 'lp-tag--on-hold',
                            default     => '',
                        };
                        $tone = match ($p->status->value) {
                            'succeeded' => 'paid',
                            'pending'   => 'warn',
                            'refunded'  => 'warn',
                            'failed'    => 'muted',
                            default     => 'info',
                        };
                        // Attribute the discount to the SPECIFIC payment
                        // it applied to, not the booking as a whole.
                        $autoDiscount = 0;
                        if ($p->type->value === 'full') {
                            $autoDiscount = (int) ($p->booking?->appliedDiscounts?->sum('amount_off') ?? 0);
                        }
                        $manualDiscount = PaymentsIndex::discountForPayment($p);
                        $totalDiscount = $autoDiscount + $manualDiscount;
                        $methodValue = $p->method ?? 'paystack';

                        if ($p->booking) {
                            $subjectHref = route('tenant.bookings.show', $p->booking->booking_ref);
                            $subjectRef  = $p->booking->booking_ref;
                            $subjectName = $p->booking->guest_name;
                            $subjectMeta = $p->booking->room && $p->booking->room->category
                                ? $p->booking->room->category->name.' · '.($p->booking->room->room_number ?? '-')
                                : ($p->booking->guest_phone ?: $p->booking->guest_email);
                        } elseif ($p->membershipSubscription) {
                            $subjectHref = route('tenant.membership.show', $p->membershipSubscription->member_ref);
                            $subjectRef  = $p->membershipSubscription->member_ref;
                            $subjectName = $p->membershipSubscription->customer?->name;
                            $subjectMeta = 'Membership · '.($p->membershipSubscription->business?->name ?? '-');
                        } elseif ($p->corporate) {
                            $subjectHref = $p->corporate->slug ? route('tenant.corporates.show', $p->corporate->slug) : null;
                            $subjectRef  = null;
                            $subjectName = $p->corporate->name;
                            $subjectMeta = 'Corporate AR'.($p->corporatePayment?->reference ? ' · '.$p->corporatePayment->reference : '');
                        } else {
                            $subjectHref = null;
                            $subjectRef  = null;
                            $subjectName = null;
                            $subjectMeta = null;
                        }
                    @endphp
                    <li class="lp-ledger-item lp-channel-row"
                        data-testid="payment-row-{{ $p->id }}"
                        wire:click="openDetail({{ $p->id }})">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                @if ($subjectName)
                                    <span class="lp-ledger-desc" style="font-weight: 500; color: var(--color-ink);">
                                        {{ $subjectName }}
                                    </span>
                                @endif
                                @if ($subjectRef && $subjectHref)
                                    <a href="{{ $subjectHref }}" wire:navigate @click.stop
                                       class="lp-mono"
                                       style="color: var(--color-ink-soft); font-size: 11.5px; background: var(--color-card-deep); border: 1px solid var(--color-line-soft); border-radius: 4px; padding: 1px 6px; text-decoration: none;">
                                        {{ $subjectRef }}
                                    </a>
                                @endif
                                <span class="lp-tag {{ $statusClass }}" data-testid="payment-status-{{ $p->id }}">
                                    {{ ucfirst($p->status->value) }}
                                </span>
                                <span class="lp-tag" data-testid="payment-method-{{ $p->id }}"
                                      style="font-size: 10.5px; background: var(--color-card-deep); color: var(--color-ink-soft);">
                                    {{ PaymentsIndex::methodLabel($methodValue) }}
                                </span>
                                <span class="lp-tag lp-tag--pending" style="font-size: 10px;">
                                    {{ ucfirst($p->type->value) }}
                                </span>
                            </div>
                            <p class="lp-ledger-meta">
                                {{ $p->paid_at?->format('j M · g:i A') ?? 'not paid yet' }}
                                @if ($subjectMeta)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ $subjectMeta }}
                                @endif
                                @if ($p->collectedBy)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span data-testid="payment-staff-{{ $p->id }}">{{ $p->collectedBy->name }}</span>
                                @endif
                                @if ($totalDiscount > 0)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span data-testid="payment-discount-{{ $p->id }}"
                                          style="color: var(--color-accent-deep); font-weight: 500;">
                                        −{{ money($totalDiscount) }} off
                                    </span>
                                @else
                                    <span data-testid="payment-discount-{{ $p->id }}" hidden></span>
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right">
                            <span class="lp-invoice-total" style="color: {{ $isRefund ? '#B85C42' : 'var(--color-ink)' }};">
                                <x-kv-money
                                    :amount="$isRefund ? -$p->amount : $p->amount"
                                    class="lp-table-amount" />
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($payments->hasPages())
                <div class="mt-4">{{ $payments->links() }}</div>
            @endif
        </section>
    @endif

    {{-- ── Detail panel (side sheet) ─────────────────────────────── --}}
    @if ($openPayment !== null)
        @php
            $b = $openPayment->booking;
            $nights = $b?->nights ?? 0;
            $ratePerNight = ($b && $nights > 0)
                ? intdiv((int) $b->room_total, $nights)
                : 0;
            $autoOff = (int) ($b?->appliedDiscounts?->sum('amount_off') ?? 0);
            $manualOff = (int) ($b?->manual_discount_amount ?? 0);
        @endphp
        <div data-testid="payment-detail-backdrop"
             wire:click="closeDetail"
             style="position: fixed; inset: 0; z-index: 60;
                    background: rgba(20,22,24,0.42);
                    backdrop-filter: blur(2px);"></div>

        <aside data-testid="payment-detail-panel"
               style="position: fixed; top: 0; right: 0; bottom: 0;
                      width: min(520px, 96vw); z-index: 61;
                      background: var(--color-card);
                      box-shadow: -18px 0 40px -18px rgba(20, 17, 12, 0.28);
                      display: flex; flex-direction: column;">
            <div style="display: flex; align-items: center; justify-content: space-between;
                        padding: 18px 22px; border-bottom: 1px solid var(--color-line-soft);">
                <div>
                    <p class="lp-mono text-[10.5px]"
                       style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;">
                        Payment
                    </p>
                    <p class="lp-display" style="font-size: 18px; font-weight: 600;">
                        {{ money($openPayment->amount) }}
                        <span class="text-[12px]" style="color: var(--color-ink-mute); font-weight: 400;">
                            · {{ PaymentsIndex::methodLabel($openPayment->method ?? 'paystack') }}
                        </span>
                    </p>
                </div>
                <button type="button" wire:click="closeDetail"
                        data-testid="payment-detail-close"
                        aria-label="Close"
                        style="background: transparent; border: 0; cursor: pointer;
                               font-size: 22px; color: var(--color-ink-mute);
                               padding: 4px 8px;">×</button>
            </div>

            <div style="flex: 1; overflow-y: auto; padding: 22px; display: grid; gap: 18px;">

                {{-- Payment core --}}
                <div>
                    <p class="lp-form-label">Reference</p>
                    <p class="lp-mono text-[12.5px]" style="color: var(--color-ink);">
                        {{ $openPayment->paystack_reference }}
                    </p>
                    @if ($openPayment->reference_note)
                        <p class="text-[12px] mt-1" style="color: var(--color-ink-mute);">
                            "{{ $openPayment->reference_note }}"
                        </p>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <p class="lp-form-label">Type</p>
                        <p class="text-[13px]">
                            @switch($openPayment->type->value)
                                @case('corporate_ar') Corporate AR @break
                                @case('pos_sale')     POS sale @break
                                @default              {{ ucfirst($openPayment->type->value) }}
                            @endswitch
                        </p>
                    </div>
                    <div>
                        <p class="lp-form-label">Status</p>
                        <p class="text-[13px]">{{ ucfirst($openPayment->status->value) }}</p>
                    </div>
                    <div>
                        <p class="lp-form-label">Method</p>
                        <p class="text-[13px]">{{ PaymentsIndex::methodLabel($openPayment->method ?? 'paystack') }}</p>
                    </div>
                    <div>
                        <p class="lp-form-label">Paid at</p>
                        <p class="text-[13px]">
                            {{ $openPayment->paid_at?->toDayDateTimeString() ?? '-' }}
                        </p>
                    </div>
                    <div>
                        <p class="lp-form-label">Collected by</p>
                        <p class="text-[13px]">
                            {{ $openPayment->collectedBy?->name ?? '-' }}
                        </p>
                    </div>
                </div>

                {{-- Corporate AR block. A corp-mirror Payment row has
                     no booking (booking_id NULL) so the guest / stay
                     / ledger sections below all skip. This section
                     surfaces the corp identity + the invoice(s) the
                     underlying CorporatePayment settled, so operators
                     can trace the cash back to the AR ledger. --}}
                @if ($openPayment->corporate)
                    @php
                        $cp = $openPayment->corporatePayment;
                        $allocs = $cp?->allocations ?? collect();
                        $allocated = (int) $allocs->sum('amount_naira');
                        $unallocated = max(0, (int) $openPayment->amount - $allocated);
                    @endphp
                    <div style="border-top: 1px solid var(--color-line-soft); padding-top: 16px;"
                         data-testid="payment-detail-corp">
                        <p class="lp-form-label">Corporate</p>
                        <a @if ($openPayment->corporate->slug) href="{{ route('tenant.corporates.show', $openPayment->corporate->slug) }}" wire:navigate @endif
                           style="color: var(--color-ink); font-weight: 600; text-decoration: underline; text-underline-offset: 3px;">
                            {{ $openPayment->corporate->name }}
                        </a>
                        @if ($cp?->reference)
                            <p class="text-[12.5px] mt-2" style="color: var(--color-ink-soft);">
                                Reference
                                <span class="lp-mono" style="color: var(--color-ink); margin-left: 6px;">
                                    {{ $cp->reference }}
                                </span>
                            </p>
                        @endif
                        @if ($cp?->note)
                            <p class="text-[12.5px] mt-1" style="color: var(--color-ink-mute);">
                                "{{ $cp->note }}"
                            </p>
                        @endif
                    </div>

                    @if ($allocs->isNotEmpty())
                        <div style="border-top: 1px solid var(--color-line-soft); padding-top: 16px;"
                             data-testid="payment-detail-allocations">
                            <p class="lp-form-label">Applied to invoice{{ $allocs->count() === 1 ? '' : 's' }}</p>
                            <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 6px;">
                                @foreach ($allocs as $al)
                                    <li style="display: flex; justify-content: space-between; align-items: baseline;
                                               font-size: 12.5px; gap: 8px;">
                                        <a href="{{ route('tenant.invoices.show', ['invoice' => $al->invoice?->id]) }}"
                                           wire:navigate
                                           style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                            {{ $al->invoice?->number ?? 'Draft' }}
                                        </a>
                                        <span class="lp-num" style="color: var(--color-ink); font-weight: 500;">
                                            {{ money($al->amount_naira) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($unallocated > 0)
                                <p class="text-[12px] mt-2" style="color: var(--color-accent-deep);
                                                                    border-top: 1px dashed var(--color-line-soft);
                                                                    padding-top: 6px;">
                                    Unallocated credit
                                    <strong style="float: right;">{{ money($unallocated) }}</strong>
                                </p>
                                <p class="text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                                    Sits on this payment until the next invoice draws it down.
                                </p>
                            @endif
                        </div>
                    @elseif ($unallocated > 0)
                        <div style="border-top: 1px solid var(--color-line-soft); padding-top: 16px;">
                            <p class="lp-form-label">Unallocated credit</p>
                            <p class="text-[13px]" style="color: var(--color-accent-deep); font-weight: 600;">
                                {{ money($unallocated) }}
                            </p>
                            <p class="text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                                No open invoice at the time. Draws down on the next issued invoice.
                            </p>
                        </div>
                    @endif

                    @if ($cp?->receipt_path)
                        <div style="border-top: 1px solid var(--color-line-soft); padding-top: 16px;">
                            <p class="lp-form-label">Receipt</p>
                            <a href="{{ \Illuminate\Support\Facades\Storage::url($cp->receipt_path) }}"
                               target="_blank" rel="noopener"
                               data-testid="payment-detail-receipt"
                               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px; font-size: 13px;">
                                Open receipt →
                            </a>
                        </div>
                    @endif
                @endif

                @if ($b)
                    {{-- Booking + guest --}}
                    <div style="border-top: 1px solid var(--color-line-soft); padding-top: 16px;">
                        <p class="lp-form-label">Booking</p>
                        <a href="{{ route('tenant.bookings.show', $b->booking_ref) }}" wire:navigate
                           style="color: var(--color-ink); font-weight: 600; text-decoration: underline; text-underline-offset: 3px;">
                            {{ $b->booking_ref }}
                        </a>
                        <p class="text-[13px] mt-1">
                            {{ $b->guest_name }}
                            <span style="color: var(--color-ink-mute);">·</span>
                            {{ $b->guest_phone ?: $b->guest_email }}
                        </p>
                        @if ($b->room)
                            <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                Room {{ $b->room->room_number ?? '-' }}
                                @if ($b->room->category) · {{ $b->room->category->name }} @endif
                            </p>
                        @endif
                    </div>

                    {{-- Stay + rate --}}
                    <div style="border-top: 1px solid var(--color-line-soft); padding-top: 16px;">
                        <p class="lp-form-label">Stay</p>
                        <p class="text-[13px]">
                            {{ $b->checkin_date?->toFormattedDateString() ?? '-' }}
                            <span style="color: var(--color-ink-mute);">→</span>
                            {{ $b->checkout_date?->toFormattedDateString() ?? '-' }}
                        </p>
                        <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                            {{ $nights }} night{{ $nights === 1 ? '' : 's' }} ·
                            <strong style="color: var(--color-ink);">
                                {{ money($ratePerNight) }}/night
                            </strong>
                            <span style="color: var(--color-ink-mute);">
                                (avg of {{ money($b->room_total) }} ÷ {{ $nights ?: '-' }})
                            </span>
                        </p>
                        @if ($b->breakfast_included)
                            <p class="text-[12px] mt-1"
                               data-testid="payment-detail-breakfast"
                               style="color: var(--color-accent-deep);">
                                ✓ With complimentary breakfast (rate reflects the tier)
                            </p>
                        @endif
                    </div>

                    {{-- Discount split --}}
                    @if ($autoOff + $manualOff > 0)
                        <div style="border-top: 1px solid var(--color-line-soft); padding-top: 16px;"
                             data-testid="payment-detail-discount">
                            <p class="lp-form-label">Discount applied</p>
                            @if ($autoOff > 0)
                                <p class="text-[12.5px]">
                                    Auto / coupon
                                    <strong style="float: right; color: var(--color-accent-deep);">
                                        −{{ money($autoOff) }}
                                    </strong>
                                </p>
                            @endif
                            @if ($manualOff > 0)
                                <p class="text-[12.5px] mt-1">
                                    Manual by {{ $b->manualDiscountBy?->name ?? 'operator' }}
                                    <strong style="float: right; color: var(--color-accent-deep);">
                                        −{{ money($manualOff) }}
                                    </strong>
                                </p>
                            @endif
                            <p class="text-[12.5px] mt-2"
                               style="border-top: 1px dashed var(--color-line-soft); padding-top: 6px;">
                                Total off
                                <strong style="float: right; color: var(--color-accent-deep);">
                                    −{{ money($autoOff + $manualOff) }}
                                </strong>
                            </p>
                        </div>
                    @endif

                    {{-- Booking payment ledger --}}
                    @if ($b->payments->isNotEmpty())
                        <div style="border-top: 1px solid var(--color-line-soft); padding-top: 16px;"
                             data-testid="payment-detail-ledger">
                            <p class="lp-form-label">All payments on this booking</p>
                            <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 6px;">
                                @foreach ($b->payments->sortByDesc('paid_at') as $sibling)
                                    <li style="display: flex; justify-content: space-between; align-items: baseline; gap: 8px;
                                               font-size: 12.5px;
                                               {{ $sibling->id === $openPayment->id ? 'font-weight: 600;' : '' }}">
                                        <span>
                                            {{ $sibling->paid_at?->format('j M · g:i A') ?? 'Not paid' }}
                                            <span style="color: var(--color-ink-mute);">·</span>
                                            {{ PaymentsIndex::methodLabel($sibling->method ?? 'paystack') }}
                                            @if ($sibling->collectedBy)
                                                <span style="color: var(--color-ink-mute);">·</span>
                                                {{ $sibling->collectedBy->name }}
                                            @endif
                                        </span>
                                        <span class="lp-mono"
                                              style="color: {{ $sibling->status->value === 'refunded' ? '#B85C42' : 'var(--color-ink)' }};">
                                            {{ $sibling->status->value === 'refunded' ? '−' : '' }}{{ money($sibling->amount) }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
            </div>
        </aside>
    @endif
</div>
