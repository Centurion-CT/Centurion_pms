@php
    $lifecycleChips = [
        ['key' => '',          'label' => 'All',      'count' => $counts['all']],
        ['key' => 'active',    'label' => 'Active',   'count' => $counts['active']],
        ['key' => 'prospect',  'label' => 'Prospect', 'count' => $counts['prospect']],
        ['key' => 'dormant',   'label' => 'Dormant',  'count' => $counts['dormant']],
        ['key' => 'churned',   'label' => 'Churned',  'count' => $counts['churned']],
    ];
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + Add customer + active count ──────
         Right side shows how many customers are active right
         now vs total, which is the number worth caring about
         when the operator opens this page for outreach. --}}
    <section class="lp-res-idx-hero" data-testid="customers-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Relationships</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Customers
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Every guest who has ever stayed, plus prospects you are working to bring in.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.customers.create') }}" wire:navigate
                       data-testid="customer-create-link"
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        Add a customer
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Active customers">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Active</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ (int) $counts['active'] > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};">
                        {{ number_format((int) $counts['active']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        of {{ number_format((int) $counts['all']) }}
                        · {{ (int) $counts['vip'] }} VIP · {{ (int) $counts['wallet'] }} with wallet
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Scope toggle (only when tenant has 2+ properties) --}}
    @if ($showScopeToggle ?? false)
        <div class="lp-cal-search-row" data-testid="scope-toggle" style="align-items: center;">
            <span class="lp-rate-sub-label" style="margin: 0;">Show</span>
            <button type="button" wire:click="$set('propertyScope', 'branch')"
                    class="lp-res-action {{ $scope === 'branch' ? 'lp-res-action--accent' : 'lp-res-action--ghost' }}"
                    data-testid="scope-branch">
                This branch
            </button>
            <button type="button" wire:click="$set('propertyScope', 'all')"
                    class="lp-res-action {{ $scope === 'all' ? 'lp-res-action--accent' : 'lp-res-action--ghost' }}"
                    data-testid="scope-all">
                All properties
            </button>
        </div>
    @endif

    {{-- ── Lifecycle tabstrip + filters ──────────────────── --}}
    <section class="lp-dash-card lp-members-controls" style="padding: 12px 14px;">
        <div class="lp-tabstrip" data-testid="lifecycle-chips" role="tablist">
            @foreach ($lifecycleChips as $chip)
                <button type="button"
                        wire:click="$set('lifecycleFilter', '{{ $chip['key'] }}')"
                        role="tab"
                        aria-selected="{{ $lifecycleFilter === $chip['key'] ? 'true' : 'false' }}"
                        class="lp-tabstrip-btn {{ $lifecycleFilter === $chip['key'] ? 'is-active' : '' }}"
                        data-testid="lifecycle-{{ $chip['key'] ?: 'all' }}">
                    {{ $chip['label'] }}
                    <span class="lp-tabstrip-count">{{ $chip['count'] }}</span>
                </button>
            @endforeach
        </div>

        <div class="lp-cal-search-row" style="align-items: center;">
            <button type="button"
                    wire:click="$set('vipFilter', '{{ $vipFilter === '1' ? '' : '1' }}')"
                    class="lp-res-action {{ $vipFilter === '1' ? 'lp-res-action--accent' : 'lp-res-action--ghost' }}"
                    data-testid="vip-toggle">
                <span style="color: {{ $vipFilter === '1' ? 'inherit' : 'var(--color-accent-deep)' }};">★</span>
                VIP only ({{ $counts['vip'] }})
            </button>
            <button type="button"
                    wire:click="$set('walletFilter', '{{ $walletFilter === '1' ? '' : '1' }}')"
                    class="lp-res-action {{ $walletFilter === '1' ? 'lp-res-action--accent' : 'lp-res-action--ghost' }}"
                    data-testid="wallet-toggle">
                Has wallet ({{ $counts['wallet'] }})
            </button>
        </div>

        <div class="lp-members-filter-form">
            <div class="lp-cal-search-field">
                <label for="customers-search" class="lp-rate-sub-label">Search</label>
                <input id="customers-search" type="search"
                       wire:model.live.debounce.300ms="search"
                       data-testid="customers-search"
                       class="lp-input"
                       style="height: 36px;"
                       placeholder="Name, email, or phone">
            </div>
            <div class="lp-cal-search-field">
                <label for="customers-tag" class="lp-rate-sub-label">Tag</label>
                <select id="customers-tag" wire:model.live="tagFilter"
                        data-testid="tag-filter" class="lp-input"
                        style="height: 36px;">
                    <option value="">All tags</option>
                    @foreach ($allTags as $tag)
                        <option value="{{ $tag }}">{{ $tag }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    {{-- ── Customers list ──────────────────────────────── --}}
    @if ($customers->isEmpty())
        @php
            $isFilterEmpty = $search === '' && $lifecycleFilter === '' && $tagFilter === '' && $vipFilter === '';
        @endphp
        <section class="lp-dash-card" data-testid="no-customers" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'      => 'sparkle',
                'title'     => $isFilterEmpty ? 'No customers yet.' : 'No customers match those filters.',
                'sub'       => $isFilterEmpty
                                 ? 'Bookings auto-create a customer here, or add one manually below.'
                                 : 'Try clearing a filter or adjusting your search.',
                'ctaLabel'  => $isFilterEmpty ? 'Add a customer' : 'Clear filters',
                'ctaHref'   => $isFilterEmpty ? route('tenant.customers.create') : null,
                'ctaClickJs'=> $isFilterEmpty ? null : "\$wire.clearFilters()",
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">
                    {{ $lifecycleFilter !== '' ? ucfirst($lifecycleFilter).' customers' : 'All customers' }}
                </span>
                <div style="display: inline-flex; align-items: center; gap: 8px;">
                    <span class="lp-dash-card-meta">
                        {{ $customers->firstItem() ? number_format($customers->firstItem()).'–'.number_format($customers->lastItem()) : '0' }}
                        of {{ number_format($customers->total()) }}
                    </span>
                    <label class="flex items-center gap-2" style="font-size: 11.5px; color: var(--color-ink-mute);">
                        <span class="lp-rate-sub-label" style="margin-bottom: 0;">Sort</span>
                        <select wire:model.live="sort" data-testid="customers-sort"
                                class="lp-input" style="font-size: 12px; padding: 4px 8px; width: auto; height: 30px;">
                            <option value="recent">Most recent stay</option>
                            <option value="value_desc">Highest lifetime value</option>
                            <option value="wallet_desc">Highest wallet balance</option>
                            <option value="bookings_desc">Most bookings</option>
                            <option value="name_asc">Name · A → Z</option>
                        </select>
                    </label>
                </div>
            </div>

            {{-- Dense table: scans a large customer book much better
                 than the card-stack did. Row is clickable end-to-end
                 (except the wallet link, which navigates elsewhere). --}}
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="customers-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Lifecycle</th>
                            <th>Contact</th>
                            <th style="text-align: right;">Bookings</th>
                            <th style="text-align: right;">Last stay</th>
                            <th style="text-align: right;">Lifetime</th>
                            <th style="text-align: right;">Wallet</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($customers as $customer)
                        @php
                            $lifecycleClass = match ($customer->lifecycle->value) {
                                'active'   => 'lp-tag--confirmed',
                                'prospect' => 'lp-tag--pending',
                                'dormant'  => 'lp-tag--on-hold',
                                'churned'  => 'lp-tag--cancelled',
                                default    => '',
                            };
                            $customerHref = route('tenant.customers.show', $customer);
                            $walletBalance = $customer->walletBalance();
                        @endphp
                        <tr class="lp-table-row-link"
                            data-testid="customer-row-{{ $customer->id }}"
                            data-row-href="{{ $customerHref }}"
                            tabindex="0"
                            x-on:click="if (! $event.target.closest('a')) window.location.href = '{{ $customerHref }}'"
                            x-on:keydown.enter.prevent="window.location.href = '{{ $customerHref }}'">
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                    <span class="lp-avatar @if ($customer->is_vip) lp-avatar--vip @endif lp-wallet-avatar" aria-hidden style="flex-shrink: 0;">
                                        {{ $customer->avatarInitials() }}
                                    </span>
                                    <div style="min-width: 0;">
                                        <a href="{{ $customerHref }}" wire:navigate
                                           data-testid="customer-open-{{ $customer->id }}"
                                           style="font-weight: 500; color: var(--color-ink); text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            @if ($customer->is_vip)
                                                <span style="color: var(--color-accent-deep); margin-right: 3px;">★</span>
                                            @endif
                                            {{ $customer->name }}
                                        </a>
                                        @if (! empty($customer->tags))
                                            <span class="lp-mono" style="font-size: 10.5px; color: var(--color-ink-mute); letter-spacing: 0.02em;">
                                                {{ implode(' · ', array_slice($customer->tags, 0, 3)) }}@if (count($customer->tags) > 3) · +{{ count($customer->tags) - 3 }} @endif
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="lp-tag {{ $lifecycleClass }}">{{ $customer->lifecycle->label() }}</span>
                            </td>
                            <td style="max-width: 240px;">
                                <div style="min-width: 0;">
                                    @if ($customer->email)
                                        <span style="font-size: 12.5px; color: var(--color-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block;">
                                            {{ $customer->email }}
                                        </span>
                                    @endif
                                    @if ($customer->phone)
                                        <span class="lp-mono" style="font-size: 11.5px; color: var(--color-ink-soft);">
                                            {{ $customer->phone }}
                                        </span>
                                    @endif
                                    @if (! $customer->email && ! $customer->phone)
                                        <span style="color: var(--color-ink-mute);">-</span>
                                    @endif
                                </div>
                            </td>
                            <td class="lp-num" style="text-align: right;">
                                {{ number_format((int) $customer->total_bookings) }}
                            </td>
                            <td class="lp-num" style="text-align: right; color: var(--color-ink-soft); font-size: 12px;">
                                @if ($customer->last_booking_at)
                                    {{ $customer->last_booking_at->format('M Y') }}
                                @else
                                    <span style="color: var(--color-ink-mute);">-</span>
                                @endif
                            </td>
                            <td class="lp-num" style="text-align: right; font-weight: 600;">
                                @if ((int) $customer->lifetime_value > 0)
                                    ₦{{ number_format((int) $customer->lifetime_value) }}
                                @else
                                    <span style="color: var(--color-ink-mute); font-weight: 400;">-</span>
                                @endif
                            </td>
                            <td class="lp-num" style="text-align: right;">
                                @if ($walletBalance > 0)
                                    <a href="{{ route('tenant.customers.wallet', $customer) }}" wire:navigate
                                       data-testid="customer-wallet-{{ $customer->id }}"
                                       style="color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">
                                        ₦{{ number_format($walletBalance) }}
                                    </a>
                                @else
                                    <span data-testid="customer-wallet-{{ $customer->id }}" style="color: var(--color-ink-mute);">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($customers->hasPages())
                <div class="mt-4">{{ $customers->links() }}</div>
            @endif
        </section>
    @endif
</div>
