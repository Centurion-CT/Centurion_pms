@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';

    $statusClass = match($corporate->status->value) {
        'active'     => 'lp-tag--confirmed',
        'onboarding' => 'lp-tag--checked-in',
        default      => 'lp-tag--checked-out',
    };
    $kycClass = match($corporate->kyc_status->value) {
        'verified' => 'lp-tag--confirmed',
        'rejected' => 'lp-tag--checked-out',
        default    => 'lp-tag--checked-in',
    };

    $tabs = [
        'overview' => 'Overview',
        'members'  => 'Members',
        'wallet'   => 'Wallet',
        'rates'    => 'Rates',
        'domains'  => 'Domains',
    ];
@endphp

<div class="lp-dash">

    @include('_partials._back-button')

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Corporate</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $corporate->name }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                {{ $corporate->primary_email }}
                <span style="color: var(--color-ink-mute);">·</span>
                <code class="lp-mono" style="font-size: 12.5px;">{{ $corporate->slug }}</code>
            </p>
        </div>
        <div class="flex items-center gap-3">
            <span class="lp-tag {{ $statusClass }}">{{ $corporate->status->label() }}</span>
            <span class="lp-tag {{ $kycClass }}">KYC · {{ $corporate->kyc_status->label() }}</span>
            <a href="{{ route('admin.corporates.edit', $corporate) }}" wire:navigate
               class="lp-btn">Edit</a>
        </div>
    </div>

    {{-- ── Wallet summary strip ──────────────────────────────────── --}}
    <div class="grid gap-3 sm:grid-cols-3" data-testid="wallet-summary">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Wallet balance</span>
            <p data-testid="wallet-balance" class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($wallet->balance_naira) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">On hold</span>
            <p data-testid="wallet-held" class="lp-display mt-2 text-[28px]" style="color: var(--color-ink-soft); font-weight: 600;">
                ₦{{ number_format($wallet->held_naira) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Available</span>
            <p data-testid="wallet-available" class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($wallet->available()) }}
            </p>
        </div>
    </div>

    {{-- ── Tabs ──────────────────────────────────────────────────── --}}
    <div class="lp-chips" data-testid="corp-tabs" style="border-bottom: 1px solid var(--color-line-soft); padding-bottom: 4px;">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                    class="lp-chip @if ($tab === $key) is-active @endif"
                    data-testid="tab-{{ $key }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ── Overview ──────────────────────────────────────────────── --}}
    @if ($tab === 'overview')
        <section class="lp-dash-card" data-testid="tab-pane-overview">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Summary</span>
                <span class="lp-dash-card-meta">Created {{ $corporate->created_at?->toFormattedDateString() }}</span>
            </div>

            <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2" style="margin-top: 8px;">
                <div>
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Primary phone</dt>
                    <dd class="mt-1" style="color: var(--color-ink); font-size: 13.5px;">{{ $corporate->primary_phone ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Billing email</dt>
                    <dd class="mt-1" style="color: var(--color-ink); font-size: 13.5px;">{{ $corporate->billing_email ?? $corporate->primary_email }}</dd>
                </div>
                <div>
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Address</dt>
                    <dd class="mt-1" style="color: var(--color-ink); font-size: 13.5px;">
                        {{ $corporate->address ?? '-' }}{{ $corporate->city ? ', '.$corporate->city : '' }}
                        <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 11.5px;">· {{ $corporate->country }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Registration / tax</dt>
                    <dd class="mt-1 lp-mono" style="color: var(--color-ink); font-size: 12.5px;">
                        {{ $corporate->registration_number ?? '-' }} · {{ $corporate->tax_id ?? '-' }}
                    </dd>
                </div>
                <div>
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Approval threshold</dt>
                    <dd class="mt-1" style="color: var(--color-ink); font-size: 13.5px;">
                        @if ($corporate->approval_threshold_naira)
                            Bookings &gt; ₦{{ number_format($corporate->approval_threshold_naira) }} require admin sign-off
                        @else
                            None, any amount auto-confirms
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Contract</dt>
                    <dd class="mt-1" style="color: var(--color-ink); font-size: 13.5px;">
                        {{ $corporate->contract_signed_at?->toFormattedDateString() ?? 'Not signed' }}
                    </dd>
                </div>
                @if ($corporate->kyc_notes)
                    <div class="sm:col-span-2">
                        <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">KYC notes</dt>
                        <dd class="mt-1" style="color: var(--color-ink-soft); font-size: 13px; white-space: pre-wrap;">{{ $corporate->kyc_notes }}</dd>
                    </div>
                @endif
                @if ($corporate->notes)
                    <div class="sm:col-span-2">
                        <dt class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Internal notes</dt>
                        <dd class="mt-1" style="color: var(--color-ink-soft); font-size: 13px; white-space: pre-wrap;">{{ $corporate->notes }}</dd>
                    </div>
                @endif
            </dl>
        </section>
    @endif

    {{-- ── Members ───────────────────────────────────────────────── --}}
    @if ($tab === 'members')
        <section class="lp-dash-card" data-testid="tab-pane-members">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Add member</span>
                <span class="lp-dash-card-meta">{{ $members->count() }} on roster</span>
            </div>

            <form wire:submit="addMember" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="add-member-form">
                <div>
                    <label class="lp-form-label" for="m-name">Name</label>
                    <input id="m-name" type="text" wire:model.live="member_name" class="lp-input" required maxlength="160">
                    @error('member_name') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lp-form-label" for="m-em">Work email</label>
                    <input id="m-em" type="email" wire:model.live="member_email" class="lp-input" required maxlength="180">
                    @error('member_email') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lp-form-label" for="m-ph">Phone</label>
                    <input id="m-ph" type="text" wire:model.live="member_phone" class="lp-input" maxlength="32">
                </div>
                <div>
                    <label class="lp-form-label" for="m-eid">Employee ID</label>
                    <input id="m-eid" type="text" wire:model.live="member_employee_id" class="lp-input" maxlength="64" placeholder="Optional">
                </div>
                <div>
                    <label class="lp-form-label" for="m-rl">Role</label>
                    <select id="m-rl" wire:model.live="member_role" class="lp-input">
                        @foreach ($memberRoles as $r)
                            <option value="{{ $r->value }}">{{ $r->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="lp-form-label" for="m-dc">Daily cap (₦)</label>
                    <input id="m-dc" type="number" min="0" wire:model.live="member_daily_cap" class="lp-input" placeholder="Blank = unlimited">
                </div>
                <div>
                    <label class="lp-form-label" for="m-mc">Monthly cap (₦)</label>
                    <input id="m-mc" type="number" min="0" wire:model.live="member_monthly_cap" class="lp-input" placeholder="Blank = unlimited">
                </div>
                <div class="sm:col-span-2 lg:col-span-3 flex justify-end">
                    <button type="submit" class="lp-pill" data-testid="add-member-btn">
                        Add member <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </button>
                </div>
            </form>
        </section>

        @if ($members->isNotEmpty())
            <section class="lp-dash-card" style="padding: 0;">
                <div class="overflow-x-auto">
                    <table class="lp-table" data-testid="members-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Daily cap</th>
                                <th>Monthly cap</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($members as $m)
                                @php
                                    $stClass = match($m->status->value) {
                                        'active'     => 'lp-tag--confirmed',
                                        'invited'    => 'lp-tag--checked-in',
                                        default      => 'lp-tag--checked-out',
                                    };
                                @endphp
                                <tr data-testid="m-row-{{ $m->id }}">
                                    <td style="color: var(--color-ink); font-weight: 500;">
                                        {{ $m->name }}
                                        @if ($m->employee_id)
                                            <div class="lp-mono" style="color: var(--color-ink-mute); font-size: 11px; margin-top: 2px;">{{ $m->employee_id }}</div>
                                        @endif
                                    </td>
                                    <td style="color: var(--color-ink-soft); font-size: 13px;">{{ $m->email }}</td>
                                    <td>
                                        <span class="lp-tag {{ $m->role->value === 'admin' ? 'lp-tag--confirmed' : 'lp-tag--checked-in' }}">
                                            {{ $m->role->label() }}
                                        </span>
                                    </td>
                                    <td><span class="lp-tag {{ $stClass }}">{{ $m->status->label() }}</span></td>
                                    <td style="color: var(--color-ink-soft);">{{ $m->daily_cap_naira ? '₦'.number_format($m->daily_cap_naira) : '-' }}</td>
                                    <td style="color: var(--color-ink-soft);">{{ $m->monthly_cap_naira ? '₦'.number_format($m->monthly_cap_naira) : '-' }}</td>
                                    <td style="text-align: right;">
                                        <div class="inline-flex items-center gap-3 text-[12.5px]" style="color: var(--color-ink-soft);">
                                            <button type="button" wire:click="toggleMemberStatus({{ $m->id }})"
                                                    style="background: none; border: 0; cursor: pointer; color: var(--color-ink-soft);">
                                                {{ $m->status->value === 'active' ? 'Suspend' : 'Activate' }}
                                            </button>
                                            <button type="button" wire:click="removeMember({{ $m->id }})"
                                                    wire:confirm="Offboard this member?"
                                                    style="background: none; border: 0; cursor: pointer; color: var(--color-danger, #b00);">
                                                Offboard
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
    @endif

    {{-- ── Wallet ────────────────────────────────────────────────── --}}
    @if ($tab === 'wallet')
        <section data-testid="tab-pane-wallet" class="grid gap-4 lg:grid-cols-2">
            <div class="lp-dash-card">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Top up wallet</span>
                </div>
                <form wire:submit="topup" style="display: grid; gap: 14px;" data-testid="topup-form">
                    <div>
                        <label class="lp-form-label" for="tu-amt">Amount (₦)</label>
                        <input id="tu-amt" type="number" min="1" wire:model.live="topup_amount" class="lp-input" required data-testid="topup-amount">
                        @error('topup_amount') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lp-form-label" for="tu-ref">Paystack reference</label>
                        <input id="tu-ref" type="text" wire:model.live="topup_reference" class="lp-input" maxlength="64" placeholder="Optional · for bank transfers">
                    </div>
                    <div>
                        <label class="lp-form-label" for="tu-note">Note</label>
                        <input id="tu-note" type="text" wire:model.live="topup_note" class="lp-input" maxlength="240">
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="lp-pill" data-testid="topup-submit">
                            Top up <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="lp-dash-card">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Manual adjustment</span>
                </div>
                <form wire:submit="adjust" style="display: grid; gap: 14px;" data-testid="adjust-form">
                    <div>
                        <label class="lp-form-label" for="aj-amt">Signed amount (₦)</label>
                        <input id="aj-amt" type="number" wire:model.live="adjust_amount" class="lp-input" required data-testid="adjust-amount">
                        <p class="lp-form-help" style="margin-top: 6px;">
                            Positive credits the wallet; negative debits it. Use for off-platform reconciliation.
                        </p>
                        @error('adjust_amount') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lp-form-label" for="aj-note">Reason</label>
                        <input id="aj-note" type="text" wire:model.live="adjust_note" class="lp-input" maxlength="240" required>
                        @error('adjust_note') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="lp-pill" data-testid="adjust-submit">
                            Apply adjustment <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="lp-dash-card lg:col-span-2" style="padding: 0;">
                <div class="lp-dash-card-head" style="padding: 20px 24px;">
                    <span class="lp-dash-card-title">Transactions</span>
                    <span class="lp-dash-card-meta">Latest 50</span>
                </div>
                @if ($transactions->isEmpty())
                    <p class="text-center" style="padding: 48px 24px; color: var(--color-ink-soft); font-size: 14px;">
                        No movements yet.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="lp-table" data-testid="tx-table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Balance after</th>
                                    <th>Held after</th>
                                    <th>Description</th>
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
                                        <td style="color: var(--color-ink-soft); white-space: nowrap;">₦{{ number_format($tx->held_after_naira) }}</td>
                                        <td style="color: var(--color-ink-soft); font-size: 12.5px;">
                                            {{ $tx->description ?? '-' }}
                                            @if ($tx->paystack_reference)
                                                <div class="lp-mono" style="color: var(--color-ink-mute); font-size: 11px; margin-top: 2px;">{{ $tx->paystack_reference }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- ── Rates ─────────────────────────────────────────────────── --}}
    @if ($tab === 'rates')
        <section class="lp-dash-card" data-testid="tab-pane-rates">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Add negotiated rate</span>
            </div>
            <form wire:submit="addRate" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="add-rate-form">
                <div>
                    <label class="lp-form-label" for="r-sc">Scope</label>
                    <select id="r-sc" wire:model.live="rate_scope" class="lp-input">
                        @foreach ($rateScopes as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($rate_scope !== 'global')
                    <div>
                        <label class="lp-form-label" for="r-id">Scope ID</label>
                        <input id="r-id" type="number" min="1" wire:model.live="rate_scope_id" class="lp-input"
                               placeholder="e.g. property_id or category_id">
                        @error('rate_scope_id') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label class="lp-form-label" for="r-tp">Rate type</label>
                    <select id="r-tp" wire:model.live="rate_type" class="lp-input">
                        @foreach ($rateTypes as $rt)
                            <option value="{{ $rt->value }}">{{ $rt->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="lp-form-label" for="r-vl">Value</label>
                    <input id="r-vl" type="number" min="0" wire:model.live="rate_value" class="lp-input" required>
                    @error('rate_value') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lp-form-label" for="r-st">Starts at</label>
                    <input id="r-st" type="date" wire:model.live="rate_starts_at" class="lp-input">
                </div>
                <div>
                    <label class="lp-form-label" for="r-en">Ends at</label>
                    <input id="r-en" type="date" wire:model.live="rate_ends_at" class="lp-input">
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="lp-form-label" for="r-no">Notes</label>
                    <input id="r-no" type="text" wire:model.live="rate_notes" class="lp-input" maxlength="500" placeholder="Optional · context for the contract review">
                </div>
                <div class="sm:col-span-2 lg:col-span-3 flex justify-end">
                    <button type="submit" class="lp-pill" data-testid="add-rate-btn">
                        Add rate <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </button>
                </div>
            </form>
        </section>

        @if ($corporate->rates->isNotEmpty())
            <section class="lp-dash-card" style="padding: 0;">
                <div class="overflow-x-auto">
                    <table class="lp-table" data-testid="rates-table">
                        <thead>
                            <tr>
                                <th>Scope</th>
                                <th>Type</th>
                                <th>Value</th>
                                <th>Window</th>
                                <th>Notes</th>
                                <th style="text-align: right;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($corporate->rates as $r)
                                <tr data-testid="rate-row-{{ $r->id }}">
                                    <td style="color: var(--color-ink); font-weight: 500;">
                                        {{ $r->scope->label() }}
                                        @if ($r->scope_id)
                                            <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 11.5px; margin-left: 6px;">#{{ $r->scope_id }}</span>
                                        @endif
                                    </td>
                                    <td style="color: var(--color-ink-soft);">{{ $r->rate_type->label() }}</td>
                                    <td>
                                        @if ($r->rate_type->value === 'percent_off')
                                            <span class="lp-table-amount">{{ $r->rate_value }}%</span>
                                        @else
                                            <span class="lp-table-amount">₦{{ number_format($r->rate_value) }}</span>
                                        @endif
                                    </td>
                                    <td style="color: var(--color-ink-soft); font-size: 12.5px;">
                                        {{ $r->starts_at?->format('d M Y') ?? '-' }}
                                        →
                                        {{ $r->ends_at?->format('d M Y') ?? '-' }}
                                    </td>
                                    <td style="color: var(--color-ink-soft); font-size: 12.5px;">{{ $r->notes ?? '-' }}</td>
                                    <td style="text-align: right;">
                                        <button type="button" wire:click="removeRate({{ $r->id }})"
                                                wire:confirm="Remove this rate?"
                                                style="background: none; border: 0; cursor: pointer; color: var(--color-danger, #b00); font-size: 12.5px;">
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif

    {{-- ── Domains ───────────────────────────────────────────────── --}}
    @if ($tab === 'domains')
        <section class="lp-dash-card" data-testid="tab-pane-domains">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Add verified email domain</span>
                <span class="lp-dash-card-meta">Anyone with this domain auto-resolves to {{ $corporate->name }}</span>
            </div>
            <form wire:submit="addDomain" class="grid gap-4 sm:grid-cols-3" data-testid="add-domain-form">
                <div class="sm:col-span-2">
                    <label class="lp-form-label" for="d-dn">Domain</label>
                    <input id="d-dn" type="text" wire:model.live="domain_value" class="lp-input" required maxlength="120" placeholder="acme.com">
                    @error('domain_value') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lp-form-label">Verified now?</label>
                    <label class="flex items-center gap-2 mt-3 text-[13px]" style="color: var(--color-ink-soft);">
                        <input type="checkbox" wire:model.live="domain_verified"
                               style="accent-color: var(--color-accent-deep);">
                        Mark as verified
                    </label>
                </div>
                <div class="sm:col-span-3 flex justify-end">
                    <button type="submit" class="lp-pill" data-testid="add-domain-btn">
                        Add domain <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </button>
                </div>
            </form>
        </section>

        @if ($corporate->domains->isNotEmpty())
            <section class="lp-dash-card" style="padding: 0;">
                <div class="overflow-x-auto">
                    <table class="lp-table" data-testid="domains-table">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Status</th>
                                <th>Added</th>
                                <th style="text-align: right;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($corporate->domains as $d)
                                <tr data-testid="domain-row-{{ $d->id }}">
                                    <td class="lp-mono" style="color: var(--color-ink); font-weight: 500;">{{ $d->domain }}</td>
                                    <td>
                                        <span class="lp-tag {{ $d->verified_at ? 'lp-tag--confirmed' : 'lp-tag--checked-in' }}">
                                            {{ $d->verified_at ? 'Verified' : 'Pending' }}
                                        </span>
                                    </td>
                                    <td style="color: var(--color-ink-soft); font-size: 12.5px;">{{ $d->created_at?->format('d M Y') }}</td>
                                    <td style="text-align: right;">
                                        <div class="inline-flex items-center gap-3 text-[12.5px]" style="color: var(--color-ink-soft);">
                                            <button type="button" wire:click="toggleDomainVerified({{ $d->id }})"
                                                    style="background: none; border: 0; cursor: pointer; color: var(--color-ink-soft);">
                                                {{ $d->verified_at ? 'Unverify' : 'Verify' }}
                                            </button>
                                            <button type="button" wire:click="removeDomain({{ $d->id }})"
                                                    wire:confirm="Remove this domain?"
                                                    style="background: none; border: 0; cursor: pointer; color: var(--color-danger, #b00);">
                                                Remove
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
    @endif
</div>
