@php
    $money = fn (int $n) => '₦' . number_format(abs($n));
@endphp

<div class="lp-dash">

    {{-- ── Back link ────────────────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
            <div>
                <span class="lp-eyebrow-strip">Guest wallet</span>
                <h1 class="lp-display mt-2 text-[clamp(24px,3.2vw,34px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    Wallet · {{ $customer->name }}
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft);">
                    Track cash on file. Guests can top up any time, spend against bookings or POS, and settle
                    overstay overnight from balance.
                </p>
            </div>

            <a href="{{ $statementUrl }}" target="_blank" rel="noopener" class="lp-pill" data-testid="wallet-pdf-link">
                Download statement (PDF)
                <span class="lp-pill-dot">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                </span>
            </a>
        </div>
    </div>

    {{-- ── Balance card + forms ─────────────────────────────────── --}}
    <div class="grid gap-4 lg:grid-cols-3">

        {{-- Balance card --}}
        <section class="lp-dash-card" data-testid="wallet-balance-card">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Available balance</span>
            </div>
            <div style="padding: 12px 0 4px;">
                <div class="lp-num-lg" style="font-size: clamp(28px, 4vw, 40px); color: var(--color-ink);"
                     data-testid="wallet-balance">
                    {{ $money($balance) }}
                </div>
                <p class="mt-2 text-[13px]" style="color: var(--color-ink-mute);">
                    Sum of every ledger entry, see history below.
                </p>
            </div>
        </section>

        {{-- Top-up form --}}
        <section class="lp-dash-card lg:col-span-{{ $isManager ? '1' : '2' }}" data-testid="wallet-topup-form">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Record a top-up</span>
            </div>

            <form wire:submit="topup" class="grid gap-3" style="padding: 8px 0;">
                <label class="grid gap-1">
                    <span class="lp-field-label">Amount (₦)</span>
                    <input type="number" min="1" step="1" wire:model="topupAmount"
                           class="lp-input" required data-testid="topup-amount">
                    @error('topupAmount') <span class="lp-field-error">{{ $message }}</span> @enderror
                </label>

                <label class="grid gap-1">
                    <span class="lp-field-label">Method</span>
                    <select wire:model="topupMethod" class="lp-input" data-testid="topup-method">
                        <option value="cash">Cash</option>
                        <option value="transfer">Bank transfer</option>
                        <option value="pos">POS terminal</option>
                        <option value="other">Other</option>
                    </select>
                </label>

                <label class="grid gap-1">
                    <span class="lp-field-label">Reference note (optional)</span>
                    <input type="text" wire:model="topupNote" class="lp-input"
                           placeholder="Bank ref, teller name, etc.">
                </label>

                <button type="submit" class="lp-btn lp-btn--primary" data-testid="topup-submit">
                    Record top-up
                </button>
            </form>
        </section>

        {{-- Refund form (manager-only) --}}
        @if ($isManager)
            <section class="lp-dash-card" data-testid="wallet-refund-form">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Refund from wallet</span>
                </div>

                <form wire:submit="refund" class="grid gap-3" style="padding: 8px 0;">
                    <label class="grid gap-1">
                        <span class="lp-field-label">Amount (₦)</span>
                        <input type="number" min="1" step="1" wire:model="refundAmount"
                               class="lp-input" required data-testid="refund-amount">
                        @error('refundAmount') <span class="lp-field-error">{{ $message }}</span> @enderror
                    </label>

                    <label class="grid gap-1">
                        <span class="lp-field-label">Refund method</span>
                        <select wire:model="refundMethod" class="lp-input" data-testid="refund-method">
                            <option value="cash">Cash</option>
                            <option value="transfer">Bank transfer</option>
                            <option value="pos">POS terminal</option>
                            <option value="other">Other</option>
                        </select>
                    </label>

                    <label class="grid gap-1">
                        <span class="lp-field-label">Reason <span style="color: var(--color-ink-mute);">(required)</span></span>
                        <textarea wire:model="refundReason" rows="2" class="lp-input" required data-testid="refund-reason"></textarea>
                        @error('refundReason') <span class="lp-field-error">{{ $message }}</span> @enderror
                    </label>

                    <button type="submit" class="lp-btn" data-testid="refund-submit"
                            style="background: #F1D8D0; color: #B85C42; border-color: #B85C42;">
                        Refund from wallet
                    </button>
                </form>
            </section>
        @endif
    </div>

    {{-- ── Transaction ledger ───────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="wallet-ledger">
        <div class="lp-dash-card-head flex flex-wrap items-center justify-between gap-3">
            <span class="lp-dash-card-title">Transactions</span>

            <div class="flex flex-wrap items-end gap-2">
                <label style="display: flex; flex-direction: column; gap: 2px;">
                    <span class="text-[10.5px]" style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.06em;">Type</span>
                    <select wire:model.live="filterType" class="lp-input" style="min-width: 140px;" data-testid="filter-type">
                        @foreach ($typeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="display: flex; flex-direction: column; gap: 2px;">
                    <span class="text-[10.5px]" style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.06em;">From</span>
                    <input type="date" wire:model.live="filterFrom" class="lp-input" data-testid="filter-from"
                           aria-label="Filter from date">
                </label>
                <label style="display: flex; flex-direction: column; gap: 2px;">
                    <span class="text-[10.5px]" style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.06em;">To</span>
                    <input type="date" wire:model.live="filterTo" class="lp-input" data-testid="filter-to"
                           aria-label="Filter to date">
                </label>
            </div>
        </div>

        @if ($transactions->isEmpty())
            <div class="lp-empty" style="padding: 40px 24px; text-align: center; color: var(--color-ink-mute);">
                No wallet activity yet.
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="lp-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="width: 100px;">Date</th>
                            <th>Type</th>
                            <th>Details</th>
                            <th style="text-align: right; width: 110px;">Amount</th>
                            <th style="text-align: right; width: 110px;">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transactions as $tx)
                            <tr data-testid="wallet-tx-row">
                                <td class="lp-mono" style="font-size: 12px; color: var(--color-ink-mute);">
                                    {{ $tx->created_at->format('Y-m-d') }}
                                    <div style="font-size: 10px;">{{ $tx->created_at->format('g:i A') }}</div>
                                </td>
                                <td>
                                    <span class="lp-tag {{ $tx->type->increasesBalance() ? 'lp-tag--confirmed' : 'lp-tag--pending' }}">
                                        {{ $tx->subtypeLabel() }}
                                    </span>
                                </td>
                                <td style="color: var(--color-ink-soft); font-size: 13px;">
                                    {{ $tx->description }}
                                    @if ($tx->booking)
                                        <div style="font-size: 11px; color: var(--color-ink-mute); margin-top: 2px;">
                                            Booking
                                            <a href="{{ route('tenant.bookings.show', $tx->booking->booking_ref) }}"
                                               wire:navigate class="lp-mono" style="text-decoration: underline;">
                                                {{ $tx->booking->booking_ref }}
                                            </a>
                                        </div>
                                    @endif
                                    @if ($tx->approvedBy)
                                        <div style="font-size: 11px; color: var(--color-ink-mute); margin-top: 2px;">
                                            Approved by {{ $tx->approvedBy->name }}
                                        </div>
                                    @endif
                                </td>
                                <td class="lp-mono" style="text-align: right; font-weight: 600;
                                    white-space: nowrap;
                                    color: {{ $tx->amount_naira > 0 ? '#4C6C1E' : '#B85C42' }};">
                                    {{ $tx->amount_naira > 0 ? '+ ' : '- ' }}{{ $money((int) $tx->amount_naira) }}
                                </td>
                                <td class="lp-mono" style="text-align: right; white-space: nowrap; color: var(--color-ink);">
                                    {{ $money((int) $tx->balance_after_naira) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="padding: 12px 4px 0;">
                {{ $transactions->links() }}
            </div>
        @endif
    </section>

</div>
