<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <span class="lp-eyebrow-strip">{{ $sale->outlet->name }}</span>
                <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    {{ $sale->reference }}
                </h1>
                <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-soft);">
                    Sold {{ optional($sale->sold_at)->format('M j, Y \a\t g:i A') ?? '-' }}
                    by {{ $sale->operator?->name ?? '-' }} · <strong style="font-weight: 500;">{{ $sale->status->label() }}</strong>
                </p>
            </div>
            <div class="flex gap-2 lp-pos__actions">
                <button type="button" onclick="window.print()" class="lp-btn" data-testid="sale-print">Print receipt</button>
                @if ($canRefund && ! $refundOpen)
                    <button type="button" wire:click="openRefund" class="lp-btn" data-testid="sale-refund">Return / refund</button>
                @endif
                @if ($canVoid)
                    <button type="button" wire:click="askVoid" class="lp-btn lp-btn--danger" data-testid="sale-void">Void sale</button>
                @endif
            </div>
        </div>
    </div>
    @if ($refundOpen)
        <section class="lp-dash-card" data-testid="refund-panel" style="border: 2px solid var(--color-accent-deep);">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Return items</span>
                <span class="lp-dash-card-meta">stock restored per line · one Refund payment written</span>
            </div>
            <div style="display: grid; gap: 14px;">
                <div class="overflow-x-auto">
                    <table class="lp-table">
                        <thead>
                            <tr>
                                <th>Line</th>
                                <th style="text-align: right;">Sold</th>
                                <th style="text-align: right;">Refundable</th>
                                <th style="text-align: right;">Return qty</th>
                                <th style="text-align: right;">Line refund</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sale->lines as $line)
                                @php $max = (int) ($refundableByLine[$line->id] ?? 0); @endphp
                                <tr data-testid="refund-line-{{ $line->id }}">
                                    <td style="color: var(--color-ink); font-weight: 500;">{{ $line->name }}</td>
                                    <td style="text-align: right;" class="lp-mono">{{ number_format($line->quantity) }}</td>
                                    <td style="text-align: right;" class="lp-mono" style="color: {{ $max > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};">
                                        {{ number_format($max) }}
                                    </td>
                                    <td style="text-align: right;">
                                        <input type="number" wire:model.live="refundQty.{{ $line->id }}"
                                               min="0" max="{{ $max }}"
                                               class="lp-input" style="width: 90px; text-align: right;"
                                               data-testid="refund-qty-{{ $line->id }}"
                                               @disabled($max === 0)>
                                    </td>
                                    <td style="text-align: right;" class="lp-mono">
                                        &#8358;{{ number_format((int) ($refundQty[$line->id] ?? 0) * (int) $line->unit_price) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="ref-method" class="lp-form-label">Refund method</label>
                        <select id="ref-method" wire:model="refundMethod" class="lp-input" data-testid="refund-method">
                            <option value="cash">Cash</option>
                            <option value="transfer">Transfer</option>
                            <option value="card">Card</option>
                            <option value="room">Credit to room</option>
                            <option value="store_credit">Store credit</option>
                        </select>
                    </div>
                    <div>
                        <label for="ref-reason" class="lp-form-label">Reason (required)</label>
                        <input id="ref-reason" type="text" wire:model="refundReason" maxlength="240"
                               data-testid="refund-reason" class="lp-input"
                               placeholder="e.g. drink sent back, wrong order, customer complaint">
                        @if ($refundError)
                            <p class="lp-form-error" data-testid="refund-error">{{ $refundError }}</p>
                        @endif
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelRefund" class="lp-btn">Cancel</button>
                    <button type="button" wire:click="confirmRefund" class="lp-btn lp-btn--primary" data-testid="refund-confirm">
                        Record refund
                    </button>
                </div>
            </div>
        </section>
    @endif

    @if ($sale->returns->isNotEmpty())
        <section class="lp-dash-card" data-testid="refund-history">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Refund history</span>
                <span class="lp-dash-card-meta">{{ $sale->returns->count() }} event{{ $sale->returns->count() === 1 ? '' : 's' }} · total &#8358;{{ number_format((int) $sale->returns->sum('refund_amount')) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Reason</th>
                            <th>Method</th>
                            <th style="text-align: right;">Amount</th>
                            <th>Lines</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sale->returns as $r)
                            <tr>
                                <td class="lp-mono text-[11.5px]" style="color: var(--color-ink-soft);">
                                    {{ $r->refunded_at?->format('M j · g:i A') }}
                                </td>
                                <td style="color: var(--color-ink); font-weight: 500;">{{ $r->reason }}</td>
                                <td class="lp-mono text-[11.5px]" style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.06em;">
                                    {{ $r->refund_method }}
                                </td>
                                <td style="text-align: right;"><span class="lp-table-amount">&#8358;{{ number_format($r->refund_amount) }}</span></td>
                                <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                    @foreach ($r->lines as $rl)
                                        <div>{{ $rl->quantity }} × {{ $sale->lines->firstWhere('id', $rl->pos_sale_line_id)?->name ?? '-' }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($confirmingVoid)
        <section class="lp-dash-card" data-testid="void-panel" style="border: 2px solid #B85C42;">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title" style="color: #B85C42;">Void this sale?</span>
                <span class="lp-dash-card-meta">stock restored · payment refunded</span>
            </div>
            <div style="display: grid; gap: 12px;">
                <div>
                    <label for="v-reason" class="lp-form-label">Reason (required)</label>
                    <input id="v-reason" type="text" wire:model="voidReason" maxlength="240"
                           data-testid="void-reason" class="lp-input" placeholder="Cancelled by customer, POS error, etc.">
                    @if ($voidError)
                        <p class="lp-form-error" data-testid="void-error">{{ $voidError }}</p>
                    @endif
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelVoid" class="lp-btn">Cancel</button>
                    <button type="button" wire:click="confirmVoid" class="lp-btn lp-btn--danger" data-testid="void-confirm">Void sale</button>
                </div>
            </div>
        </section>
    @endif

    {{-- Receipt shown as a floating paper slip on a neutral base so
         it visually resembles what will come off the thermal printer.
         The dashboard card wrapper was hiding that paper-shape cue. --}}
    <section class="lp-pos__receipt-frame" style="padding: 24px 16px; display: flex; justify-content: center;">
        @include('livewire.dashboard.pos._receipt', ['sale' => $sale])
    </section>

    @if ($sale->status->value === 'voided')
        <p class="text-[12.5px]" style="color: #B85C42;" data-testid="sale-voided-note">
            Voided {{ optional($sale->voided_at)->format('M j, Y \a\t g:i A') }} · reason:
            <em>{{ $sale->voided_reason }}</em>
        </p>
    @endif
</div>
