@php
    $money = fn (int $n) => money($n);
    $statusClass = match ($invoice->status->value) {
        'draft'   => 'lp-tag--pending',
        'issued'  => 'lp-tag--on-hold',
        'partial' => 'lp-tag--on-hold',
        'paid'    => 'lp-tag--confirmed',
        'void'    => 'lp-tag--cancelled',
        default   => '',
    };
@endphp

<div class="lp-dash">

    {{-- ── Staff chrome. `.invoice-chrome` is hidden on print via
         the @media rule at the bottom of this file so only the paper
         document itself prints. ────────────────────────────────── --}}
    <div class="invoice-chrome">
        @include('_partials._back-button')

        {{-- ── Hero: identity + balance + primary actions ─────
             Editorial invoice ID + guest/booking meta on the left;
             right-side money panel carries outstanding balance in
             Fraunces red, with status tag under the eyebrow. --}}
        <section class="lp-res-idx-hero mt-3" data-testid="invoice-hero">
            <div class="lp-res-idx-hero-body">
                <div class="lp-res-idx-hero-identity">
                    <span class="lp-eyebrow-strip">Invoice</span>
                    <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                        style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                        {{ $invoice->number ?? 'Draft' }}
                    </h1>
                    <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                        {{ $invoice->guest_name ?? 'Unnamed guest' }}
                        @if ($invoice->booking?->booking_ref)
                            <span style="color: var(--color-ink-mute);">·</span>
                            <span class="lp-mono">{{ $invoice->booking->booking_ref }}</span>
                        @endif
                        @if ($invoice->issued_at)
                            <span style="color: var(--color-ink-mute);">·</span>
                            issued {{ $invoice->issued_at->toFormattedDateString() }}
                        @endif
                    </p>
                    <div class="lp-res-idx-hero-actions">
                        <button type="button" onclick="window.print()"
                                data-testid="print-invoice"
                                class="lp-res-action lp-res-action--primary">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4 6V3h8v3M4 11H2v-4a1 1 0 011-1h10a1 1 0 011 1v4h-2M4 10h8v4H4z"/></svg>
                            Print
                        </button>
                        <a href="{{ route('tenant.invoices.pdf', $invoice) }}"
                           data-testid="download-invoice-pdf"
                           class="lp-res-action">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 11v2.5h10V11M8 3v8M5 8l3 3 3-3"/></svg>
                            PDF
                        </a>
                        @if (in_array($invoice->status->value, ['issued', 'partial'], true))
                            <button type="button" wire:click="void"
                                    data-testid="void-invoice"
                                    wire:confirm="Void this invoice? This cannot be undone."
                                    class="lp-res-action lp-res-action--ghost">
                                Void
                            </button>
                        @endif
                    </div>
                    @if (in_array($invoice->status->value, ['issued', 'partial'], true))
                        <div class="mt-3">
                            <label for="void-reason" class="lp-rate-sub-label">Void reason (optional)</label>
                            <input id="void-reason" type="text" wire:model="voidReason"
                                   placeholder="Only stored when voiding, not sent to guest"
                                   data-testid="void-reason"
                                   class="lp-input" style="max-width: 320px;" />
                        </div>
                    @endif
                </div>

                <aside class="lp-res-idx-hero-money" aria-label="Invoice balance">
                    <div class="lp-ih-hero-accrued">
                        <p class="lp-ih-hero-accrued-label">
                            <span class="lp-tag {{ $statusClass }}" data-testid="invoice-status">
                                {{ $invoice->status->value }}
                            </span>
                        </p>
                        @php $bal = (int) $invoice->balanceOwed(); @endphp
                        <p class="lp-ih-hero-accrued-value {{ $bal > 0 ? 'lp-ih-hero-accrued-value--warn' : '' }}"
                           data-testid="invoice-hero-balance">
                            {{ money($bal) }}
                        </p>
                        <p class="lp-ih-hero-accrued-sub">
                            @if ($bal > 0)
                                {{ money($invoice->amount_paid) }} of {{ money($invoice->total) }} paid
                            @else
                                Fully settled · {{ money($invoice->total) }} total
                            @endif
                        </p>
                    </div>
                </aside>
            </div>
        </section>

        {{-- Record-payment form (chrome, not printed). Surfaces while
             the invoice still has an outstanding balance. Recording a
             payment is the only path to Paid. --}}
        @if (in_array($invoice->status->value, ['issued', 'partial'], true) && $invoice->balanceOwed() > 0)
            <section class="lp-dash-card mt-4" data-testid="record-payment-form" style="padding: 16px 18px;">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Record a payment</span>
                    <span class="lp-dash-card-meta">outstanding {{ money($invoice->balanceOwed()) }}</span>
                </div>
                <form wire:submit="recordPayment" class="mt-3 grid gap-3 sm:grid-cols-[130px_150px_1fr_auto] sm:items-end">
                    <div>
                        <label for="pay-amount" class="lp-form-label">Amount ({{ currency_symbol() }})</label>
                        <input id="pay-amount" type="number" min="1" wire:model="paymentAmount"
                               data-testid="pay-amount" class="lp-input" />
                    </div>
                    <div>
                        <label for="pay-method" class="lp-form-label">Method</label>
                        <select id="pay-method" wire:model="paymentMethod"
                                data-testid="pay-method" class="lp-input">
                            <option value="cash">Cash</option>
                            <option value="transfer">Transfer</option>
                            <option value="card">Card</option>
                            <option value="paystack">Paystack</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label for="pay-note" class="lp-form-label">Reference (optional)</label>
                        <input id="pay-note" type="text" wire:model="paymentReferenceNote"
                               placeholder="Txn id, POS receipt no..."
                               data-testid="pay-note" class="lp-input" />
                    </div>
                    <button type="submit" data-testid="record-payment"
                            class="lp-res-action lp-res-action--primary"
                            wire:loading.attr="disabled" wire:target="recordPayment"
                            style="height: 40px;">
                        <span wire:loading.remove wire:target="recordPayment">Record</span>
                        <span wire:loading wire:target="recordPayment">Saving…</span>
                    </button>
                </form>
            </section>
        @endif

        {{-- Payment history (chrome). Card-stack of every payment,
             newest first, with tone marker per method. --}}
        @if ($invoice->payments->isNotEmpty())
            <section class="lp-dash-card mt-4" data-testid="invoice-payments" style="padding: 14px 16px;">
                <div class="lp-dash-card-head" style="margin-bottom: 10px;">
                    <span class="lp-dash-card-title">Payments</span>
                    <span class="lp-dash-card-meta">
                        {{ $invoice->payments->count() }} {{ Str::plural('entry', $invoice->payments->count()) }} ·
                        {{ money($invoice->payments->sum('amount')) }} collected
                    </span>
                </div>
                <ul class="lp-ledger lp-invoice-payments">
                    @foreach ($invoice->payments as $pay)
                        @php
                            $method = $pay->method ?? 'paystack';
                            $tone   = in_array($method, ['card', 'paystack'], true) ? 'paid'
                                    : ($method === 'transfer' ? 'info'
                                    : ($method === 'cash' ? 'warn' : 'muted'));
                            $ref    = $pay->reference_note ?? $pay->paystack_reference;
                        @endphp
                        <li class="lp-ledger-item">
                            <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                            <div class="lp-ledger-body">
                                <div class="lp-gr-primary">
                                    <span class="lp-ledger-desc" style="text-transform: capitalize;">{{ $method }}</span>
                                    @if ($ref)
                                        <span class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px; background: var(--color-card-deep); border: 1px solid var(--color-line-soft); border-radius: 4px; padding: 1px 6px;">
                                            {{ $ref }}
                                        </span>
                                    @endif
                                </div>
                                <p class="lp-ledger-meta">
                                    {{ ($pay->paid_at ?? $pay->created_at)?->toFormattedDateString() }}
                                    @if (($pay->paid_at ?? $pay->created_at))
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        {{ ($pay->paid_at ?? $pay->created_at)->format('g:i a') }}
                                    @endif
                                </p>
                            </div>
                            <div class="lp-ledger-right">
                                <span class="lp-invoice-total">{{ money($pay->amount) }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    {{-- ── The invoice document itself. Print-optimised: fits on one
         A4 page with generous margins; forbids page-break inside the
         totals + signature block. ─────────────────────────────────── --}}
    <article class="invoice-doc lp-dash-card mt-5" data-testid="invoice-doc"
             style="padding: 40px 44px; background: #fff;">

        {{-- Header: property brand vs. invoice metadata --}}
        <header class="flex flex-wrap items-start justify-between gap-6" style="border-bottom: 1px solid #E4E1D9; padding-bottom: 20px;">
            <div>
                <p class="lp-display text-[26px]" style="color: #14171A; font-weight: 600; line-height: 1.04;">
                    {{ $tenant?->name ?? 'Your property' }}
                </p>
                @if ($tenant?->email)
                    <p class="mt-1 text-[12.5px]" style="color: #6E767D;">{{ $tenant->email }}</p>
                @endif
                @if ($tenant?->phone)
                    <p class="text-[12.5px]" style="color: #6E767D;">{{ $tenant->phone }}</p>
                @endif
            </div>
            <div style="text-align: right;">
                <p class="lp-mono text-[10.5px]" style="letter-spacing: 0.16em; text-transform: uppercase; color: #6E767D;">Invoice</p>
                <p class="lp-mono mt-1 text-[16px]" style="color: #14171A; font-weight: 600;" data-testid="invoice-number">
                    {{ $invoice->number ?? '-' }}
                </p>
                @if ($invoice->issued_at)
                    <p class="mt-1 text-[12px]" style="color: #6E767D;">
                        Issued {{ $invoice->issued_at->toFormattedDateString() }}
                    </p>
                @endif
                @if ($invoice->due_at)
                    <p class="text-[12px]" style="color: #6E767D;">
                        Due {{ $invoice->due_at->toFormattedDateString() }}
                    </p>
                @endif
            </div>
        </header>

        {{-- Guest + booking summary. Corporate-guaranteed bookings
             bill the org first (that's who's actually paying the
             invoice), with the individual staff traveller as a
             "Guest" sub-line for reference. --}}
        @php
            $invoiceCorp = $invoice->booking?->corporate;
        @endphp
        <section class="mt-6 grid gap-6 sm:grid-cols-2 text-[13.5px]">
            <div>
                <p class="lp-mono text-[10.5px]" style="letter-spacing: 0.16em; text-transform: uppercase; color: #6E767D;">Bill to</p>
                @if ($invoiceCorp)
                    <p class="mt-2" style="color: #14171A; font-weight: 600;" data-testid="invoice-bill-to-corp">
                        {{ $invoiceCorp->name }}
                    </p>
                    @if ($invoiceCorp->billing_email)
                        <p style="color: #3A4148;">{{ $invoiceCorp->billing_email }}</p>
                    @elseif ($invoiceCorp->primary_email)
                        <p style="color: #3A4148;">{{ $invoiceCorp->primary_email }}</p>
                    @endif
                    @if ($invoiceCorp->primary_phone)
                        <p style="color: #3A4148;">{{ $invoiceCorp->primary_phone }}</p>
                    @endif
                    @if ($invoiceCorp->address || $invoiceCorp->city)
                        <p style="color: #3A4148;">
                            {{ $invoiceCorp->address }}@if ($invoiceCorp->address && $invoiceCorp->city), @endif{{ $invoiceCorp->city }}
                        </p>
                    @endif
                    @if ($invoiceCorp->tax_id)
                        <p class="lp-mono text-[11px]" style="color: #6E767D; margin-top: 4px;">
                            TAX ID · {{ $invoiceCorp->tax_id }}
                        </p>
                    @endif
                    <p class="mt-3 text-[11.5px]" style="color: #6E767D;">Guest</p>
                    <p style="color: #14171A;">{{ $invoice->guest_name }}</p>
                    @if ($invoice->guest_phone)
                        <p style="color: #6E767D; font-size: 12px;">{{ $invoice->guest_phone }}</p>
                    @endif
                @else
                    <p class="mt-2" style="color: #14171A; font-weight: 500;">{{ $invoice->guest_name }}</p>
                    @if ($invoice->guest_email)
                        <p style="color: #3A4148;">{{ $invoice->guest_email }}</p>
                    @endif
                    @if ($invoice->guest_phone)
                        <p style="color: #3A4148;">{{ $invoice->guest_phone }}</p>
                    @endif
                @endif
            </div>
            @if ($invoice->booking)
                <div>
                    <p class="lp-mono text-[10.5px]" style="letter-spacing: 0.16em; text-transform: uppercase; color: #6E767D;">Stay</p>
                    <p class="lp-mono mt-2" style="color: #14171A; font-weight: 500;">{{ $invoice->booking->booking_ref }}</p>
                    <p style="color: #3A4148;">
                        {{ $invoice->booking->checkin_date?->toFormattedDateString() }} → {{ $invoice->booking->checkout_date?->toFormattedDateString() }}
                    </p>
                    <p style="color: #3A4148;">{{ $invoice->booking->nights }} night{{ $invoice->booking->nights === 1 ? '' : 's' }}</p>
                </div>
            @endif
        </section>

        {{-- Line items --}}
        <table class="mt-6 w-full text-[13px]" data-testid="invoice-lines">
            <thead>
                <tr style="text-align: left; color: #6E767D; border-bottom: 1px solid #E4E1D9;">
                    <th class="py-2" style="font-weight: 500;">Description</th>
                    <th class="py-2" style="font-weight: 500; text-align: right; width: 60px;">Qty</th>
                    <th class="py-2" style="font-weight: 500; text-align: right; width: 130px;">Unit</th>
                    <th class="py-2" style="font-weight: 500; text-align: right; width: 130px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr style="border-bottom: 1px solid #F0EDE4;">
                        <td class="py-2 pr-3" style="color: #14171A;">
                            {{ $line->description }}
                        </td>
                        <td class="py-2 lp-mono" style="text-align: right; color: #3A4148;">{{ $line->quantity }}</td>
                        <td class="py-2 lp-mono" style="text-align: right; color: #3A4148;">{{ $money($line->unit_price) }}</td>
                        <td class="py-2 lp-mono" style="text-align: right; color: #14171A;">{{ $money($line->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Totals, kept together so a page break can't split them off from the signature block. --}}
        <div class="invoice-totals mt-4 grid gap-3 sm:grid-cols-[1fr_260px] items-start">
            <div>
                @if ($invoice->notes)
                    <p class="lp-mono text-[10.5px]" style="letter-spacing: 0.16em; text-transform: uppercase; color: #6E767D;">Notes</p>
                    <p class="mt-2 text-[13px] whitespace-pre-line" style="color: #3A4148;">{{ $invoice->notes }}</p>
                @endif
            </div>
            <dl class="grid gap-2 text-[13.5px]">
                <div class="flex justify-between">
                    <dt style="color: #3A4148;">Subtotal</dt>
                    <dd class="lp-mono" style="color: #14171A;" data-testid="invoice-subtotal">{{ $money($invoice->subtotal) }}</dd>
                </div>
                @if ($invoice->amount_paid > 0)
                    <div class="flex justify-between">
                        <dt style="color: #3A4148;">Already paid</dt>
                        <dd class="lp-mono" style="color: #14171A;">− {{ $money($invoice->amount_paid) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between pt-2"
                     style="border-top: 1px solid #E4E1D9; font-weight: 600; color: #14171A; font-size: 15px;">
                    <dt>Balance owed</dt>
                    <dd class="lp-mono" data-testid="invoice-balance-owed">{{ $money($invoice->balanceOwed()) }}</dd>
                </div>
            </dl>
        </div>

        {{-- Payment details --}}
        @if ($invoice->bank_name || $invoice->bank_account_number)
            <section class="mt-6 rounded-lg" style="padding: 14px 16px; background: #F7F4EC; border: 1px solid #E4E1D9;"
                     data-testid="invoice-bank-details">
                <p class="lp-mono text-[10.5px]" style="letter-spacing: 0.16em; text-transform: uppercase; color: #6E767D;">Pay into</p>
                <div class="mt-2 grid gap-1 text-[13px]" style="color: #14171A;">
                    @if ($invoice->bank_name)
                        <p><strong style="font-weight: 500;">Bank:</strong> {{ $invoice->bank_name }}</p>
                    @endif
                    @if ($invoice->bank_account_name)
                        <p><strong style="font-weight: 500;">Account name:</strong> {{ $invoice->bank_account_name }}</p>
                    @endif
                    @if ($invoice->bank_account_number)
                        <p class="lp-mono"><strong style="font-weight: 500;">Account number:</strong> {{ $invoice->bank_account_number }}</p>
                    @endif
                </div>
            </section>
        @else
            <p class="mt-6 text-[12.5px]" style="color: #B84A17;" data-testid="invoice-bank-missing">
                No payout account on file, set Paystack details on your property to include payment info on printed invoices.
            </p>
        @endif

        {{-- Signature block --}}
        <div class="invoice-signature mt-10 grid gap-8 sm:grid-cols-2 text-[12.5px]" style="color: #3A4148;">
            <div>
                <div style="border-top: 1px solid #14171A; padding-top: 6px; margin-top: 40px;">Guest signature</div>
                <p class="mt-1" style="color: #6E767D;">Date: _______________</p>
            </div>
            <div>
                <div style="border-top: 1px solid #14171A; padding-top: 6px; margin-top: 40px;">
                    Received by ({{ $invoice->issuedBy?->name ?? 'Front desk' }})
                </div>
                <p class="mt-1" style="color: #6E767D;">Date: _______________</p>
            </div>
        </div>

    </article>

    {{-- Print rules scoped to the invoice show screen. Chrome disappears
         so paper output is only the .invoice-doc article. Lives INSIDE
         the lp-dash root because Livewire refuses components with more
         than one root element. --}}
    <style>
        @media print {
            .invoice-chrome { display: none !important; }
            .lp-dash > *:not(.invoice-doc) { display: none !important; }
            body, html { background: #fff !important; }
            .lp-dash-card { box-shadow: none !important; border: 0 !important; }
            .invoice-doc { padding: 0 !important; }
            .invoice-totals, .invoice-signature { page-break-inside: avoid; }
            a[href]::after { content: '' !important; }
        }
    </style>

</div>
