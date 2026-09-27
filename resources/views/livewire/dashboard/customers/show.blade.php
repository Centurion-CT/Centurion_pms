@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';

    $lifecycleClass = match ($customer->lifecycle->value) {
        'active'   => 'lp-tag--confirmed',
        'prospect' => 'lp-tag--pending',
        'dormant'  => 'lp-tag--on-hold',
        'churned'  => 'lp-tag--cancelled',
        default    => '',
    };
@endphp

<div class="lp-dash">

    {{-- ── Back + editorial hero ────────────────────────────────
         Left: identity (lifecycle eyebrow, name + VIP star, contact,
         action strip). Right: money aside with lifetime value +
         sub-line (bookings count + last stay). Same pattern the
         customer index + accounting + bookings pages use so the
         property-book detail no longer feels off-rhythm. --}}
    @include('_partials._back-button')

    <section class="lp-res-idx-hero mt-3" data-testid="customer-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Relationships · {{ $customer->lifecycle->label() }}</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)] flex flex-wrap items-baseline gap-3"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    @if ($customer->is_vip)
                        <span style="color: var(--color-accent-deep);">★</span>
                    @endif
                    <span data-testid="customer-name">{{ $customer->name }}</span>
                </h1>
                @if ($customer->email || $customer->phone)
                    <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                        @if ($customer->email)
                            <a href="mailto:{{ $customer->email }}" style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">{{ $customer->email }}</a>
                        @endif
                        @if ($customer->email && $customer->phone)
                            <span style="color: var(--color-ink-mute); margin: 0 8px;">·</span>
                        @endif
                        @if ($customer->phone)
                            <a href="tel:{{ $customer->phone }}" class="lp-mono" style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">{{ $customer->phone }}</a>
                        @endif
                    </p>
                @endif

                <div class="lp-res-idx-hero-actions">
                    <button type="button" wire:click="toggleVip"
                            data-testid="toggle-vip"
                            class="lp-res-action {{ $customer->is_vip ? 'lp-res-action--ghost' : 'lp-res-action--primary' }}">
                        <span style="color: {{ $customer->is_vip ? 'var(--color-ink-mute)' : 'var(--color-accent-deep)' }};">★</span>
                        {{ $customer->is_vip ? 'Remove VIP flag' : 'Mark as VIP' }}
                    </button>
                    <a href="{{ route('tenant.customers.wallet', $customer) }}" wire:navigate
                       data-testid="customer-wallet-link"
                       class="lp-res-action">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2 5h12v8H2z"/><path d="M11 9h1"/></svg>
                        Wallet · ₦{{ number_format($customer->walletBalance()) }}
                    </a>
                    <a href="{{ route('tenant.customers.edit', $customer) }}" wire:navigate
                       data-testid="customer-edit-link"
                       class="lp-res-action lp-res-action--ghost">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 13h3l7-7-3-3-7 7z"/></svg>
                        Edit profile
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Lifetime value">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Lifetime value</p>
                    <p class="lp-ih-hero-accrued-value"
                       style="color: {{ (int) $customer->lifetime_value > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};">
                        ₦{{ number_format((int) $customer->lifetime_value) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        {{ number_format((int) $customer->total_bookings) }}
                        {{ Str::plural('booking', (int) $customer->total_bookings) }}
                        @if ($customer->last_booking_at)
                            <span style="color: var(--color-ink-mute);">·</span>
                            last {{ $customer->last_booking_at->diffForHumans() }}
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Purchases (POS sales tied to this customer) ─────────────
         Only shows sales where the cashier tied a customer at the
         payment sheet, whether paid or complimentary. Historical
         walk-in sales without a customer_id will not appear. --}}
    <section class="lp-dash-card" data-testid="customer-purchases" style="margin-top: 4px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Purchases</span>
            <span class="lp-dash-card-meta">
                {{ number_format($purchasesKpi['count']) }}
                {{ Str::plural('sale', $purchasesKpi['count']) }} at the till
                @if ($purchasesKpi['count'] > 0)
                    <span style="color: var(--color-ink-mute);">·</span>
                    <a href="{{ route('tenant.customers.purchases.export', $customer) }}"
                       data-testid="customer-purchases-export"
                       style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                        Download CSV
                    </a>
                @endif
            </span>
        </div>

        @if ($purchases->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                No purchases tied yet. When a cashier taps "Save new customer" or looks up a phone at the payment sheet, sales land here.
            </p>
        @else
            {{-- Purchases KPI strip: paid vs comp so the split is obvious --}}
            <div class="grid gap-2 grid-cols-2 lg:grid-cols-4"
                 data-testid="customer-purchases-kpis"
                 style="margin-bottom: 14px;">
                <div class="lp-dash-card" style="padding: 12px 14px; display: flex; align-items: center; gap: 10px;">
                    <span aria-hidden style="width: 6px; height: 6px; border-radius: 999px; background: var(--color-accent-deep); box-shadow: 0 0 0 3px var(--color-accent-deep)22;"></span>
                    <div style="min-width: 0;">
                        <p class="lp-num-lg" style="font-size: 18px;">₦{{ number_format($purchasesKpi['paid_value']) }}</p>
                        <p class="lp-mono text-[10px] tracking-[0.14em] uppercase" style="color: var(--color-ink-mute); margin-top: 2px;">
                            Paid value · {{ number_format($purchasesKpi['paid_count']) }} {{ Str::plural('sale', $purchasesKpi['paid_count']) }}
                        </p>
                    </div>
                </div>
                <div class="lp-dash-card" style="padding: 12px 14px; display: flex; align-items: center; gap: 10px;">
                    <span aria-hidden style="width: 6px; height: 6px; border-radius: 999px; background: #a36318; box-shadow: 0 0 0 3px #a3631822;"></span>
                    <div style="min-width: 0;">
                        <p class="lp-num-lg" style="font-size: 18px;">₦{{ number_format($purchasesKpi['comp_value']) }}</p>
                        <p class="lp-mono text-[10px] tracking-[0.14em] uppercase" style="color: var(--color-ink-mute); margin-top: 2px;">
                            Comp value · {{ number_format($purchasesKpi['comp_count']) }} {{ Str::plural('sale', $purchasesKpi['comp_count']) }}
                        </p>
                    </div>
                </div>
                <div class="lp-dash-card" style="padding: 12px 14px; display: flex; align-items: center; gap: 10px;">
                    <span aria-hidden style="width: 6px; height: 6px; border-radius: 999px; background: #4B7BB0; box-shadow: 0 0 0 3px #4B7BB022;"></span>
                    <div style="min-width: 0;">
                        <p class="lp-num-lg" style="font-size: 18px;">
                            ₦{{ number_format($purchasesKpi['paid_value'] + $purchasesKpi['comp_value']) }}
                        </p>
                        <p class="lp-mono text-[10px] tracking-[0.14em] uppercase" style="color: var(--color-ink-mute); margin-top: 2px;">
                            Total consumed
                        </p>
                    </div>
                </div>
                <div class="lp-dash-card" style="padding: 12px 14px; display: flex; align-items: center; gap: 10px;">
                    <span aria-hidden style="width: 6px; height: 6px; border-radius: 999px; background: #7A6EA7; box-shadow: 0 0 0 3px #7A6EA722;"></span>
                    <div style="min-width: 0;">
                        <p class="lp-num-lg" style="font-size: 16px;">
                            {{ $purchasesKpi['last_purchase_at']?->format('D, j M') ?? '-' }}
                        </p>
                        <p class="lp-mono text-[10px] tracking-[0.14em] uppercase" style="color: var(--color-ink-mute); margin-top: 2px;">
                            Last purchase
                            @if ($purchasesKpi['last_purchase_at'])
                                <span style="text-transform: none; letter-spacing: 0; color: var(--color-ink-mute); font-size: 11px; margin-left: 4px;">
                                    {{ $purchasesKpi['last_purchase_at']->diffForHumans() }}
                                </span>
                            @endif
                        </p>
                    </div>
                </div>
            </div>

            <ul class="lp-ledger" data-testid="customer-purchases-list">
                @foreach ($purchases as $sale)
                    @php
                        $isComp = (bool) $sale->is_complimentary;
                        $tone = $isComp ? 'attn' : 'paid';
                        $tenderMethods = $sale->payments->pluck('method')->unique()->values();
                        // Show the sale total for paid sales, or the sum of
                        // line subtotals for comped sales (since sale.total
                        // is 0 for comps).
                        $displayValue = $isComp
                            ? (int) $sale->lines->sum('subtotal')
                            : (int) $sale->total;
                    @endphp
                    <li class="lp-ledger-item lp-channel-row"
                        data-testid="customer-purchase-{{ $sale->id }}">
                        <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                        <div class="lp-ledger-body">
                            <div class="lp-gr-primary">
                                <span class="lp-ledger-desc" style="font-weight: 600; color: var(--color-ink);">
                                    {{ $sale->outlet?->name ?? 'Outlet' }}
                                </span>
                                @if ($isComp)
                                    <span class="lp-tag lp-tag--pending">Complimentary</span>
                                @else
                                    @foreach ($tenderMethods as $m)
                                        <span class="lp-tag lp-tag--confirmed" style="text-transform: capitalize;">{{ $m }}</span>
                                    @endforeach
                                @endif
                                <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase;">
                                    #{{ $sale->id }}
                                </span>
                            </div>
                            <p class="lp-ledger-meta">
                                {{ $sale->created_at->format('D, j M · g:i A') }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                {{ $sale->lines->sum('quantity') }} {{ Str::plural('item', $sale->lines->sum('quantity')) }}
                                <span style="color: var(--color-ink-mute);">·</span>
                                by {{ $sale->operator?->name ?? 'Operator' }}
                                @if ($isComp && $sale->complimentary_reason)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    <span style="color: #a36318;">{{ Str::limit($sale->complimentary_reason, 40) }}</span>
                                @endif
                            </p>
                            @if ($sale->lines->isNotEmpty())
                                <p class="lp-ledger-meta" style="color: var(--color-ink-soft); margin-top: 4px;">
                                    @foreach ($sale->lines as $line)
                                        <span>
                                            {{ $line->quantity }}× {{ $line->name }}@if (! $loop->last), @endif
                                        </span>
                                    @endforeach
                                </p>
                            @endif
                        </div>
                        <div class="lp-ledger-right">
                            <p class="lp-num-lg" style="font-size: 16px; text-align: right;
                                {{ $isComp ? 'color: #a36318; text-decoration: line-through; text-decoration-thickness: 1px;' : 'color: var(--color-ink);' }}">
                                ₦{{ number_format($displayValue) }}
                            </p>
                            @if ($isComp)
                                <p class="lp-mono text-[10px] tracking-[0.14em] uppercase"
                                   style="color: #a36318; margin-top: 2px; text-align: right;">
                                    No charge
                                </p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ── Two-col body ──────────────────────────────────────────── --}}
    <div class="grid gap-4 lg:grid-cols-3">

        {{-- Activity column --}}
        <div class="lg:col-span-2" style="display: grid; gap: 18px;">

            {{-- Log new interaction --}}
            <section class="lp-dash-card" data-testid="log-interaction">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Log an interaction</span>
                    <span class="lp-dash-card-meta">call, email, WhatsApp, note…</span>
                </div>

                <form wire:submit="logInteraction" style="display: grid; gap: 16px;">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="new-type" class="lp-form-label">Type</label>
                            <select id="new-type" wire:model.live="newType"
                                    data-testid="new-type" class="lp-input">
                                @foreach ($typeOptions as $opt)
                                    @if ($opt->value !== 'system')
                                        <option value="{{ $opt->value }}">{{ $opt->label() }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="new-direction" class="lp-form-label">Direction</label>
                            <select id="new-direction" wire:model="newDirection"
                                    data-testid="new-direction" class="lp-input">
                                @foreach ($directionOptions as $opt)
                                    <option value="{{ $opt->value }}">
                                        {{ $opt->value === 'none' ? '- (none)' : $opt->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="new-subject" class="lp-form-label">Subject <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span></label>
                        <input id="new-subject" type="text" wire:model="newSubject" maxlength="200"
                               data-testid="new-subject" class="lp-input"
                               placeholder="Called to confirm arrival time">
                        @error('newSubject') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="new-body" class="lp-form-label">Notes</label>
                        <textarea id="new-body" wire:model="newBody" rows="3" maxlength="4000"
                                  data-testid="new-body" class="lp-input"
                                  placeholder="Anything you want to remember about this touchpoint…"></textarea>
                        @error('newBody') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" data-testid="log-submit" class="lp-pill">
                            <span wire:loading.remove wire:target="logInteraction">Save to timeline</span>
                            <span wire:loading wire:target="logInteraction">Saving…</span>
                            <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                        </button>
                    </div>
                </form>
            </section>

            {{-- Timeline --}}
            <section class="lp-dash-card" data-testid="timeline">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Activity timeline</span>
                    <span class="lp-dash-card-meta">{{ $interactions->count() }} entr{{ $interactions->count() === 1 ? 'y' : 'ies' }}</span>
                </div>

                @if ($interactions->isEmpty())
                    <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                        No activity yet. Logged calls, emails, and booking events will appear here.
                    </p>
                @else
                    <div class="lp-timeline">
                        @foreach ($interactions as $entry)
                            @php
                                $isSystem = $entry->type->value === 'system';
                                $typeLabel = $entry->type->label();
                            @endphp
                            <div class="lp-timeline-row"
                                 data-system="{{ $isSystem ? 'true' : 'false' }}"
                                 data-direction="{{ $entry->direction->value }}"
                                 data-testid="timeline-entry-{{ $entry->id }}">
                                <div class="meta">
                                    <span class="label @if ($isSystem) label--system @endif">{{ $typeLabel }}</span>
                                    @if ($entry->direction->value !== 'none')
                                        <span style="font-size: 10.5px; color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">
                                            {{ $entry->direction->label() }}
                                        </span>
                                    @endif
                                    <span style="margin-left: auto; color: var(--color-ink-mute);">
                                        {{ $entry->happened_at->diffForHumans() }}
                                    </span>
                                </div>
                                @if ($entry->subject)
                                    <p class="subject">{{ $entry->subject }}</p>
                                @endif
                                @if ($entry->body)
                                    <p class="body">{{ $entry->body }}</p>
                                @endif
                                <p class="by">
                                    @if ($entry->booking)
                                        Linked to <a href="{{ route('tenant.bookings.show', $entry->booking->booking_ref) }}" wire:navigate
                                                    style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">{{ $entry->booking->booking_ref }}</a>
                                    @endif
                                    @if ($entry->operator)
                                        @if ($entry->booking) · @endif
                                        Logged by {{ $entry->operator->name }}
                                    @elseif ($isSystem)
                                        @if ($entry->booking) · @endif Automatic
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

        {{-- Sidebar column --}}
        <aside style="display: grid; gap: 18px; align-content: start;">

            {{-- Bookings --}}
            <section class="lp-dash-card" data-testid="customer-bookings">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Bookings</span>
                    <span class="lp-dash-card-meta">{{ $bookings->count() }}</span>
                </div>

                @if ($bookings->isEmpty())
                    <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                        No bookings linked yet.
                    </p>
                @else
                    <ul class="divide-y list-none p-0 m-0" style="border-color: var(--color-line-soft);">
                        @foreach ($bookings as $booking)
                            @php
                                $statusClass = match ($booking->booking_status->value) {
                                    'confirmed'   => 'lp-tag--confirmed',
                                    'on_hold'     => 'lp-tag--on-hold',
                                    'pending'     => 'lp-tag--pending',
                                    'cancelled'   => 'lp-tag--cancelled',
                                    'checked_in'  => 'lp-tag--checked-in',
                                    'checked_out' => 'lp-tag--checked-out',
                                    default       => '',
                                };
                            @endphp
                            <li class="py-3" data-testid="customer-booking-{{ $booking->id }}">
                                <a href="{{ route('tenant.bookings.show', $booking->booking_ref) }}" wire:navigate
                                   class="grid gap-1" style="color: inherit;">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">
                                            {{ $booking->booking_ref }}
                                        </span>
                                        <span class="lp-tag {{ $statusClass }}">
                                            {{ ucwords(str_replace('_', ' ', $booking->booking_status->value)) }}
                                        </span>
                                    </span>
                                    <span class="text-[13px]" style="color: var(--color-ink); font-weight: 500;">
                                        {{ $booking->room?->name }}
                                    </span>
                                    <span class="text-[12px]" style="color: var(--color-ink-soft);">
                                        {{ $booking->checkin_date->toFormattedDateString() }}
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        {{ $booking->nights }}n
                                        <span style="color: var(--color-ink-mute);">·</span>
                                        ₦{{ number_format($booking->total_amount) }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Profile snapshot --}}
            <section class="lp-dash-card" data-testid="customer-profile">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Profile</span>
                    <span class="lp-tag {{ $lifecycleClass }}">{{ $customer->lifecycle->label() }}</span>
                </div>

                <dl class="lp-dl">
                    @if ($customer->address || $customer->city || $customer->country)
                        <div class="lp-dl-row">
                            <dt class="lp-dl-label">Address</dt>
                            <dd class="lp-dl-value">
                                {{ collect([$customer->address, $customer->city, $customer->state, $customer->country])->filter()->implode(', ') }}
                            </dd>
                        </div>
                    @endif

                    <div class="lp-dl-row">
                        <dt class="lp-dl-label">Marketing</dt>
                        <dd class="lp-dl-value">
                            @if ($customer->marketing_opt_in)
                                <span class="lp-tag lp-tag--confirmed">Opted in</span>
                            @else
                                <span class="lp-tag lp-tag--checked-out">Not opted in</span>
                            @endif
                        </dd>
                    </div>

                    @if (! empty($customer->tags))
                        <div class="lp-dl-row">
                            <dt class="lp-dl-label">Tags</dt>
                            <dd class="lp-dl-value">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($customer->tags as $tag)
                                        <span class="lp-tag-pill">{{ $tag }}</span>
                                    @endforeach
                                </div>
                            </dd>
                        </div>
                    @endif

                    @if ($customer->notes)
                        <div class="lp-dl-row">
                            <dt class="lp-dl-label">Notes</dt>
                            <dd class="lp-dl-value" style="white-space: pre-wrap;">{{ $customer->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </section>
        </aside>
    </div>
</div>
