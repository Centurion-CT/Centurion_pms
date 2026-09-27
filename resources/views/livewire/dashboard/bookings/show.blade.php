@php
    $statusValue = $booking->booking_status->value;
    $statusClass = match ($statusValue) {
        'confirmed'    => 'lp-tag--confirmed',
        'on_hold'      => 'lp-tag--on-hold',
        'pending'      => 'lp-tag--pending',
        'cancelled'    => 'lp-tag--cancelled',
        'checked_in'   => 'lp-tag--checked-in',
        'checked_out'  => 'lp-tag--checked-out',
        default        => '',
    };
    $statusLabel = ucwords(str_replace('_', ' ', $statusValue));
@endphp

<div class="lp-dash">

    {{-- ── Back link + header ───────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <span class="lp-eyebrow-strip">Booking</span>
                <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)] flex flex-wrap items-baseline gap-3"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    <span>Reservation</span>
                    <span class="lp-mono text-[16px]"
                          style="color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase; font-weight: 500;">
                        {{ $booking->booking_ref }}
                    </span>
                </h1>
                <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                    {{ $booking->guest_name }} &middot;
                    @if ($booking->room)
                        {{ $booking->room->name }}
                    @elseif ($booking->isUnassigned())
                        {{ $booking->roomCategory?->name ?? 'Category' }} · Unassigned
                    @else
                        &middot;
                    @endif
                    &middot;
                    {{ $booking->checkin_date->toFormattedDateString() }}
                </p>
            </div>
            <span class="lp-tag {{ $statusClass }}" data-testid="booking-status">{{ $statusLabel }}</span>
        </div>
    </div>

    {{-- ── Status / error banners ───────────────────────────────── --}}
    {{-- ── Summary + Actions ────────────────────────────────────── --}}
    <div class="grid gap-4 lg:grid-cols-3">

        <section data-testid="booking-summary" class="lp-dash-card lg:col-span-2">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Summary</span>
            </div>

            <dl class="lp-dl">
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Payment</dt>
                    <dd class="lp-dl-value">{{ ucwords(str_replace('_', ' ', $booking->payment_status->value)) }}</dd>
                </div>
                <div class="lp-dl-row" data-testid="booking-source">
                    <dt class="lp-dl-label">Channel</dt>
                    <dd class="lp-dl-value">
                        {{ $booking->source->label() }}
                        @if ($booking->source_note)
                            <span class="muted">·</span>
                            <span style="color: var(--color-ink-soft);">{{ $booking->source_note }}</span>
                        @endif
                    </dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Room</dt>
                    <dd class="lp-dl-value">
                        @if ($booking->room)
                            {{ $booking->room->name }}
                        @elseif ($booking->isUnassigned())
                            {{-- Category-level booking, no room pinned yet.
                                 Front desk must assign one before check-in
                                 (MarkCheckedIn refuses room_id NULL). --}}
                            <span data-testid="booking-show-unassigned"
                                  style="display: inline-flex; align-items: center; gap: 8px;">
                                {{ $booking->roomCategory?->name ?? 'Category' }}
                                <span class="lp-tag" style="font-size: 10px; padding: 1px 6px;
                                                            background: var(--color-surface-2);
                                                            color: var(--color-ink-mute);
                                                            text-transform: uppercase; letter-spacing: 0.05em;">
                                    Unassigned
                                </span>
                            </span>
                        @else
                            &middot;
                        @endif
                    </dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Guest</dt>
                    <dd class="lp-dl-value">
                        <div style="font-weight: 500;">{{ $booking->guest_name }}</div>
                        <div class="text-[12.5px] mt-0.5 muted">
                            {{ $booking->guest_email }} &middot; {{ $booking->guest_phone }}
                        </div>
                    </dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Dates</dt>
                    <dd class="lp-dl-value">
                        {{ $booking->checkin_date->toFormattedDateString() }}
                        <span class="muted">→</span>
                        {{ $booking->checkout_date->toFormattedDateString() }}
                        <span class="muted">({{ $booking->nights }} night{{ $booking->nights === 1 ? '' : 's' }})</span>
                    </dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Guests</dt>
                    <dd class="lp-dl-value">
                        {{ $booking->adults }} adult{{ $booking->adults === 1 ? '' : 's' }}
                        @if ($booking->children > 0)
                            <span class="muted">·</span> {{ $booking->children }} child{{ $booking->children === 1 ? '' : 'ren' }}
                        @endif
                    </dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Total</dt>
                    <dd class="lp-dl-value">
                        <span class="amount">₦{{ number_format($booking->total_amount) }}</span>
                    </dd>
                </div>
                @if ($booking->manual_discount_amount > 0)
                    <div class="lp-dl-row" data-testid="manual-discount-row">
                        <dt class="lp-dl-label">Manual discount</dt>
                        <dd class="lp-dl-value">
                            <span class="amount" style="color: var(--color-accent-deep);">
                                −₦{{ number_format($booking->manual_discount_amount) }}
                                <span class="muted" style="font-weight: 400;">
                                    ({{ rtrim(rtrim(number_format((float) $booking->manual_discount_percent, 2), '0'), '.') }}%)
                                </span>
                            </span>
                            @if ($booking->manualDiscountBy)
                                <div class="text-[12px] mt-1" style="color: var(--color-ink-mute);">
                                    Applied by {{ $booking->manualDiscountBy->name }}
                                </div>
                            @endif
                        </dd>
                    </div>
                @endif
                @if ($booking->deposit_amount > 0)
                    <div class="lp-dl-row">
                        <dt class="lp-dl-label">Deposit / Balance</dt>
                        <dd class="lp-dl-value">
                            ₦{{ number_format($booking->deposit_amount) }}
                            <span class="muted">/</span>
                            <span class="amount amount-warn">₦{{ number_format($booking->balance_due) }}</span>
                        </dd>
                    </div>
                @endif
                @if ($booking->special_requests)
                    <div class="lp-dl-row">
                        <dt class="lp-dl-label">Requests</dt>
                        <dd class="lp-dl-value" style="font-style: italic;">{{ $booking->special_requests }}</dd>
                    </div>
                @endif
                @if ($booking->corporate)
                    <div class="lp-dl-row" data-testid="corporate-section">
                        <dt class="lp-dl-label">Corporate</dt>
                        <dd class="lp-dl-value">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span class="lp-mono text-[10.5px]"
                                      style="color: var(--color-accent-deep); letter-spacing: 0.08em; text-transform: uppercase;">
                                    Corporate · {{ $booking->corporate->name }}
                                </span>
                                @if ($booking->corporateMember)
                                    <span class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                        {{ $booking->corporateMember->name }}
                                    </span>
                                @endif
                            </div>
                            @if ((int) ($booking->corporate_discount_naira ?? 0) > 0)
                                <div class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                                    Negotiated saving:
                                    <strong style="color: var(--color-ink); font-weight: 500;">
                                        ₦{{ number_format((int) $booking->corporate_discount_naira) }}
                                    </strong>
                                    <span class="muted">vs the public rate</span>
                                </div>
                            @endif
                            <div class="text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                                Paid from the corporate wallet, no on-site charge required.
                            </div>
                        </dd>
                    </div>
                @endif
            </dl>
        </section>

        {{-- Payment breakdown - only rendered for bookings that
             actually carry fees (online engine: guest paid via
             Paystack, so the Keverna service fee + Paystack's
             processing were added to the guest total). Walk-ins /
             OTA bookings have 0 fees so the card would just repeat
             "Total: N / Payout: N" and adds no signal - hidden in
             that case. --}}
        @php
            $roomTotal = (int) $booking->room_total;
            $kevernaFee = (int) $booking->keverna_fee;
            $paystackFee = (int) $booking->paystack_fee;
            $operatorPayout = max(0, (int) $booking->total_amount - $kevernaFee - $paystackFee);
            $isMarketplace = (string) $booking->source_note === 'Keverna Stays marketplace';
            $showPaymentBreakdown = ($kevernaFee + $paystackFee) > 0;
        @endphp

        @if ($showPaymentBreakdown)
            <section data-testid="booking-payment-breakdown" class="lp-dash-card">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">
                        Payment breakdown
                        @if ($isMarketplace)
                            <span class="lp-mono text-[10px] tracking-[0.14em] uppercase"
                                  style="color: var(--color-accent-deep); margin-left: 8px;">
                                Keverna Stays
                            </span>
                        @endif
                    </span>
                </div>

                @php
                    // Additive fee model: Keverna + Paystack are added
                    // ON TOP of the room subtotal + shown to the guest
                    // as separate line items. Operator receives the
                    // full subtotal (their listed rate); the fees pass
                    // through to Keverna + Paystack respectively.
                    $displayGuestPaid = (int) $booking->total_amount;
                    $displayPayout = max(0, $roomTotal); // = subtotal, in full
                    $feeLabel = $isMarketplace
                        ? \App\Support\Marketplace\FeePolicy::percentFor($roomTotal)
                        : 5.0;
                @endphp
                <dl class="lp-dl">
                    @if ($roomTotal > 0)
                        <div class="lp-dl-row" data-testid="pb-room-subtotal">
                            <dt class="lp-dl-label">Room subtotal</dt>
                            <dd class="lp-dl-value">₦{{ number_format($roomTotal) }}</dd>
                        </div>
                    @endif
                    @if ($kevernaFee > 0)
                        <div class="lp-dl-row" data-testid="pb-keverna-fee">
                            <dt class="lp-dl-label">
                                Keverna service fee
                                <span class="muted" style="font-weight: 400;">
                                    ({{ rtrim(rtrim(number_format($feeLabel, 1), '0'), '.') }}% · guest paid)
                                </span>
                            </dt>
                            <dd class="lp-dl-value">
                                <span class="amount" style="color: var(--color-ink-soft);">
                                    +₦{{ number_format($kevernaFee) }}
                                </span>
                            </dd>
                        </div>
                    @endif
                    @if ($paystackFee > 0)
                        <div class="lp-dl-row" data-testid="pb-paystack-fee">
                            <dt class="lp-dl-label">
                                Payment processing
                                <span class="muted" style="font-weight: 400;">(Paystack · guest paid)</span>
                            </dt>
                            <dd class="lp-dl-value">
                                <span class="amount" style="color: var(--color-ink-soft);">
                                    +₦{{ number_format($paystackFee) }}
                                </span>
                            </dd>
                        </div>
                    @endif
                    <div class="lp-dl-row" data-testid="pb-guest-paid"
                         style="border-top: 1px solid var(--color-line-soft); padding-top: 8px;">
                        <dt class="lp-dl-label">Guest paid</dt>
                        <dd class="lp-dl-value">
                            <span class="amount">₦{{ number_format($displayGuestPaid) }}</span>
                        </dd>
                    </div>
                    <div class="lp-dl-row" data-testid="pb-operator-payout">
                        <dt class="lp-dl-label">
                            <strong style="color: var(--color-ink);">Your payout</strong>
                        </dt>
                        <dd class="lp-dl-value">
                            <span class="amount" style="color: var(--color-accent-deep); font-weight: 700;">
                                ₦{{ number_format($displayPayout) }}
                            </span>
                        </dd>
                    </div>
                    @if ($booking->deposit_amount > 0 && (int) $booking->balance_due > 0)
                        <div class="lp-dl-row" data-testid="pb-balance-note"
                             style="padding-top: 8px;">
                            <dt class="lp-dl-label" style="grid-column: 1 / -1;">
                                <span class="muted" style="font-weight: 400; font-size: 12px;">
                                    Deposit paid via Paystack: ₦{{ number_format((int) $booking->deposit_amount) }}.
                                    Balance ₦{{ number_format((int) $booking->balance_due) }} collected at property.
                                </span>
                            </dt>
                        </div>
                    @endif
                </dl>

                @if ($isMarketplace)
                    <p style="font-size: 11.5px; color: var(--color-ink-mute); margin-top: 12px;
                              padding: 8px 10px; background: rgba(20,23,26,0.03); border-radius: 6px;">
                        Booked through Keverna Stays. Both the {{ rtrim(rtrim(number_format($feeLabel, 1), '0'), '.') }}% service fee and Paystack processing are added to the guest total on checkout - your payout is the full room rate, and settles to your Paystack subaccount on the next Paystack cycle.
                    </p>
                @endif
            </section>
        @endif

        <section data-testid="booking-actions" class="lp-dash-card" style="display: grid; gap: 10px; align-content: start;">
            <div class="lp-dash-card-head" style="margin-bottom: 4px;">
                <span class="lp-dash-card-title">Actions</span>
            </div>

            @if ($canConfirm)
                <button type="button" wire:click="confirm" data-testid="action-confirm"
                        wire:confirm="Mark this booking as Confirmed?"
                        class="lp-btn lp-btn--primary lp-btn--block">Confirm booking</button>
            @endif

            @if ($booking->isUnassigned())
                {{-- Category-level booking - front desk needs to pin a
                     real room before check-in. Dropdown lists only Active
                     rooms in the booking's category that are free for
                     the full date range; empty state means the range
                     needs a different category or new dates. --}}
                <div data-testid="assign-room-block"
                     style="display: grid; gap: 8px; padding: 10px 12px;
                            border: 1px dashed var(--color-hairline); border-radius: 10px;
                            background: var(--color-surface-2);">
                    <div style="font-size: 12.5px; color: var(--color-ink-soft); line-height: 1.4;">
                        <strong style="color: var(--color-ink); font-weight: 600;">Assign a room.</strong>
                        Front desk picks a specific room from
                        <em>{{ $booking->roomCategory?->name ?? 'the category' }}</em>
                        before this guest can be checked in.
                    </div>
                    @if ($assignableRooms->isEmpty())
                        <p data-testid="assign-room-empty"
                           style="font-size: 12px; color: var(--color-danger); margin: 0;">
                            No rooms in this category are free for
                            {{ $booking->checkin_date->toFormattedDateString() }} –
                            {{ $booking->checkout_date->toFormattedDateString() }}.
                        </p>
                    @else
                        <div class="flex flex-wrap gap-2" style="align-items: center;">
                            <select wire:model.live="assignRoomId"
                                    data-testid="assign-room-select"
                                    class="lp-input" style="flex: 1; min-width: 140px;">
                                <option value="">Pick a room…</option>
                                @foreach ($assignableRooms as $r)
                                    <option value="{{ $r->id }}">Room {{ $r->room_number }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="assignRoom"
                                    data-testid="assign-room-submit"
                                    @disabled($assignRoomId === null || $assignRoomId === '')
                                    class="lp-btn lp-btn--primary">
                                <span wire:loading.remove wire:target="assignRoom">Assign</span>
                                <span wire:loading wire:target="assignRoom">Assigning…</span>
                            </button>
                        </div>
                    @endif
                </div>
            @elseif ($canCheckIn)
                <button type="button" wire:click="checkIn" data-testid="action-checkin"
                        class="lp-btn lp-btn--block">Mark checked in</button>
            @endif

            @if ($canCheckOut)
                <button type="button" wire:click="checkOut" data-testid="action-checkout"
                        class="lp-btn lp-btn--block">Mark checked out</button>
            @endif

            <button type="button" wire:click="resendConfirmation" data-testid="action-resend"
                    class="lp-btn lp-btn--block">
                Resend confirmation email
            </button>

            {{-- ── Wallet controls ─────────────────────────────────
                 Visible only when the booking is linked to a Customer
                 and that Customer has money on file. "Pay outstanding
                 from wallet" hits balance_due + charges via the
                 PayBookingFromWallet + SettleChargesFromWallet actions.
            --}}
            @if ($booking->customer && $walletBalance > 0)
                <div data-testid="booking-wallet-block"
                     style="margin-top: 8px; padding-top: 12px; border-top: 1px solid var(--color-line-soft); display: grid; gap: 8px;">
                    <div class="flex items-center justify-between text-[12.5px]" style="color: var(--color-ink-soft);">
                        <span>Wallet on file</span>
                        <span class="lp-mono" style="font-weight: 600; color: var(--color-ink);"
                              data-testid="booking-wallet-balance">
                            ₦{{ number_format($walletBalance) }}
                        </span>
                    </div>

                    @if ($booking->outstandingTotal() > 0)
                        <button type="button" wire:click="payOutstandingFromWallet"
                                data-testid="action-pay-from-wallet"
                                wire:confirm="Pay outstanding balance from {{ $booking->customer->name }}'s wallet?"
                                class="lp-btn lp-btn--block">
                            Pay ₦{{ number_format($booking->outstandingTotal()) }} from wallet
                        </button>

                        @if ($booking->charges()->unsettled()->exists())
                            <button type="button" wire:click="sweepChargesFromWallet"
                                    data-testid="action-sweep-charges"
                                    class="lp-btn lp-btn--block">
                                Settle charges from wallet
                            </button>
                        @endif
                    @endif

                    <a href="{{ route('tenant.customers.wallet', $booking->customer) }}"
                       wire:navigate class="lp-btn lp-btn--block" style="text-align: center;">
                        View wallet history
                    </a>
                </div>
            @elseif ($booking->customer)
                <div data-testid="booking-wallet-empty"
                     style="margin-top: 8px; padding-top: 12px; border-top: 1px solid var(--color-line-soft);">
                    <a href="{{ route('tenant.customers.wallet', $booking->customer) }}"
                       wire:navigate class="lp-btn lp-btn--block" style="text-align: center;">
                        Top up guest wallet
                    </a>
                </div>
            @endif

            {{-- Invoice: front desk drafts a printable bill for this
                 booking. Prior invoices (if any) are listed under this
                 button so staff can reprint / reopen the last one
                 instead of accidentally generating a duplicate. --}}
            <a href="{{ route('tenant.invoices.create', $booking->booking_ref) }}"
               wire:navigate data-testid="action-invoice"
               class="lp-btn lp-btn--block" style="text-align: center;">
                Generate invoice
            </a>
            @if ($booking->invoices->isNotEmpty())
                <div data-testid="booking-invoices" class="grid gap-1"
                     style="margin-top: 6px; padding-top: 10px; border-top: 1px solid var(--color-line-soft);">
                    <p class="lp-mono text-[10.5px]" style="letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">
                        Previously issued
                    </p>
                    @foreach ($booking->invoices as $inv)
                        <a href="{{ route('tenant.invoices.show', $inv->id) }}" wire:navigate
                           class="flex items-center justify-between text-[13px]"
                           style="color: var(--color-ink); text-decoration: none; padding: 4px 0;">
                            <span class="lp-mono">{{ $inv->number ?? 'Draft' }}</span>
                            <span class="lp-tag lp-tag--{{ $inv->status->value === 'paid' ? 'confirmed' : ($inv->status->value === 'void' ? 'cancelled' : 'on-hold') }}"
                                  style="font-size: 10px; {{ $inv->status->value === 'partial' ? 'background: rgba(180, 83, 9, 0.12); color: #8b4d0f;' : '' }}">{{ $inv->status->value }}</span>
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($canCancel)
                <div style="margin-top: 8px; padding-top: 14px; border-top: 1px solid var(--color-line-soft); display: grid; gap: 10px;">
                    <label class="flex items-start gap-2.5 text-[12.5px]" style="color: var(--color-ink-soft); cursor: pointer;">
                        <input type="checkbox" wire:model.live="refundRequested"
                               data-testid="refund-flag"
                               style="margin-top: 3px; accent-color: var(--color-accent-deep);">
                        <span>
                            Request a refund on Paystack
                            <span style="color: var(--color-ink-mute);">
                                (does not auto-execute, note for your reconciliation)
                            </span>
                        </span>
                    </label>
                    <button type="button" wire:click="cancel" data-testid="action-cancel"
                            wire:confirm="Cancel this booking?"
                            class="lp-btn lp-btn--danger lp-btn--block">Cancel booking</button>
                </div>
            @endif
        </section>
    </div>

    {{-- ── Audit log ────────────────────────────────────────────── --}}
    <section data-testid="audit-log" class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Audit log</span>
            <span class="lp-dash-card-meta">{{ $audit->count() }} entr{{ $audit->count() === 1 ? 'y' : 'ies' }}</span>
        </div>

        @if ($audit->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">No audit entries yet.</p>
        @else
            <div class="lp-audit">
                @foreach ($audit as $entry)
                    <div class="lp-audit-row">
                        <div class="ts">{{ $entry->created_at?->toDateTimeString() }}</div>
                        <div class="desc">{{ $entry->description }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
