<div class="lp-dash">

    <div>
        <span class="lp-eyebrow-strip">Recurring revenue</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Subscriptions
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Every subscription on the platform, trial, active, expired. Extend inline or jump to the tenant page for deeper actions.
        </p>
    </div>

    {{-- ── Counters ──────────────────────────────────────────────── --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="stats">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Live</span>
            <p data-testid="stat-live"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($stats['live']) }}
            </p>
            <span class="lp-tag lp-tag--confirmed" style="margin-top: 8px;">Trial + active</span>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Expiring &lt; 30 days</span>
            <p data-testid="stat-expiring"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($stats['expiring_soon']) }}
            </p>
            <span class="lp-tag lp-tag--checked-in" style="margin-top: 8px;">Needs follow-up</span>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">On trial</span>
            <p data-testid="stat-trial"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                {{ number_format($stats['trial']) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                  style="color: var(--color-ink-mute);">Annual ARR</span>
            <p data-testid="stat-arr"
               class="lp-display mt-2 text-[28px]"
               style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($stats['arr_ngn']) }}
            </p>
        </div>
    </div>

    {{-- ── Filters ───────────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Filter</span>
            <button type="button" wire:click="clearFilters"
                    data-testid="clear-filters"
                    style="background: none; border: 0; cursor: pointer; color: var(--color-ink-soft); font-size: 12px; text-decoration: underline; text-underline-offset: 4px;">
                Clear all
            </button>
        </div>

        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="sub-q">Tenant</label>
                <input id="sub-q" type="search"
                       wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Name, slug or email"
                       data-testid="search">
            </div>
            <div>
                <label class="lp-form-label" for="sub-status">Status</label>
                <select id="sub-status" wire:model.live="statusFilter" class="lp-input"
                        data-testid="status-filter">
                    <option value="">Any status</option>
                    @foreach ($statusOptions as $opt)
                        <option value="{{ $opt->value }}">{{ ucfirst($opt->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="lp-form-label" for="sub-plan">Plan</label>
                <select id="sub-plan" wire:model.live="planFilter" class="lp-input"
                        data-testid="plan-filter">
                    <option value="">Any plan</option>
                    @foreach ($planOptions as $opt)
                        <option value="{{ $opt->value }}">{{ $opt->value }}</option>
                    @endforeach
                </select>
            </div>
            <div class="lg:col-span-4">
                <label class="lp-form-label">Expiring within</label>
                <div class="flex gap-2 mt-1" data-testid="expiring-filter">
                    @foreach ([['', 'Any'], ['7', '7 days'], ['30', '30 days'], ['90', '90 days']] as [$value, $label])
                        <button type="button"
                                wire:click="$set('expiringWindow', '{{ $value }}')"
                                class="lp-tag"
                                style="cursor: pointer; padding: 6px 12px;
                                       {{ $expiringWindow === $value
                                          ? 'background: var(--color-ink); color: var(--color-card); border-color: var(--color-ink);'
                                          : '' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        </form>
    </section>

    {{-- ── Table ─────────────────────────────────────────────────── --}}
    @if ($rows->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="empty" class="text-[18px]"
               style="color: var(--color-ink); font-weight: 500;">
                No subscriptions match the filter.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="subs-table">
                    <thead>
                        <tr>
                            <th>Tenant</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Started</th>
                            <th>Expires</th>
                            <th>Amount</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $sub)
                            @php
                                $statusClass = match ($sub->status->value) {
                                    'active', 'trial' => 'lp-tag--confirmed',
                                    'expired'         => 'lp-tag--checked-out',
                                    'failed'          => 'lp-tag--checked-out',
                                    default           => 'lp-tag--checked-in',
                                };
                                $expiresSoon = $sub->expires_at->isFuture()
                                    && $sub->expires_at->diffInDays(now()) <= 14
                                    && $sub->status->value === 'active';
                                $expired = $sub->expires_at->isPast();
                            @endphp
                            <tr data-testid="sub-row-{{ $sub->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $sub->tenant?->name ?? '-' }}
                                    @if ($sub->tenant)
                                        <div class="lp-mono"
                                             style="color: var(--color-ink-mute); font-size: 11.5px; margin-top: 2px;">
                                            {{ $sub->tenant->slug }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="lp-mono"
                                          style="color: var(--color-ink-soft); font-size: 11.5px;">
                                        {{ $sub->plan->value }}
                                    </span>
                                </td>
                                <td>
                                    <span class="lp-tag {{ $statusClass }}">{{ $sub->status->value }}</span>
                                </td>
                                <td style="color: var(--color-ink-soft); font-size: 12.5px; white-space: nowrap;">
                                    {{ $sub->starts_at?->format('d M Y') }}
                                </td>
                                <td style="font-size: 12.5px; white-space: nowrap;
                                           color: {{ $expired ? '#8c1e1e' : ($expiresSoon ? '#a36318' : 'var(--color-ink-soft)') }};">
                                    {{ $sub->expires_at?->format('d M Y') }}
                                    @if ($expiresSoon || $expired)
                                        <div class="lp-mono"
                                             style="font-size: 10.5px; margin-top: 2px;">
                                            {{ $expired ? 'expired '.$sub->expires_at->diffForHumans() : 'in '.$sub->expires_at->diffForHumans(['parts' => 1]) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="lp-table-amount">₦{{ number_format($sub->amount) }}</span>
                                </td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-3 text-[12.5px]"
                                         style="color: var(--color-ink-soft);">
                                        @if ($sub->tenant)
                                            <a href="{{ route('admin.tenants.show', $sub->tenant) }}" wire:navigate
                                               data-testid="view-{{ $sub->id }}"
                                               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">
                                                View
                                            </a>
                                        @endif
                                        <button type="button"
                                                wire:click="extend({{ $sub->id }}, 30)"
                                                data-testid="extend-30-{{ $sub->id }}"
                                                style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">
                                            +30d
                                        </button>
                                        <button type="button"
                                                wire:click="extend({{ $sub->id }}, 90)"
                                                wire:confirm="Extend by 90 days?"
                                                data-testid="extend-90-{{ $sub->id }}"
                                                style="color: var(--color-ink-soft); background: none; border: 0; cursor: pointer;">
                                            +90d
                                        </button>
                                        @if ($sub->tenant)
                                            @php
                                                $isPaidActive = $sub->status->value === 'active'
                                                    && $sub->plan->value !== 'trial';
                                            @endphp
                                            @if ($isPaidActive)
                                                <button type="button"
                                                        wire:click="openRenew({{ $sub->tenant->id }})"
                                                        data-testid="renew-{{ $sub->id }}"
                                                        style="color: var(--color-ink); background: none; border: 0; cursor: pointer; font-weight: 500; text-decoration: underline; text-underline-offset: 4px;">
                                                    Renew
                                                </button>
                                            @else
                                                <button type="button"
                                                        wire:click="openActivate({{ $sub->tenant->id }})"
                                                        data-testid="activate-{{ $sub->id }}"
                                                        style="color: var(--color-ink); background: none; border: 0; cursor: pointer; font-weight: 500; text-decoration: underline; text-underline-offset: 4px;">
                                                    Activate
                                                </button>
                                            @endif
                                        @endif
                                    </div>
                                    @if ($sub->outstanding() > 0)
                                        <div style="margin-top: 6px; display: flex; align-items: center; gap: 8px; justify-content: flex-end;">
                                            <span class="lp-mono" style="font-size: 10.5px; color: #8c1e1e;">
                                                Outstanding ₦{{ number_format($sub->outstanding()) }}
                                            </span>
                                            <button type="button"
                                                    wire:click="openPay({{ $sub->id }})"
                                                    data-testid="record-payment-{{ $sub->id }}"
                                                    style="color: var(--color-ink); background: none; border: 0; cursor: pointer; font-size: 12px; font-weight: 500; text-decoration: underline; text-underline-offset: 4px;">
                                                Record payment
                                            </button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $rows->links() }}</div>
        </section>
    @endif

    {{-- ── Activate / Renew modal ────────────────────────────── --}}
    @if ($formOpen)
        <div class="fixed inset-0 z-40 flex items-center justify-center"
             style="background: rgba(20, 18, 15, 0.55); padding: 20px;"
             wire:click.self="closeForm"
             data-testid="sub-form-modal">
            <div class="lp-dash-card"
                 style="width: 100%; max-width: 560px; padding: 26px 28px; background: var(--color-card);">
                <div class="flex items-center justify-between">
                    <span class="lp-eyebrow-strip">
                        {{ $formMode === 'renew' ? 'Renew subscription' : 'Activate subscription' }}
                    </span>
                    <button type="button" wire:click="closeForm"
                            data-testid="sub-form-close"
                            style="background: none; border: 0; cursor: pointer; color: var(--color-ink-mute); font-size: 22px; line-height: 1;">
                        &times;
                    </button>
                </div>
                <h2 class="lp-display mt-2 text-[22px]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.1;">
                    {{ $formTenantName ?? 'Tenant' }}
                </h2>
                <p class="mt-1 text-[13px]" style="color: var(--color-ink-soft);">
                    Record the agreed contract terms, payment received to date, and (if any) outstanding balance. Contract, receipt, and invoice mails are queued automatically.
                </p>

                <form wire:submit.prevent="saveForm" class="mt-5 grid gap-4">
                    <div>
                        <label class="lp-form-label" for="form-plan">Plan</label>
                        <select id="form-plan" wire:model="formPlan" class="lp-input"
                                data-testid="form-plan">
                            @foreach ($planOptions as $opt)
                                <option value="{{ $opt->value }}">{{ $opt->value }}</option>
                            @endforeach
                        </select>
                        @error('formPlan') <p class="mt-1 text-[12px]" style="color: #8c1e1e;">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="lp-form-label" for="form-contract">Contract amount (₦)</label>
                            <input id="form-contract" type="number" min="0" step="1"
                                   wire:model="formContract" class="lp-input"
                                   data-testid="form-contract">
                            @error('formContract') <p class="mt-1 text-[12px]" style="color: #8c1e1e;">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="lp-form-label" for="form-received">Amount received (₦)</label>
                            <input id="form-received" type="number" min="0" step="1"
                                   wire:model="formReceived" class="lp-input"
                                   data-testid="form-received">
                            @error('formReceived') <p class="mt-1 text-[12px]" style="color: #8c1e1e;">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="lp-form-label" for="form-duration">Duration (months)</label>
                        <input id="form-duration" type="number" min="1" max="36" step="1"
                               wire:model="formDuration" class="lp-input"
                               data-testid="form-duration">
                        @error('formDuration') <p class="mt-1 text-[12px]" style="color: #8c1e1e;">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="lp-form-label" for="form-notes">Agreement notes (optional)</label>
                        <textarea id="form-notes" wire:model="formNotes" class="lp-input"
                                  rows="3" data-testid="form-notes"
                                  placeholder="e.g. installment plan, discount rationale"></textarea>
                        @error('formNotes') <p class="mt-1 text-[12px]" style="color: #8c1e1e;">{{ $message }}</p> @enderror
                    </div>

                    @php
                        $outstanding = max(0, (int) $formContract - (int) $formReceived);
                    @endphp
                    <div class="lp-mono" style="font-size: 11.5px; color: var(--color-ink-soft);">
                        @if ($outstanding > 0)
                            Outstanding: <strong style="color: #8c1e1e;">₦{{ number_format($outstanding) }}</strong>. Contract, receipt, and invoice mails will be queued.
                        @else
                            Fully paid. Contract and receipt mails will be queued.
                        @endif
                    </div>

                    <div class="mt-1 flex items-center justify-end gap-3">
                        <button type="button" wire:click="closeForm"
                                data-testid="form-cancel"
                                style="background: none; border: 0; cursor: pointer; color: var(--color-ink-soft); text-decoration: underline; text-underline-offset: 4px;">
                            Cancel
                        </button>
                        <button type="submit"
                                class="lp-btn lp-btn--primary"
                                data-testid="form-save">
                            {{ $formMode === 'renew' ? 'Renew' : 'Activate' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ── Record-payment modal ──────────────────────────────── --}}
    @if ($payOpen)
        <div class="fixed inset-0 z-40 flex items-center justify-center"
             style="background: rgba(20, 18, 15, 0.55); padding: 20px;"
             wire:click.self="closePay"
             data-testid="record-payment-modal">
            <div class="lp-dash-card"
                 style="width: 100%; max-width: 480px; padding: 26px 28px; background: var(--color-card);">
                <div class="flex items-center justify-between">
                    <span class="lp-eyebrow-strip">Record payment</span>
                    <button type="button" wire:click="closePay"
                            data-testid="pay-close"
                            style="background: none; border: 0; cursor: pointer; color: var(--color-ink-mute); font-size: 22px; line-height: 1;">
                        &times;
                    </button>
                </div>
                <h2 class="lp-display mt-2 text-[22px]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.1;">
                    {{ $payTenantName ?? 'Tenant' }}
                </h2>
                <p class="mt-1 text-[13px]" style="color: var(--color-ink-soft);">
                    Book an inbound payment against this subscription's outstanding balance. A fresh receipt is queued, and an updated invoice if any balance remains.
                </p>

                <form wire:submit.prevent="savePay" class="mt-5 grid gap-4">
                    <div class="lp-dash-card" style="padding: 12px 14px; background: var(--color-card-deep); border-radius: 8px;">
                        <span class="lp-mono" style="font-size: 10.5px; color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.14em;">Outstanding</span>
                        <p class="lp-display" style="font-size: 22px; color: var(--color-ink); font-weight: 600; margin-top: 2px;">
                            ₦{{ number_format($payOutstanding) }}
                        </p>
                    </div>

                    <div>
                        <label class="lp-form-label" for="pay-amount">Amount received (₦)</label>
                        <input id="pay-amount" type="number" min="1" max="{{ $payOutstanding }}" step="1"
                               wire:model="payAmount" class="lp-input"
                               data-testid="pay-amount">
                        @error('payAmount') <p class="mt-1 text-[12px]" style="color: #8c1e1e;">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="lp-form-label" for="pay-notes">Notes (optional)</label>
                        <textarea id="pay-notes" wire:model="payNotes" class="lp-input"
                                  rows="2" data-testid="pay-notes"
                                  placeholder="e.g. transfer from GTB, ref XYZ"></textarea>
                        @error('payNotes') <p class="mt-1 text-[12px]" style="color: #8c1e1e;">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-1 flex items-center justify-end gap-3">
                        <button type="button" wire:click="closePay"
                                data-testid="pay-cancel"
                                style="background: none; border: 0; cursor: pointer; color: var(--color-ink-soft); text-decoration: underline; text-underline-offset: 4px;">
                            Cancel
                        </button>
                        <button type="submit"
                                class="lp-btn lp-btn--primary"
                                data-testid="pay-save">
                            Record payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
