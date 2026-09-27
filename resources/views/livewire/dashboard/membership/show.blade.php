@php
    $now = \Illuminate\Support\Carbon::now();
    $displayStatus = $sub->displayStatus($now);
    $isActive = $displayStatus === 'active';
    $isCancelled = $sub->status === \App\Models\MembershipSubscription::STATUS_CANCELLED;
@endphp

<div class="lp-dash">

    {{-- ── Back link + editorial hero ────────────────── --}}
    @include('_partials._back-button')

    <section class="lp-res-idx-hero mt-3" data-testid="member-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Membership</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    {{ $sub->customer?->name ?? 'Unnamed member' }}
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    <span class="lp-mono" style="background: var(--color-card-deep); border: 1px solid var(--color-line-soft); border-radius: 4px; padding: 1px 6px; font-size: 12.5px;">
                        {{ $sub->member_ref }}
                    </span>
                    <span style="color: var(--color-ink-mute);">·</span>
                    {{ $sub->business?->name ?? 'No business' }}
                    <span style="color: var(--color-ink-mute);">·</span>
                    {{ $sub->plan?->name ?? 'No plan' }}
                </p>
                @if ($sub->customer?->phone || $sub->customer?->email)
                    <p class="mt-1 text-[13px]" style="color: var(--color-ink-soft);">
                        @if ($sub->customer?->phone)
                            <span class="lp-mono">{{ $sub->customer->phone }}</span>
                        @endif
                        @if ($sub->customer?->email)
                            @if ($sub->customer?->phone) <span style="color: var(--color-ink-mute);">·</span> @endif
                            {{ $sub->customer->email }}
                        @endif
                    </p>
                @endif
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Membership status">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">
                        @if ($isActive)
                            <span class="lp-tag lp-tag--confirmed">Active</span>
                        @elseif ($isCancelled)
                            <span class="lp-tag lp-tag--on-hold">Cancelled</span>
                        @else
                            <span class="lp-tag lp-tag--on-hold">Expired</span>
                        @endif
                    </p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ $isActive ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};"
                       data-testid="member-hero-paid">
                        ₦{{ number_format($totalPaid) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        @if ($isActive && $sub->expires_at)
                            expires {{ $sub->expires_at->format('j M') }} · {{ $sub->daysRemaining($now) }}d left
                        @elseif ($sub->expires_at)
                            expired {{ $sub->expires_at->format('j M Y') }}
                        @else
                            total paid
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Summary strip ─────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="member-summary" style="padding: 14px 18px;">
        <div class="grid gap-4 md:grid-cols-4">
            <div>
                <p class="lp-rate-sub-label" style="margin-bottom: 4px;">Member ref</p>
                <p class="lp-mono text-[13.5px]" style="color: var(--color-ink);">{{ $sub->member_ref }}</p>
            </div>
            <div>
                <p class="lp-rate-sub-label" style="margin-bottom: 4px;">Started</p>
                <p class="text-[13.5px]" style="color: var(--color-ink);">
                    {{ $sub->started_at?->toFormattedDateString() ?? '-' }}
                </p>
            </div>
            <div>
                <p class="lp-rate-sub-label" style="margin-bottom: 4px;">Expires</p>
                <p class="text-[13.5px]" style="color: var(--color-ink);">
                    {{ $sub->expires_at?->toFormattedDateString() ?? '-' }}
                    @if ($isActive)
                        <span style="color: var(--color-ink-mute); font-size: 11.5px;">
                            in {{ $sub->daysRemaining($now) }}d
                        </span>
                    @endif
                </p>
            </div>
            <div>
                <p class="lp-rate-sub-label" style="margin-bottom: 4px;">Total paid</p>
                <p class="lp-display" style="font-size: 18px; font-weight: 600; color: var(--color-ink); letter-spacing: -0.005em;">
                    ₦{{ number_format($totalPaid) }}
                </p>
            </div>
        </div>
        @if ($isCancelled && $sub->cancellation_reason)
            <div class="mt-3 text-[12.5px]" style="color: var(--color-ink-soft); padding-top: 10px; border-top: 1px solid var(--color-line-soft);">
                <strong style="color: var(--color-ink);">Cancellation reason:</strong> {{ $sub->cancellation_reason }}
            </div>
        @endif
    </section>

    {{-- Actions --}}
    @unless ($isCancelled)
        <section class="lp-dash-card">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Actions</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="toggleRenew"
                        data-testid="action-renew"
                        class="lp-res-action lp-res-action--primary">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 8a5 5 0 018.5-3.5L13 6M13 3v3h-3M13 8a5 5 0 01-8.5 3.5L3 10M3 13v-3h3"/></svg>
                    Renew
                </button>
                <button type="button" wire:click="toggleExtend"
                        data-testid="action-extend"
                        class="lp-res-action">
                    Extend
                </button>
                <button type="button" wire:click="togglePayment"
                        data-testid="action-payment"
                        class="lp-res-action">
                    Take payment
                </button>
                <button type="button" wire:click="toggleCancel"
                        data-testid="action-cancel"
                        class="lp-res-action lp-res-action--ghost"
                        style="color: #B85C42;">
                    Cancel
                </button>
            </div>

            {{-- Renew form --}}
            @if ($renewOpen)
                <div style="margin-top: 12px; padding: 12px 14px;
                            background: var(--color-surface-2); border-radius: 10px;
                            display: grid; gap: 10px;"
                     data-testid="renew-panel">
                    <div class="text-[12.5px]" style="color: var(--color-ink-soft);">
                        <strong style="color: var(--color-ink);">Renew.</strong>
                        Rolls the expiry forward by the plan's validity, starting from the later
                        of today or the current expiry.
                    </div>
                    <div class="grid gap-2 md:grid-cols-3">
                        <div>
                            <label class="lp-form-label">Plan</label>
                            <select wire:model.live="renewPlanId" class="lp-input"
                                    data-testid="renew-plan">
                                @foreach ($planOptions as $p)
                                    <option value="{{ $p->id }}">
                                        {{ $p->name }} · {{ $p->validity_days }}d · &#8358;{{ number_format($p->price) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="lp-form-label">Amount (&#8358;)</label>
                            <input type="number" min="0" step="1" wire:model="renewAmount"
                                   class="lp-input" data-testid="renew-amount">
                        </div>
                        <div>
                            <label class="lp-form-label">Method</label>
                            <select wire:model="renewMethod" class="lp-input" data-testid="renew-method">
                                <option value="cash">Cash</option>
                                <option value="transfer">Bank transfer</option>
                                <option value="card">Card</option>
                                <option value="pos">POS terminal</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="toggleRenew"
                                class="lp-btn" style="font-size: 12px;">Cancel</button>
                        <button type="button" wire:click="renew"
                                data-testid="renew-submit"
                                class="lp-btn lp-btn--primary" style="font-size: 12px;">Renew</button>
                    </div>
                </div>
            @endif

            {{-- Extend form --}}
            @if ($extendOpen)
                <div style="margin-top: 12px; padding: 12px 14px;
                            background: var(--color-surface-2); border-radius: 10px;
                            display: grid; gap: 10px;"
                     data-testid="extend-panel">
                    <div class="text-[12.5px]" style="color: var(--color-ink-soft);">
                        <strong style="color: var(--color-ink);">Extend.</strong>
                        Add days without a fresh cycle. Useful for make-good days after downtime.
                    </div>
                    <div class="grid gap-2 md:grid-cols-3">
                        <div>
                            <label class="lp-form-label">Days to add</label>
                            <input type="number" min="1" step="1" wire:model="extendDays"
                                   class="lp-input" data-testid="extend-days">
                        </div>
                        <div>
                            <label class="lp-form-label">Amount (&#8358;)</label>
                            <input type="number" min="0" step="1" wire:model="extendAmount"
                                   class="lp-input" data-testid="extend-amount"
                                   placeholder="0 for comp">
                        </div>
                        <div>
                            <label class="lp-form-label">Method</label>
                            <select wire:model="extendMethod" class="lp-input" data-testid="extend-method">
                                <option value="cash">Cash</option>
                                <option value="transfer">Bank transfer</option>
                                <option value="card">Card</option>
                                <option value="pos">POS terminal</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="toggleExtend"
                                class="lp-btn" style="font-size: 12px;">Cancel</button>
                        <button type="button" wire:click="extend"
                                data-testid="extend-submit"
                                class="lp-btn lp-btn--primary" style="font-size: 12px;">Extend</button>
                    </div>
                </div>
            @endif

            {{-- Take payment form --}}
            @if ($paymentOpen)
                <div style="margin-top: 12px; padding: 12px 14px;
                            background: var(--color-surface-2); border-radius: 10px;
                            display: grid; gap: 10px;"
                     data-testid="payment-panel">
                    <div class="text-[12.5px]" style="color: var(--color-ink-soft);">
                        <strong style="color: var(--color-ink);">Take payment.</strong>
                        Record a receipt without changing the period.
                    </div>
                    <div class="grid gap-2 md:grid-cols-3">
                        <div>
                            <label class="lp-form-label">Amount (&#8358;)</label>
                            <input type="number" min="1" step="1" wire:model="paymentAmount"
                                   class="lp-input" data-testid="payment-amount">
                        </div>
                        <div>
                            <label class="lp-form-label">Method</label>
                            <select wire:model="paymentMethod" class="lp-input" data-testid="payment-method">
                                <option value="cash">Cash</option>
                                <option value="transfer">Bank transfer</option>
                                <option value="card">Card</option>
                                <option value="pos">POS terminal</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label class="lp-form-label">Note (optional)</label>
                            <input type="text" wire:model="paymentNote" class="lp-input"
                                   data-testid="payment-note">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="togglePayment"
                                class="lp-btn" style="font-size: 12px;">Cancel</button>
                        <button type="button" wire:click="takePayment"
                                data-testid="payment-submit"
                                class="lp-btn lp-btn--primary" style="font-size: 12px;">Record</button>
                    </div>
                </div>
            @endif

            {{-- Cancel form --}}
            @if ($cancelOpen)
                <div style="margin-top: 12px; padding: 12px 14px;
                            background: rgba(184, 92, 66, 0.05); border-radius: 10px;
                            display: grid; gap: 10px;"
                     data-testid="cancel-panel">
                    <div class="text-[12.5px]" style="color: var(--color-ink-soft);">
                        <strong style="color: #B85C42;">Cancel membership.</strong>
                        Immediately revokes access. Payment history stays intact.
                    </div>
                    <div>
                        <label class="lp-form-label">Reason (optional)</label>
                        <input type="text" wire:model="cancelReason" class="lp-input"
                               data-testid="cancel-reason"
                               placeholder="e.g. Member relocated, refund issued off-platform">
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="toggleCancel"
                                class="lp-btn" style="font-size: 12px;">Keep active</button>
                        <button type="button" wire:click="cancel"
                                data-testid="cancel-submit"
                                class="lp-btn" style="font-size: 12px; color: #B85C42;">
                            Cancel membership
                        </button>
                    </div>
                </div>
            @endif
        </section>
    @endunless

    {{-- Payment history --}}
    <section class="lp-dash-card" style="padding: 14px 16px;">
        <div class="lp-dash-card-head" style="margin-bottom: 12px;">
            <span class="lp-dash-card-title">Payment history</span>
            <span class="lp-dash-card-meta">
                {{ $payments->count() }} {{ Str::plural('entry', $payments->count()) }}
                · ₦{{ number_format($payments->sum('amount')) }} collected
            </span>
        </div>
        @if ($payments->isEmpty())
            <div data-testid="no-payments">
                @include('livewire.dashboard._partials._empty', [
                    'icon'  => 'wallet',
                    'title' => 'No payments recorded yet.',
                    'sub'   => 'Every renewal, extension, or standalone payment lands here.',
                ])
            </div>
        @else
            <ul class="lp-ledger lp-invoice-payments" data-testid="payment-history-table">
                @foreach ($payments as $p)
                    @php
                        $method = $p->method ?? 'other';
                        $tone   = in_array($method, ['card', 'paystack', 'pos'], true) ? 'paid'
                                : ($method === 'transfer' ? 'info'
                                : ($method === 'cash' ? 'warn' : 'muted'));
                    @endphp
                    <li class="lp-ledger-item" data-testid="payment-row-{{ $p->id }}">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <span class="lp-ledger-desc" style="text-transform: capitalize;">{{ $method }}</span>
                                <span class="lp-tag" style="font-size: 9.5px; padding: 1px 6px;">
                                    {{ ucfirst((string) $p->membership_kind) }}
                                </span>
                                @if ($p->paystack_reference)
                                    <span class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px; background: var(--color-card-deep); border: 1px solid var(--color-line-soft); border-radius: 4px; padding: 1px 6px;">
                                        {{ $p->paystack_reference }}
                                    </span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                {{ $p->paid_at?->toFormattedDateString() ?? '-' }}
                                @if ($p->paid_at)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ $p->paid_at->format('g:i A') }}
                                @endif
                                @if ($p->membership_period_start && $p->membership_period_end)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ $p->membership_period_start->format('j M') }} → {{ $p->membership_period_end->format('j M Y') }}
                                @endif
                                @if ($p->collectedBy?->name)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    collected by {{ $p->collectedBy->name }}
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right">
                            <span class="lp-invoice-total">₦{{ number_format($p->amount) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

</div>
