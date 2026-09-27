<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="flex flex-wrap items-start justify-between gap-3 mt-4">
            <div>
                <span class="lp-eyebrow-strip">Purchasing</span>
                <h1 class="lp-display mt-3 text-[clamp(24px,3vw,30px)] lp-mono"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    {{ $po->reference }}
                </h1>
                <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                    {{ $po->supplier->name }} → {{ $po->outlet->name }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="lp-mono text-[10.5px]"
                      data-testid="po-status"
                      style="padding: 4px 12px; border-radius: 999px; background: {{ $po->status->toneColor() }}22; color: {{ $po->status->toneColor() }}; letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;">
                    {{ $po->status->label() }}
                </span>
                @if ($po->status === App\Enums\PurchaseOrderStatus::Draft)
                    <a href="{{ route('tenant.purchasing.orders.edit', $po) }}" wire:navigate class="lp-btn">Edit</a>
                    <button type="button" wire:click="markSent" class="lp-btn" data-testid="po-mark-sent">Mark as sent</button>
                @endif
                @if ($po->status !== App\Enums\PurchaseOrderStatus::Received && $po->status !== App\Enums\PurchaseOrderStatus::Cancelled)
                    <button type="button" wire:click="cancel" wire:confirm="Cancel this purchase order?"
                            class="lp-btn" style="color: #B85C42;" data-testid="po-cancel">Cancel</button>
                @endif
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="lp-flash-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="lp-flash-error">{{ session('error') }}</div>
    @endif

    {{-- ── Meta strip ───────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="grid gap-3 sm:grid-cols-4">
            <div>
                <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">Expected</span>
                <div class="text-[14px]" style="color: var(--color-ink);">
                    {{ $po->expected_at ? $po->expected_at->toFormattedDateString() : '-' }}
                </div>
            </div>
            <div>
                <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">Placed</span>
                <div class="text-[14px]" style="color: var(--color-ink);">
                    {{ $po->placed_at ? $po->placed_at->toFormattedDateString() : '-' }}
                </div>
            </div>
            <div>
                <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">Received</span>
                <div class="text-[14px]" style="color: var(--color-ink);">
                    {{ $po->received_at ? $po->received_at->toFormattedDateString() : '-' }}
                </div>
            </div>
            <div>
                <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">Total</span>
                <div class="text-[14px] lp-mono" style="color: var(--color-ink); font-weight: 600;">
                    &#8358;{{ number_format($po->total) }}
                </div>
            </div>
        </div>
        @if ($po->notes)
            <p class="mt-4 text-[13.5px]" style="color: var(--color-ink-soft); white-space: pre-line;">
                {{ $po->notes }}
            </p>
        @endif
    </section>

    {{-- ── Lines + receive UI ──────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Line items</span>
            <span class="lp-dash-card-meta">{{ $po->lines->count() }} {{ Str::plural('line', $po->lines->count()) }}</span>
        </div>
        @php $canReceive = $po->status->isOpen(); @endphp
        <div class="overflow-x-auto">
            <table class="lp-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align: right;">Ordered</th>
                        <th style="text-align: right;">Received</th>
                        <th style="text-align: right;">Outstanding</th>
                        <th style="text-align: right;">Unit cost</th>
                        @if ($canReceive)
                            <th style="text-align: right;">Receive now</th>
                        @endif
                        <th style="text-align: right;">Line total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($po->lines as $line)
                        <tr data-testid="po-line-row-{{ $line->id }}">
                            <td style="color: var(--color-ink); font-weight: 500;">
                                {{ $line->product->name ?? '-' }}
                                @if ($line->product?->sku)
                                    <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute);">· {{ $line->product->sku }}</span>
                                @endif
                            </td>
                            <td style="text-align: right;" class="lp-mono">{{ number_format($line->quantity_ordered) }}</td>
                            <td style="text-align: right;" class="lp-mono">{{ number_format($line->quantity_received) }}</td>
                            <td style="text-align: right;" class="lp-mono"
                                style="color: {{ $line->outstanding() > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};">
                                {{ number_format($line->outstanding()) }}
                            </td>
                            <td style="text-align: right;" class="lp-mono">&#8358;{{ number_format($line->unit_cost) }}</td>
                            @if ($canReceive)
                                <td style="text-align: right;">
                                    <input type="number" wire:model="receipt.{{ $line->id }}"
                                           min="0" max="{{ $line->outstanding() }}"
                                           class="lp-input" style="width: 90px; text-align: right;"
                                           data-testid="po-receive-{{ $line->id }}"
                                           @disabled($line->outstanding() === 0)>
                                </td>
                            @endif
                            <td style="text-align: right;">
                                <span class="lp-table-amount">&#8358;{{ number_format($line->subtotal) }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($canReceive)
            <div class="mt-4" style="display: grid; gap: 12px; border-top: 1px solid var(--color-line-soft); padding-top: 16px;">
                <div>
                    <label for="p-rn" class="lp-form-label">Receipt note</label>
                    <input id="p-rn" type="text" wire:model="receiptNote" maxlength="240"
                           class="lp-input" data-testid="po-receipt-note"
                           placeholder="Delivery reference or condition notes (optional)">
                </div>
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <button type="button" wire:click="receiveAll" class="lp-btn"
                            data-testid="po-receive-all">Mark fully received</button>
                    <button type="button" wire:click="receive" class="lp-btn lp-btn--primary"
                            data-testid="po-receive">Record delivery</button>
                </div>
            </div>
        @endif
    </section>
</div>
