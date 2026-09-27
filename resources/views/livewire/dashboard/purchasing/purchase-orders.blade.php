<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="flex flex-wrap items-start justify-between gap-3 mt-4">
            <div>
                <span class="lp-eyebrow-strip">Purchasing</span>
                <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    Purchase orders
                </h1>
                <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                    Draft, send, and receive orders from your suppliers. Received stock lands in the destination outlet.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('tenant.purchasing.suppliers.index') }}" wire:navigate class="lp-btn">Suppliers</a>
                <a href="{{ route('tenant.purchasing.orders.create') }}" wire:navigate class="lp-btn lp-btn--primary"
                   data-testid="po-new">+ New order</a>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="lp-flash-success">{{ session('status') }}</div>
    @endif

    {{-- ── PO pulse strip ───────────────────────────────────────── --}}
    @php
        $poTiles = [
            [
                'label' => 'Open orders', 'sub' => 'sent + partially received',
                'text'  => number_format($openCount),
                'dot'   => $openCount > 0 ? 'var(--color-accent-deep)' : '#4B7BB0',
                'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Drafts', 'sub' => 'not yet sent to supplier',
                'text'  => number_format($draftCount),
                'dot'   => $draftCount > 0 ? '#a36318' : '#7A6EA7',
                'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Received · 30d', 'sub' => 'landed deliveries',
                'text'  => '₦'.number_format($spend30d),
                'dot'   => 'var(--color-accent-deep)',
                'color' => 'var(--color-ink)',
            ],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-1 sm:grid-cols-3" data-testid="po-kpis">
        @foreach ($poTiles as $tile)
            <div class="lp-dash-card"
                 style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: {{ $tile['color'] }};">
                        {{ $tile['text'] }}
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

    {{-- ── Filters ──────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-2" data-testid="po-filters">
        <select wire:model.live="statusFilter" class="lp-input" style="font-size: 12.5px; padding: 6px 28px 6px 12px; width: auto;">
            <option value="">All statuses</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}">{{ $s->label() }}</option>
            @endforeach
        </select>
        <select wire:model.live="supplierFilter" class="lp-input" style="font-size: 12.5px; padding: 6px 28px 6px 12px; width: auto;">
            <option value="">All suppliers</option>
            @foreach ($suppliers as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="outletFilter" class="lp-input" style="font-size: 12.5px; padding: 6px 28px 6px 12px; width: auto;">
            <option value="">All outlets</option>
            @foreach ($outlets as $o)
                <option value="{{ $o->id }}">{{ $o->name }}</option>
            @endforeach
        </select>
    </div>

    @if ($orders->isEmpty())
        <section class="lp-dash-card" data-testid="po-empty" style="text-align: center; padding: 48px 24px;">
            <h2 class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">
                No purchase orders match.
            </h2>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute); line-height: 1.5;">
                Compose one from the New order button, or clear the filters above.
            </p>
        </section>
    @else
        <section class="lp-dash-card">
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Supplier</th>
                            <th>Outlet</th>
                            <th>Status</th>
                            <th>Expected</th>
                            <th style="text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $po)
                            <tr data-testid="po-row-{{ $po->id }}"
                                onclick="window.location='{{ route('tenant.purchasing.orders.show', $po) }}'"
                                style="cursor: pointer;">
                                <td class="lp-mono" style="color: var(--color-ink); font-weight: 500;">{{ $po->reference }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $po->supplier->name ?? '-' }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $po->outlet->name ?? '-' }}</td>
                                <td>
                                    <span class="lp-mono text-[10.5px]"
                                          style="padding: 3px 10px; border-radius: 999px; background: {{ $po->status->toneColor() }}22; color: {{ $po->status->toneColor() }}; letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;">
                                        {{ $po->status->label() }}
                                    </span>
                                </td>
                                <td style="color: var(--color-ink-soft);">
                                    {{ $po->expected_at ? $po->expected_at->toFormattedDateString() : '-' }}
                                </td>
                                <td style="text-align: right;">
                                    <span class="lp-table-amount">&#8358;{{ number_format($po->total) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
