<div class="lp-dash">

    @include('_partials._back-button')

    {{-- ── Hero: title + total-on-deposit side panel ────────
         Right side surfaces the total liability owed to guests
         in Fraunces. This is the number a hotel's cashier books
         against, and the whole page exists to work it down. --}}
    <section class="lp-res-idx-hero mt-3" data-testid="wallets-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Finance · Wallets</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Guest wallets
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Every guest carrying cash on file. Drill in for the ledger, top-up form, and refund controls.
                </p>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Total on deposit">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">On deposit</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ (int) $totalOnDeposit > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};"
                       data-testid="wallets-hero-total">
                        ₦{{ number_format((int) $totalOnDeposit) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        across {{ number_format((int) $fundedCount) }}
                        funded {{ Str::plural('wallet', (int) $fundedCount) }}
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Wallets pulse strip ──────────────────────────────────── --}}
    @php
        $walletTiles = [
            [
                'label' => 'Deposits on file', 'sub' => 'liability owed to guests',
                'value' => (int) $totalOnDeposit, 'money' => true,
                'dot' => 'var(--color-accent-deep)',
                'testid' => 'kpi-total-deposit',
            ],
            [
                'label' => 'Funded wallets', 'sub' => 'guests with a positive balance',
                'value' => (int) $fundedCount, 'money' => false,
                'dot' => '#4B7BB0',
                'testid' => 'kpi-funded-count',
            ],
            [
                'label' => 'Total wallets', 'sub' => 'includes ₦0 wallets ever touched',
                'value' => (int) $totalWallets, 'money' => false,
                'dot' => '#7A6EA7',
                'testid' => 'kpi-total-wallets',
            ],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-1 sm:grid-cols-3" data-testid="wallets-kpis">
        @foreach ($walletTiles as $tile)
            <div class="lp-dash-card" data-testid="{{ $tile['testid'] }}"
                 style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: var(--color-ink);">
                        @if ($tile['money'])&#8358;@endif{{ number_format($tile['value']) }}
                    </p>
                    <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                       style="color: var(--color-ink-mute); margin-top: 2px;">
                        {{ $tile['label'] }}
                        <span style="text-transform: none; letter-spacing: 0;
                                     color: var(--color-ink-mute);
                                     font-size: 11px; margin-left: 4px;">
                            {{ $tile['sub'] }}
                        </span>
                    </p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Filter bar ────────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Filter</span>
        </div>

        <form wire:submit.prevent class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="wallets-search" class="lp-form-label">Search</label>
                <input id="wallets-search" type="search"
                       wire:model.live.debounce.300ms="search"
                       data-testid="wallets-search"
                       class="lp-input"
                       placeholder="Guest name, email, or phone">
            </div>
            <div>
                <label for="wallets-filter" class="lp-form-label">Show</label>
                <select id="wallets-filter" wire:model.live="balanceFilter"
                        data-testid="wallets-filter" class="lp-input">
                    <option value="funded">Funded only</option>
                    <option value="all">All wallets</option>
                </select>
            </div>
        </form>
    </section>

    {{-- ── Roster ──────────────────────────────────────── --}}
    @if ($wallets->isEmpty())
        <section class="lp-dash-card" data-testid="no-wallets" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'  => 'wallet',
                'title' => $search === ''
                            ? 'No guest wallets have a balance yet.'
                            : 'No guests match your search.',
                'sub'   => 'Wallets are created the first time a guest tops up or overpays a booking.',
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">
                    {{ $balanceFilter === 'funded' ? 'Funded wallets' : 'All wallets' }}
                </span>
                <div style="display: inline-flex; align-items: center; gap: 8px;">
                    <span class="lp-dash-card-meta">
                        {{ $wallets->firstItem() ? number_format($wallets->firstItem()).'–'.number_format($wallets->lastItem()) : '0' }}
                        of {{ number_format($wallets->total()) }}
                    </span>
                    <label class="flex items-center gap-2" style="font-size: 11.5px; color: var(--color-ink-mute);">
                        <span class="lp-rate-sub-label" style="margin-bottom: 0;">Sort</span>
                        <select wire:model.live="sort" data-testid="wallets-sort"
                                class="lp-input" style="font-size: 12px; padding: 4px 8px; width: auto; height: 30px;">
                            <option value="balance_desc">Highest balance</option>
                            <option value="balance_asc">Lowest balance</option>
                            <option value="recent">Most recently used</option>
                            <option value="name_asc">Name · A → Z</option>
                        </select>
                    </label>
                </div>
            </div>

            <ul class="lp-ledger" data-testid="wallets-table">
                @foreach ($wallets as $wallet)
                    @php $customer = $wallet->customer; @endphp
                    @continue ($customer === null)
                    @php
                        $walletHref = route('tenant.customers.wallet', $customer);
                        $tone = (int) $wallet->balance_naira > 0 ? 'paid' : 'muted';
                    @endphp
                    <li class="lp-ledger-item lp-category-row lp-channel-row"
                        data-testid="wallet-row-{{ $wallet->id }}"
                        data-row-href="{{ $walletHref }}"
                        tabindex="0"
                        x-on:click="window.location.href = '{{ $walletHref }}'"
                        x-on:keydown.enter.prevent="window.location.href = '{{ $walletHref }}'">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <span class="lp-avatar @if ($customer->is_vip) lp-avatar--vip @endif lp-wallet-avatar" aria-hidden>
                            {{ $customer->avatarInitials() }}
                        </span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <a href="{{ $walletHref }}" wire:navigate
                                   @click.stop
                                   class="lp-ledger-desc"
                                   style="font-weight: 500; color: var(--color-ink); text-decoration: none;">
                                    {{ $customer->name }}
                                </a>
                                <span class="lp-category-type">{{ $customer->lifecycle->label() }}</span>
                                @if ($customer->is_vip)
                                    <span class="lp-tag lp-tag--confirmed" style="font-size: 9.5px;">VIP</span>
                                @endif
                            </div>
                            <p class="lp-ledger-meta">
                                @if ($customer->email)
                                    {{ $customer->email }}
                                @endif
                                @if ($customer->phone)
                                    @if ($customer->email)
                                        <span style="color: var(--color-ink-mute);">·</span>
                                    @endif
                                    <span class="lp-mono">{{ $customer->phone }}</span>
                                @endif
                                @if ($wallet->updated_at)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    used {{ $wallet->updated_at->diffForHumans() }}
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right">
                            <span class="lp-invoice-total"
                                  style="color: {{ (int) $wallet->balance_naira > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};"
                                  data-testid="wallet-balance-{{ $wallet->id }}">
                                ₦{{ number_format((int) $wallet->balance_naira) }}
                            </span>
                            <a href="{{ $walletHref }}" wire:navigate
                               @click.stop
                               data-testid="wallet-open-{{ $wallet->id }}"
                               class="lp-ledger-action">Open →</a>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($wallets->hasPages())
                <div class="mt-4">{{ $wallets->links() }}</div>
            @endif
        </section>
    @endif
</div>
