<div class="lp-dash">

    {{-- ── Hero: title + New outlet + Low-stock side panel ─
         Right side surfaces the low-stock count in warn tone
         so the operator sees the action-worthy number without
         having to scan the pulse strip below. --}}
    <section class="lp-res-idx-hero" data-testid="inventory-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Business · Inventory</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Inventory
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Outlets, products, stock. Whatever you sell out of a bar, kitchen, laundry, or gift shop lives here.
                </p>
                <div class="lp-res-idx-hero-actions">
                    @if ($canManagePrices)
                        <a href="{{ route('tenant.inventory.outlets.create') }}" wire:navigate
                           data-testid="inventory-new-outlet"
                           class="lp-res-action lp-res-action--primary">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                            New outlet
                        </a>
                    @endif
                    <a href="{{ route('tenant.inventory.audit-log') }}" wire:navigate
                       data-testid="inventory-audit-log-link"
                       class="lp-res-action">
                        Change history
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Low stock signal">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Low stock</p>
                    <p class="lp-ih-hero-accrued-value {{ (int) $lowStock > 0 ? 'lp-ih-hero-accrued-value--warn' : '' }}"
                       style="{{ (int) $lowStock === 0 ? 'color: var(--color-accent-deep);' : '' }}"
                       data-testid="inventory-hero-low-stock">
                        {{ number_format((int) $lowStock) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        @if ((int) $lowStock === 0)
                            everything stocked
                        @else
                            {{ Str::plural('SKU', (int) $lowStock) }} at or below threshold
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Pulse strip ──────────────────────────────────────────── --}}
    @php
        $invTiles = [
            [
                'label' => 'Outlets',   'sub' => 'bars, kitchens, laundries',
                'value' => (int) $totalOutlets,
                'dot' => 'var(--color-accent-deep)', 'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Products',  'sub' => 'across all outlets',
                'value' => (int) $totalProducts,
                'dot' => '#4B7BB0', 'color' => 'var(--color-ink)',
            ],
            [
                'label' => 'Low stock', 'sub' => 'at or below threshold',
                'value' => (int) $lowStock,
                'dot' => $lowStock > 0 ? '#B85C42' : 'var(--color-accent-deep)',
                'color' => $lowStock > 0 ? '#B85C42' : 'var(--color-ink)',
            ],
        ];
    @endphp
    <div class="grid gap-2 grid-cols-1 sm:grid-cols-3" data-testid="inventory-kpis">
        @foreach ($invTiles as $tile)
            <div class="lp-dash-card"
                 style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                          background: {{ $tile['dot'] }};
                                          box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                          flex-shrink: 0;"></span>
                <div style="min-width: 0; flex: 1;">
                    <p class="lp-num-lg text-[22px]" style="color: {{ $tile['color'] }};">
                        {{ number_format($tile['value']) }}
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

    {{-- ── Outlets grid ────────────────────────────────── --}}
    @if ($outlets->isEmpty())
        <section class="lp-dash-card" data-testid="inventory-empty" style="padding: 14px 16px;">
            @include('livewire.dashboard._partials._empty', [
                'icon'      => 'box',
                'title'     => 'No outlets yet.',
                'sub'       => $canManagePrices
                                ? 'Start with your bar or restaurant. Add products next, then hand your bartender or cashier the POS terminal.'
                                : 'Ask a manager to create the first outlet. You can maintain products and receive stock once one exists.',
                'ctaLabel'  => $canManagePrices ? 'Create first outlet' : null,
                'ctaHref'   => $canManagePrices ? route('tenant.inventory.outlets.create') : null,
            ])
        </section>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="inventory-outlets-grid">
            @foreach ($outlets as $outlet)
                <a href="{{ route('tenant.inventory.outlets.show', $outlet) }}" wire:navigate
                   class="lp-dash-card lp-integration-card"
                   data-testid="outlet-card-{{ $outlet->slug }}"
                   style="display: grid; gap: 12px; text-decoration: none; color: inherit;">
                    <div class="flex items-start justify-between gap-3">
                        <span class="lp-mono text-[10.5px]"
                              style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">
                            {{ $outlet->type->label() }}
                        </span>
                        @if (! $outlet->active)
                            <span class="lp-mono text-[10.5px]"
                                  style="padding: 3px 10px; border-radius: 999px; background: rgba(224, 122, 95, 0.14); color: #B85C42; letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;">
                                Inactive
                            </span>
                        @endif
                    </div>
                    <h2 class="text-[20px]" style="color: var(--color-ink); font-weight: 600; line-height: 1.1;">
                        {{ $outlet->name }}
                    </h2>
                    <p class="lp-mono text-[11.5px]" style="color: var(--color-ink-mute);">
                        {{ number_format($outlet->products_count) }} active product{{ $outlet->products_count === 1 ? '' : 's' }}
                        @if ($outlet->tax_rate > 0)
                            &middot; {{ rtrim(rtrim((string) $outlet->tax_rate, '0'), '.') }}% tax
                        @endif
                    </p>
                    <span class="lp-mono text-[11px] mt-1"
                          style="color: var(--color-accent-deep); letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;">
                        Manage &rarr;
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</div>
