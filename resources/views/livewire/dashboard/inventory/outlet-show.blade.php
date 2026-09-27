<div class="lp-dash">
    @include('_partials._back-button')

    {{-- ── Hero: outlet identity + today's cash-in ──────────
         Right side rolls up today's ledger: how much came
         through this outlet + tracked SKU count. The delete
         action stays behind the manager gate but as a smaller
         ghost pill so it doesn't sit next to the primary
         request/receive CTA. --}}
    @php
        $todayRevenue = (int) collect($rows)->sum(fn ($r) => (int) ($r['total_naira'] ?? 0));
        $trackedCount = is_countable($rows) ? count($rows) : $rows->count();
        $pendingCount = is_countable($pendingRequests) ? count($pendingRequests) : $pendingRequests->count();
        $isManager = (auth('web')->user()?->role ?? null) === \App\Enums\OperatorRole::Manager;
    @endphp
    <section class="lp-res-idx-hero mt-3" data-testid="outlet-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">{{ $outlet->type->label() }}</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    {{ $outlet->name }}
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Daily sheet, stock movements, and everything else that flows through this outlet.
                    @if ($outlet->tax_rate > 0)
                        <span class="lp-mono" style="color: var(--color-ink-mute); margin-left: 6px;">
                            {{ rtrim(rtrim((string) $outlet->tax_rate, '0'), '.') }}% tax
                        </span>
                    @endif
                </p>
                <div class="lp-res-idx-hero-actions">
                    @if ($usesCentralStore)
                        <button type="button" wire:click="openRequest"
                                data-testid="request-from-store-btn"
                                class="lp-res-action lp-res-action--primary">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M2 8h10M8 4l4 4-4 4"/></svg>
                            Request from store
                        </button>
                    @else
                        <a href="{{ route('tenant.inventory.outlets.receive', $outlet) }}" wire:navigate
                           data-testid="receive-stock-direct-btn"
                           class="lp-res-action lp-res-action--primary">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                            Receive stock
                        </a>
                        <a href="{{ route('tenant.inventory.products.create', $outlet) }}" wire:navigate
                           data-testid="add-product-direct-btn"
                           class="lp-res-action">
                            Add product
                        </a>
                    @endif
                    <a href="{{ route('tenant.inventory.outlets.sheet', $outlet) }}" wire:navigate
                       data-testid="outlet-sheet-link"
                       class="lp-res-action">
                        Full book
                    </a>
                    <a href="{{ route('tenant.inventory.outlets.edit', $outlet) }}" wire:navigate
                       class="lp-res-action lp-res-action--ghost">
                        Edit outlet
                    </a>
                    @if ($isManager)
                        {{-- Manager-only danger action. Cascade-deletes
                             products / stock movements / sales via FK. --}}
                        <button type="button"
                                wire:click="deleteOutlet"
                                wire:confirm="Delete outlet &quot;{{ $outlet->name }}&quot;? This wipes every product, movement, and POS sale on it. Cannot be undone."
                                data-testid="delete-outlet-btn"
                                class="lp-res-action lp-res-action--ghost"
                                style="color: #B85C42; border-color: rgba(184, 92, 66, 0.35);">
                            <span wire:loading.remove wire:target="deleteOutlet">Delete outlet</span>
                            <span wire:loading wire:target="deleteOutlet">Deleting…</span>
                        </button>
                    @endif
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Today at this outlet">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Today · sold value</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ $todayRevenue > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};"
                       data-testid="outlet-hero-today-revenue">
                        ₦{{ number_format($todayRevenue) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        {{ number_format($trackedCount) }} tracked {{ Str::plural('SKU', $trackedCount) }}
                        @if ($usesCentralStore && $pendingCount > 0)
                            · <span style="color: #A8650A;">{{ $pendingCount }} pending {{ Str::plural('request', $pendingCount) }}</span>
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>
    {{-- ── Daily sheet (today), Balance & Remark are display-only,
         derived from the ledger. To add or top up a product, use
         + Request from store. --}}
    <section class="lp-dash-card" data-testid="outlet-daily-sheet">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">
                Daily sheet &middot;
                <span style="font-weight: 400;">{{ $today->format('D, j M Y') }}</span>
            </span>
            <span class="lp-dash-card-meta">
                {{ $rows->count() }} tracked · <a href="{{ route('tenant.inventory.outlets.sheet', $outlet) }}" wire:navigate style="color: var(--color-accent-deep); text-decoration: underline;">Full book &rarr;</a>
            </span>
        </div>

        @if ($rows->isEmpty())
            <p class="text-[13.5px]" data-testid="products-empty" style="color: var(--color-ink-mute);">
                No {{ mb_strtolower($outlet->type->productNoun(plural: true)) }} at this outlet yet.
                @if ($usesCentralStore)
                    Hit <strong>+ Request from store</strong> to bring the first {{ mb_strtolower($outlet->type->productNoun()) }} in.
                @else
                    Hit <strong>+ Add product</strong> to define your first {{ mb_strtolower($outlet->type->productNoun()) }}, then <strong>+ Receive stock</strong> to log deliveries.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="outlet-sheet-today">
                    <thead>
                        <tr>
                            <th style="width: 40px;">S/N</th>
                            <th>Description of item</th>
                            <th style="text-align: right;">Opening</th>
                            <th style="text-align: right;">Supply</th>
                            <th style="text-align: right;">Total</th>
                            <th style="text-align: right;">Sold</th>
                            <th style="text-align: right;">Price</th>
                            <th style="text-align: right;">Total (&#8358;)</th>
                            <th style="text-align: right;">Balance</th>
                            <th>Remark</th>
                            @if ((auth('web')->user()?->role ?? null) === \App\Enums\OperatorRole::Manager)
                                <th style="width: 60px;"></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-testid="product-row-{{ $row['product_id'] }}">
                                <td class="lp-mono text-[11.5px]" style="color: var(--color-ink-mute);">{{ $row['sn'] }}</td>
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $row['name'] }}
                                    @if ($row['sku'])
                                        <span class="lp-mono text-[10.5px]" style="display:block; color: var(--color-ink-mute);">SKU {{ $row['sku'] }}</span>
                                    @endif
                                </td>
                                <td style="text-align: right;" class="lp-mono">{{ number_format($row['opening']) }}</td>
                                <td style="text-align: right;" class="lp-mono">
                                    {{ $row['supply_in'] > 0 ? number_format($row['supply_in']) : '-' }}
                                </td>
                                <td style="text-align: right;" class="lp-mono" style="font-weight: 500;">{{ number_format($row['total']) }}</td>
                                <td style="text-align: right;" class="lp-mono">
                                    {{ $row['consumption'] > 0 ? number_format($row['consumption']) : '-' }}
                                </td>
                                <td style="text-align: right;"><span class="lp-table-amount">&#8358;{{ number_format((int) $row['price']) }}</span></td>
                                <td style="text-align: right;">
                                    {{ $row['total_naira'] > 0
                                        ? '₦'.number_format((int) $row['total_naira'])
                                        : '-' }}
                                </td>
                                <td style="text-align: right;" class="lp-mono" style="font-weight: 600;">
                                    {{ number_format($row['expected_balance']) }}
                                </td>
                                <td>
                                    @if ($row['remark'])
                                        <span class="lp-mono text-[11px]"
                                              style="padding: 2px 8px; border-radius: 999px;
                                                     background: {{ $row['variance'] === 0 ? '#DCFCE7' : '#FEF3C7' }};
                                                     color: {{ $row['variance'] === 0 ? '#166534' : '#92400E' }};">
                                            {{ $row['remark'] }}
                                        </span>
                                    @else
                                        <span style="color: var(--color-ink-mute); font-size: 12px;">-</span>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    @if ((auth('web')->user()?->role ?? null) === \App\Enums\OperatorRole::Manager)
                                        <button type="button"
                                                wire:click="deleteProduct({{ $row['product_id'] }})"
                                                wire:confirm="Delete &quot;{{ $row['name'] }}&quot;? Stock movements + open requests are wiped. Historical POS receipts survive with a NULL product reference. Cannot be undone."
                                                data-testid="delete-product-{{ $row['product_id'] }}"
                                                style="background: transparent; border: 0; cursor: pointer;
                                                       font-family: inherit; font-size: 11px;
                                                       color: #B85C42; text-decoration: underline;
                                                       text-underline-offset: 3px;">
                                            Delete
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @endif
    </section>

    {{-- ── Pending requests summary strip ─────────────────────── --}}
    @if ($pendingRequests->isNotEmpty())
        <section class="lp-dash-card" data-testid="pending-requests-strip">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Pending requests from this outlet</span>
                <span class="lp-dash-card-meta">{{ $pendingRequests->count() }} awaiting the store keeper</span>
            </div>
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Filed</th>
                            <th>Product</th>
                            <th style="text-align: right;">Qty</th>
                            <th>Note</th>
                            <th>Requested by</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingRequests as $req)
                            <tr data-testid="pending-request-{{ $req->id }}">
                                <td class="lp-mono text-[11px]" style="color: var(--color-ink-soft);">{{ $req->created_at?->format('M j · g:i A') }}</td>
                                <td style="color: var(--color-ink); font-weight: 500;">{{ $req->product?->name ?? '-' }}</td>
                                <td class="lp-mono" style="text-align: right; font-weight: 600;">{{ $req->quantity }}</td>
                                <td class="text-[12px]" style="color: var(--color-ink-mute);">{{ $req->requested_note ?? '-' }}</td>
                                <td class="text-[12px]" style="color: var(--color-ink-soft);">{{ $req->requestedBy?->name ?? '-' }}</td>
                                <td class="lp-mono text-[11px]" style="text-align: right;">
                                    <button type="button" wire:click="cancelRequest({{ $req->id }})"
                                            data-testid="cancel-request-{{ $req->id }}"
                                            style="color: #B85C42; text-decoration: underline; background: none; border: none; padding: 0; cursor: pointer;">
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

    {{-- ── Request-stock modal ────────────────────────────────── --}}
    {{-- Teleported to <body> so it floats over the outlet page instead
         of pushing content down. Backdrop click / Escape / × / Cancel
         all close via cancelRequestModal(). --}}
    <template x-teleport="body">
    <div class="lp-page" style="display: contents;">
    <div x-show="$wire.requestModalOpen"
         x-cloak
         @click.self="$wire.cancelRequestModal()"
         @keydown.escape.window="if ($wire.requestModalOpen) $wire.cancelRequestModal()"
         data-testid="request-panel"
         class="lp-modal-backdrop"
         style="z-index: 200;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);">
        <div @click.stop
             data-testid="request-modal-card"
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
                        data-testid="request-modal-close"
                        style="background: none; border: 0; font-size: 24px; line-height: 1; color: var(--color-ink-mute); cursor: pointer; padding: 2px 6px;">
                    ×
                </button>
            </div>

            @if ($storeProducts->isEmpty())
                <p class="text-[13.5px]" style="color: var(--color-ink-mute);" data-testid="request-empty-store">
                    Central store has no products yet. A manager needs to add products to the
                    store before outlets can request them.
                </p>
                <div class="flex justify-end mt-3">
                    <button type="button" wire:click="cancelRequestModal" class="lp-btn">Close</button>
                </div>
            @else
                <form wire:submit="submitRequest" style="display: grid; gap: 14px;">
                    <div>
                        <label for="r-product" class="lp-form-label">Store product</label>
                        <select id="r-product" wire:model="requestStoreProductId"
                                data-testid="request-store-product" class="lp-input" required>
                            <option value="">- pick a product from the store -</option>
                            @foreach ($storeProducts as $sp)
                                <option value="{{ $sp->id }}">
                                    {{ $sp->name }} · store has {{ $sp->quantity_on_hand }}
                                </option>
                            @endforeach
                        </select>
                        @error('requestStoreProductId') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="r-qty" class="lp-form-label">Quantity</label>
                            <input id="r-qty" type="number" min="1" step="1" wire:model="requestQuantity" required
                                   data-testid="request-qty" class="lp-input" placeholder="How many do you need?">
                            @error('requestQuantity') <p class="lp-form-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="r-note" class="lp-form-label">Note (optional)</label>
                            <input id="r-note" type="text" wire:model="requestNote" maxlength="240"
                                   data-testid="request-note" class="lp-input" placeholder="e.g. Weekend anticipated demand">
                            @error('requestNote') <p class="lp-form-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="text-[11.5px]" style="color: var(--color-ink-mute);">
                        Store keeper reviews and either approves (optionally revising the quantity)
                        or rejects with a note. Approved requests auto-issue: your outlet gets the
                        product (auto-added if new) and today's Supply bumps by the approved quantity.
                    </p>
                    <div class="flex justify-end gap-3">
                        <button type="button" wire:click="cancelRequestModal" class="lp-btn">Cancel</button>
                        <button type="submit" class="lp-btn lp-btn--primary" data-testid="request-submit">Send request</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
    </div>
    </template>

    {{-- ── Recent stock movements audit ───────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Recent stock movements</span>
            <span class="lp-dash-card-meta">last 15</span>
        </div>
        @if ($recentMovements->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">No movements yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Product</th>
                            <th>Reason</th>
                            <th style="text-align: right;">Delta</th>
                            <th style="text-align: right;">After</th>
                            <th>Operator</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentMovements as $m)
                            <tr>
                                <td class="lp-mono text-[11px]" style="color: var(--color-ink-soft);">{{ $m->occurred_at?->format('M j · g:i A') }}</td>
                                <td>{{ $m->product?->name ?? '-' }}</td>
                                <td class="lp-mono text-[11px]" style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.06em;">{{ $m->reason->label() }}</td>
                                <td style="text-align: right;" class="lp-mono">
                                    <span style="color: {{ $m->delta > 0 ? 'var(--color-accent-deep)' : '#B85C42' }}; font-weight: 600;">
                                        {{ $m->delta > 0 ? '+' : '' }}{{ $m->delta }}
                                    </span>
                                </td>
                                <td style="text-align: right;" class="lp-mono">{{ $m->quantity_after }}</td>
                                <td class="text-[12px]" style="color: var(--color-ink-soft);">{{ $m->operator?->name ?? '-' }}</td>
                                <td class="text-[12px]" style="color: var(--color-ink-mute);">{{ $m->note }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
