<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <span class="lp-eyebrow-strip">Housekeeping</span>
                <h1 class="lp-display mt-2 text-[clamp(26px,3.4vw,36px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    {{ $outlet->name }}
                </h1>
                <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute);">
                    On-hand stock right now. Rows in amber are at or below their
                    low-stock threshold. Amenities auto-deduct when housekeeping
                    marks a room clean, no manual step needed for normal turnovers.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($usesCentralStore)
                    <button type="button"
                            wire:click="openRequest"
                            class="lp-btn lp-btn--primary"
                            data-testid="supplies-request-btn">
                        + Request from store
                    </button>
                @elseif ($canReceiveStock)
                    {{-- Per-department mode: no central store to pull
                         from, so restocking is a direct supplier delivery
                         on the outlet page. Jump straight there for
                         Manager / Inventory clerk / Stock keeper. --}}
                    <a href="{{ route('tenant.inventory.outlets.receive', $outlet) }}"
                       wire:navigate
                       class="lp-btn lp-btn--primary"
                       data-testid="supplies-receive-btn">
                        Log a delivery
                    </a>
                @endif
                {{-- Log turnover is a backfill escape hatch, hidden behind a
                     smaller ghost button. The primary path is auto-deduct
                     on mark-clean; this button covers "we cleaned rooms
                     without opening an inspection". --}}
                <button type="button"
                        wire:click="openTurnover"
                        class="lp-btn"
                        data-testid="supplies-turnover-btn"
                        title="Manually deduct amenities for cleaning done outside the housekeeping module"
                        style="opacity: 0.85;">
                    Log turnover (backfill)
                </button>
            </div>
        </div>
        @if (! $usesCentralStore && ! $canReceiveStock)
            <p class="mt-2 text-[12.5px]"
               style="color: var(--color-ink-mute); max-width: 60ch;"
               data-testid="supplies-restock-hint">
                Out of stock? This property runs on per-department inventory,
                so restocking is a direct supplier delivery, ask a Manager
                or Inventory clerk to log it from Inventory.
            </p>
        @endif
    </div>

    @if (session('status'))
        <div class="lp-dash-card" style="border-left: 4px solid var(--color-accent-deep);">
            <p class="text-[13.5px]" style="color: var(--color-ink);">{{ session('status') }}</p>
        </div>
    @endif

    {{-- ── Current stock, low-stock highlighted ────────────────── --}}
    <section class="lp-dash-card" data-testid="supplies-sheet">
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
            <div>
                <h2 class="text-[17px]" style="color: var(--color-ink); font-weight: 600;">
                    On-hand · {{ $today->format('D, j M Y') }}
                </h2>
                <p class="text-[12.5px]" style="color: var(--color-ink-mute);">
                    {{ $rows->count() }} tracked {{ Str::plural('product', $rows->count()) }}
                </p>
            </div>
        </div>

        @if ($rows->isEmpty())
            <div style="padding: 32px 0; text-align: center; color: var(--color-ink-mute); font-size: 13.5px;">
                Nothing on this trolley yet.
                @if ($usesCentralStore)
                    Hit <strong>+ Request from store</strong> to bring the first item in.
                @endif
            </div>
        @else
            <div class="lp-table-wrap" style="overflow-x: auto;">
                <table class="lp-table" data-testid="supplies-sheet-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">S/N</th>
                            <th>Item</th>
                            <th class="text-right">Clean stock</th>
                            <th class="text-right">In use</th>
                            <th class="text-right">At laundry</th>
                            <th class="text-right">Per clean</th>
                            <th>Status</th>
                            <th>Laundry</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $product = \App\Models\Product::query()->find($row['product_id']);
                                $onHand = $product?->quantity_on_hand ?? $row['expected_balance'] ?? 0;
                                $threshold = $product?->low_stock_threshold ?? 0;
                                $isLow = $threshold > 0 && $onHand <= $threshold;
                                $isRotational = (bool) ($product?->is_rotational ?? false);
                                $inUse = (int) ($product?->qty_in_use ?? 0);
                                $atLaundry = (int) ($product?->qty_at_laundry ?? 0);
                                $usage = (int) ($product?->usage_per_clean ?? 0);
                            @endphp
                            @php
                                // "≈ N more rooms" projection — clean stock
                                // divided by per-clean usage, rounded down.
                                // Skipped when usage is 0 (product isn't part
                                // of the turnover routine).
                                $roomsLeft = $usage > 0 ? intdiv($onHand, $usage) : null;
                                $flipConfirm = $isRotational
                                    ? 'Turn this back to consumable? Only works if in-use + at-laundry are 0.'
                                    : 'Mark this rotational? Bedsheets/towels track clean stock, in use, and at laundry buckets.';
                            @endphp
                            <tr data-testid="supplies-row-{{ $row['product_id'] }}"
                                @if ($isLow) style="background: rgba(248, 191, 137, 0.16);" @endif>
                                <td>{{ $row['sn'] }}</td>
                                <td>
                                    <div style="font-weight: 500;">{{ $row['name'] }}</div>
                                    <div class="text-[11px]" style="color: var(--color-ink-mute);">
                                        @if ($row['sku']) {{ $row['sku'] }} · @endif
                                        {{ $isRotational ? 'rotational' : 'consumable' }}
                                        @if ($canManagePrices)
                                            ·
                                            <button type="button"
                                                    wire:click="toggleRotational({{ $row['product_id'] }})"
                                                    wire:confirm="{{ $flipConfirm }}"
                                                    data-testid="supplies-rotational-toggle-{{ $row['product_id'] }}"
                                                    style="background: none; border: 0; padding: 0; color: var(--color-ink-mute); text-decoration: underline; cursor: pointer; font-size: 11px;">
                                                {{ $isRotational ? 'make consumable' : 'make rotational' }}
                                            </button>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-right lp-mono" style="font-weight: 600;">
                                    {{ number_format($onHand) }}
                                    <div class="text-[10.5px]" style="color: var(--color-ink-mute); font-weight: 400;">
                                        threshold {{ $threshold > 0 ? number_format($threshold) : '—' }}
                                    </div>
                                    @if ($roomsLeft !== null)
                                        <div class="text-[10.5px]"
                                             style="color: {{ $roomsLeft <= 2 ? '#B85C42' : 'var(--color-ink-mute)' }}; font-weight: 400;"
                                             data-testid="supplies-rooms-left-{{ $row['product_id'] }}">
                                            ≈ {{ $roomsLeft }} more {{ $roomsLeft === 1 ? 'room' : 'rooms' }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-right lp-mono" style="color: {{ $isRotational ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};">
                                    {{ $isRotational ? number_format($inUse) : '—' }}
                                </td>
                                <td class="text-right lp-mono" style="color: {{ $isRotational ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};">
                                    {{ $isRotational ? number_format($atLaundry) : '—' }}
                                </td>
                                <td class="text-right lp-mono">
                                    @if ($canManagePrices)
                                        <input type="number" min="0" max="999" step="1"
                                               value="{{ $usage }}"
                                               wire:change="setUsagePerClean({{ $row['product_id'] }}, $event.target.value)"
                                               data-testid="supplies-usage-{{ $row['product_id'] }}"
                                               class="lp-input lp-input--compact text-right"
                                               style="width: 60px;">
                                    @else
                                        {{ $usage > 0 ? number_format($usage) : '—' }}
                                    @endif
                                    <div class="text-[10.5px]" style="color: var(--color-ink-mute); font-weight: 400;">
                                        {{ $usage === 1 ? 'unit / room' : 'units / room' }}
                                    </div>
                                </td>
                                <td>
                                    @if ($isLow)
                                        <span class="lp-mono text-[10.5px]"
                                              style="padding: 3px 8px; border-radius: 999px; background: #FEF3C7; color: #92400E; letter-spacing: 0.06em; text-transform: uppercase;"
                                              data-testid="supplies-low-{{ $row['product_id'] }}">
                                            low
                                        </span>
                                    @else
                                        <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute);">
                                            ok
                                        </span>
                                    @endif

                                    @if ($usesCentralStore && $pendingByStoreProduct->has($row['product_id']))
                                        <span class="lp-mono text-[10.5px]"
                                              style="margin-left: 6px; padding: 3px 8px; border-radius: 999px; background: #E0E7FF; color: #3730A3; letter-spacing: 0.06em; text-transform: uppercase;">
                                            requested
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if ($isRotational)
                                        {{-- Two separate qty fields so a misclick
                                             between Send and Receive can't record
                                             the wrong movement. Each field is
                                             beside its own button. --}}
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <div class="flex items-center gap-1">
                                                <input type="number" min="1" step="1"
                                                       wire:model.defer="laundryDrafts.{{ $row['product_id'] }}.send"
                                                       data-testid="supplies-send-qty-{{ $row['product_id'] }}"
                                                       class="lp-input lp-input--compact text-right"
                                                       placeholder="qty"
                                                       aria-label="Quantity to send to laundry"
                                                       style="width: 52px;">
                                                <button type="button"
                                                        wire:click="sendToLaundry({{ $row['product_id'] }})"
                                                        class="lp-btn lp-btn--compact"
                                                        data-testid="supplies-send-laundry-{{ $row['product_id'] }}"
                                                        title="Move from In use to At laundry"
                                                        style="font-size: 11px; padding: 3px 8px;">
                                                    Send
                                                </button>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <input type="number" min="1" step="1"
                                                       wire:model.defer="laundryDrafts.{{ $row['product_id'] }}.receive"
                                                       data-testid="supplies-receive-qty-{{ $row['product_id'] }}"
                                                       class="lp-input lp-input--compact text-right"
                                                       placeholder="qty"
                                                       aria-label="Quantity received from laundry"
                                                       style="width: 52px;">
                                                <button type="button"
                                                        wire:click="receiveFromLaundry({{ $row['product_id'] }})"
                                                        class="lp-btn lp-btn--compact lp-btn--primary"
                                                        data-testid="supplies-receive-laundry-{{ $row['product_id'] }}"
                                                        title="Move from At laundry to Clean stock"
                                                        style="font-size: 11px; padding: 3px 8px;">
                                                    Receive
                                                </button>
                                            </div>
                                        </div>
                                        @error('laundryDrafts.'.$row['product_id'])
                                            <div class="lp-field-error mt-1" style="font-size: 11px;">{{ $message }}</div>
                                        @enderror
                                    @else
                                        <span class="text-[11.5px]" style="color: var(--color-ink-mute);">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-[12px]" style="color: var(--color-ink-mute);">
                Rotational items (bedsheets, towels) track three buckets, clean stock on the
                shelf, in use in rooms, and at the laundry. Consumables (soaps, amenities)
                only use the clean-stock column. <strong>Per clean</strong> is how much a
                room turnover deducts of this SKU, applied automatically when housekeeping
                marks a room clean. <strong>Send</strong> moves linens from in-use to the
                laundry, <strong>Receive</strong> brings the clean bundle back to the
                shelf.
            </p>
        @endif
    </section>

    {{-- ── Pending requests from this outlet ────────────────────── --}}
    @if ($pendingRequests->isNotEmpty())
        <section class="lp-dash-card" data-testid="supplies-pending">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">
                    Pending requests
                    <span class="lp-mono text-[11px]"
                          style="margin-left: 8px; padding: 2px 8px; border-radius: 999px;
                                 background: #FEF3C7; color: #92400E;">
                        {{ $pendingRequests->count() }}
                    </span>
                </span>
                <span class="lp-dash-card-meta">Waiting on the store keeper</span>
            </div>
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Filed</th>
                            <th>Product</th>
                            <th class="text-right">Qty</th>
                            <th>Requested by</th>
                            <th>Note</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingRequests as $req)
                            <tr>
                                <td class="lp-mono text-[11px]" style="color: var(--color-ink-soft); white-space: nowrap;">
                                    {{ $req->created_at?->format('M j · g:i A') }}
                                </td>
                                <td style="font-weight: 500;">{{ $req->product?->name ?? '—' }}</td>
                                <td class="text-right lp-mono" style="font-weight: 600;">
                                    {{ number_format($req->quantity) }}
                                </td>
                                <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                    {{ $req->requestedBy?->name ?? '—' }}
                                </td>
                                <td class="text-[12px]" style="color: var(--color-ink-mute);">
                                    {{ $req->requested_note ?? '—' }}
                                </td>
                                <td class="text-right">
                                    <button type="button"
                                            wire:click="cancelRequest({{ $req->id }})"
                                            class="lp-btn lp-btn--compact"
                                            data-testid="supplies-cancel-request-{{ $req->id }}">
                                        Cancel
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ── Recent movements, small audit trail ──────────────────── --}}
    <section class="lp-dash-card" data-testid="supplies-movements">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Recent movements</span>
            <span class="lp-dash-card-meta">last 15</span>
        </div>
        @if ($recentMovements->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                No movements yet.
            </p>
        @else
            <div class="lp-table-wrap" style="overflow-x: auto;">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Product</th>
                            <th>Reason</th>
                            <th class="text-right">Delta</th>
                            <th class="text-right">After</th>
                            <th>Operator</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentMovements as $m)
                            <tr>
                                <td class="lp-mono text-[11px]" style="color: var(--color-ink-soft); white-space: nowrap;">
                                    {{ $m->occurred_at?->format('M j · g:i A') }}
                                </td>
                                <td>{{ $m->product?->name ?? '—' }}</td>
                                <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                    {{ $m->reason?->label() ?? '—' }}
                                </td>
                                <td class="text-right lp-mono" style="font-weight: 600; color: {{ $m->delta >= 0 ? 'var(--color-ink)' : '#B85C42' }};">
                                    {{ $m->delta >= 0 ? '+' : '−' }}{{ number_format(abs((int) $m->delta)) }}
                                </td>
                                <td class="text-right lp-mono">{{ number_format((int) $m->quantity_after) }}</td>
                                <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                    {{ $m->operator?->name ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- ── Turnover modal ────────────────────────────────────────── --}}
    {{-- Teleported to <body> so ancestor transforms / overflow / stacking
         context can't clip or de-fix the panel. `x-show` binds to the
         Livewire prop via $wire, so opening + closing are instant on
         the client. Backdrop click / Escape / X / Cancel all close. --}}
    <template x-teleport="body">
    <div class="lp-page" style="display: contents;">
    <div x-show="$wire.turnoverModalOpen"
         x-cloak
         @click.self="$wire.cancelTurnover()"
         @keydown.escape.window="if ($wire.turnoverModalOpen) $wire.cancelTurnover()"
         class="lp-modal-backdrop"
         data-testid="supplies-turnover-modal"
         style="z-index: 200;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);">
        <div @click.stop
             style="width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto;
                    background: var(--color-card);
                    border-radius: 16px;
                    border: 1px solid var(--color-line-soft);
                    box-shadow: 0 24px 60px rgba(0,0,0,0.22);
                    padding: 24px 26px 22px;">
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid var(--color-line);">
                <div>
                    <div class="lp-mono" style="font-size: 10.5px; color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                        Log turnover
                    </div>
                    <h2 style="font-family: var(--font-body); font-size: 18px; font-weight: 600; color: var(--color-ink); margin: 4px 0 0;">
                        Manual backfill
                    </h2>
                    <p style="font-size: 12.5px; color: var(--color-ink-mute); margin: 4px 0 0;">
                        Applies each product&rsquo;s <em>per clean</em> rate × the room count.
                    </p>
                </div>
                <button type="button"
                        @click="$wire.cancelTurnover()"
                        aria-label="Close"
                        style="background: none; border: 0; font-size: 24px; line-height: 1; color: var(--color-ink-mute); cursor: pointer; padding: 2px 6px;">
                    ×
                </button>
            </div>
            <div style="display: grid; gap: 12px;">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="lp-form-label" for="turnover-rooms">Rooms cleaned</label>
                        <input id="turnover-rooms" type="number" min="1" step="1"
                               wire:model.defer="turnoverRooms"
                               data-testid="supplies-turnover-rooms"
                               class="lp-input">
                        @error('turnoverRooms')
                            <div class="lp-field-error mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                    <div>
                        <label class="lp-form-label" for="turnover-note">Room refs (optional)</label>
                        <input id="turnover-note" type="text"
                               wire:model.defer="turnoverNote"
                               placeholder="e.g. Rm 101, 202"
                               data-testid="supplies-turnover-note"
                               class="lp-input">
                    </div>
                </div>
                <div class="flex items-center gap-2" style="margin-top: 4px;">
                    <button type="button" wire:click="cancelTurnover" class="lp-btn">
                        Cancel
                    </button>
                    <button type="button" wire:click="logTurnover"
                            class="lp-btn lp-btn--primary"
                            data-testid="supplies-turnover-submit">
                        Log &amp; deduct
                    </button>
                </div>
            </div>
        </div>
    </div>
    </div>
    </template>

    {{-- ── Request modal ─────────────────────────────────────────── --}}
    @if ($usesCentralStore)
    <template x-teleport="body">
    <div class="lp-page" style="display: contents;">
    <div x-show="$wire.requestModalOpen"
         x-cloak
         @click.self="$wire.cancelRequestModal()"
         @keydown.escape.window="if ($wire.requestModalOpen) $wire.cancelRequestModal()"
         class="lp-modal-backdrop"
         data-testid="supplies-request-modal"
         style="z-index: 200;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);">
        <div @click.stop
             style="width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto;
                    background: var(--color-card);
                    border-radius: 16px;
                    border: 1px solid var(--color-line-soft);
                    box-shadow: 0 24px 60px rgba(0,0,0,0.22);
                    padding: 24px 26px 22px;">
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid var(--color-line);">
                <div>
                    <div class="lp-mono" style="font-size: 10.5px; color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                        Request from store
                    </div>
                    <h2 style="font-family: var(--font-body); font-size: 18px; font-weight: 600; color: var(--color-ink); margin: 4px 0 0;">
                        {{ $outlet->name }}
                    </h2>
                    <p style="font-size: 12.5px; color: var(--color-ink-mute); margin: 4px 0 0;">
                        Store keeper approves &amp; issues into this outlet.
                    </p>
                </div>
                <button type="button"
                        @click="$wire.cancelRequestModal()"
                        aria-label="Close"
                        style="background: none; border: 0; font-size: 24px; line-height: 1; color: var(--color-ink-mute); cursor: pointer; padding: 2px 6px;">
                    ×
                </button>
            </div>
            <div style="display: grid; gap: 12px;">
                <div>
                    <label class="lp-form-label" for="req-product">Store product</label>
                    <select id="req-product"
                            wire:model.defer="requestStoreProductId"
                            data-testid="supplies-request-product"
                            class="lp-input">
                        <option value="">- pick a product from the store -</option>
                        @foreach ($storeProducts as $sp)
                            <option value="{{ $sp->id }}">
                                {{ $sp->name }} · store has {{ (int) $sp->quantity_on_hand }}
                            </option>
                        @endforeach
                    </select>
                    @error('requestStoreProductId')
                        <div class="lp-field-error mt-1">{{ $message }}</div>
                    @enderror
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="lp-form-label" for="req-qty">Quantity</label>
                        <input id="req-qty" type="number" min="1" step="1"
                               wire:model.defer="requestQuantity"
                               data-testid="supplies-request-qty"
                               class="lp-input">
                        @error('requestQuantity')
                            <div class="lp-field-error mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                    <div>
                        <label class="lp-form-label" for="req-note">Note (optional)</label>
                        <input id="req-note" type="text"
                               wire:model.defer="requestNote"
                               placeholder="e.g. Turnover x8 this evening"
                               data-testid="supplies-request-note"
                               class="lp-input">
                    </div>
                </div>
                <div class="flex items-center gap-2" style="margin-top: 4px;">
                    <button type="button" wire:click="cancelRequestModal" class="lp-btn">
                        Cancel
                    </button>
                    <button type="button" wire:click="submitRequest"
                            class="lp-btn lp-btn--primary"
                            data-testid="supplies-request-submit">
                        Send request
                    </button>
                </div>
            </div>
        </div>
    </div>
    </div>
    </template>
    @endif

</div>
