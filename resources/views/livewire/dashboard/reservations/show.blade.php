@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';

    $statusClass = match ($booking->booking_status->value) {
        'confirmed'   => 'lp-tag--confirmed',
        'on_hold'     => 'lp-tag--on-hold',
        'pending'     => 'lp-tag--pending',
        'cancelled'   => 'lp-tag--cancelled',
        'checked_in'  => 'lp-tag--checked-in',
        'checked_out' => 'lp-tag--checked-out',
        'no_show'     => 'lp-tag--cancelled',
        default       => '',
    };
    $statusLabel = ucwords(str_replace('_', ' ', $booking->booking_status->value));

    $isVip = (bool) ($booking->customer?->is_vip);
    $minCheckout = $booking->checkout_date->copy()->addDay()->toDateString();

    // Stay progress — human-friendly framing for the hero. Front-desk
    // recognises "Day 3 of 5" faster than raw date maths, and the
    // amber "Overstay +N" mode replaces the separate banner below.
    $today = \Illuminate\Support\Carbon::today();
    $stayTotalNights = max(1, (int) $booking->nights);
    if ($today->lt($booking->checkin_date)) {
        $stayPhase = 'upcoming';
        $stayLine = 'Arrives in '.$today->diffInDays($booking->checkin_date).'d';
        $stayProgress = 0;
    } elseif ($today->gte($booking->checkout_date)) {
        $overNights = max(0, (int) $booking->checkout_date->diffInDays($today));
        if ($overNights > 0 && $booking->booking_status === \App\Enums\BookingStatus::CheckedIn) {
            $stayPhase = 'overstay';
            $stayLine = 'Overstay +'.$overNights.'n';
            $stayProgress = 100;
        } else {
            $stayPhase = 'departed';
            $stayLine = 'Departed';
            $stayProgress = 100;
        }
    } else {
        $stayPhase = 'in-house';
        $dayNum = max(1, min($stayTotalNights, (int) $booking->checkin_date->diffInDays($today) + 1));
        $stayLine = 'Day '.$dayNum.' of '.$stayTotalNights;
        $stayProgress = (int) round(($dayNum / $stayTotalNights) * 100);
    }
@endphp

