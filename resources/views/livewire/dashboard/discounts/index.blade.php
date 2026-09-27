@php
    $tabs = [
        ['key' => 'all',      'label' => 'All',          'count' => $counts['all']],
        ['key' => 'active',   'label' => 'Active',       'count' => $counts['active']],
        ['key' => 'auto',     'label' => 'Auto-apply',   'count' => $counts['auto']],
        ['key' => 'coupon',   'label' => 'Coupon codes', 'count' => $counts['coupon']],
        ['key' => 'inactive', 'label' => 'Inactive',     'count' => $counts['all'] - $counts['active']],
    ];
    $activeFilters = ($search !== '' ? 1 : 0) + ($statusFilter !== 'all' ? 1 : 0);
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + New discount CTA ────────────────
         Right side hosts "Active" count so the operator sees
         how many promotions are running right now. --}}
    <section class="lp-res-idx-hero" data-testid="discounts-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Marketing</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Discounts &amp; coupons
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Auto-apply rules and coupon codes. Targeted by room, dates, length of stay, or channel.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.discounts.create') }}" wire:navigate
                       data-testid="discount-create-link"
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                        New discount
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Active promotions">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Active</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ (int) $counts['active'] > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};">
                        {{ number_format((int) $counts['active']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        {{ (int) $counts['auto'] }} auto ·
                        {{ (int) $counts['coupon'] }} coupon codes
                    </p>
                </div>
            </aside>
        </div>
    </section>

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

    {{-- ── Tabstrip + search ──────────────────────────── --}}
    <section class="lp-dash-card lp-members-controls" style="padding: 12px 14px;">
        <div class="lp-tabstrip" data-testid="discount-tabs" role="tablist">
            @foreach ($tabs as $t)
                <button type="button"
                        wire:click="$set('statusFilter', '{{ $t['key'] }}')"
                        role="tab"
                        aria-selected="{{ $statusFilter === $t['key'] ? 'true' : 'false' }}"
                        class="lp-tabstrip-btn {{ $statusFilter === $t['key'] ? 'is-active' : '' }}"
                        data-testid="tab-{{ $t['key'] }}">
                    {{ $t['label'] }}
                    <span class="lp-tabstrip-count">{{ $t['count'] }}</span>
                </button>
            @endforeach
        </div>
        <div class="lp-cal-search-field">
            <label for="discount-search" class="lp-rate-sub-label">Search</label>
            <input id="discount-search" type="search" wire:model.live.debounce.300ms="search"
                   data-testid="discount-search" class="lp-input"
                   style="height: 36px;"
                   placeholder="Name or code (e.g. SUMMER25)">
        </div>
    </section>

    {{-- ── Discount list ────────────────────────────────── --}}
    @if ($discounts->isEmpty())
        @php
            $isFilterEmpty = $search === '' && $statusFilter === 'all';
        @endphp
        <section class="lp-dash-card" data-testid="no-discounts" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'      => 'sparkle',
                'title'     => $isFilterEmpty ? 'No discounts yet.' : 'No discounts match those filters.',
                'sub'       => $isFilterEmpty
                                ? 'Create your first to start running promotions or distributing coupon codes.'
                                : 'Try clearing the search or switching tabs.',
                'ctaLabel'  => $isFilterEmpty ? 'New discount' : null,
                'ctaHref'   => $isFilterEmpty ? route('tenant.discounts.create') : null,
            ])
        </section>
    @else
        <section class="lp-dash-card" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">
                    {{ $activeFilters > 0 ? 'Matching discounts' : 'All discounts' }}
                </span>
                <span class="lp-dash-card-meta">
                    {{ $discounts->firstItem() ? number_format($discounts->firstItem()).'–'.number_format($discounts->lastItem()) : '0' }}
                    of {{ number_format($discounts->total()) }}
                </span>
            </div>

            <ul class="lp-ledger" data-testid="discounts-table">
                @foreach ($discounts as $d)
                    @php
                        $tone = $d->active ? 'paid' : 'muted';
                        $editUrl = route('tenant.discounts.edit', $d);
                    @endphp
                    <li class="lp-ledger-item lp-category-row" data-testid="discount-row-{{ $d->id }}">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <span class="lp-category-thumb lp-category-thumb--empty" aria-hidden style="cursor: default; border-style: solid;">
                            @if ($d->code)
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h6l4-4 6 6-4 4v6H8l-4-4z"/><circle cx="8" cy="12" r="1" fill="currentColor" stroke="none"/></svg>
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8l16 0M4 16l16 0M12 4l0 16"/></svg>
                            @endif
                        </span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <a href="{{ $editUrl }}" wire:navigate
                                   class="lp-ledger-desc"
                                   style="font-weight: 600; color: var(--color-ink); text-decoration: none;">
                                    {{ $d->name }}
                                </a>
                                @if ($d->code)
                                    <span class="lp-mono" style="color: var(--color-ink); letter-spacing: 0.06em; padding: 2px 8px; background: var(--color-card-deep); border: 1px solid var(--color-line-soft); border-radius: 4px; font-size: 11.5px;">
                                        {{ $d->code }}
                                    </span>
                                @else
                                    <span class="lp-tag lp-tag--confirmed">Auto-apply</span>
                                @endif
                                <button type="button"
                                        wire:click="toggleActive({{ $d->id }})"
                                        data-testid="toggle-{{ $d->id }}"
                                        class="lp-tag {{ $d->active ? 'lp-tag--confirmed' : 'lp-tag--checked-out' }}"
                                        style="border: 0; cursor: pointer; font-family: inherit;">
                                    {{ $d->active ? 'Active' : 'Paused' }}
                                </button>
                            </div>
                            <p class="lp-ledger-meta">
                                @if ($d->starts_on || $d->ends_on)
                                    {{ $d->starts_on?->format('j M') ?? 'always' }}
                                    <span style="color: var(--color-ink-mute);">→</span>
                                    {{ $d->ends_on?->format('j M Y') ?? 'always' }}
                                @else
                                    Always
                                @endif
                                <span style="color: var(--color-ink-mute);">·</span>
                                @if ($d->min_nights || $d->max_nights)
                                    <span class="lp-mono">{{ $d->min_nights ?? '-' }}–{{ $d->max_nights ?? '∞' }}</span>
                                    {{ Str::plural('night', (int) ($d->max_nights ?? $d->min_nights ?? 1)) }}
                                @else
                                    any length
                                @endif
                                <span style="color: var(--color-ink-mute);">·</span>
                                used <span class="lp-mono">{{ $d->used_count }}{{ $d->max_uses ? '/'.$d->max_uses : '' }}</span>
                                @if ($d->description)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ \Illuminate\Support\Str::limit($d->description, 60) }}
                                @endif
                            </p>
                        </div>
                        <div class="lp-ledger-right lp-category-right">
                            <span class="lp-category-rate">{{ $d->discountSummary() }}</span>
                            <div class="lp-ledger-actions">
                                <a href="{{ $editUrl }}" wire:navigate
                                   data-testid="edit-{{ $d->id }}"
                                   class="lp-ledger-action">Edit</a>
                                <button type="button" wire:click="delete({{ $d->id }})"
                                        wire:confirm="Delete this discount? Past applications stay on their bookings."
                                        data-testid="delete-{{ $d->id }}"
                                        class="lp-ledger-action lp-ledger-action--danger">Delete</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($discounts->hasPages())
                <div class="mt-4">{{ $discounts->links() }}</div>
            @endif
        </section>
    @endif
</div>
