@php
    $now = \Illuminate\Support\Carbon::now();
    $statusTabs = [
        'all'       => 'All',
        'active'    => 'Active',
        'expired'   => 'Expired',
        'cancelled' => 'Cancelled',
    ];
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + Sign up primary CTA ────────────── --}}
    @include('_partials._back-button')

    <section class="lp-res-idx-hero mt-3" data-testid="members-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Membership · Members</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Members
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Everyone currently subscribed, expired, or cancelled across all businesses.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <button type="button" wire:click="toggleSubscribe"
                            data-testid="membership-subscribe-toggle"
                            class="lp-res-action {{ $subscribeOpen ? 'lp-res-action--ghost' : 'lp-res-action--primary' }}">
                        @if (! $subscribeOpen)
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        @endif
                        {{ $subscribeOpen ? 'Close sign up' : 'Sign a member up' }}
                    </button>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Member counts">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Total</p>
                    <p class="lp-ih-hero-accrued-value" style="color: var(--color-ink);">
                        {{ number_format($subs->total()) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        @if ($statusFilter !== 'all')
                            filtered by {{ $statusTabs[$statusFilter] ?? $statusFilter }}
                        @else
                            across every status
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- Subscribe panel ── inline form for signing a customer up. --}}
    @if ($subscribeOpen)
        <section class="lp-dash-card" data-testid="membership-subscribe-form">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Sign a member up</span>
                <button type="button" wire:click="toggleSubscribe"
                        class="lp-btn" style="font-size: 12px;">Close</button>
            </div>

            {{-- Step 1: customer picker --}}
            @if ($pickedCustomer === null)
                <div>
                    <label class="lp-form-label" for="cust-search">Find customer</label>
                    <input id="cust-search" type="text"
                           wire:model.live.debounce.250ms="customerSearch"
                           data-testid="membership-customer-search"
                           class="lp-input"
                           placeholder="Name, phone or email">
                    @if (trim($customerSearch) !== '' && $customerMatches->isEmpty())
                        <p class="text-[12px] mt-2" style="color: var(--color-ink-mute);">
                            No customer matches. Create the customer under Customers first, then come back.
                        </p>
                    @endif
                    @if ($customerMatches->isNotEmpty())
                        <ul style="list-style: none; padding: 0; margin: 8px 0 0; display: grid; gap: 4px;">
                            @foreach ($customerMatches as $c)
                                <li>
                                    <button type="button"
                                            wire:click="$set('customerId', {{ $c->id }})"
                                            data-testid="pick-customer-{{ $c->id }}"
                                            style="width: 100%; text-align: left; background: transparent;
                                                   border: 1px solid var(--color-line-soft);
                                                   border-radius: 8px; padding: 8px 10px; cursor: pointer;
                                                   font-family: inherit;">
                                        <span style="display: block; font-size: 13px; font-weight: 500;">
                                            {{ $c->name }}
                                        </span>
                                        <span class="text-[11px]" style="color: var(--color-ink-mute);">
                                            {{ $c->phone ?? $c->email ?? '-' }}
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @else
                {{-- Step 2: plan + start + payment --}}
                <div class="grid gap-3">
                    <div style="padding: 8px 10px; background: var(--color-card-deep); border-radius: 8px;
                                display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                        <div>
                            <div class="text-[13px]" style="color: var(--color-ink); font-weight: 600;">
                                {{ $pickedCustomer->name }}
                            </div>
                            <div class="text-[11px]" style="color: var(--color-ink-mute);">
                                {{ $pickedCustomer->phone ?? $pickedCustomer->email ?? '-' }}
                            </div>
                        </div>
                        <button type="button" wire:click="$set('customerId', null)"
                                class="lp-btn" style="font-size: 11px;">Change</button>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="lp-form-label" for="sub-biz">Business</label>
                            <select id="sub-biz" wire:model.live="businessId"
                                    data-testid="sub-biz" class="lp-input">
                                <option value="">Pick a business</option>
                                @foreach ($businesses as $b)
                                    @if ($b->active)
                                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="lp-form-label" for="sub-plan">Plan</label>
                            <select id="sub-plan" wire:model.live="planId"
                                    data-testid="sub-plan" class="lp-input"
                                    @disabled($planOptions->isEmpty())>
                                <option value="">
                                    {{ $businessId ? 'Pick a plan' : 'Pick a business first' }}
                                </option>
                                @foreach ($planOptions as $p)
                                    <option value="{{ $p->id }}">
                                        {{ $p->name }} · &#8358;{{ number_format($p->price) }} · {{ $p->validity_days }}d
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="lp-form-label" for="sub-start">Start date</label>
                            <input id="sub-start" type="date" wire:model="startDate"
                                   data-testid="sub-start" class="lp-input">
                        </div>
                        <div>
                            <label class="lp-form-label" for="sub-amount">Payment (&#8358;)</label>
                            <input id="sub-amount" type="number" min="0" step="1"
                                   wire:model="paymentAmount"
                                   data-testid="sub-amount" class="lp-input">
                        </div>
                        <div class="md:col-span-2">
                            <label class="lp-form-label" for="sub-method">Payment method</label>
                            <select id="sub-method" wire:model="paymentMethod"
                                    data-testid="sub-method" class="lp-input">
                                <option value="cash">Cash</option>
                                <option value="transfer">Bank transfer</option>
                                <option value="card">Card</option>
                                <option value="pos">POS terminal</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="toggleSubscribe"
                                class="lp-btn" style="font-size: 12px;">Cancel</button>
                        <button type="button" wire:click="subscribe"
                                data-testid="membership-subscribe-submit"
                                class="lp-btn lp-btn--primary" style="font-size: 12px;">
                            Subscribe &amp; take payment
                        </button>
                    </div>
                </div>
            @endif
        </section>
    @endif

    {{-- ── Status tabstrip + filters ───────────────────── --}}
    <section class="lp-dash-card lp-members-controls" style="padding: 12px 14px;">
        <div class="lp-tabstrip" data-testid="member-status-tabs" role="tablist">
            @foreach ($statusTabs as $k => $label)
                <button type="button" wire:click="$set('statusFilter', '{{ $k }}')"
                        role="tab"
                        aria-selected="{{ $statusFilter === $k ? 'true' : 'false' }}"
                        class="lp-tabstrip-btn {{ $statusFilter === $k ? 'is-active' : '' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <form wire:submit.prevent class="lp-members-filter-form">
            <div class="lp-cal-search-field">
                <label for="member-search" class="lp-rate-sub-label">Search</label>
                <input id="member-search" type="search" wire:model.live.debounce.300ms="search"
                       data-testid="member-search" class="lp-input"
                       style="height: 36px;"
                       placeholder="Name, phone, email or member ref">
            </div>
            <div class="lp-cal-search-field">
                <label for="member-biz" class="lp-rate-sub-label">Business</label>
                <select id="member-biz" wire:model.live="businessFilter" class="lp-input"
                        data-testid="member-biz-filter"
                        style="height: 36px;">
                    <option value="">All businesses</option>
                    @foreach ($businesses as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($subs->isEmpty())
        <section class="lp-dash-card" data-testid="no-members" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'sparkle',
                'title' => 'No members match these filters.',
                'sub'   => 'Try switching tabs, clearing the search, or signing a new customer up.',
                'ctaLabel'   => 'Sign a member up',
                'ctaClickJs' => "\$wire.toggleSubscribe()",
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">{{ ucfirst($statusFilter === 'all' ? 'all members' : $statusFilter.' members') }}</span>
                <span class="lp-dash-card-meta">
                    {{ $subs->firstItem() ? number_format($subs->firstItem()).'–'.number_format($subs->lastItem()) : '0' }}
                    of {{ number_format($subs->total()) }}
                </span>
            </div>
            <ul class="lp-ledger" data-testid="members-table">
                @foreach ($subs as $s)
                    @php
                        $displayStatus = $s->displayStatus($now);
                        $tone = match ($displayStatus) {
                            'active'    => 'paid',
                            'cancelled' => 'muted',
                            default     => 'warn',
                        };
                        $showRef = route('tenant.membership.show', $s->member_ref);
                    @endphp
                    <li class="lp-ledger-item" data-testid="member-row-{{ $s->id }}">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <a href="{{ $showRef }}" wire:navigate
                                   class="lp-ledger-desc"
                                   style="font-weight: 500; text-decoration: none; color: var(--color-ink);">
                                    {{ $s->customer?->name ?? 'Unnamed customer' }}
                                </a>
                                <span class="lp-mono" style="color: var(--color-ink-soft); font-size: 11.5px; background: var(--color-card-deep); border: 1px solid var(--color-line-soft); border-radius: 4px; padding: 1px 6px;">
                                    {{ $s->member_ref }}
                                </span>
                                @if ($displayStatus === 'active')
                                    <span class="lp-tag lp-tag--confirmed">Active</span>
                                @elseif ($displayStatus === 'cancelled')
                                    <span class="lp-tag lp-tag--on-hold">Cancelled</span>
                                @else
                                    <span class="lp-tag lp-tag--on-hold">Expired</span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                {{ $s->business?->name ?? 'No business' }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                {{ $s->plan?->name ?? 'No plan' }}
                                @if ($s->started_at)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    started {{ $s->started_at->format('j M Y') }}
                                @endif
                                @if ($s->expires_at)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    expires {{ $s->expires_at->format('j M Y') }}
                                    @if ($displayStatus === 'active')
                                        <span style="color: var(--color-ink-mute);">
                                            (in {{ $s->daysRemaining($now) }} day{{ $s->daysRemaining($now) === 1 ? '' : 's' }})
                                        </span>
                                    @endif
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right lp-ledger-actions">
                            <a href="{{ $showRef }}" wire:navigate class="lp-ledger-action">Open</a>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($subs->hasPages())
                <div class="mt-4">{{ $subs->links() }}</div>
            @endif
        </section>
    @endif

</div>