<div class="lp-dash"
     x-data="{ guestOpen: false }"
     x-init="
        // Deep-link scroll: /reservations/{ref}?charge=1 (or ?extend=1,
        // ?reassign=1, ?settle=1, ?checkout=1) sets $wire.scrollTarget
        // on the server; here we scroll the matching [data-scroll-anchor]
        // element into view + gently outline it so the operator's eye
        // lands on the right surface.
        $nextTick(() => {
            const target = $wire.get('scrollTarget');
            if (!target) return;
            const el = document.querySelector('[data-scroll-anchor=\'' + target + '\']');
            if (!el) return;
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            el.style.transition = 'box-shadow 300ms';
            el.style.boxShadow = '0 0 0 3px var(--color-accent-soft, rgba(140,200,140,0.35))';
            setTimeout(() => { el.style.boxShadow = ''; }, 1400);
            // Clear so a subsequent client-side navigation doesn't re-scroll.
            $wire.set('scrollTarget', '', false);
        });
     ">

    {{-- ── Back link ──────────────────────────────────────────── --}}
    @include('_partials._back-button')

    {{-- ── Hero: identity + money story ─────────────────────────
         Consolidates the old header + 4 KPI tiles + action bar
         into one editorial surface. Left side owns WHO the guest
         is + WHERE they are in the stay. Right side owns WHAT the
         money looks like right now. Actions live in a full-width
         strip underneath. --}}
    @php
        // Paid so far reads from the actual Payment ledger, net of
        // refunds. Sourcing this from `total_amount - balance_due` was
        // a lie for Pending bookings: `balance_due` on a walk-in that
        // hasn't paid yet is 0 (that field tracks deposit-held quote,
        // not cash received), which made the panel say Paid = total
        // and Outstanding = 0 when nothing had actually been collected.
        $succeededPaid = (int) $booking->payments()
            ->where('status', \App\Enums\PaymentStatus::Succeeded->value)
            ->sum('amount');
        $refundedPaid = (int) $booking->payments()
            ->where('status', \App\Enums\PaymentStatus::Refunded->value)
            ->sum('amount');
        $paidSoFar = max(0, $succeededPaid - $refundedPaid);

        // Live vs terminal states. Cancelled / NoShow / CheckedOut
        // aren't receivables the front desk can act on — the guest
        // isn't arriving (or has already left), so we don't inflate
        // the outstanding for the "stay never got paid" case: any
        // real refund exposure lives in the Refunds page instead.
        $isTerminal = in_array($booking->booking_status, [
            \App\Enums\BookingStatus::Cancelled,
            \App\Enums\BookingStatus::NoShow,
        ], true);

        // Actually unpaid portion of the stay. `outstandingTotal()`
        // sums balance_due + unsettled charges + overstay, so for a
        // Pending unpaid booking it reports 0 — misleading. Fall back
        // to the stay balance when payments haven't caught up to the
        // ledger yet, but only when the booking is still live.
        if (! $isTerminal) {
            $stayUnpaid = max(0, (int) $booking->total_amount - $paidSoFar);
            $outstandingTotal = max((int) $outstandingTotal, $stayUnpaid);
        }

        $stayPhaseColor = match ($stayPhase) {
            'overstay' => '#B85C42',
            'in-house' => 'var(--color-accent-deep)',
            'upcoming' => 'var(--color-ink-soft)',
            'departed' => 'var(--color-ink-mute)',
        };
    @endphp
    <section class="lp-res-hero" data-testid="reservation-hero">
        <div class="lp-res-hero-body">
            {{-- ── LEFT: guest identity + stay progress ─────────── --}}
            <div class="lp-res-hero-identity">
                <div class="flex items-start gap-4">
                    <span class="lp-avatar lp-avatar--lg @if ($isVip) lp-avatar--vip @endif">
                        {{ $booking->customer?->avatarInitials() ?? mb_strtoupper(mb_substr($booking->guest_name, 0, 1)) }}
                    </span>
                    <div style="min-width: 0; flex: 1;">
                        <div class="flex items-center flex-wrap gap-2">
                            <span class="lp-tag {{ $statusClass }}" data-testid="reservation-status">{{ $statusLabel }}</span>
                            @if ($isVip)
                                <span class="lp-tag" style="background: color-mix(in srgb, var(--color-accent-deep) 14%, transparent); color: var(--color-accent-deep); border: 0;">★ VIP</span>
                            @endif
                            <span class="lp-mono" style="font-size: 10.5px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--color-ink-mute);">
                                {{ $booking->booking_ref }}
                            </span>
                        </div>
                        <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                            style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                            <span data-testid="reservation-guest">{{ $booking->guest_name }}</span>
                        </h1>
                        <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft);">
                            {{ $booking->room?->name }}
                            <span style="color: var(--color-ink-mute);">·</span>
                            {{ $booking->checkin_date->toFormattedDateString() }}
                            <span style="color: var(--color-ink-mute);">→</span>
                            {{ $booking->checkout_date->toFormattedDateString() }}
                            <span style="color: var(--color-ink-mute);">·</span>
                            {{ $booking->nights }}n
                        </p>
                    </div>
                </div>

                {{-- Stay progress meter — replaces the buried nights
                     count with a human "Day 3 of 5" framing + a
                     visible progress track that flips amber on
                     overstay. --}}
                <div class="lp-stay-progress mt-4" data-testid="reservation-stay-progress">
                    <div class="flex items-baseline justify-between">
                        <span class="lp-mono" style="font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">
                            Stay
                        </span>
                        <span style="font-size: 13px; font-weight: 500; color: {{ $stayPhaseColor }};">
                            {{ $stayLine }}
                        </span>
                    </div>
                    <div class="lp-stay-progress-track">
                        <span class="lp-stay-progress-fill"
                              style="width: {{ $stayProgress }}%; background: {{ $stayPhaseColor }};"></span>
                    </div>
                </div>

                @if ($booking->corporate)
                    <div class="mt-3 inline-flex items-center gap-2"
                         data-testid="reservation-corp-tag"
                         style="padding: 6px 12px; border-radius: 999px;
                                background: var(--color-card-deep);
                                border: 1px solid var(--color-line-soft);">
                        <span class="lp-mono text-[10.5px]"
                              style="color: var(--color-accent-deep); letter-spacing: 0.08em; text-transform: uppercase;">
                            Corporate · {{ $booking->corporate->name }}
                        </span>
                        @if ($booking->corporateMember)
                            <span class="text-[12px]" style="color: var(--color-ink-soft);">
                                · {{ $booking->corporateMember->name }}
                            </span>
                        @endif
                        @if ((int) ($booking->corporate_discount_naira ?? 0) > 0)
                            <span class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                · saved {{ money($booking->corporate_discount_naira) }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>

            {{-- ── RIGHT: money summary ─────────────────────────── --}}
            <div class="lp-res-hero-money" data-testid="reservation-money">
                <div class="lp-res-hero-money-head">
                    <span class="lp-mono" style="font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">
                        @if ($outstandingTotal > 0) Outstanding @else Balance @endif
                    </span>
                </div>
                <p class="lp-res-hero-money-total"
                   data-testid="reservation-outstanding"
                   style="color: {{ $outstandingTotal > 0 ? '#8c1e1e' : 'var(--color-ink)' }};">
                    {{ money($outstandingTotal) }}
                </p>
                @if ($outstandingTotal > 0)
                    <p class="lp-res-hero-money-sub" style="color: #a36318;">
                        @if ($booking->booking_status === \App\Enums\BookingStatus::Cancelled)
                            Left on the ledger from cancellation
                        @elseif ($booking->booking_status === \App\Enums\BookingStatus::NoShow)
                            Left on the ledger from no-show
                        @else
                            Must clear before checkout
                        @endif
                    </p>
                @else
                    <p class="lp-res-hero-money-sub" style="color: var(--color-accent-deep);">
                        Nothing owed
                    </p>
                @endif

                <dl class="lp-res-hero-breakdown">
                    <div>
                        <dt>Stay total</dt>
                        <dd>{{ money($booking->total_amount) }}</dd>
                    </div>
                    <div>
                        <dt>Paid</dt>
                        <dd style="color: var(--color-accent-deep);">{{ money($paidSoFar) }}</dd>
                    </div>
                    <div>
                        <dt>Open charges</dt>
                        <dd style="color: {{ $unsettledTotal > 0 ? '#a36318' : 'var(--color-ink)' }};">
                            {{ money($unsettledTotal) }}
                        </dd>
                    </div>
                </dl>

                @if ((int) $booking->manual_discount_amount > 0 || ($overstayNights ?? 0) > 0)
                    <div class="lp-res-hero-pills">
                        @if ((int) $booking->manual_discount_amount > 0)
                            <span class="lp-res-hero-pill lp-res-hero-pill--credit" data-testid="hero-discount-pill">
                                Discount −{{ money($booking->manual_discount_amount) }}
                            </span>
                        @endif
                        @if (($overstayNights ?? 0) > 0)
                            <span class="lp-res-hero-pill lp-res-hero-pill--warn" data-testid="hero-overstay-pill">
                                Overstay +{{ $overstayNights }}n · {{ money($overstayAmount) }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- Manual discount is now a hero pill. Detailed "authorised by"
         attribution surfaces in the timeline event instead. --}}

    {{-- ── Overstay notice ──────────────────────────────────────────
         Three states the operator needs to distinguish:

         (a) fully uncharged — nothing on the ledger yet, MarkCheckedOut
             will add the full amount when they press Check out;
         (b) partially charged — the nightly auto-cron billed some but
             the guest is still accruing; the delta will be topped up
             at checkout (or on the next cron run);
         (c) fully charged — everything is on the ledger, just needs
             settling before checkout can complete.

         The banner used to conflate (b) and (c) into a single "already
         charged" hint that lied about the ledger line count. Hero
         pill carries the top-line signal; this section keeps the
         verbose "what happens on checkout" copy for the accruing +
         uncharged states. --}}
    @if ($overstayNights > 0)
        @php
            $overstayRemaining = max(0, $overstayNights - $overstayCharged);
            $overstayVariant = $overstayCharged === 0
                ? 'pending'
                : ($overstayRemaining > 0 ? 'accruing' : 'charged');
        @endphp
        {{-- Compact callout — the top-line signal already lives in
             the hero pill; this strip owns the "what happens on
             checkout" copy for pending / accruing states, and a
             quiet "on the ledger" note for the fully-charged one. --}}
        <div class="lp-overstay-callout lp-overstay-callout--{{ $overstayVariant }}"
             data-testid="overstay-notice"
             data-overstay-variant="{{ $overstayVariant }}">
            <svg class="lp-overstay-icon" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><circle cx="8" cy="8" r="6.5"/><path d="M8 4.5V8l2.5 1.5"/></svg>
            <div class="lp-overstay-body">
                @if ($overstayVariant === 'pending')
                    <span class="lp-overstay-lead">
                        <strong>{{ money($overstayAmount) }}</strong> overstay pending, {{ $overstayNights }} night{{ $overstayNights === 1 ? '' : 's' }} past checkout.
                    </span>
                    <span class="lp-overstay-note">
                        Added to the ledger the moment you press <strong style="color: var(--color-ink);">Check out</strong>.
                    </span>
                @elseif ($overstayVariant === 'accruing')
                    <span class="lp-overstay-lead">
                        <strong>{{ money($overstayAmount) }}</strong> overstay still to bill, {{ $overstayCharged }} of {{ $overstayNights }} night{{ $overstayNights === 1 ? '' : 's' }} already on the ledger.
                    </span>
                    <span class="lp-overstay-note">
                        The remaining {{ $overstayRemaining }} bills automatically on <strong style="color: var(--color-ink);">Check out</strong>.
                    </span>
                @else
                    <span class="lp-overstay-lead">
                        All {{ $overstayNights }} night{{ $overstayNights === 1 ? '' : 's' }} of overstay on the ledger below. Settle to complete checkout.
                    </span>
                @endif
            </div>
        </div>
    @endif

    {{-- ── Primary action bar ─────────────────────────────────────
         Surfaces the frequently-used front-desk actions in a wide
         horizontal strip so the operator's eye lands on them before
         the ledger table below competes for attention. Duplicates
         a small set of the sidebar buttons (Check in/out primary,
         Extend, Change room, Issue invoice) — the sidebar hides
         them at desktop widths via .lp-desk-actions-collapsed to
         avoid two copies of the same CTA on the page. --}}
    @php
        $showCheckIn = $canCheckIn ?? false;
        $showCheckOut = $canCheckOut ?? false;
        $checkoutBlockedByOutstanding = $showCheckOut
            && $outstandingTotal > 0
            && ! $booking->isCorporatePreAuthorized();
        $corpWillInvoiceStrip = $showCheckOut
            && $outstandingTotal > 0
            && $booking->isCorporatePreAuthorized();
        $primaryVerb = $showCheckOut ? 'Check out' : ($showCheckIn ? 'Check in' : null);
    @endphp
    <section class="lp-res-actionbar" data-testid="reservation-actionbar">
        <div class="lp-res-actionbar-toolbar">
            {{-- Primary CTA: whichever verb the booking's state
                 supports (check-in / check-out). Filled pill so it
                 owns the row visually. --}}
            @if ($primaryVerb === 'Check in')
                <button type="button" wire:click="checkIn"
                        wire:confirm="Check in {{ $booking->guest_name }} now?"
                        data-testid="actionbar-checkin"
                        class="lp-res-action lp-res-action--primary">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M9 3l4 5-4 5M3 8h10"/></svg>
                    Check in
                </button>
            @elseif ($primaryVerb === 'Check out')
                <button type="button" wire:click="checkOut"
                        data-scroll-anchor="checkout"
                        wire:confirm="{{ $corpWillInvoiceStrip
                            ? 'Check out '.$booking->guest_name.'? '.money($outstandingTotal).' will bill to '.($booking->corporate?->name ?? 'the corporate').' by invoice.'
                            : 'Check out '.$booking->guest_name.'? Outstanding balance must be '.money(0).'.' }}"
                        @disabled($checkoutBlockedByOutstanding)
                        data-testid="actionbar-checkout"
                        class="lp-res-action lp-res-action--primary">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M7 3l-4 5 4 5M13 8H3"/></svg>
                    Check out
                </button>
            @endif

            @if ($outstandingTotal > 0 && ! $isTerminal)
                <button type="button"
                        x-on:click="document.querySelector('[data-scroll-anchor=&quot;settle&quot;]')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                        data-testid="actionbar-take-payment"
                        class="lp-res-action lp-res-action--accent">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2 5.5h12v6H2zM5.5 8h5"/></svg>
                    Take payment
                </button>
            @endif

            @if ($canExtend)
                <button type="button"
                        wire:click="openExtendModal({{ $booking->id }})"
                        data-testid="actionbar-extend"
                        class="lp-res-action">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                    Extend stay
                </button>
            @endif

            @if ($canReassign && $booking->room_id !== null && $reassignCandidates->isNotEmpty())
                <button type="button"
                        wire:click="toggleReassign"
                        wire:loading.attr="disabled"
                        wire:target="toggleReassign"
                        x-on:click="$nextTick(() => document.querySelector('[data-scroll-anchor=&quot;reassign&quot;]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))"
                        data-testid="actionbar-change-room"
                        class="lp-res-action">
                    <svg wire:loading.remove wire:target="toggleReassign"
                         viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4 5h10l-2-2M12 11H2l2 2"/></svg>
                    <svg wire:loading wire:target="toggleReassign"
                         viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden
                         style="animation: kv-spin 720ms linear infinite;"><path d="M8 2a6 6 0 016 6"/></svg>
                    <span wire:loading.remove wire:target="toggleReassign">{{ $showReassign ? 'Cancel change' : 'Change room' }}</span>
                    <span wire:loading wire:target="toggleReassign">Loading rooms…</span>
                </button>
            @endif

            @if (in_array($booking->booking_status, [
                    \App\Enums\BookingStatus::CheckedIn,
                    \App\Enums\BookingStatus::Confirmed,
                    \App\Enums\BookingStatus::CheckedOut,
                    \App\Enums\BookingStatus::OnHold,
                ], true))
                <a href="{{ route('tenant.invoices.create', $booking->booking_ref) }}"
                   wire:navigate
                   data-testid="actionbar-issue-invoice"
                   class="lp-res-action">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4 2h6l3 3v9H4zM10 2v3h3M6 8h4M6 10.5h4"/></svg>
                    Issue invoice
                </a>
            @endif

            <button type="button"
                    x-on:click="guestOpen = true"
                    data-testid="actionbar-guest"
                    class="lp-res-action lp-res-action--ghost">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><circle cx="8" cy="6" r="2.5"/><path d="M3.5 13c.9-2.2 2.6-3.5 4.5-3.5S11.6 10.8 12.5 13"/></svg>
                Guest
            </button>
        </div>
        @if ($checkoutBlockedByOutstanding)
            <p class="lp-res-actionbar-hint" style="color: #A8650A;" data-testid="actionbar-blocked-hint">
                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden style="display: inline-block; vertical-align: -2px; margin-right: 4px;"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5M8 11h.01"/></svg>
                Check out is blocked. Clear the outstanding {{ money($outstandingTotal) }} first — press <strong style="color: var(--color-ink);">Take payment</strong>.
            </p>
        @elseif ($corpWillInvoiceStrip)
            <p class="lp-res-actionbar-hint" data-testid="actionbar-corp-hint">
                Outstanding {{ money($outstandingTotal) }} will bill to
                <strong style="color: var(--color-ink);">{{ $booking->corporate?->name ?? 'the corp' }}</strong>
                on the next invoice cycle.
            </p>
        @endif
    </section>

    {{-- ── Two-col body ───────────────────────────────────────────── --}}
    {{-- 3:1 split at lg+ (was 2:1) so the charges ledger doesn't get
         its Description column crushed to ~60-80px on 13" laptops —
         the part-paid trail we stamp into descriptions could wrap
         into 6-8 vertical lines before this. Front-desk aside stays
         legible at 25%. Class is defined in app.css to guarantee the
         rule ships in the build (Tailwind JIT can miss arbitrary
         values with commas + parens). --}}
    <div class="lp-res-body">

        {{-- LEFT: charges, extension --}}
        <div style="min-width: 0; display: grid; gap: 18px;">

            {{-- Charges ledger --}}
            @php
                $pendingOverstayNights = max(0, $overstayNights - $overstayCharged);
                $pendingOverstayAmount = (int) $overstayAmount;
                $hasPendingOverstay = $pendingOverstayNights > 0 && $pendingOverstayAmount > 0;
                $ledgerEntryCount = $charges->count() + ($hasPendingOverstay ? 1 : 0);
            @endphp
            <section class="lp-dash-card" data-testid="charges-ledger"
                     data-scroll-anchor="settle">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Charges &amp; outstanding</span>
                    <span class="lp-dash-card-meta">{{ $ledgerEntryCount }} entr{{ $ledgerEntryCount === 1 ? 'y' : 'ies' }}</span>
                </div>

                @if ($charges->isEmpty() && ! $hasPendingOverstay)
                    @include('livewire.dashboard._partials._empty', [
                        'icon'      => 'wallet',
                        'title'     => $isTerminal
                                        ? 'No extra charges on record.'
                                        : 'No extra charges on this stay yet.',
                        'sub'       => $isTerminal
                                        ? 'Once a booking is cancelled or marked no-show, new charges can\'t be added.'
                                        : 'Room-service, food & beverage, damage, and other lines appear here as you add them.',
                        'ctaLabel'  => $isTerminal ? null : 'Add a charge',
                        'ctaClickJs'=> $isTerminal ? null : "document.querySelector('[data-scroll-anchor=\"add-charge\"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' })",
                    ])
                @else
                    {{-- Card-stack ledger. Each entry is a self-contained
                         row: leading marker (dot / icon), description +
                         attribution + relative time on the left, amount
                         + state pill + inline action on the right.
                         Reads faster than a 5-column table on mid-width
                         layouts and gives the pending / cancelled cases
                         real visual weight. --}}
                    <ul class="lp-ledger" data-testid="charges-ledger-list">
                        @if ($hasPendingOverstay)
                            <li class="lp-ledger-item lp-ledger-item--pending"
                                data-testid="charge-row-pending-overstay">
                                <span class="lp-ledger-marker lp-ledger-marker--warn" aria-hidden></span>
                                <div class="lp-ledger-body">
                                    <p class="lp-ledger-desc">
                                        {{ $pendingOverstayNights }} night{{ $pendingOverstayNights === 1 ? '' : 's' }} overstay
                                    </p>
                                    <p class="lp-ledger-meta">
                                        Auto-bills on the next cron run or when you press <strong style="color: var(--color-ink);">Check out</strong>.
                                    </p>
                                </div>
                                <div class="lp-ledger-right">
                                    <span class="lp-ledger-amount" style="color: #A8650A;">
                                        {{ money($pendingOverstayAmount) }}
                                    </span>
                                    <span class="lp-tag lp-tag--pending" style="background: #FEF3C7; color: #78350F;">
                                        Pending
                                    </span>
                                </div>
                            </li>
                        @endif
                        @foreach ($charges as $charge)
                            @php
                                $isExtension = $charge->type === \App\Enums\BookingChargeType::Extension;
                                $markerClass = $charge->settled
                                    ? 'lp-ledger-marker--paid'
                                    : ($isExtension ? 'lp-ledger-marker--info' : 'lp-ledger-marker--warn');
                            @endphp
                            <li class="lp-ledger-item @if (! $charge->settled) lp-ledger-item--open @endif"
                                data-testid="charge-row-{{ $charge->id }}">
                                <span class="lp-ledger-marker {{ $markerClass }}" aria-hidden></span>
                                <div class="lp-ledger-body">
                                    <p class="lp-ledger-desc">{{ $charge->description }}</p>
                                    <p class="lp-ledger-meta">
                                        {{ $charge->created_at?->diffForHumans() }}
                                        @if ($charge->operator)
                                            <span style="color: var(--color-ink-mute);">·</span>
                                            by {{ $charge->operator->name }}
                                        @endif
                                        @if ($charge->settled && $charge->settled_method)
                                            <span style="color: var(--color-ink-mute);">·</span>
                                            paid via {{ ucfirst(str_replace('_', ' ', $charge->settled_method)) }}
                                        @endif
                                    </p>
                                </div>
                                <div class="lp-ledger-right">
                                    <span class="lp-ledger-amount">{{ money($charge->amount) }}</span>
                                    @if ($charge->settled)
                                        <span class="lp-tag lp-tag--confirmed">Paid</span>
                                    @else
                                        <div class="lp-ledger-actions">
                                            @if ($isExtension)
                                                <button type="button"
                                                        wire:click="cancelExtension({{ $charge->id }})"
                                                        wire:confirm="Cancel this extension? Nights will be released and the charge voided."
                                                        data-testid="cancel-extension-{{ $charge->id }}"
                                                        class="lp-ledger-action lp-ledger-action--danger">
                                                    Cancel extension
                                                </button>
                                            @endif
                                            <button type="button"
                                                    wire:click="settleOne({{ $charge->id }})"
                                                    data-testid="settle-charge-{{ $charge->id }}"
                                                    class="lp-ledger-action">
                                                Mark paid
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @php
                        // Outstanding now includes projected overstay so
                        // the settle-everything button reflects the true
                        // total the operator would collect (settleAll
                        // materialises the pending overstay first).
                        $settlePayable = (int) $outstandingTotal;
                    @endphp
                    @if ($settlePayable > 0)
                        @php
                            // Preview how "Settle everything" would split: wallet
                            // gets drained first (capped at outstanding), fallback
                            // method covers the rest. Mirrors SettleChargesWalletFirst
                            // so the operator sees the same numbers before + after.
                            $walletApplies    = $settleMethod !== 'wallet';
                            $walletContribution = $walletApplies ? min((int) $walletBalance, $settlePayable) : 0;
                            $fallbackShortfall  = max(0, $settlePayable - $walletContribution);
                            $methodChips = [
                                ['cash', 'Cash', '<path d="M2 5h12v6H2zM8 5v6M4 8h.01M12 8h.01"/>'],
                                ['transfer', 'Transfer', '<path d="M3 4l4-2 4 2M3 12l4 2 4-2M7 2v12"/>'],
                                ['card', 'Card', '<path d="M2 5h12v6H2zM2 8h12M4 10h2"/>'],
                                ['paystack', 'Paystack', '<path d="M4 3l8 5-8 5z"/>'],
                                ['other', 'Other', '<circle cx="8" cy="8" r="6"/><path d="M8 5v3.5M8 11h.01"/>'],
                            ];
                        @endphp
                        <div class="lp-settle-strip">
                            <div class="lp-settle-strip-head">
                                <span class="lp-mono" style="font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">
                                    Settle outstanding
                                </span>
                                <span class="lp-settle-strip-total" data-testid="settle-outstanding">
                                    {{ money($settlePayable) }}
                                </span>
                            </div>

                            {{-- Method chips + optional wallet chip. One-tap
                                 method selection replaces the old dropdown. --}}
                            <div class="lp-settle-methods" role="radiogroup" aria-label="Payment method">
                                @foreach ($methodChips as [$key, $label, $iconSvg])
                                    <button type="button"
                                            wire:click="$set('settleMethod', '{{ $key }}')"
                                            data-testid="settle-method-chip-{{ $key }}"
                                            class="lp-settle-method @if ($settleMethod === $key) is-active @endif"
                                            role="radio"
                                            aria-checked="{{ $settleMethod === $key ? 'true' : 'false' }}">
                                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden>{!! $iconSvg !!}</svg>
                                        <span>{{ $label }}</span>
                                    </button>
                                @endforeach
                                @if ($walletBalance > 0)
                                    <button type="button"
                                            wire:click="$set('settleMethod', 'wallet')"
                                            data-testid="settle-method-chip-wallet"
                                            class="lp-settle-method @if ($settleMethod === 'wallet') is-active @endif">
                                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2 5h12v6H2zM11 8h1"/></svg>
                                        <span>Wallet · {{ money($walletBalance) }}</span>
                                    </button>
                                @endif
                            </div>

                            @if ($booking->customer && $walletBalance === 0)
                                <p class="lp-settle-hint">
                                    Guest wallet is empty. Top up on the customer page to use it here.
                                </p>
                            @elseif ($walletApplies && $walletContribution > 0 && $fallbackShortfall > 0)
                                <p class="lp-settle-hint" data-testid="settle-mixed-hint" style="color: #4A5A2E;">
                                    Draws <strong>{{ money($walletContribution) }}</strong> from wallet, then collects
                                    <strong>{{ money($fallbackShortfall) }}</strong> as {{ $settleMethod }}.
                                </p>
                            @elseif ($walletApplies && $walletContribution > 0 && $fallbackShortfall === 0)
                                <p class="lp-settle-hint" data-testid="settle-mixed-hint" style="color: #4A5A2E;">
                                    Wallet covers everything. Nothing to collect at the counter.
                                </p>
                            @elseif ($settleMethod === 'wallet' && $walletBalance < $settlePayable)
                                <p class="lp-settle-hint" style="color: #A8650A;">
                                    Wallet-only covers <strong>{{ money($walletBalance) }}</strong> of {{ money($settlePayable) }}.
                                    Switch to cash / transfer to auto-collect the rest.
                                </p>
                            @endif

                            <div class="lp-settle-cta-row">
                                <button type="button" wire:click="togglePartPay"
                                        data-testid="toggle-part-pay"
                                        class="lp-res-action">
                                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2 8h8M6 4l4 4-4 4"/></svg>
                                    {{ $partPayOpen ? 'Cancel part payment' : 'Take part payment' }}
                                </button>
                                <button type="button" wire:click="settleAll"
                                        data-testid="settle-all"
                                        class="lp-res-action lp-res-action--primary">
                                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2.5 7l3.2 3.2L13.5 3"/></svg>
                                    <span wire:loading.remove wire:target="settleAll">Settle everything · {{ money($settlePayable) }}</span>
                                    <span wire:loading wire:target="settleAll">Settling…</span>
                                </button>
                            </div>
                        </div>

                        {{-- Part-payment inline form. Appears below
                             the settle chip when the operator toggles
                             it on. Alpine wraps the amount input so
                             we can format the display value with
                             thousands separators as the operator types
                             + surface a live "remaining after this
                             payment" hint. wire:model.live syncs the
                             numeric raw value on every change so the
                             Take payment button's server-side disabled
                             check flips immediately. --}}
                        @if ($partPayOpen)
                            {{-- Part-payment expandable — inline slice-out
                                 that keeps the operator inside the settle
                                 strip's rhythm. Amount → live "remaining
                                 after this" preview with quick-fill chips
                                 for common splits (½, ⅔, all-but-1000).
                                 Method chips reuse the settle strip's
                                 vocabulary. --}}
                            <div class="lp-partpay" data-testid="part-pay-panel"
                                 x-data="{
                                    raw: @entangle('partPayAmount').live,
                                    outstanding: {{ (int) $settlePayable }},
                                    get remaining() {
                                        return Math.max(0, this.outstanding - (Number(this.raw) || 0));
                                    },
                                    get amountValid() {
                                        const n = Number(this.raw) || 0;
                                        return n > 0 && n <= this.outstanding;
                                    },
                                    format(n) {
                                        return n ? Number(n).toLocaleString('en-NG') : '';
                                    },
                                    onAmountInput(e) {
                                        const digits = String(e.target.value).replace(/[^0-9]/g, '');
                                        this.raw = digits === '' ? null : Number(digits);
                                        e.target.value = this.format(this.raw);
                                    },
                                    quickFill(amount) {
                                        this.raw = amount;
                                    },
                                 }">
                                <div class="lp-partpay-caption">
                                    <span class="lp-mono" style="font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-ink-mute);">
                                        Part payment
                                    </span>
                                    <span style="font-size: 12.5px; color: var(--color-ink-soft);">
                                        Record what the guest hands over now, the remainder rides on the folio.
                                    </span>
                                </div>

                                {{-- Amount field with currency prefix + quick-fill chips
                                     for half, two-thirds, and all-but-1000 splits. --}}
                                <div class="lp-partpay-row">
                                    <div class="lp-partpay-amount">
                                        <div class="lp-partpay-input">
                                            <span class="lp-partpay-currency">{{ currency_symbol() }}</span>
                                            <input type="text" inputmode="numeric"
                                                   :value="format(raw)"
                                                   @input.debounce.150ms="onAmountInput($event)"
                                                   data-testid="part-pay-amount"
                                                   placeholder="e.g. {{ number_format((int) floor($settlePayable / 2)) }}">
                                        </div>
                                        <div class="lp-partpay-quicks">
                                            @php
                                                $halfAmount = (int) floor($settlePayable / 2);
                                                $twoThirdAmount = (int) floor($settlePayable * 2 / 3);
                                                $leaveThousand = max(0, $settlePayable - 1000);
                                            @endphp
                                            <button type="button"
                                                    @click="quickFill({{ $halfAmount }})"
                                                    class="lp-partpay-quick"
                                                    data-testid="part-pay-quick-half">
                                                Half · {{ money($halfAmount) }}
                                            </button>
                                            <button type="button"
                                                    @click="quickFill({{ $twoThirdAmount }})"
                                                    class="lp-partpay-quick"
                                                    data-testid="part-pay-quick-two-thirds">
                                                Two-thirds
                                            </button>
                                            @if ($leaveThousand > 0 && $leaveThousand < $settlePayable)
                                                <button type="button"
                                                        @click="quickFill({{ $leaveThousand }})"
                                                        class="lp-partpay-quick"
                                                        data-testid="part-pay-quick-leave-1k">
                                                    Leave {{ money(1000) }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="lp-partpay-method-col">
                                        <span class="lp-mono lp-partpay-method-label">Method</span>
                                        @php
                                            $partMethods = [
                                                ['cash', 'Cash'],
                                                ['transfer', 'Transfer'],
                                                ['card', 'Card'],
                                                ['pos', 'POS'],
                                                ['other', 'Other'],
                                            ];
                                        @endphp
                                        <div class="lp-settle-methods">
                                            @foreach ($partMethods as [$key, $label])
                                                <button type="button"
                                                        wire:click="$set('partPayMethod', '{{ $key }}')"
                                                        data-testid="part-pay-method-chip-{{ $key }}"
                                                        class="lp-settle-method @if ($partPayMethod === $key) is-active @endif">
                                                    <span>{{ $label }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                                {{-- Live remaining-after-payment hint. Colour flips
                                     red when the operator types more than the
                                     outstanding balance. --}}
                                <p class="lp-partpay-hint" data-testid="part-pay-remaining"
                                   x-show="raw !== null && Number(raw) > 0"
                                   x-cloak
                                   :style="'color: ' + (amountValid ? 'var(--color-ink-soft)' : '#B85C42') + ';'">
                                    <template x-if="amountValid">
                                        <span>
                                            After this: <strong x-text="@js(currency_symbol()) + format(remaining)"
                                                    style="color: var(--color-ink); font-weight: 600;"></strong>
                                            stays on the folio.
                                        </span>
                                    </template>
                                    <template x-if="! amountValid">
                                        <span>
                                            Amount exceeds the <strong x-text="@js(currency_symbol()) + format(outstanding)"></strong> outstanding —
                                            use Settle everything instead.
                                        </span>
                                    </template>
                                </p>

                                <div class="lp-partpay-actions">
                                    <button type="button" wire:click="togglePartPay"
                                            data-testid="part-pay-cancel"
                                            class="lp-res-action">
                                        Cancel
                                    </button>
                                    <button type="button"
                                            @click="await $wire.set('partPayAmount', Number(raw) || 0, true); $wire.call('takePartPayment')"
                                            data-testid="part-pay-submit"
                                            :disabled="! amountValid"
                                            :class="! amountValid ? 'lp-res-action' : 'lp-res-action lp-res-action--primary'">
                                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M2 5.5h12v6H2zM5.5 8h5"/></svg>
                                        <span wire:loading.remove wire:target="takePartPayment">Take payment</span>
                                        <span wire:loading wire:target="takePartPayment">Recording…</span>
                                    </button>
                                </div>
                            </div>
                        @endif
                    @endif
                @endif
            </section>

            {{-- Add a charge — chip-picker for type + compact inline
                 entry. Each type carries its own icon + suggested
                 description placeholder so the operator can start
                 typing straight into a pre-typed context instead of
                 filling three separate fields. --}}
            @php
                $chargeTypeMeta = [
                    'food_beverage' => [
                        'icon'   => '<path d="M5 3v10M3 3v3a2 2 0 002 2 2 2 0 002-2V3M11 3c-1.1 0-2 2-2 4s.9 2 2 2v4"/>',
                        'hint'   => '2× dinner + bottle of red',
                    ],
                    'room_service'  => [
                        'icon'   => '<path d="M2 8h12M8 8V3M4 12h8"/>',
                        'hint'   => 'Late-night club sandwich + mineral water',
                    ],
                    'laundry'       => [
                        'icon'   => '<circle cx="8" cy="9" r="3"/><path d="M4 3h8v10H4zM6 5h.01M9 5h.01"/>',
                        'hint'   => '4 shirts + 2 trousers, express',
                    ],
                    'transport'     => [
                        'icon'   => '<path d="M2 10h12M4 10V6h8v4M5 12v1M11 12v1"/>',
                        'hint'   => 'Airport drop-off, sedan',
                    ],
                    'damage'        => [
                        'icon'   => '<path d="M8 2l5.5 12h-11z"/><path d="M8 6v4M8 12h.01"/>',
                        'hint'   => 'Broken tumbler, replacement fee',
                    ],
                    'other'         => [
                        'icon'   => '<circle cx="8" cy="8" r="6"/><path d="M8 5.5v2.5M8 10.5h.01"/>',
                        'hint'   => 'Describe the charge',
                    ],
                ];
            @endphp
            @if (! $isTerminal)
            <section class="lp-dash-card lp-add-charge" data-testid="add-charge-form"
                     data-scroll-anchor="add-charge">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Add a charge</span>
                    <span class="lp-dash-card-meta">Tap a category, describe, price</span>
                </div>

                <form wire:submit="addCharge" class="lp-add-charge-form">
                    <div class="lp-add-charge-chips" role="radiogroup" aria-label="Charge type">
                        @foreach ($chargeTypeOptions as $opt)
                            @php
                                $meta = $chargeTypeMeta[$opt->value] ?? $chargeTypeMeta['other'];
                            @endphp
                            <button type="button"
                                    wire:click="$set('newChargeType', '{{ $opt->value }}')"
                                    data-testid="charge-type-chip-{{ $opt->value }}"
                                    class="lp-add-charge-chip @if ($newChargeType === $opt->value) is-active @endif"
                                    role="radio"
                                    aria-checked="{{ $newChargeType === $opt->value ? 'true' : 'false' }}">
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden>
                                    {!! $meta['icon'] !!}
                                </svg>
                                <span>{{ $opt->label() }}</span>
                            </button>
                        @endforeach
                    </div>

                    @php
                        $activeHint = $chargeTypeMeta[$newChargeType] ?? $chargeTypeMeta['other'];
                    @endphp
                    <div class="lp-add-charge-row">
                        <div class="lp-add-charge-desc">
                            <label for="charge-desc" class="lp-form-label">Description</label>
                            <input id="charge-desc" type="text" wire:model="newChargeDescription"
                                   maxlength="240" data-testid="charge-desc" class="lp-input"
                                   placeholder="{{ $activeHint['hint'] }}">
                            @error('newChargeDescription') <p class="lp-form-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="lp-add-charge-amount">
                            <label for="charge-amount" class="lp-form-label">Amount ({{ currency_symbol() }})</label>
                            <input id="charge-amount" type="number" wire:model="newChargeAmount"
                                   min="1" data-testid="charge-amount" class="lp-input"
                                   placeholder="15000">
                            @error('newChargeAmount') <p class="lp-form-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="lp-add-charge-submit">
                            <button type="submit" data-testid="charge-submit" class="lp-res-action lp-res-action--primary">
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden><path d="M8 3v10M3 8h10"/></svg>
                                <span wire:loading.remove wire:target="addCharge">Add charge</span>
                                <span wire:loading wire:target="addCharge">Adding…</span>
                            </button>
                        </div>
                    </div>
                </form>
            </section>
            @endif

            {{-- Legacy inline extend form retired — the sidebar
                 "Extend stay" button now opens the shared modal
                 (extend-stay-modal.blade.php, included below).
                 That flow previews the new rate, accepts an
                 extension discount, and can collect payment inline. --}}
        </div>

        {{-- RIGHT: front-desk contextual panels sidebar.
             All primary CTAs (Check in/out, Extend, Change room, Issue
             invoice, Guest details) live in the top action strip, so
             this aside only carries situational panels: reassign, post-
             reassign reconcile, unassigned-category picker, corporate
             pre-auth check-in, confirm-and-pay stack, request-approval,
             plus the mobile-only mirror of the top strip's buttons.
             When none of those apply on desktop, the aside collapses
             out of layout via .lp-res-aside--empty. --}}
        @php
            $sidebarHasContent = $showReassign
                || $pendingReconcileDelta !== null
                || $issuedCouponCode !== null
                || ($booking->room_id === null && $booking->room_category_id !== null)
                || $canCorporateCheckIn
                || $pendingCheckinApproval !== null
                || ($booking->booking_status === \App\Enums\BookingStatus::Pending
                    && ! $canCorporateCheckIn
                    && ! $pendingCheckinApproval)
                || $canRequestCheckinApproval;
        @endphp
        <aside class="lp-res-aside {{ $sidebarHasContent ? '' : 'lp-res-aside--empty' }}">

            <section class="lp-dash-card" data-testid="desk-actions">
                <div class="lp-dash-card-head">
                    <span class="lp-dash-card-title">Front desk</span>
                </div>

                <div style="display: grid; gap: 10px;">

                    {{-- Assigned-room wrapper. The static room label + inline
                         "Change" affordance used to live here, but the top
                         action strip surfaces both, so this wrapper now
                         only hosts the reassign / reconcile / coupon
                         panels which appear on demand. Keeps the scroll
                         anchor stable for the top strip's "Change room"
                         button. --}}
                    <div data-testid="assigned-room" style="display: grid; gap: 6px;">

                        @if ($showReassign && $reassignCandidates->isNotEmpty() && $pendingReconcileDelta === null && $issuedCouponCode === null)
                            <div style="display: grid; gap: 8px; margin-top: 6px;" data-testid="reassign-panel"
                                 data-scroll-anchor="reassign">
                                <div style="position: relative;">
                                <select wire:model.live="reassignTargetRoomId"
                                        wire:loading.attr="disabled"
                                        wire:target="reassignTargetRoomId"
                                        data-testid="reassign-target"
                                        class="lp-input" style="font-size: 13px; padding: 8px 10px;">
                                    <option value="">- pick a room -</option>
                                    @foreach ($reassignCandidates->groupBy(fn ($r) => $r->category?->name ?? 'Uncategorised') as $categoryName => $rooms)
                                        <optgroup label="{{ $categoryName }} · {{ money($rooms->first()->base_rate) }}/night">
                                            @foreach ($rooms as $candidate)
                                                <option value="{{ $candidate->id }}">
                                                    Room {{ $candidate->room_number ?? $candidate->id }}
                                                    @if ($candidate->internal_notes)
                                                        · {{ \Illuminate\Support\Str::limit($candidate->internal_notes, 40) }}
                                                    @endif
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                <span wire:loading wire:target="reassignTargetRoomId"
                                      aria-hidden="true"
                                      style="position: absolute; right: 30px; top: 50%; transform: translateY(-50%);
                                             width: 12px; height: 12px;
                                             border: 2px solid rgba(20, 22, 24, 0.18);
                                             border-top-color: var(--color-ink);
                                             border-radius: 999px;
                                             animation: kv-spin 720ms linear infinite;
                                             pointer-events: none;"></span>
                                </div>

                                {{-- Preview the rate delta as soon as the operator picks a room,
                                     so there are no surprises at confirm time. --}}
                                @if ($previewCandidate !== null)
                                    <div data-testid="reassign-delta-preview"
                                         style="padding: 8px 10px; border-radius: 6px;
                                                background: {{ $previewDelta === 0 ? 'var(--color-card-deep)' : ($previewDelta > 0 ? 'rgba(184, 92, 66, 0.10)' : 'rgba(60, 120, 90, 0.10)') }};
                                                font-size: 12px; color: var(--color-ink);">
                                        <div class="lp-mono" style="font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-ink-mute);">
                                            Rate delta · remaining nights
                                        </div>
                                        <div style="margin-top: 4px;">
                                            @if ($previewDelta === 0)
                                                Same rate. No reconciliation needed.
                                            @elseif ($previewDelta > 0)
                                                <strong>+{{ money($previewDelta) }}</strong> · guest owes more for remaining nights.
                                                Reconciliation will open after the swap.
                                            @else
                                                <strong>−{{ money(abs($previewDelta)) }}</strong> · guest is due a refund or coupon.
                                                Reconciliation will open after the swap.
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                <button type="button" wire:click="reassignRoom"
                                        wire:confirm="Move this reservation to the selected room?"
                                        data-testid="reassign-confirm"
                                        class="lp-btn lp-btn--block"
                                        style="font-size: 12.5px;">
                                    <span wire:loading.remove wire:target="reassignRoom">Confirm reassignment</span>
                                    <span wire:loading wire:target="reassignRoom">Reassigning…</span>
                                </button>
                            </div>
                        @endif

                        {{-- ── Reconciliation panel ─────────────────────────────
                             Appears after a cross-rate reassignment. Sign of
                             $pendingReconcileDelta drives which actions are
                             offered: positive → collect only; negative →
                             refund / issue coupon. --}}
                        @if ($pendingReconcileDelta !== null)
                            <div data-testid="reconcile-panel"
                                 style="margin-top: 8px; padding: 14px; border-radius: 8px;
                                        background: var(--color-card-deep); display: grid; gap: 10px;">
                                <div class="lp-mono" style="font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-ink-mute);">
                                    Reconcile · {{ $pendingReconcileDelta > 0 ? 'guest owes' : 'due to guest' }}
                                </div>
                                <div class="lp-num-lg" style="font-size: 22px; color: var(--color-ink);">
                                    {{ money(abs($pendingReconcileDelta)) }}
                                </div>

                                {{-- Breakdown: shows the operator exactly how
                                     the suggested number was computed. The
                                     "use full-nights" affordance below lets
                                     them retroactively credit past nights
                                     when the reassign is fixing an
                                     assignment mistake rather than a normal
                                     mid-stay downgrade. --}}
                                @if ($reconcileOldRate > 0 && $reconcileNewRate > 0 && $reconcileBookedNights > 0)
                                    <div data-testid="reconcile-breakdown"
                                         style="padding: 8px 10px; border-radius: 6px;
                                                background: var(--color-card); border: 1px solid var(--color-line-soft);
                                                font-size: 11.5px; color: var(--color-ink-soft); line-height: 1.6;">
                                        <div>
                                            <span class="lp-mono">₦{{ number_format($reconcileNewRate) }}</span>
                                            <span style="color: var(--color-ink-mute);">−</span>
                                            <span class="lp-mono">₦{{ number_format($reconcileOldRate) }}</span>
                                            = <span class="lp-mono" style="color: var(--color-ink);">
                                                {{ ($reconcileNewRate - $reconcileOldRate) < 0 ? '−' : '+' }}₦{{ number_format(abs($reconcileNewRate - $reconcileOldRate)) }}
                                            </span>
                                            per night
                                        </div>
                                        <div style="margin-top: 2px; color: var(--color-ink-mute);">
                                            Applied to {{ $reconcileRemainingNights }} of {{ $reconcileBookedNights }} booked
                                            {{ Str::plural('night', $reconcileBookedNights) }}
                                            @if ($reconcileRemainingNights < $reconcileBookedNights)
                                                · {{ $reconcileBookedNights - $reconcileRemainingNights }} already spent stay at the old rate
                                            @endif
                                        </div>
                                        @if ($reconcileFullNightsDelta !== null
                                             && $reconcileFullNightsDelta < 0
                                             && abs($reconcileFullNightsDelta) > abs($pendingReconcileDelta))
                                            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--color-line-soft);
                                                        display: flex; align-items: baseline; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
                                                <div>
                                                    <span style="color: var(--color-ink-mute);">Fixing an assignment mistake?</span>
                                                    Refund the full
                                                    <span class="lp-mono" style="color: var(--color-ink);">
                                                        {{ money(abs($reconcileFullNightsDelta)) }}
                                                    </span>
                                                    ({{ $reconcileBookedNights }} {{ Str::plural('night', $reconcileBookedNights) }})
                                                </div>
                                                <button type="button"
                                                        wire:click="useFullNightsRefund"
                                                        data-testid="reconcile-use-full-nights"
                                                        class="lp-btn"
                                                        style="font-size: 11px; padding: 4px 10px; white-space: nowrap;">
                                                    Use {{ money(abs($reconcileFullNightsDelta)) }}
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                {{-- Mode chips: collect / refund / coupon --}}
                                <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                    @if ($pendingReconcileDelta > 0)
                                        <button type="button" wire:click="chooseReconcileMode('collect')"
                                                data-testid="reconcile-mode-collect"
                                                class="lp-chip @if ($reconcileMode === 'collect') is-active @endif"
                                                style="cursor: pointer; padding: 6px 12px;">Collect</button>
                                    @else
                                        <button type="button" wire:click="chooseReconcileMode('refund')"
                                                data-testid="reconcile-mode-refund"
                                                class="lp-chip @if ($reconcileMode === 'refund') is-active @endif"
                                                style="cursor: pointer; padding: 6px 12px;">Refund</button>
                                        <button type="button" wire:click="chooseReconcileMode('coupon')"
                                                data-testid="reconcile-mode-coupon"
                                                class="lp-chip @if ($reconcileMode === 'coupon') is-active @endif"
                                                style="cursor: pointer; padding: 6px 12px;">Issue coupon</button>
                                    @endif
                                </div>

                                {{-- Method row, cash / transfer / card / paystack, only
                                     for collect + refund. Coupon has no method. --}}
                                @if ($reconcileMode === 'collect' || $reconcileMode === 'refund')
                                    <div>
                                        <label class="lp-form-label" style="font-size: 10.5px;">Method</label>
                                        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;">
                                            @foreach (['cash' => 'Cash', 'transfer' => 'Transfer', 'card' => 'Card', 'paystack' => 'Paystack'] as $key => $label)
                                                <button type="button"
                                                        wire:click="$set('reconcileMethod', '{{ $key }}')"
                                                        data-testid="reconcile-method-{{ $key }}"
                                                        class="lp-chip @if ($reconcileMethod === $key) is-active @endif"
                                                        style="cursor: pointer; padding: 4px 10px; font-size: 12px;">
                                                    {{ $label }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">
                                        Amount ({{ currency_symbol() }})
                                        <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">
                                            &middot; editable
                                        </span>
                                    </label>
                                    <input type="number" wire:model="reconcileAmount" min="1"
                                           data-testid="reconcile-amount"
                                           class="lp-input" style="font-size: 15px;">
                                </div>

                                <div>
                                    <label class="lp-form-label" style="font-size: 10.5px;">
                                        Note <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">&middot; optional</span>
                                    </label>
                                    <input type="text" wire:model="reconcileReason" maxlength="200"
                                           data-testid="reconcile-reason"
                                           class="lp-input" style="font-size: 12.5px;">
                                </div>

                                <div style="display: flex; gap: 6px;">
                                    <button type="button" wire:click="skipReconcile"
                                            data-testid="reconcile-skip"
                                            class="lp-btn" style="font-size: 12px; padding: 6px 12px;">
                                        Skip
                                    </button>
                                    <button type="button" wire:click="confirmReconcile"
                                            data-testid="reconcile-confirm"
                                            class="lp-btn lp-btn--primary"
                                            @disabled($reconcileMode === null || $reconcileAmount <= 0)
                                            style="flex: 1; font-size: 12.5px;">
                                        @if ($reconcileMode === 'collect')
                                            Collect {{ money($reconcileAmount) }}
                                        @elseif ($reconcileMode === 'refund')
                                            Refund {{ money($reconcileAmount) }}
                                        @elseif ($reconcileMode === 'coupon')
                                            Issue coupon
                                        @else
                                            Pick an option
                                        @endif
                                    </button>
                                </div>
                            </div>
                        @endif

                        {{-- ── Coupon-issued confirmation ───────────────────────
                             After IssueBookingCoupon runs, show the code so the
                             operator can copy / share / write it on a receipt. --}}
                        @if ($issuedCouponCode !== null)
                            <div data-testid="coupon-issued"
                                 style="margin-top: 8px; padding: 14px; border-radius: 8px;
                                        background: color-mix(in srgb, var(--color-accent-deep) 12%, transparent);
                                        border: 1px solid color-mix(in srgb, var(--color-accent-deep) 40%, transparent);">
                                <div class="lp-mono" style="font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-accent-deep);">
                                    Coupon issued
                                </div>
                                <div class="lp-mono" style="margin-top: 6px; font-size: 18px; font-weight: 700; color: var(--color-ink); letter-spacing: 0.06em;">
                                    {{ $issuedCouponCode }}
                                </div>
                                <p style="margin: 6px 0 10px; font-size: 12px; color: var(--color-ink-soft);">
                                    Share this code with the guest. Single-use, worth the credited amount off any future booking.
                                </p>
                                <button type="button" wire:click="dismissCoupon"
                                        data-testid="coupon-dismiss"
                                        class="lp-btn" style="font-size: 12px;">Done</button>
                            </div>
                        @endif
                    </div>

                    {{-- Category-only (unassigned) reservation panel.
                         Three actions, each rendered as an inline
                         disclosure so the operator's eye lands on the
                         one they need: assign a room, change category,
                         confirm + collect payment. The check-in button
                         below unlocks automatically once (a) a room is
                         pinned and (b) the booking status flipped to
                         Confirmed / OnHold via the payment path.
                         Suppressed for terminal states (Cancelled /
                         NoShow) — the reservation isn't happening. --}}
                    @if ($booking->room_id === null && $booking->room_category_id !== null && ! $isTerminal)
                        <div data-testid="unassigned-panel"
                             style="display: grid; gap: 10px; padding: 12px 14px;
                                    border: 1px dashed var(--color-hairline);
                                    border-radius: 10px;
                                    background: var(--color-surface-2);">
                            <div style="font-size: 12.5px; color: var(--color-ink-soft); line-height: 1.4;">
                                <strong style="color: var(--color-ink); font-weight: 600;">
                                    Assign a room in
                                    <em>{{ $booking->roomCategory?->name ?? 'this category' }}</em>
                                </strong>
                                before check-in, or switch the guest to a different category.
                            </div>

                            @if ($assignableRooms->isEmpty())
                                <p data-testid="assign-room-empty"
                                   style="font-size: 12px; color: var(--color-danger); margin: 0;">
                                    No rooms in this category are free for
                                    {{ $booking->checkin_date->toFormattedDateString() }} to
                                    {{ $booking->checkout_date->toFormattedDateString() }}.
                                    Try changing the category below.
                                </p>
                            @else
                                <div class="flex flex-wrap gap-2" style="align-items: center;">
                                    <select wire:model.live="assignRoomId"
                                            data-testid="assign-room-select"
                                            class="lp-input" style="flex: 1; min-width: 140px; font-size: 13px;">
                                        <option value="">Pick a room</option>
                                        @foreach ($assignableRooms as $r)
                                            <option value="{{ $r->id }}">Room {{ $r->room_number }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="assignRoom"
                                            data-testid="assign-room-submit"
                                            @disabled($assignRoomId === null || $assignRoomId === '')
                                            class="lp-btn lp-btn--primary">
                                        <span wire:loading.remove wire:target="assignRoom">Assign</span>
                                        <span wire:loading wire:target="assignRoom">Assigning...</span>
                                    </button>
                                </div>
                            @endif

                            @if ($switchableCategories->isNotEmpty())
                                <div style="border-top: 1px solid var(--color-hairline); padding-top: 10px;">
                                    <label class="lp-form-label" style="margin: 0 0 6px;">
                                        Change category
                                        <span style="color: var(--color-ink-mute); font-weight: 400;">
                                            &middot; re-prices at the new base rate
                                        </span>
                                    </label>
                                    <div class="flex flex-wrap gap-2" style="align-items: center;">
                                        <select wire:model.live="switchCategoryId"
                                                data-testid="switch-category-select"
                                                class="lp-input" style="flex: 1; min-width: 140px; font-size: 13px;">
                                            <option value="">Pick a category</option>
                                            @foreach ($switchableCategories as $cat)
                                                <option value="{{ $cat->id }}">
                                                    {{ $cat->name }} · {{ money($cat->base_rate) }}/night
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="button" wire:click="changeCategory"
                                                data-testid="switch-category-submit"
                                                @disabled($switchCategoryId === null || $switchCategoryId === '')
                                                class="lp-btn">
                                            <span wire:loading.remove wire:target="changeCategory">Change</span>
                                            <span wire:loading wire:target="changeCategory">Changing...</span>
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Corporate-guaranteed direct check-in. Front
                         desk sees this INSTEAD of the Confirm & take
                         payment gate — the corp is guarantor, so we
                         skip payment collection and check in directly.
                         The outstanding stays on the folio for later
                         wallet settle / invoice. --}}
                    @if ($canCorporateCheckIn)
                        <div data-testid="corp-checkin-panel"
                             style="padding: 12px 14px;
                                    border: 1px solid rgba(122, 168, 116, 0.35);
                                    border-radius: 10px;
                                    background: rgba(122, 168, 116, 0.08);
                                    display: grid; gap: 10px;">
                            <div style="font-size: 12.5px; line-height: 1.4; color: var(--color-ink);">
                                <strong style="color: var(--color-ink); font-weight: 600;">Corporate guaranteed.</strong>
                                {{ $booking->corporate?->name ?? '' }} settles the folio at
                                checkout. Skip payment collection and check the guest in.
                            </div>
                            <button type="button"
                                    wire:click="checkInAsCorporate"
                                    wire:confirm="Check {{ $booking->guest_name }} in now? Outstanding {{ money($booking->total_amount) }} will bill to the corp."
                                    data-testid="action-corp-checkin"
                                    class="lp-btn lp-btn--primary lp-btn--block">
                                <span wire:loading.remove wire:target="checkInAsCorporate">Check in (corp guaranteed)</span>
                                <span wire:loading wire:target="checkInAsCorporate">Checking in...</span>
                            </button>
                        </div>
                    @endif

                    {{-- Pending approval banner. When a request is in
                         flight, hide the confirm/request buttons and
                         show the waiting state instead. --}}
                    @if ($pendingCheckinApproval)
                        <div data-testid="pending-checkin-approval-banner"
                             style="padding: 12px 14px;
                                    border: 1px solid rgba(168, 101, 10, 0.35);
                                    border-radius: 10px;
                                    background: rgba(254, 243, 199, 0.5);
                                    display: grid; gap: 6px;">
                            <div class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                                 style="color: #A8650A;">
                                Waiting on approval
                            </div>
                            <div style="font-size: 12.5px; line-height: 1.4; color: var(--color-ink);">
                                <strong style="color: var(--color-ink); font-weight: 600;">Check-in without payment requested.</strong>
                                A manager is reviewing. The reservation will flip to Checked in the moment they approve. Requested
                                {{ $pendingCheckinApproval->created_at?->diffForHumans() }}.
                            </div>
                        </div>
                    @endif

                    {{-- Confirm + collect payment for a Pending booking.
                         Common on the category-only path (reservation
                         landed as Pending / Pending) but also useful for
                         any pending walk-in that needs to become
                         Confirmed on the front desk.

                         Hidden when the booking is corporate-guaranteed
                         (the corp path is the intended flow there) or
                         when an approval is already pending. --}}
                    @if (
                        $booking->booking_status === \App\Enums\BookingStatus::Pending
                        && ! $canCorporateCheckIn
                        && ! $pendingCheckinApproval
                    )
                        <div data-testid="confirm-pay-panel"
                             style="display: grid; gap: 8px;">
                            @if (! $showConfirmPay)
                                <button type="button" wire:click="toggleConfirmPay"
                                        data-testid="toggle-confirm-pay"
                                        class="lp-btn lp-btn--primary lp-btn--block">
                                    Confirm &amp; take payment ({{ money($booking->total_amount) }})
                                </button>
                            @else
                                <div style="padding: 12px 14px; border: 1px solid var(--color-hairline);
                                            border-radius: 10px; background: var(--color-card-deep);
                                            display: grid; gap: 10px;">
                                    <div style="font-size: 12.5px; color: var(--color-ink-soft); line-height: 1.4;">
                                        <strong style="color: var(--color-ink); font-weight: 600;">Confirm &amp; take payment</strong>
                                        <div class="text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                                            Stay total: {{ money($booking->total_amount) }} for {{ $booking->nights }} night{{ $booking->nights === 1 ? '' : 's' }}
                                        </div>
                                    </div>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <div>
                                            <label class="lp-form-label" style="font-size: 10.5px;">Amount</label>
                                            <div style="position: relative;">
                                                <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
                                                             font-size: 12px; color: var(--color-ink-mute); pointer-events: none;">{{ currency_symbol() }}</span>
                                                <input type="number" min="1" step="1"
                                                       wire:model="confirmPayAmount"
                                                       data-testid="confirm-pay-amount"
                                                       class="lp-input"
                                                       style="width: 100%; padding-left: 22px; font-size: 13px;">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="lp-form-label" style="font-size: 10.5px;">Method</label>
                                            <select wire:model="confirmPayMethod"
                                                    data-testid="confirm-pay-method"
                                                    class="lp-input" style="font-size: 13px;">
                                                <option value="cash">Cash</option>
                                                <option value="transfer">Bank transfer</option>
                                                <option value="card">Card</option>
                                                <option value="pos">POS terminal</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="flex justify-end gap-2 pt-1">
                                        <button type="button" wire:click="toggleConfirmPay"
                                                data-testid="confirm-pay-cancel"
                                                class="lp-btn" style="font-size: 12px;">Cancel</button>
                                        <button type="button" wire:click="confirmAndPay"
                                                data-testid="confirm-pay-submit"
                                                @disabled(($confirmPayAmount ?? 0) <= 0)
                                                class="lp-btn lp-btn--primary" style="font-size: 12px;">
                                            <span wire:loading.remove wire:target="confirmAndPay">Confirm &amp; pay</span>
                                            <span wire:loading wire:target="confirmAndPay">Recording...</span>
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Alternative to Confirm & take payment: request
                         approval to check in without collecting any
                         money upfront. Only offered when the booking
                         is Pending, has a room assigned, isn't corp-
                         guaranteed, and doesn't already have an
                         approval pending. --}}
                    @if ($canRequestCheckinApproval && ! $pendingCheckinApproval)
                        <button type="button"
                                wire:click="requestCheckinWithoutPayment"
                                wire:confirm="Ask a manager to approve checking {{ $booking->guest_name }} in without payment?"
                                data-testid="action-request-checkin-approval"
                                class="lp-btn lp-btn--block"
                                style="font-size: 12.5px;">
                            <span wire:loading.remove wire:target="requestCheckinWithoutPayment">
                                Request check-in without payment
                            </span>
                            <span wire:loading wire:target="requestCheckinWithoutPayment">Requesting...</span>
                        </button>
                    @endif

                    <div class="lp-desk-actions-collapsed" data-testid="desk-actions-mobile-only">
                    @if ($canCheckIn)
                        <button type="button" wire:click="checkIn" data-testid="action-checkin"
                                wire:confirm="Check in {{ $booking->guest_name }} now?"
                                class="lp-btn lp-btn--primary lp-btn--block">Check in</button>

                        {{-- Housekeeping override — only rendered when the
                             room has an open inspection AND the current
                             operator is Frontdesk/Manager. Clicking opens
                             a small Alpine modal that captures a required
                             reason, then calls overrideAndCheckIn which
                             stamps the override on the inspection + logs
                             it against the operator + immediately checks
                             the guest in. --}}
                        @if ($openInspection)
                            @if ($canOverrideHousekeeping)
                                <div x-data="{ open: false, reason: '' }" style="margin-top: -4px;">
                                    <button type="button" @click="open = true"
                                            data-testid="action-override-checkin"
                                            class="lp-btn lp-btn--block"
                                            style="border-color: #A8650A; color: #A8650A;">
                                        Mark room clean &amp; check in
                                    </button>
                                    <p class="text-[11.5px]" style="color: #A8650A; margin-top: 4px;">
                                        Room hasn't cleared housekeeping ({{ $openInspection->status->waitingOn() }}).
                                        Override logs against your operator.
                                    </p>

                                    <template x-teleport="body">
                                    <div x-show="open" x-cloak
                                         @keydown.escape.window="open = false"
                                         @click.self="open = false"
                                         data-testid="override-modal-backdrop"
                                         class="lp-modal-backdrop"
                                         style="z-index: 80;
                                                background: rgba(20, 22, 24, 0.42);
                                                backdrop-filter: blur(2px);">
                                        <div @click.stop
                                             data-testid="override-modal-card"
                                             class="lp-page"
                                             style="width: 100%; max-width: 460px; max-height: calc(100vh - 40px); overflow-y: auto;
                                                    background: var(--color-card);
                                                    border-radius: 16px;
                                                    border: 1px solid var(--color-line-soft);
                                                    box-shadow: 0 24px 60px rgba(0,0,0,0.22);
                                                    padding: 22px 24px 20px;">
                                            <p class="lp-mono text-[10.5px]"
                                               style="color: #A8650A; letter-spacing: 0.18em; text-transform: uppercase;">
                                                Front-desk override
                                            </p>
                                            <h2 class="lp-display" style="font-size: 19px; font-weight: 600; margin-top: 4px; line-height: 1.2;">
                                                Mark room clean &amp; check in
                                            </h2>
                                            <p class="text-[12.5px] mt-2" style="color: var(--color-ink-soft);">
                                                This bypasses the housekeeper &rarr; porter &rarr; supervisor chain
                                                and attributes the sign-off to you. The reason is stored on the
                                                inspection so audit reports show who cleared the room and why.
                                            </p>
                                            <div style="margin-top: 14px;">
                                                <label for="override-reason" class="lp-form-label">
                                                    Reason
                                                </label>
                                                <input id="override-reason" type="text"
                                                       x-model="reason"
                                                       wire:model.live="overrideReason"
                                                       maxlength="240"
                                                       data-testid="override-reason"
                                                       placeholder="e.g. housekeeper off shift, I cleaned it myself"
                                                       class="lp-input">
                                            </div>
                                            <div class="flex justify-end gap-3 mt-5 pt-4"
                                                 style="border-top: 1px solid var(--color-line-soft);">
                                                <button type="button" @click="open = false"
                                                        class="lp-btn" style="font-size: 12.5px;">Cancel</button>
                                                <button type="button"
                                                        @click="if (reason.trim().length > 0) { $wire.overrideAndCheckIn(); open = false; }"
                                                        data-testid="override-submit"
                                                        :disabled="reason.trim().length === 0"
                                                        wire:loading.attr="disabled"
                                                        wire:target="overrideAndCheckIn"
                                                        class="lp-btn lp-btn--primary"
                                                        style="font-size: 12.5px;">
                                                    <span wire:loading.remove wire:target="overrideAndCheckIn">Override &amp; check in</span>
                                                    <span wire:loading wire:target="overrideAndCheckIn">Working…</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    </template>
                                </div>
                            @else
                                <p class="text-[11.5px]" style="color: #A8650A; margin-top: -4px;">
                                    Room hasn't cleared housekeeping ({{ $openInspection->status->waitingOn() }}).
                                    Ask a front-desk supervisor or manager to override if needed.
                                </p>
                            @endif
                        @endif
                    @endif

                    @if ($canCheckOut)
                        @php
                            $checkoutBlockedByOutstanding = $outstandingTotal > 0
                                && ! $booking->isCorporatePreAuthorized();
                            $corpWillInvoice = $outstandingTotal > 0
                                && $booking->isCorporatePreAuthorized();
                        @endphp
                        <button type="button" wire:click="checkOut" data-testid="action-checkout"
                                data-scroll-anchor="checkout"
                                wire:confirm="{{ $corpWillInvoice
                                    ? 'Check out '.$booking->guest_name.'? '.money($outstandingTotal).' will bill to '.($booking->corporate?->name ?? 'the corporate').' by invoice.'
                                    : 'Check out '.$booking->guest_name.'? Outstanding balance must be '.money(0).'.' }}"
                                @disabled($checkoutBlockedByOutstanding)
                                class="lp-btn lp-btn--primary lp-btn--block">Check out</button>
                        @if ($checkoutBlockedByOutstanding)
                            <p class="text-[11.5px]" style="color: #A8650A; margin-top: -4px;">
                                Clear the outstanding {{ money($outstandingTotal) }} first.
                            </p>
                        @elseif ($corpWillInvoice)
                            <p class="text-[11.5px]" data-testid="checkout-corp-invoice-hint"
                               style="color: var(--color-ink-soft); margin-top: -4px;">
                                Outstanding {{ money($outstandingTotal) }} will bill to
                                <strong style="color: var(--color-ink);">{{ $booking->corporate?->name ?? 'the corp' }}</strong>
                                on the next invoice cycle.
                            </p>
                        @endif
                    @endif

                    {{-- Change room - explicit action button so the
                         swap flow is discoverable (the small "Change"
                         link on the assigned-room card was too easy to
                         miss). Opens the reassign picker + scrolls
                         into view. Only rendered when there IS a room
                         to move to; when the property is fully booked
                         forward we surface the reason inline. --}}
                    @if ($canReassign && $booking->room_id !== null)
                        @if ($reassignCandidates->isNotEmpty())
                            <button type="button"
                                    wire:click="toggleReassign"
                                    data-testid="action-change-room"
                                    x-on:click="$nextTick(() => document.querySelector('[data-scroll-anchor=&quot;reassign&quot;]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))"
                                    class="lp-btn lp-btn--block">
                                {{ $showReassign ? 'Cancel change' : 'Change room' }}
                            </button>
                        @else
                            <button type="button" disabled
                                    data-testid="action-change-room-disabled"
                                    class="lp-btn lp-btn--block"
                                    style="opacity: 0.5; cursor: not-allowed;">
                                Change room
                            </button>
                            <p class="text-[11.5px]" style="color: var(--color-ink-mute); margin-top: -4px;">
                                No other rooms free for the remaining nights.
                            </p>
                        @endif
                    @endif

                    {{-- Extend stay — opens the shared modal that
                         previews rate, accepts an extension discount,
                         and can collect payment inline. --}}
                    @if ($canExtend)
                        <button type="button"
                                wire:click="openExtendModal({{ $booking->id }})"
                                data-testid="action-extend"
                                class="lp-btn lp-btn--block">
                            Extend stay
                        </button>
                    @endif

                    {{-- Issue invoice — front desk's shortcut to the
                         invoice composer, pre-scoped to this booking.
                         Shown for any booking that could reasonably
                         need a paper trail (in-house, confirmed,
                         checked-out). Deliberately not for Pending /
                         Cancelled / NoShow. --}}
                    @if (in_array($booking->booking_status, [
                            \App\Enums\BookingStatus::CheckedIn,
                            \App\Enums\BookingStatus::Confirmed,
                            \App\Enums\BookingStatus::CheckedOut,
                            \App\Enums\BookingStatus::OnHold,
                        ], true))
                        <a href="{{ route('tenant.invoices.create', $booking->booking_ref) }}"
                           wire:navigate
                           data-testid="action-issue-invoice"
                           class="lp-btn lp-btn--block"
                           style="text-align: center; text-decoration: none;">
                            Issue invoice
                        </a>
                    @endif

                    @if ($canNoShow)
                        <button type="button" wire:click="markNoShow"
                                wire:confirm="Mark {{ $booking->guest_name }} as no-show? Deposit will be forfeit if present."
                                data-testid="action-no-show"
                                class="lp-btn lp-btn--block">Mark no-show</button>
                    @endif

                    @if ($canCancel)
                        <div style="margin-top: 4px; padding-top: 12px; border-top: 1px solid var(--color-line-soft);">
                            <button type="button" wire:click="cancel"
                                    wire:confirm="Cancel this reservation?"
                                    data-testid="action-cancel"
                                    class="lp-btn lp-btn--danger lp-btn--block">Cancel reservation</button>
                        </div>
                    @endif

                    </div> {{-- /.lp-desk-actions-collapsed --}}
                </div>
            </section>
        </aside>
    </div>

    {{-- ── Timeline ─────────────────────────────────────────────────
         Chronological lifecycle of the booking, rendered as the last
         block on the page. Front desk usually reaches for the operational
         controls (charges + Actions column) first, then wants the audit
         "when did each thing happen" story - keeping this at the bottom
         matches how they actually use the page. Data is captured in
         activity_log all along; this card just surfaces it. --}}
    @if (! empty($timeline))
        <section data-testid="reservation-timeline" class="lp-dash-card lp-res-timeline-card">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">Timeline</span>
                <span class="lp-dash-card-meta">
                    {{ count($timeline) }} event{{ count($timeline) === 1 ? '' : 's' }}
                </span>
            </div>
            <ol class="lp-timeline">
                @foreach ($timeline as $i => $ev)
                    @php
                        $tone = match ($ev['kind']) {
                            'paid', 'checked_in', 'confirmed' => 'positive',
                            'checked_out'                     => 'ink',
                            'extended'                        => 'info',
                            'extension_cancelled',
                            'cancelled', 'no_show'            => 'danger',
                            default                           => 'muted',
                        };
                        // Compact icon per event kind — a tiny visual
                        // anchor so front desk scans the story faster
                        // than reading every label.
                        $iconSvg = match ($ev['kind']) {
                            'confirmed'           => '<path d="M2.5 7l3.2 3.2L13.5 3"/>',
                            'paid'                => '<path d="M2.5 5.5h11M2.5 10.5h11M5.5 8h5"/>',
                            'checked_in'          => '<path d="M9 3l4 5-4 5M3 8h10"/>',
                            'checked_out'         => '<path d="M7 3l-4 5 4 5M13 8H3"/>',
                            'extended'            => '<path d="M8 3v10M3 8h10"/>',
                            'extension_cancelled' => '<path d="M4 4l8 8M12 4l-8 8"/>',
                            'cancelled', 'no_show'=> '<path d="M4 4l8 8M12 4l-8 8"/>',
                            default               => '<circle cx="8" cy="8" r="1.5"/>',
                        };
                        $isLast = $i === count($timeline) - 1;
                    @endphp
                    <li class="lp-timeline-item lp-timeline-item--{{ $tone }}"
                        data-testid="timeline-{{ $ev['kind'] }}">
                        <span class="lp-timeline-icon" aria-hidden>
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                {!! $iconSvg !!}
                            </svg>
                        </span>
                        @unless ($isLast)
                            <span class="lp-timeline-trunk" aria-hidden></span>
                        @endunless
                        <div class="lp-timeline-body">
                            <div class="lp-timeline-head">
                                <span class="lp-timeline-label">{{ $ev['label'] }}</span>
                                <time class="lp-timeline-time"
                                      datetime="{{ $ev['at']->toIso8601String() }}"
                                      title="{{ $ev['at']->toDayDateTimeString() }}">
                                    {{ $ev['at']->diffForHumans() }}
                                </time>
                            </div>
                            @if (! empty($ev['meta']))
                                <p class="lp-timeline-meta">{{ $ev['meta'] }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{-- Guest details modal — replaces the old sidebar Guest card.
         Editorial layout: large avatar-lit header, quick contact
         buttons (call / email / copy phone), CRM stripe with total
         stays + lifecycle badge, then supplemental sections for
         special requests + no-show stamp. Same payload as before,
         same wire:navigate footer links; only the shape has changed. --}}
    <div x-show="guestOpen"
         x-cloak
         x-on:keydown.escape.window="guestOpen = false"
         x-on:click.self="guestOpen = false"
         class="lp-modal-backdrop"
         data-testid="guest-modal"
         style="position: fixed; inset: 0; z-index: 60;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);
                display: flex; align-items: center; justify-content: center;
                padding: 20px;">
        <div x-on:click.stop
             class="lp-page lp-guest-modal"
             style="width: 100%; max-width: 520px; max-height: calc(100vh - 40px); overflow-y: auto;
                    background: var(--color-card);
                    border-radius: 18px;
                    border: 1px solid var(--color-line-soft);
                    box-shadow: 0 24px 60px rgba(0,0,0,0.22);">
            {{-- Header — avatar-lit hero. --}}
            <div class="lp-guest-modal-head">
                <div class="lp-guest-modal-headtop">
                    <span class="lp-mono lp-guest-modal-eyebrow">Guest details</span>
                    <button type="button" x-on:click="guestOpen = false"
                            data-testid="guest-modal-close"
                            class="lp-guest-modal-close" aria-label="Close">
                        &times;
                    </button>
                </div>
                <div class="lp-guest-modal-identity">
                    <span class="lp-avatar lp-avatar--lg @if ($isVip) lp-avatar--vip @endif">
                        {{ $booking->customer?->avatarInitials() ?? mb_strtoupper(mb_substr($booking->guest_name, 0, 1)) }}
                    </span>
                    <div style="min-width: 0;">
                        <h2 class="lp-display lp-guest-modal-name">
                            @if ($isVip)
                                <span style="color: var(--color-accent-deep); margin-right: 6px;">★</span>
                            @endif
                            {{ $booking->guest_name }}
                        </h2>
                        <p class="lp-guest-modal-sub">
                            {{ $booking->adults }} adult{{ $booking->adults === 1 ? '' : 's' }}
                            @if ($booking->children > 0)
                                &nbsp;&middot;&nbsp; {{ $booking->children }} child{{ $booking->children === 1 ? '' : 'ren' }}
                            @endif
                            &nbsp;&middot;&nbsp; {{ $booking->nights }}n stay
                        </p>
                    </div>
                </div>
            </div>

            {{-- Body — grouped sections. --}}
            <div class="lp-guest-modal-body">
                {{-- Contact quick-actions. Each channel is a tap-target
                     rather than a text row so front-desk can dial / mail
                     without highlighting to copy first. --}}
                <div class="lp-guest-modal-contact">
                    @if ($booking->guest_phone)
                        <a href="tel:{{ $booking->guest_phone }}"
                           class="lp-guest-contact-tile" data-testid="guest-modal-phone">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 3.5c0 5 4 9 9 9h1.5v-2l-2-1-1 1a7 7 0 0 1-4.5-4.5l1-1-1-2H4z"/></svg>
                            <div>
                                <span class="lp-guest-contact-label">Call</span>
                                <span class="lp-guest-contact-value lp-mono">{{ $booking->guest_phone }}</span>
                            </div>
                        </a>
                    @endif
                    @if ($booking->guest_email)
                        <a href="mailto:{{ $booking->guest_email }}"
                           class="lp-guest-contact-tile" data-testid="guest-modal-email">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2 4h12v8H2zM2 4l6 5 6-5"/></svg>
                            <div>
                                <span class="lp-guest-contact-label">Email</span>
                                <span class="lp-guest-contact-value">{{ $booking->guest_email }}</span>
                            </div>
                        </a>
                    @endif
                    @if (! $booking->guest_email && ! $booking->guest_phone)
                        <p class="lp-guest-contact-none">No contact details on file.</p>
                    @endif
                </div>

                {{-- CRM strip — badge + total stays + lifetime spend. --}}
                @if ($booking->customer)
                    <div class="lp-guest-modal-crm">
                        <span class="lp-tag lp-tag--confirmed">{{ $booking->customer->lifecycle->label() }}</span>
                        <span class="lp-guest-crm-stat">
                            <span class="lp-guest-crm-num">{{ $booking->customer->total_bookings }}</span>
                            <span class="lp-guest-crm-label">stay{{ $booking->customer->total_bookings === 1 ? '' : 's' }}</span>
                        </span>
                        @if ((int) ($booking->customer->lifetime_value ?? 0) > 0)
                            <span class="lp-guest-crm-stat">
                                <span class="lp-guest-crm-num">{{ money($booking->customer->lifetime_value) }}</span>
                                <span class="lp-guest-crm-label">lifetime</span>
                            </span>
                        @endif
                    </div>
                @endif

                @if ($booking->special_requests)
                    <div class="lp-guest-modal-block">
                        <span class="lp-mono lp-guest-modal-block-label">Special requests</span>
                        <p class="lp-guest-modal-block-body" style="font-style: italic; white-space: pre-wrap;">
                            {{ $booking->special_requests }}
                        </p>
                    </div>
                @endif

                @if ($booking->no_show_at)
                    <div class="lp-guest-modal-block lp-guest-modal-block--danger">
                        <span class="lp-mono lp-guest-modal-block-label" style="color: #8c1e1e;">No-show</span>
                        <p class="lp-guest-modal-block-body" style="color: #8c1e1e;">
                            {{ $booking->no_show_at->toDayDateTimeString() }}
                        </p>
                    </div>
                @endif
            </div>

            <div class="lp-guest-modal-footer">
                @if ($booking->customer)
                    <a href="{{ route('tenant.customers.show', $booking->customer) }}" wire:navigate
                       data-testid="guest-modal-crm"
                       class="lp-guest-modal-footer-link">
                        Open CRM profile
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                    </a>
                @else
                    <span></span>
                @endif
                <a href="{{ route('tenant.bookings.show', $booking->booking_ref) }}" wire:navigate
                   data-testid="guest-modal-records"
                   class="lp-guest-modal-footer-link lp-guest-modal-footer-link--quiet">
                    Records view
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                </a>
            </div>
        </div>
    </div>

    {{-- Shared extend-stay modal (from HasExtendStayModal trait).
         The extend flow has its own inline discount picker so
         we don't ship a standalone post-checkin discount modal
         here — one entry point per money-changing action. --}}
    @include('livewire.dashboard._partials.extend-stay-modal')

    {{-- Post-cancel refund modal — opens automatically when a
         reservation is cancelled while there's held cash on it.
         Pre-fills with the held amount + cash method; operator
         just confirms or edits. --}}
    @if ($showPostCancelRefund)
        <div x-data="{ visible: true, dismiss() { this.visible = false; this.$wire.closePostCancelRefund(); } }"
             x-show="visible"
             x-cloak
             @click.self="dismiss()"
             @keydown.escape.window="dismiss()"
             data-testid="post-cancel-refund-backdrop"
             class="lp-modal-backdrop lp-page"
             style="z-index: 80;
                    background: rgba(20, 22, 24, 0.55);
                    backdrop-filter: blur(2px);">
            <div @click.stop
                 data-testid="post-cancel-refund-card"
                 style="width: 100%; max-width: 480px; max-height: calc(100vh - 40px); overflow-y: auto;
                        background: var(--color-card);
                        border-radius: 16px;
                        border: 1px solid var(--color-line-soft);
                        box-shadow: 0 24px 60px rgba(0,0,0,0.22);
                        padding: 22px 24px 20px;">
                <p class="lp-mono text-[10.5px]"
                   style="color: #A8650A; letter-spacing: 0.18em; text-transform: uppercase;">
                    Refund guest
                </p>
                <h2 class="lp-display" style="font-size: 19px; font-weight: 600; margin-top: 4px; line-height: 1.2;">
                    Booking is cancelled. Refund the {{ money($postCancelHeld) }} on the till?
                </h2>
                <p class="text-[12.5px] mt-2" style="color: var(--color-ink-soft);">
                    The reservation just cancelled, but the guest paid
                    <strong>{{ money($postCancelHeld) }}</strong> that's still with the property.
                    Record a refund now so the ledger reflects reality.
                    You can also close this. It'll show up on the Refunds page for later.
                </p>

                <div class="grid gap-3 sm:grid-cols-2" style="margin-top: 14px;">
                    <div>
                        <label class="lp-form-label" for="post-cancel-amount">Amount</label>
                        <div style="position: relative;">
                            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
                                         color: var(--color-ink-mute); font-size: 14px;">{{ currency_symbol() }}</span>
                            <input id="post-cancel-amount" type="number" min="1" step="1"
                                   wire:model.live="postCancelAmount"
                                   max="{{ $postCancelHeld }}"
                                   data-testid="post-cancel-amount"
                                   class="lp-input"
                                   style="padding-left: 26px;">
                        </div>
                    </div>
                    <div>
                        <label class="lp-form-label" for="post-cancel-method">Method</label>
                        <select id="post-cancel-method"
                                wire:model="postCancelMethod"
                                data-testid="post-cancel-method"
                                class="lp-input">
                            <option value="cash">Cash</option>
                            <option value="transfer">Bank transfer</option>
                            <option value="card">Card / POS</option>
                            <option value="paystack">Paystack</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top: 12px;">
                    <label class="lp-form-label" for="post-cancel-note">
                        Reference / reason <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                    </label>
                    <input id="post-cancel-note" type="text"
                           wire:model="postCancelNote"
                           maxlength="240"
                           placeholder="e.g. Transfer ref 88245, reason"
                           data-testid="post-cancel-note"
                           class="lp-input">
                </div>

                <div class="flex justify-end gap-3 mt-5 pt-4"
                     style="border-top: 1px solid var(--color-line-soft);">
                    <button type="button" @click="dismiss()"
                            class="lp-btn"
                            data-testid="post-cancel-later">
                        Refund later
                    </button>
                    <button type="button"
                            wire:click="submitPostCancelRefund"
                            wire:loading.attr="disabled"
                            wire:target="submitPostCancelRefund"
                            @disabled($postCancelAmount <= 0 || $postCancelAmount > $postCancelHeld)
                            data-testid="post-cancel-submit"
                            style="background: #A8650A; color: #fff; border: 0;
                                   padding: 7px 16px; border-radius: 999px;
                                   font-family: inherit; font-size: 12.5px; font-weight: 600;
                                   cursor: pointer;
                                   {{ ($postCancelAmount <= 0 || $postCancelAmount > $postCancelHeld) ? 'opacity: 0.4; cursor: not-allowed;' : '' }}">
                        <span wire:loading.remove wire:target="submitPostCancelRefund">Record refund</span>
                        <span wire:loading wire:target="submitPostCancelRefund">Recording…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Early-checkout resolution modal — opens when checkOut() detects
         unused paid nights (guest leaving before booked checkout).
         Operator picks between keep-as-revenue, wallet-credit, or
         cash refund; completeCheckOut(mode) runs MarkCheckedOut with
         the pick. --}}
    @if ($earlyCheckoutOpen)
        <div x-data="{ visible: true, dismiss() { this.visible = false; this.$wire.closeEarlyCheckout(); } }"
             x-show="visible"
             x-cloak
             @click.self="dismiss()"
             @keydown.escape.window="dismiss()"
             data-testid="early-checkout-backdrop"
             class="lp-modal-backdrop lp-page"
             style="z-index: 80;
                    background: rgba(20, 22, 24, 0.55);
                    backdrop-filter: blur(2px);">
            <div @click.stop
                 data-testid="early-checkout-card"
                 style="width: 100%; max-width: 480px; max-height: calc(100vh - 40px); overflow-y: auto;
                        background: var(--color-card);
                        border-radius: 16px;
                        border: 1px solid var(--color-line-soft);
                        box-shadow: 0 24px 60px rgba(0,0,0,0.22);
                        padding: 22px 24px 20px;">
                <p class="lp-mono text-[10.5px]"
                   style="color: #A8650A; letter-spacing: 0.18em; text-transform: uppercase;">
                    Leaving early
                </p>
                <h2 class="lp-display" style="font-size: 19px; font-weight: 600; margin-top: 4px; line-height: 1.2;">
                    {{ $booking->guest_name }} paid for
                    {{ $earlyCheckoutUnusedNights }}
                    unused night{{ $earlyCheckoutUnusedNights === 1 ? '' : 's' }}.
                </h2>
                <p class="text-[12.5px] mt-2" style="color: var(--color-ink-soft);">
                    Refundable
                    <strong style="color: var(--color-ink);">{{ money($earlyCheckoutRefundable) }}</strong>
                    ({{ $earlyCheckoutUnusedNights }}
                    × {{ money($earlyCheckoutPerNight) }}/night).
                    Pick how to close the folio. The ledger records the
                    decision either way.
                </p>

                <div style="display: grid; gap: 8px; margin-top: 16px;">
                    <button type="button"
                            wire:click="completeCheckOut('wallet')"
                            wire:loading.attr="disabled"
                            wire:target="completeCheckOut"
                            data-testid="early-checkout-wallet"
                            class="lp-btn lp-btn--primary lp-btn--block">
                        Credit {{ money($earlyCheckoutRefundable) }} to guest wallet
                    </button>
                    <button type="button"
                            wire:click="completeCheckOut('cash')"
                            wire:loading.attr="disabled"
                            wire:target="completeCheckOut"
                            data-testid="early-checkout-cash"
                            class="lp-btn lp-btn--block">
                        Refund {{ money($earlyCheckoutRefundable) }} cash at the desk
                    </button>
                    <button type="button"
                            wire:click="completeCheckOut('keep')"
                            wire:loading.attr="disabled"
                            wire:target="completeCheckOut"
                            data-testid="early-checkout-keep"
                            class="lp-btn lp-btn--block">
                        Keep as revenue (no refund)
                    </button>
                </div>

                <button type="button" @click="dismiss()"
                        data-testid="early-checkout-cancel"
                        style="margin-top: 12px; background: transparent; border: 0; cursor: pointer;
                               font-family: inherit; font-size: 12px; color: var(--color-ink-mute);
                               text-decoration: underline; text-underline-offset: 3px;">
                    Cancel, don't check out yet
                </button>
            </div>
        </div>
    @endif
</div>
