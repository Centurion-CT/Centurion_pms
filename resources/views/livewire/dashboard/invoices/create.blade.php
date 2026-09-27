@php
    $money = fn (int $n) => '₦' . number_format($n);
@endphp

<div class="lp-dash">

    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip">New invoice</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Invoice for {{ $booking->guest_name }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                {{ $booking->room?->name }} · {{ $booking->checkin_date?->toFormattedDateString() }} → {{ $booking->checkout_date?->toFormattedDateString() }} · {{ $booking->nights }} night{{ $booking->nights === 1 ? '' : 's' }}
            </p>
        </div>
    </div>

    <form wire:submit="issue" class="grid gap-4">

        <section class="lp-dash-card" style="padding: 24px;">
            <div class="lp-dash-card-head">
                <div>
                    <span class="lp-dash-card-title">Line items</span>
                    <p class="text-[11.5px] mt-1" style="color: var(--color-ink-mute); margin: 4px 0 0;">
                        Auto-populated from the booking, room rate + discounts + every unsettled charge on the folio.
                        Add a charge on the reservation page + click Regenerate if the folio changed.
                    </p>
                </div>
                <button type="button" wire:click="regenerate" data-testid="regenerate-lines"
                        class="lp-btn" style="font-size: 12.5px;">
                    Regenerate
                </button>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-[13.5px]" data-testid="lines-table">
                    <thead>
                        <tr style="color: var(--color-ink-mute); text-align: left; border-bottom: 1px solid var(--color-line-soft);">
                            <th class="py-2 pr-3" style="font-weight: 500;">Description</th>
                            <th class="py-2 pr-3" style="font-weight: 500; width: 90px; text-align: right;">Qty</th>
                            <th class="py-2 pr-3" style="font-weight: 500; width: 160px; text-align: right;">Unit price (₦)</th>
                            <th class="py-2 pr-3" style="font-weight: 500; width: 160px; text-align: right;">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($lines as $index => $line)
                            @php
                                $isDiscount = (int) $line['line_total'] < 0;
                                $rowColor  = $isDiscount ? 'var(--color-accent-deep)' : 'var(--color-ink)';
                            @endphp
                            <tr wire:key="line-{{ $index }}" style="border-bottom: 1px solid var(--color-line-soft);">
                                <td class="py-3 pr-3" style="color: {{ $rowColor }};"
                                    data-testid="line-{{ $index }}-description">
                                    {{ $line['description'] }}
                                </td>
                                <td class="py-3 pr-3 lp-mono" style="text-align: right; color: {{ $rowColor }};"
                                    data-testid="line-{{ $index }}-qty">
                                    {{ (int) $line['quantity'] }}
                                </td>
                                <td class="py-3 pr-3 lp-mono" style="text-align: right; color: {{ $rowColor }};"
                                    data-testid="line-{{ $index }}-unit">
                                    @if ($isDiscount)&minus;@endif&#8358;{{ number_format(abs((int) $line['unit_price'])) }}
                                </td>
                                <td class="py-3 pr-3 lp-mono" style="text-align: right; color: {{ $rowColor }};"
                                    data-testid="line-{{ $index }}-total">
                                    @if ($isDiscount)&minus;@endif&#8358;{{ number_format(abs((int) $line['line_total'])) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center" style="color: var(--color-ink-mute);">
                                    Nothing to bill on this booking yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
            <section class="lp-dash-card" style="padding: 24px;">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Notes (optional)</span>
                </div>
                <textarea wire:model="notes" rows="4" data-testid="invoice-notes"
                          class="lp-input mt-2" style="width: 100%;"
                          placeholder="Add a note the guest should see on the invoice…"></textarea>

                <label for="due-at" class="lp-form-label mt-4">Due date (optional)</label>
                <input id="due-at" type="date" wire:model="dueAt" data-testid="invoice-due-at"
                       class="lp-input" style="max-width: 220px;" />
            </section>

            <section class="lp-dash-card" style="padding: 24px;">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Totals</span>
                </div>
                <dl class="mt-3 grid gap-2 text-[14px]">
                    <div class="flex justify-between">
                        <dt style="color: var(--color-ink-soft);">Subtotal</dt>
                        <dd class="lp-mono" data-testid="invoice-subtotal">{{ $money($subtotal) }}</dd>
                    </div>
                    <div class="flex justify-between items-center gap-3">
                        <dt style="color: var(--color-ink-soft);">Already paid</dt>
                        <dd class="lp-mono">
                            <input type="number" min="0" wire:model.live.debounce.300ms="depositApplied"
                                   data-testid="invoice-deposit"
                                   class="lp-input" style="width: 130px; text-align: right;" />
                        </dd>
                    </div>
                    <p class="text-[11.5px]" style="color: var(--color-ink-mute); margin: -6px 0 0;">
                        Prefilled from the booking's settled payments (deposit + cash + wallet).
                        Adjust if this invoice is a fresh receivable.
                    </p>
                    <div class="flex justify-between pt-2"
                         style="border-top: 1px solid var(--color-line-soft); font-weight: 600; color: var(--color-ink);">
                        <dt>Balance owed</dt>
                        <dd class="lp-mono" data-testid="invoice-balance">{{ $money($balance) }}</dd>
                    </div>
                </dl>

                <button type="submit" data-testid="issue-invoice"
                        class="lp-pill mt-5"
                        wire:loading.attr="disabled" wire:target="issue"
                        style="justify-self: end;">
                    <span wire:loading.remove wire:target="issue">Issue invoice</span>
                    <span wire:loading wire:target="issue">Issuing…</span>
                    <span class="lp-pill-dot">
                        <span wire:loading.remove wire:target="issue">
                            <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                        </span>
                        <span wire:loading wire:target="issue" class="kv-spinner" aria-hidden="true"></span>
                    </span>
                </button>
                <p class="mt-2 text-[12px]" style="color: var(--color-ink-mute);">
                    Once issued, the invoice is numbered and can't be edited. Void + reissue if it needs changing.
                </p>
            </section>
        </div>

    </form>

</div>
