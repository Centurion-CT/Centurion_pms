@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">B2B</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Corporates
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                {{ $corporates->total() }} corporate{{ $corporates->total() === 1 ? '' : 's' }} onboarded.
                Wallets, members, rates and KYC managed from each corporate's profile.
            </p>
        </div>

        <a href="{{ route('admin.corporates.create') }}" wire:navigate
           class="lp-pill" data-testid="create-corporate">
            Onboard corporate <span class="lp-pill-dot">{!! $pillArrow !!}</span>
        </a>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="stats">
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Active</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">{{ number_format($stats['active']) }}</p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Onboarding</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">{{ number_format($stats['onboarding']) }}</p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Suspended</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">{{ number_format($stats['suspended']) }}</p>
        </div>
        <div class="lp-dash-card" style="padding: 18px 22px;">
            <span class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">Wallet float</span>
            <p class="lp-display mt-2 text-[28px]" style="color: var(--color-ink); font-weight: 600;">₦{{ number_format($stats['float']) }}</p>
        </div>
    </div>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Filter</span></div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="c-q">Search</label>
                <input id="c-q" type="search" wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Name, slug or email" data-testid="search">
            </div>
            <div>
                <label class="lp-form-label" for="c-st">Status</label>
                <select id="c-st" wire:model.live="statusFilter" class="lp-input" data-testid="status-filter">
                    <option value="">Any status</option>
                    @foreach ($statusOptions as $opt)
                        <option value="{{ $opt->value }}">{{ $opt->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="lp-form-label" for="c-kyc">KYC</label>
                <select id="c-kyc" wire:model.live="kycFilter" class="lp-input" data-testid="kyc-filter">
                    <option value="">Any KYC</option>
                    @foreach ($kycOptions as $opt)
                        <option value="{{ $opt->value }}">{{ $opt->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($corporates->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="empty" class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">
                No corporates yet.
            </p>
            <p class="mt-2 text-[13px]" style="color: var(--color-ink-soft);">
                Onboard your first corporate above to get started.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="corporates-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Primary email</th>
                            <th>Status</th>
                            <th>KYC</th>
                            <th>Wallet</th>
                            <th>Held</th>
                            <th style="text-align: right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($corporates as $c)
                            @php
                                $statusClass = match($c->status->value) {
                                    'active'     => 'lp-tag--confirmed',
                                    'onboarding' => 'lp-tag--checked-in',
                                    default      => 'lp-tag--checked-out',
                                };
                                $kycClass = match($c->kyc_status->value) {
                                    'verified' => 'lp-tag--confirmed',
                                    'rejected' => 'lp-tag--checked-out',
                                    default    => 'lp-tag--checked-in',
                                };
                            @endphp
                            <tr data-testid="c-row-{{ $c->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $c->name }}
                                    <div class="lp-mono" style="color: var(--color-ink-mute); font-size: 11.5px; margin-top: 2px;">
                                        {{ $c->slug }}
                                    </div>
                                </td>
                                <td style="color: var(--color-ink-soft); font-size: 13px;">{{ $c->primary_email }}</td>
                                <td><span class="lp-tag {{ $statusClass }}">{{ $c->status->label() }}</span></td>
                                <td><span class="lp-tag {{ $kycClass }}">{{ $c->kyc_status->label() }}</span></td>
                                <td><span class="lp-table-amount">₦{{ number_format((int) ($c->wallet?->balance_naira ?? 0)) }}</span></td>
                                <td style="color: var(--color-ink-soft); font-size: 12.5px;">
                                    ₦{{ number_format((int) ($c->wallet?->held_naira ?? 0)) }}
                                </td>
                                <td style="text-align: right;">
                                    <a href="{{ route('admin.corporates.show', $c) }}" wire:navigate
                                       data-testid="view-{{ $c->id }}"
                                       style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px; font-size: 12.5px; font-weight: 500;">
                                        Open →
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $corporates->links() }}</div>
        </section>
    @endif
</div>
