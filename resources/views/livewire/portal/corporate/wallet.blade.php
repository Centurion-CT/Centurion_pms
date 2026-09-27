@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div>
        <span class="lp-eyebrow-strip">Wallet</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Wallet
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Top up your balance, see every hold + spend + refund.
        </p>
    </div>

    {{-- ── Balance strip ─────────────────────────────────────────── --}}
    <div class="grid gap-3 sm:grid-cols-3" data-testid="balance-strip">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Balance</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($wallet->balance_naira) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">On hold</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink-soft); font-weight: 600;">
                ₦{{ number_format($wallet->held_naira) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Available</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($wallet->available()) }}
            </p>
        </div>
    </div>

    {{-- ── Top-up ────────────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Top up wallet</span>
            <span class="lp-dash-card-meta">Card · transfer · bank, via Paystack</span>
        </div>
        <form wire:submit="startTopup" class="grid gap-4 sm:grid-cols-3" data-testid="topup-form">
            <div>
                <label class="lp-form-label" for="tu-amt">Amount (₦)</label>
                <input id="tu-amt" type="number" min="1000" wire:model.live="requestAmount" class="lp-input" required>
                @error('requestAmount') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="tu-note">Note</label>
                <input id="tu-note" type="text" wire:model.live="requestNote" class="lp-input" maxlength="240" placeholder="e.g. Q3 budget allocation">
            </div>
            <div class="sm:col-span-3 flex justify-end">
                <button type="submit" class="lp-pill" data-testid="topup-submit">
                    <span wire:loading.remove wire:target="startTopup">Pay now</span>
                    <span wire:loading wire:target="startTopup">Redirecting…</span>
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </button>
            </div>
        </form>
        <p class="lp-form-help" style="margin-top: 12px;">
            You'll be redirected to Paystack to complete the payment. Your wallet is credited
            instantly once Paystack confirms.
        </p>
    </section>

    @if ($pendingTopups->isNotEmpty())
        <section class="lp-dash-card" data-testid="pending-topups">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Pending top-ups</span>
                <span class="lp-dash-card-meta">Resume or cancel an unfinished checkout</span>
            </div>
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Started</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Note</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingTopups as $t)
                            <tr data-testid="pending-row-{{ $t->id }}">
                                <td class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px; white-space: nowrap;">
                                    {{ $t->created_at?->format('d M · g:i A') }}
                                </td>
                                <td class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px;">
                                    {{ $t->reference }}
                                </td>
                                <td><span class="lp-table-amount">₦{{ number_format($t->amount_naira) }}</span></td>
                                <td style="color: var(--color-ink-soft); font-size: 12.5px;">{{ $t->note ?? '-' }}</td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-3 text-[12.5px]" style="color: var(--color-ink-soft);">
                                        @if ($t->authorization_url)
                                            <a href="{{ $t->authorization_url }}" target="_blank" rel="noopener"
                                               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">
                                                Resume
                                            </a>
                                        @endif
                                        <button type="button" wire:click="cancelTopup({{ $t->id }})"
                                                style="background: none; border: 0; cursor: pointer; color: var(--color-danger, #b00);">
                                            Cancel
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($recentTopups->isNotEmpty())
        <section class="lp-dash-card" data-testid="recent-topups">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Recent top-ups</span>
            </div>
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentTopups as $t)
                            @php
                                $tag = match ($t->status) {
                                    'succeeded' => 'lp-tag--confirmed',
                                    'failed'    => 'lp-tag--checked-out',
                                    'cancelled' => 'lp-tag--checked-out',
                                    default     => 'lp-tag--checked-in',
                                };
                            @endphp
                            <tr>
                                <td class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px; white-space: nowrap;">
                                    {{ ($t->paid_at ?? $t->created_at)?->format('d M Y · g:i A') }}
                                </td>
                                <td class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px;">{{ $t->reference }}</td>
                                <td><span class="lp-table-amount">₦{{ number_format($t->amount_naira) }}</span></td>
                                <td><span class="lp-tag {{ $tag }}">{{ ucfirst($t->status) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ── Transactions ──────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Transactions</span>
            <span class="lp-dash-card-meta">Latest first</span>
        </div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label" for="tx-tp">Type</label>
                <select id="tx-tp" wire:model.live="typeFilter" class="lp-input">
                    <option value="">All</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($transactions->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">
                No transactions yet.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Balance after</th>
                            <th>Description</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transactions as $tx)
                            @php
                                $tag = match($tx->type->value) {
                                    'topup', 'refund', 'release' => 'lp-tag--confirmed',
                                    'adjustment'                 => 'lp-tag--checked-in',
                                    default                      => 'lp-tag--checked-out',
                                };
                            @endphp
                            <tr>
                                <td class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px; white-space: nowrap;">
                                    {{ $tx->created_at?->format('d M Y · g:i A') }}
                                </td>
                                <td><span class="lp-tag {{ $tag }}">{{ $tx->type->label() }}</span></td>
                                <td style="color: {{ $tx->amount_naira >= 0 ? 'var(--color-ink)' : '#8c1e1e' }}; font-weight: 500; white-space: nowrap;">
                                    {{ $tx->amount_naira >= 0 ? '+' : '' }}₦{{ number_format($tx->amount_naira) }}
                                </td>
                                <td style="color: var(--color-ink); white-space: nowrap;">₦{{ number_format($tx->balance_after_naira) }}</td>
                                <td style="color: var(--color-ink-soft); font-size: 12.5px;">{{ $tx->description ?? '-' }}</td>
                                <td style="color: var(--color-ink-soft); font-size: 12px;">{{ $tx->member?->name ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $transactions->links() }}</div>
        </section>
    @endif
</div>
