<div class="lp-dash">

    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip" style="color: #A8650A;">Danger zone</span>
            <h1 class="lp-display mt-3 text-[clamp(22px,3vw,32px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Remove a test record
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft); max-width: 640px;">
                Reservations and POS sales made during onboarding tests live here. Pick
                the tab, find the specific row, and click <strong>Remove</strong>. Each
                deletion is confirmed one at a time so real records can't be swept up.
                <strong style="color: #A8650A;">Cannot be undone.</strong>
            </p>
        </div>
    </div>

    {{-- ── Tabs ────────────────────────────────────────────────── --}}
    <div class="lp-chips" data-testid="cleanup-tabs">
        <button type="button" wire:click="setTab('bookings')"
                class="lp-chip @if ($tab === 'bookings') is-active @endif"
                data-testid="cleanup-tab-bookings">Bookings</button>
        <button type="button" wire:click="setTab('pos')"
                class="lp-chip @if ($tab === 'pos') is-active @endif"
                data-testid="cleanup-tab-pos">POS sales</button>
        <button type="button" wire:click="setTab('catalog')"
                class="lp-chip @if ($tab === 'catalog') is-active @endif"
                data-testid="cleanup-tab-catalog">POS catalog</button>
    </div>

    {{-- ══════════════════════════════════════════════════════════
         BOOKINGS TAB
         ══════════════════════════════════════════════════════════ --}}
    @if ($tab === 'bookings')
        <section class="lp-dash-card">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Find the reservation</span>
                <span class="lp-dash-card-meta">
                    {{ $bookings->total() ?? $bookings->count() }} match
                </span>
            </div>

            <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label for="cleanup-search" class="lp-form-label">Search</label>
                    <input id="cleanup-search" type="search"
                           wire:model.live.debounce.400ms="search"
                           placeholder="Ref, guest name, email or phone"
                           data-testid="cleanup-search" class="lp-input">
                </div>
                <div>
                    <label for="cleanup-status" class="lp-form-label">Status</label>
                    <select id="cleanup-status" wire:model.live="statusFilter"
                            data-testid="cleanup-status" class="lp-input">
                        <option value="">All</option>
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="checked_in">Checked in</option>
                        <option value="checked_out">Checked out</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="no_show">No show</option>
                    </select>
                </div>
            </form>
        </section>

        @if ($bookings->isEmpty())
            <section class="lp-dash-card text-center" style="padding: 56px 24px;">
                <p data-testid="cleanup-no-bookings" class="text-[18px]"
                   style="color: var(--color-ink); font-weight: 500;">
                    No reservations match this filter.
                </p>
            </section>
        @else
            <section class="lp-dash-card" style="padding: 0;">
                <div class="overflow-x-auto">
                    <table data-testid="cleanup-table" class="lp-table">
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Dates</th>
                                <th>Status</th>
                                <th style="text-align: right;">Total</th>
                                <th style="text-align: right;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bookings as $b)
                                <tr data-testid="cleanup-row">
                                    <td>
                                        <code class="lp-mono"
                                              style="color: var(--color-ink); font-size: 12.5px; font-weight: 500;">
                                            {{ $b->booking_ref }}
                                        </code>
                                    </td>
                                    <td style="color: var(--color-ink);">
                                        {{ $b->guest_name ?? '-' }}
                                        @if ($b->guest_phone)
                                            <span style="color: var(--color-ink-mute); font-size: 12px;">
                                                · {{ $b->guest_phone }}
                                            </span>
                                        @endif
                                    </td>
                                    <td style="color: var(--color-ink-soft);">
                                        {{ $b->room?->room_number ?? '-' }}
                                    </td>
                                    <td style="color: var(--color-ink-soft); font-size: 12.5px;">
                                        {{ $b->checkin_date?->format('j M Y') ?? '-' }}
                                        &rarr;
                                        {{ $b->checkout_date?->format('j M Y') ?? '-' }}
                                    </td>
                                    <td style="color: var(--color-ink-soft); font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em;">
                                        {{ str_replace('_', ' ', $b->booking_status?->value ?? '') }}
                                    </td>
                                    <td class="lp-mono" style="text-align: right; color: var(--color-ink);">
                                        ₦{{ number_format((int) $b->total_amount) }}
                                    </td>
                                    <td style="text-align: right;">
                                        <button type="button"
                                                wire:click="openConfirm({{ $b->id }})"
                                                data-testid="cleanup-remove-{{ $b->id }}"
                                                style="background: transparent; color: #A8650A;
                                                       border: 1px solid #A8650A;
                                                       padding: 5px 12px; border-radius: 999px;
                                                       font-family: inherit; font-size: 12px; font-weight: 500;
                                                       cursor: pointer;">
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="padding: 12px 16px; border-top: 1px solid var(--color-line-soft);">
                    {{ $bookings->links() }}
                </div>
            </section>
        @endif

        {{-- Confirmation modal (single booking) --}}
        @if ($pendingBooking !== null)
            <div data-testid="cleanup-confirm-backdrop"
                 wire:click.self="cancelConfirm"
                 style="position: fixed; inset: 0; z-index: 80;
                        background: rgba(20, 22, 24, 0.55);
                        backdrop-filter: blur(2px);
                        display: flex; align-items: center; justify-content: center;
                        padding: 20px;">
                <div data-testid="cleanup-confirm-card"
                     style="width: 100%; max-width: 520px;
                            background: var(--color-card);
                            border: 1px solid var(--color-line-soft);
                            border-radius: 16px;
                            padding: 24px 26px 20px;
                            box-shadow: 0 24px 60px rgba(0,0,0,0.25);">
                    <p class="lp-mono text-[10.5px]"
                       style="color: #A8650A; letter-spacing: 0.18em; text-transform: uppercase;">
                        Remove reservation
                    </p>
                    <h2 class="lp-display" style="font-size: 20px; font-weight: 600; margin-top: 4px; line-height: 1.2;">
                        Delete {{ $pendingBooking->guest_name ?? 'this reservation' }}?
                    </h2>
                    <p class="text-[12.5px]" style="color: var(--color-ink-soft); margin-top: 4px;">
                        <code class="lp-mono" style="color: var(--color-ink);">{{ $pendingBooking->booking_ref }}</code>
                        · Room {{ $pendingBooking->room?->room_number ?? '-' }}
                        · {{ $pendingBooking->checkin_date?->format('j M Y') ?? '-' }}
                        &rarr; {{ $pendingBooking->checkout_date?->format('j M Y') ?? '-' }}
                    </p>

                    <div style="background: var(--color-card-deep); border-radius: 10px;
                                padding: 12px 14px; margin-top: 14px;
                                display: flex; flex-direction: column; gap: 4px; font-size: 12.5px;">
                        <div class="flex justify-between">
                            <span style="color: var(--color-ink-soft);">Payments</span>
                            <span class="lp-mono">
                                {{ $pendingImpact['payments_count'] }} · ₦{{ number_format($pendingImpact['payments_amount']) }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span style="color: var(--color-ink-soft);">Charges</span>
                            <span class="lp-mono">{{ $pendingImpact['charges_count'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span style="color: var(--color-ink-soft);">Wallet activity</span>
                            <span class="lp-mono">₦{{ number_format($pendingImpact['wallet_amount']) }}</span>
                        </div>
                    </div>

                    @if ($pendingImpact['wallet_amount'] > 0)
                        <p class="mt-3 text-[12px]" style="color: #A8650A;">
                            ⚠ Wallet transactions on this booking will be removed from the ledger.
                            The guest's wallet balance is <strong>not</strong> automatically refunded.
                        </p>
                    @endif

                    <p class="text-[12.5px] mt-3" style="color: var(--color-ink-soft);">
                        Also deletes any linked invoices, availability holds, and activity-log
                        entries. <strong style="color: #A8650A;">This cannot be undone.</strong>
                    </p>

                    <div style="margin-top: 16px;">
                        <label for="cleanup-slug" class="lp-form-label">
                            Type the tenant slug to confirm
                        </label>
                        <p class="mt-1 text-[12px]" style="color: var(--color-ink-mute);">
                            Slug: <code class="lp-mono" style="color: var(--color-ink);">{{ $tenant->slug }}</code>
                        </p>
                        <input id="cleanup-slug" type="text"
                               wire:model.live="confirmSlug"
                               placeholder="{{ $tenant->slug }}"
                               data-testid="cleanup-confirm-slug"
                               autocomplete="off" spellcheck="false"
                               class="lp-input" style="margin-top: 6px;">
                    </div>

                    <div class="flex justify-end gap-3 mt-5 pt-4"
                         style="border-top: 1px solid var(--color-line-soft);">
                        <button type="button" wire:click="cancelConfirm" class="lp-btn"
                                data-testid="cleanup-confirm-cancel">
                            Cancel
                        </button>
                        <button type="button" wire:click="purge"
                                data-testid="cleanup-confirm-submit"
                                @disabled(trim($confirmSlug) !== $tenant->slug)
                                style="background: {{ trim($confirmSlug) === $tenant->slug ? '#A8650A' : '#D9BFA3' }};
                                       color: #fff; border: 0;
                                       padding: 7px 16px; border-radius: 999px;
                                       font-family: inherit; font-size: 12.5px; font-weight: 600;
                                       cursor: {{ trim($confirmSlug) === $tenant->slug ? 'pointer' : 'not-allowed' }};">
                            <span wire:loading.remove wire:target="purge">Remove this reservation</span>
                            <span wire:loading wire:target="purge">Removing…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- ══════════════════════════════════════════════════════════
         POS SALES TAB
         ══════════════════════════════════════════════════════════ --}}
    @if ($tab === 'pos')
        <section class="lp-dash-card">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Find the POS sale</span>
                <span class="lp-dash-card-meta">
                    {{ $posSales->total() ?? $posSales->count() }} match
                </span>
            </div>

            <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label for="cleanup-pos-search" class="lp-form-label">Search</label>
                    <input id="cleanup-pos-search" type="search"
                           wire:model.live.debounce.400ms="search"
                           placeholder="Reference, notes, outlet or operator"
                           data-testid="cleanup-pos-search" class="lp-input">
                </div>
                <div>
                    <label for="cleanup-pos-status" class="lp-form-label">Status</label>
                    <select id="cleanup-pos-status" wire:model.live="statusFilter"
                            data-testid="cleanup-pos-status" class="lp-input">
                        <option value="">All</option>
                        <option value="draft">Draft</option>
                        <option value="completed">Completed</option>
                        <option value="voided">Voided</option>
                    </select>
                </div>
            </form>
        </section>

        @if ($posSales->isEmpty())
            <section class="lp-dash-card text-center" style="padding: 56px 24px;">
                <p data-testid="cleanup-no-pos" class="text-[18px]"
                   style="color: var(--color-ink); font-weight: 500;">
                    No POS sales match this filter.
                </p>
            </section>
        @else
            <section class="lp-dash-card" style="padding: 0;">
                <div class="overflow-x-auto">
                    <table data-testid="cleanup-pos-table" class="lp-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Outlet</th>
                                <th>Operator</th>
                                <th>Sold at</th>
                                <th>Status</th>
                                <th style="text-align: right;">Total</th>
                                <th style="text-align: right;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($posSales as $s)
                                <tr data-testid="cleanup-pos-row">
                                    <td>
                                        <code class="lp-mono"
                                              style="color: var(--color-ink); font-size: 12.5px; font-weight: 500;">
                                            {{ $s->reference }}
                                        </code>
                                    </td>
                                    <td style="color: var(--color-ink);">
                                        {{ $s->outlet?->name ?? '-' }}
                                    </td>
                                    <td style="color: var(--color-ink-soft);">
                                        {{ $s->operator?->name ?? '-' }}
                                    </td>
                                    <td style="color: var(--color-ink-soft); font-size: 12.5px;">
                                        {{ $s->sold_at?->format('j M Y, g:i A') ?? '-' }}
                                    </td>
                                    <td style="color: var(--color-ink-soft); font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em;">
                                        {{ str_replace('_', ' ', $s->status?->value ?? '') }}
                                    </td>
                                    <td class="lp-mono" style="text-align: right; color: var(--color-ink);">
                                        ₦{{ number_format((int) $s->total) }}
                                    </td>
                                    <td style="text-align: right;">
                                        <button type="button"
                                                wire:click="openPosConfirm({{ $s->id }})"
                                                data-testid="cleanup-pos-remove-{{ $s->id }}"
                                                style="background: transparent; color: #A8650A;
                                                       border: 1px solid #A8650A;
                                                       padding: 5px 12px; border-radius: 999px;
                                                       font-family: inherit; font-size: 12px; font-weight: 500;
                                                       cursor: pointer;">
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="padding: 12px 16px; border-top: 1px solid var(--color-line-soft);">
                    {{ $posSales->links() }}
                </div>
            </section>
        @endif

        {{-- Confirmation modal (single POS sale) --}}
        @if ($pendingPos !== null)
            <div data-testid="cleanup-pos-confirm-backdrop"
                 wire:click.self="cancelPosConfirm"
                 style="position: fixed; inset: 0; z-index: 80;
                        background: rgba(20, 22, 24, 0.55);
                        backdrop-filter: blur(2px);
                        display: flex; align-items: center; justify-content: center;
                        padding: 20px;">
                <div data-testid="cleanup-pos-confirm-card"
                     style="width: 100%; max-width: 520px;
                            background: var(--color-card);
                            border: 1px solid var(--color-line-soft);
                            border-radius: 16px;
                            padding: 24px 26px 20px;
                            box-shadow: 0 24px 60px rgba(0,0,0,0.25);">
                    <p class="lp-mono text-[10.5px]"
                       style="color: #A8650A; letter-spacing: 0.18em; text-transform: uppercase;">
                        Remove POS sale
                    </p>
                    <h2 class="lp-display" style="font-size: 20px; font-weight: 600; margin-top: 4px; line-height: 1.2;">
                        Delete {{ $pendingPos->reference }}?
                    </h2>
                    <p class="text-[12.5px]" style="color: var(--color-ink-soft); margin-top: 4px;">
                        {{ $pendingPos->outlet?->name ?? '-' }}
                        · by {{ $pendingPos->operator?->name ?? '-' }}
                        · {{ $pendingPos->sold_at?->format('j M Y, g:i A') ?? 'draft' }}
                    </p>

                    <div style="background: var(--color-card-deep); border-radius: 10px;
                                padding: 12px 14px; margin-top: 14px;
                                display: flex; flex-direction: column; gap: 4px; font-size: 12.5px;">
                        <div class="flex justify-between">
                            <span style="color: var(--color-ink-soft);">Sale total</span>
                            <span class="lp-mono">₦{{ number_format((int) $pendingPos->total) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span style="color: var(--color-ink-soft);">Payments</span>
                            <span class="lp-mono">
                                {{ $pendingPosImpact['payments_count'] }} · ₦{{ number_format($pendingPosImpact['payments_amount']) }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span style="color: var(--color-ink-soft);">Wallet activity</span>
                            <span class="lp-mono">₦{{ number_format($pendingPosImpact['wallet_amount']) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span style="color: var(--color-ink-soft);">Stock movements</span>
                            <span class="lp-mono">{{ $pendingPosImpact['stock_count'] }}</span>
                        </div>
                    </div>

                    @if ($pendingPosImpact['stock_count'] > 0 || $pendingPosImpact['wallet_amount'] > 0)
                        <p class="mt-3 text-[12px]" style="color: #A8650A;">
                            ⚠ Stock movements + wallet transactions on this sale will be removed
                            from the ledger. Neither stock on-hand nor the guest's wallet balance
                            is <strong>automatically</strong> restored. Reconcile separately if needed.
                        </p>
                    @endif

                    <p class="text-[12.5px] mt-3" style="color: var(--color-ink-soft);">
                        Also deletes sale lines, returns, tied booking/credit-line charges,
                        and activity-log entries.
                        <strong style="color: #A8650A;">This cannot be undone.</strong>
                    </p>

                    <div style="margin-top: 16px;">
                        <label for="cleanup-pos-slug" class="lp-form-label">
                            Type the tenant slug to confirm
                        </label>
                        <p class="mt-1 text-[12px]" style="color: var(--color-ink-mute);">
                            Slug: <code class="lp-mono" style="color: var(--color-ink);">{{ $tenant->slug }}</code>
                        </p>
                        <input id="cleanup-pos-slug" type="text"
                               wire:model.live="confirmSlug"
                               placeholder="{{ $tenant->slug }}"
                               data-testid="cleanup-pos-confirm-slug"
                               autocomplete="off" spellcheck="false"
                               class="lp-input" style="margin-top: 6px;">
                    </div>

                    <div class="flex justify-end gap-3 mt-5 pt-4"
                         style="border-top: 1px solid var(--color-line-soft);">
                        <button type="button" wire:click="cancelPosConfirm" class="lp-btn"
                                data-testid="cleanup-pos-confirm-cancel">
                            Cancel
                        </button>
                        <button type="button" wire:click="purgePos"
                                data-testid="cleanup-pos-confirm-submit"
                                @disabled(trim($confirmSlug) !== $tenant->slug)
                                style="background: {{ trim($confirmSlug) === $tenant->slug ? '#A8650A' : '#D9BFA3' }};
                                       color: #fff; border: 0;
                                       padding: 7px 16px; border-radius: 999px;
                                       font-family: inherit; font-size: 12.5px; font-weight: 600;
                                       cursor: {{ trim($confirmSlug) === $tenant->slug ? 'pointer' : 'not-allowed' }};">
                            <span wire:loading.remove wire:target="purgePos">Remove this sale</span>
                            <span wire:loading wire:target="purgePos">Removing…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- ══════════════════════════════════════════════════════════
         POS CATALOG TAB - nuclear "wipe all outlets + products + stores"
         reset for tenants who created onboarding test data + want to
         start their real reports from a blank slate.
         ══════════════════════════════════════════════════════════ --}}
    @if ($tab === 'catalog')
        <section class="lp-dash-card" data-testid="cleanup-catalog">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">POS catalog</span>
                <span class="lp-dash-card-meta">
                    {{ $catalog['counts']['outlets'] }} outlet(s) ·
                    {{ $catalog['counts']['products'] }} product(s) ·
                    {{ $catalog['counts']['stores'] }} store(s) ·
                    {{ $catalog['counts']['sales'] }} sale(s)
                </span>
            </div>

            @if ($catalog['counts']['outlets'] === 0
                && $catalog['counts']['products'] === 0
                && $catalog['counts']['stores'] === 0)
                <p class="text-[13px]" style="color: var(--color-ink-mute); padding: 8px 0;"
                   data-testid="cleanup-catalog-empty">
                    Nothing to purge. This tenant has no outlets, products, or stores yet.
                </p>
            @else
                <p class="text-[13px]" style="color: var(--color-ink-soft); margin: 0 0 12px;">
                    One-shot nuclear reset. Wipes every outlet, product, and store on this
                    tenant + everything that references them: POS sales + lines + returns,
                    payments, wallet transactions, stock movements, booking + credit-line
                    charges matched by sale reference, and the audit trail for those subjects.
                    Stock balances + wallet balances are NOT auto-recomputed.
                </p>

                @if ($catalog['outlets']->isNotEmpty())
                    <div style="margin-top: 8px;">
                        <p class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                            Outlets ({{ $catalog['counts']['outlets'] }})
                        </p>
                        <ul style="list-style: none; padding: 0; margin: 4px 0 0; display: grid; gap: 4px;">
                            @foreach ($catalog['outlets'] as $o)
                                <li class="text-[12.5px]" style="color: var(--color-ink);"
                                    data-testid="cleanup-catalog-outlet-{{ $o->id }}">
                                    {{ $o->name }}
                                    <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); margin-left: 6px;">
                                        {{ $o->type->label() }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($catalog['products']->isNotEmpty())
                    <div style="margin-top: 14px;">
                        <p class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                            Products ({{ $catalog['counts']['products'] }})
                        </p>
                        <ul style="list-style: none; padding: 0; margin: 4px 0 0; display: grid; gap: 4px; max-height: 240px; overflow-y: auto;">
                            @foreach ($catalog['products'] as $p)
                                <li class="text-[12.5px]" style="color: var(--color-ink);">
                                    {{ $p->name }}
                                    @if ($p->sku)
                                        <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); margin-left: 6px;">
                                            SKU {{ $p->sku }}
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div style="margin-top: 18px; padding: 12px 14px; border: 1px solid #F5D9A8; background: #FFF4E5; border-radius: 10px;">
                    <p class="text-[12.5px]" style="color: var(--color-ink); font-weight: 600; margin: 0;">
                        Retype the tenant slug to confirm.
                    </p>
                    <p class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin: 4px 0 8px;">
                        {{ $tenant->slug }}
                    </p>
                    <div class="flex flex-wrap gap-2 items-center">
                        <input type="text" wire:model.live="confirmSlug"
                               data-testid="cleanup-catalog-confirm-input"
                               placeholder="{{ $tenant->slug }}"
                               class="lp-input"
                               style="flex: 1; min-width: 180px; max-width: 260px; font-size: 13px;">
                        <button type="button" wire:click="purgeCatalog"
                                data-testid="cleanup-catalog-confirm-submit"
                                @disabled(trim($confirmSlug) !== $tenant->slug)
                                style="background: {{ trim($confirmSlug) === $tenant->slug ? '#B85C42' : '#D9BFA3' }};
                                       color: #fff; border: 0;
                                       padding: 7px 16px; border-radius: 999px;
                                       font-family: inherit; font-size: 12.5px; font-weight: 600;
                                       cursor: {{ trim($confirmSlug) === $tenant->slug ? 'pointer' : 'not-allowed' }};">
                            <span wire:loading.remove wire:target="purgeCatalog">Wipe POS catalog</span>
                            <span wire:loading wire:target="purgeCatalog">Wiping…</span>
                        </button>
                    </div>
                </div>
            @endif
        </section>
    @endif
</div>
