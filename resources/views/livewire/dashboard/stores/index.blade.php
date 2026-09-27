<div class="lp-dash">

    {{-- ── Hero: title + primary + day nav ────────────────
         The keeper's home base carries every action they use
         all day, so the hero-actions cluster is dense. The
         .lp-rates-month-nav pill wraps prev/today/next day so
         it reads as a single control, not three loose buttons. --}}
    <section class="lp-res-idx-hero" data-testid="store-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Business · Store</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Store
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Central store and daily stock sheet. Received from suppliers, issued to outlets, balance at end of day.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.store.issue') }}" wire:navigate
                       class="lp-res-action lp-res-action--primary"
                       data-testid="store-issue-link">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2 8h10M8 4l4 4-4 4"/></svg>
                        Issue to outlet
                    </a>
                    <a href="{{ route('tenant.store.receive') }}" wire:navigate
                       class="lp-res-action"
                       data-testid="store-receive-link">
                        Receive stock
                    </a>
                    <a href="{{ route('tenant.store.products.index') }}" wire:navigate
                       class="lp-res-action lp-res-action--ghost"
                       data-testid="store-products-link">
                        Products
                    </a>
                    <a href="{{ route('tenant.purchasing.orders.index') }}" wire:navigate
                       class="lp-res-action lp-res-action--ghost"
                       data-testid="store-orders-link">
                        Purchase orders
                    </a>
                    <a href="{{ route('tenant.purchasing.suppliers.index') }}" wire:navigate
                       class="lp-res-action lp-res-action--ghost"
                       data-testid="store-suppliers-link">
                        Suppliers
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Sheet day nav">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Sheet day</p>
                    <p class="lp-display" style="font-size: clamp(17px, 2vw, 20px); font-weight: 600; color: var(--color-ink); letter-spacing: -0.005em; line-height: 1.15; margin: 4px 0 0;">
                        {{ $sheetDate->format('D, j M') }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub" style="margin-bottom: 8px;">
                        {{ $sheetDate->format('Y') }}
                    </p>
                    <div class="lp-rates-month-nav" style="justify-content: flex-end;">
                        <button type="button" wire:click="shiftDay(-1)"
                                class="lp-rates-month-btn" data-testid="store-day-prev"
                                aria-label="Previous day">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M7.5 3L4.5 6l3 3"/></svg>
                        </button>
                        <button type="button" wire:click="goToToday"
                                class="lp-rates-month-label" data-testid="store-day-today"
                                style="background: transparent; border: 0; cursor: pointer;">
                            Today
                        </button>
                        <button type="button" wire:click="shiftDay(1)"
                                class="lp-rates-month-btn" data-testid="store-day-next"
                                aria-label="Next day">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4.5 3l3 3-3 3"/></svg>
                        </button>
                    </div>
                </div>
            </aside>
        </div>
    </section>
    {{-- ── Pending outlet requests, keeper's inbox ─────────── --}}
    @if ($pendingRequests->isNotEmpty())
        <section class="lp-dash-card" data-testid="store-pending-requests"
                 style="border: 2px solid #F59E0B;">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">
                    Pending requests
                    <span class="lp-mono text-[11px]"
                          style="margin-left: 8px; padding: 2px 8px; border-radius: 999px;
                                 background: #FEF3C7; color: #92400E;">
                        {{ $pendingRequests->count() }}
                    </span>
                </span>
                <span class="lp-dash-card-meta">Outlets are waiting on your approval</span>
            </div>

            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Filed</th>
                            <th>Outlet</th>
                            <th>Product</th>
                            <th style="text-align: right;">Asked</th>
                            <th>Note</th>
                            <th style="text-align: right;">Approve qty</th>
                            <th>Resolve note (optional)</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingRequests as $req)
                            <tr data-testid="store-request-row-{{ $req->id }}">
                                <td class="lp-mono text-[11px]" style="color: var(--color-ink-soft); white-space: nowrap;">
                                    {{ $req->created_at?->format('M j · g:i A') }}
                                </td>
                                <td style="font-weight: 500;">{{ $req->outlet?->name ?? '-' }}</td>
                                <td>
                                    {{ $req->product?->name ?? '-' }}
                                    @if ($req->requestedBy)
                                        <div class="text-[11px]" style="color: var(--color-ink-mute);">
                                            by {{ $req->requestedBy->name }}
                                        </div>
                                    @endif
                                </td>
                                <td class="lp-mono" style="text-align: right; font-weight: 600;">{{ $req->quantity }}</td>
                                <td class="text-[12px]" style="color: var(--color-ink-mute);">
                                    {{ $req->requested_note ?? '-' }}
                                </td>
                                <td style="text-align: right; min-width: 90px;">
                                    <input type="number" min="1" step="1"
                                           wire:model.defer="requestDrafts.{{ $req->id }}.approved_qty"
                                           data-testid="request-approved-qty-{{ $req->id }}"
                                           class="lp-input lp-input--compact"
                                           style="width: 70px; text-align: right;">
                                </td>
                                <td style="min-width: 180px;">
                                    <input type="text"
                                           wire:model.defer="requestDrafts.{{ $req->id }}.note"
                                           data-testid="request-note-{{ $req->id }}"
                                           class="lp-input lp-input--compact"
                                           placeholder="e.g. Only 8 in stock today"
                                           style="width: 100%;">
                                    @error('requestDrafts.'.$req->id)
                                        <div style="font-size: 11px; color: #B85C42; margin-top: 4px;">{{ $message }}</div>
                                    @enderror
                                </td>
                                <td class="lp-mono text-[11px]" style="text-align: right; white-space: nowrap;">
                                    <button type="button" wire:click="approveRequest({{ $req->id }})"
                                            data-testid="approve-request-{{ $req->id }}"
                                            class="lp-btn lp-btn--compact lp-btn--primary"
                                            style="padding: 3px 10px; font-size: 11.5px;">
                                        Approve
                                    </button>
                                    <button type="button" wire:click="rejectRequest({{ $req->id }})"
                                            data-testid="reject-request-{{ $req->id }}"
                                            style="margin-left: 4px; color: #B85C42; background: none;
                                                   border: none; padding: 3px 6px; cursor: pointer;
                                                   text-decoration: underline; font-size: 11.5px;">
                                        Reject
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($store === null)
        <section class="lp-dash-card" data-testid="store-empty" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'box',
                'title' => 'Central store not provisioned.',
                'sub'   => 'The two-tier stock model runs a per-property central store. This property doesn\'t have one yet, please contact support.',
            ])
        </section>
    @else
        {{-- ── Sheet header ───────────────────────────────────── --}}
        <section class="lp-dash-card" data-testid="store-sheet">
            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                <div>
                    <h2 class="text-[17px]" style="color: var(--color-ink); font-weight: 600;">
                        {{ $store->name }} &middot; {{ $sheetDate->format('D, j M Y') }}
                    </h2>
                    <p class="text-[12.5px]" style="color: var(--color-ink-mute);">
                        {{ $rows->count() }} tracked {{ Str::plural('product', $rows->count()) }}
                    </p>
                </div>
            </div>

            @if ($rows->isEmpty())
                <div style="padding: 32px 0; text-align: center; color: var(--color-ink-mute); font-size: 13.5px;">
                    No stock-tracked products in the store yet.
                </div>
            @else
                <div class="lp-table-wrap" style="overflow-x: auto;">
                    <table class="lp-table" data-testid="store-sheet-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">S/N</th>
                                <th>Description of item</th>
                                <th class="text-right">Opening</th>
                                <th class="text-right">Supply</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">Consumption</th>
                                <th class="text-right">Price</th>
                                <th class="text-right">Total (&#8358;)</th>
                                <th class="text-right">Balance</th>
                                <th>Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $hasCount = $row['physical_qty'] !== null;
                                    $balanceValue = $hasCount ? $row['physical_qty'] : $row['expected_balance'];
                                    $autoRemark = $row['remark'] ?? 'balanced';
                                @endphp
                                <tr data-testid="store-row-{{ $row['product_id'] }}"
                                    x-data="{ editing: false }">
                                    <td>{{ $row['sn'] }}</td>
                                    <td>
                                        <div style="font-weight: 500;">{{ $row['name'] }}</div>
                                        @if ($row['sku'])
                                            <div class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                                {{ $row['sku'] }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($row['opening']) }}</td>
                                    <td class="text-right">{{ $row['supply_in'] > 0 ? number_format($row['supply_in']) : '-' }}</td>
                                    <td class="text-right">{{ number_format($row['total']) }}</td>
                                    <td class="text-right">{{ $row['consumption'] > 0 ? number_format($row['consumption']) : '-' }}</td>
                                    <td class="text-right">&#8358;{{ number_format((int) $row['price']) }}</td>
                                    <td class="text-right">
                                        {{ $row['total_naira'] > 0
                                            ? '₦'.number_format((int) $row['total_naira'])
                                            : '-' }}
                                    </td>
                                    {{-- Balance: auto-derived from opening + supply − consumption
                                         by default. If the keeper has locked a physical count,
                                         show that (bold) with the expected value as a chip so
                                         variance stays legible at a glance. --}}
                                    <td class="text-right lp-mono" style="min-width: 90px; font-weight: 600;"
                                        x-show="!editing"
                                        data-testid="store-balance-cell-{{ $row['product_id'] }}">
                                        {{ number_format($balanceValue) }}
                                        @if ($hasCount)
                                            <div class="text-[10.5px]"
                                                 style="color: var(--color-ink-mute); font-weight: 400;">
                                                expected {{ number_format($row['expected_balance']) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-right" style="min-width: 90px;" x-show="editing" x-cloak>
                                        <input type="number" min="0" step="1"
                                               wire:model.defer="counts.{{ $row['product_id'] }}.physical_qty"
                                               placeholder="{{ $row['expected_balance'] }}"
                                               class="lp-input lp-input--compact text-right"
                                               data-testid="store-count-input-{{ $row['product_id'] }}"
                                               style="width: 80px;">
                                    </td>
                                    {{-- Remark: always auto-derived. "balanced" when there's no
                                         count or variance is zero; "N flat" or "N over" when
                                         a physical count has been locked and diverges. --}}
                                    <td style="min-width: 180px;">
                                        <div class="flex items-center gap-2" x-show="!editing">
                                            @php
                                                $hasVariance = $hasCount && $row['variance'] !== 0;
                                                // When variance is present, wrap the remark in a
                                                // link to the audit log filtered to (product, day).
                                                // Operators trace where the missing / extra units
                                                // went, receipts, issuances, adjustments, and who
                                                // did what — without leaving the store screen for a
                                                // manual search.
                                                $traceUrl = $hasVariance
                                                    ? route('tenant.inventory.audit-log', [
                                                        'product' => $row['product_id'],
                                                        'from' => $sheetDate->toDateString(),
                                                        'to' => $sheetDate->toDateString(),
                                                    ])
                                                    : null;
                                            @endphp
                                            @if ($traceUrl !== null)
                                                <a href="{{ $traceUrl }}" wire:navigate
                                                   class="lp-mono text-[12px]"
                                                   data-testid="store-remark-trace-{{ $row['product_id'] }}"
                                                   style="color: var(--color-ink); text-decoration: underline;">
                                                    {{ $autoRemark }}
                                                    <span aria-hidden="true" style="font-size: 10px; color: var(--color-ink-mute);">· trace</span>
                                                </a>
                                            @else
                                                <span class="lp-mono text-[12px]"
                                                      style="color: var(--color-ink-mute);"
                                                      data-testid="store-remark-cell-{{ $row['product_id'] }}">
                                                    {{ $hasCount ? $autoRemark : 'auto · '.$autoRemark }}
                                                </span>
                                            @endif
                                            <button type="button"
                                                    x-on:click="editing = true"
                                                    class="text-[11px]"
                                                    data-testid="store-count-edit-{{ $row['product_id'] }}"
                                                    style="color: var(--color-ink-mute); background: none; border: 0; padding: 2px 4px; cursor: pointer; text-decoration: underline;">
                                                {{ $hasCount ? 'Re-count' : 'Record count' }}
                                            </button>
                                        </div>
                                        <div class="flex items-center gap-1" x-show="editing" x-cloak>
                                            <button type="button"
                                                    x-on:click="editing = false"
                                                    class="text-[11px]"
                                                    style="color: var(--color-ink-mute); background: none; border: 0; padding: 4px 6px; cursor: pointer;">
                                                Cancel
                                            </button>
                                            <button type="button"
                                                    wire:click="saveCount({{ $row['product_id'] }})"
                                                    x-on:click="editing = false"
                                                    class="lp-btn lp-btn--compact lp-btn--primary"
                                                    data-testid="store-count-save-{{ $row['product_id'] }}">
                                                Save count
                                            </button>
                                        </div>
                                        @error('counts.'.$row['product_id'])
                                            <div class="lp-field-error mt-1">{{ $message }}</div>
                                        @enderror
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 text-[12px]" style="color: var(--color-ink-mute);">
                    Balance and remark auto-calculate from opening + supply &minus; consumption.
                    Hit <strong>Record count</strong> on any row when you physically count and
                    want to lock the day&rsquo;s number, the remark then shows how far off the
                    count was. When a variance shows up, tap it to trace every movement for
                    that SKU on this day.
                </div>
            @endif
        </section>

        {{-- ── Consumption history for the selected date ────────── --}}
        <section class="lp-dash-card" data-testid="store-consumption-history">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">
                    Consumption history &middot;
                    <span style="font-weight: 400;">{{ $sheetDate->format('D, j M Y') }}</span>
                </span>
                <span class="lp-dash-card-meta">
                    {{ $consumptionHistory->count() }}
                    {{ Str::plural('issuance', $consumptionHistory->count()) }}
                    &middot; scrub the date above to jump backward / forward
                </span>
            </div>

            @if ($consumptionHistory->isEmpty())
                <p class="text-[13.5px]" style="color: var(--color-ink-mute);"
                   data-testid="store-consumption-empty">
                    Nothing left the store on this day.
                </p>
            @else
                <div class="lp-table-wrap" style="overflow-x: auto;">
                    <table class="lp-table" data-testid="store-consumption-table">
                        <thead>
                            <tr>
                                <th style="width: 78px;">Time</th>
                                <th>Product</th>
                                <th>Sent to outlet</th>
                                <th class="text-right" style="width: 90px;">Quantity</th>
                                <th>Issued by</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($consumptionHistory as $row)
                                <tr data-testid="store-consumption-row-{{ $row['id'] }}">
                                    <td class="lp-mono text-[12px]" style="color: var(--color-ink-soft); white-space: nowrap;">
                                        {{ $row['occurred_at']?->format('g:i A') ?? '-' }}
                                    </td>
                                    <td>
                                        <div style="font-weight: 500; color: var(--color-ink);">
                                            {{ $row['product_name'] }}
                                        </div>
                                        @if ($row['product_sku'])
                                            <div class="text-[11px]" style="color: var(--color-ink-mute);">
                                                {{ $row['product_sku'] }}
                                            </div>
                                        @endif
                                    </td>
                                    <td style="color: var(--color-ink);">{{ $row['outlet_name'] }}</td>
                                    <td class="text-right lp-mono" style="font-weight: 600;">
                                        {{ number_format($row['quantity']) }}
                                    </td>
                                    <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                        {{ $row['operator_name'] ?? '-' }}
                                    </td>
                                    <td class="text-[12px]" style="color: var(--color-ink-mute);">
                                        {{ $row['note'] ?? '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
