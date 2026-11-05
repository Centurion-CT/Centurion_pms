@php
    $status = $booking->booking_status->value;
    $first = \Illuminate\Support\Str::before(trim((string) $booking->guest_name), ' ') ?: 'there';
    $arrival = $booking->checkin_date->format('D j M');
    [$badge, $badgeTone, $headline] = match ($status) {
        'pending'     => ['Awaiting payment', 'warn', "Almost there, {$first}."],
        'on_hold'     => ['Deposit paid', 'ok', "See you on {$arrival}, {$first}."],
        'confirmed'   => ['Booking confirmed', 'ok', "See you on {$arrival}, {$first}."],
        'checked_in'  => ['Checked in', 'ok', "Welcome, {$first}."],
        'checked_out' => ['Stay complete', 'muted', "Thanks for staying, {$first}."],
        'cancelled'   => ['Cancelled', 'bad', 'This booking is cancelled.'],
        default       => [ucfirst(str_replace('_', ' ', $status)), 'muted', "Your booking, {$first}."],
    };
    $category = $booking->roomCategory ?? $booking->room?->category;
    $photo = $category?->heroImage() ?? $booking->room?->getMedia('gallery')->first();
    // A booking still awaiting payment has balance_due 0 but nothing paid.
    $awaiting = $booking->booking_status === \App\Enums\BookingStatus::Pending;
    $paid = $awaiting ? 0 : max(0, (int) $booking->total_amount - (int) $booking->balance_due);
    $due = $awaiting ? (int) $booking->total_amount : (int) $booking->balance_due;

    $property = $booking->room?->property ?? \App\Models\Tenant::current()?->property;
    $rules = $property?->houseRules() ?? [];
    $inFrom = \App\Site\Stay::time($rules['check_in_from'] ?? '');
    $outBy = \App\Site\Stay::time($rules['check_out_to'] ?? '') ?: \App\Site\Stay::time($rules['check_out_from'] ?? '');
    $guestUrl = fn (string $suffix) => '/guest/booking/'.$booking->booking_ref.$suffix.'?token='.$token;
    $address = collect([$property?->address, $property?->city, $property?->state])->filter()->unique()->implode(', ');
@endphp

{{-- Guest booking page / payment confirmation (approved mockup 4). --}}
<div class="cx-guest">
    <style>
        .cx-guest { background: #FFF; }
        .cx-guest-hero-inner { max-width: 1120px; margin: 0 auto; padding: 40px 24px 28px; display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px 40px; }
        .cx-status { margin: 0 0 4px; font-weight: 600; }
        .cx-status--ok { color: var(--c-free); }
        .cx-status--warn { color: var(--c-last); }
        .cx-status--bad { color: var(--c-danger); }
        .cx-status--muted { color: var(--c-mute); }
        .cx-guest-title { font: 600 clamp(32px, 4vw, 44px)/1.1 var(--f-display); letter-spacing: -.02em; margin: 0; }
        .cx-guest-lede { color: var(--c-mute); margin: 8px 0 0; max-width: 60ch; }
        .cx-ref { margin: 0; color: var(--c-mute); }
        .cx-ref strong { font-size: 20px; font-weight: 600; letter-spacing: .02em; color: var(--c-ink); }
        .cx-due { max-width: 1072px; margin: 0 auto 24px; padding: 20px 24px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px 32px; background: var(--panel); border-radius: 12px; color: var(--on-panel); }
        .cx-due p { margin: 0; }
        .cx-due .amount { font-size: 22px; font-weight: 600; color: #FFF; }
        .cx-guest-body { max-width: 1120px; margin: 0 auto; padding: 0 24px 64px; display: grid; gap: 32px 40px; grid-template-columns: 1fr; align-items: start; }
        @media (min-width: 960px) { .cx-guest-body { grid-template-columns: minmax(0, 1fr) 340px; } }
        @media (max-width: 1120px) { .cx-due { margin-left: 24px; margin-right: 24px; } }
        @media (max-width: 720px) { .cx-guest-hero-inner { padding: 24px 16px 20px; } .cx-guest-body { padding: 0 16px 40px; } .cx-due { margin: 0 16px 16px; padding: 16px; } .cx-due .btn { width: 100%; } }
        .cx-stay { display: grid; grid-template-columns: 1fr; border: 1px solid var(--c-rule); border-radius: 12px; overflow: hidden; }
        @media (min-width: 640px) { .cx-stay { grid-template-columns: 240px 1fr; } }
        .cx-stay-photo { min-height: 160px; background: var(--tint) center / cover no-repeat; display: flex; padding: 24px 24px 0; }
        .cx-stay-photo .bars { flex: 1; }
        .cx-stay-body { padding: 20px 24px; }
        .cx-dl { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 24px; margin: 16px 0 0; }
        .cx-dl dt { font-size: 15px; color: var(--c-mute); }
        .cx-dl dd { margin: 0; font-weight: 600; color: var(--c-ink); }
        .cx-h3 { font-size: 22px; font-weight: 600; line-height: 1.3; font-family: var(--f-body); margin: 0; }
        .cx-address { margin: 2px 0 0; color: var(--c-mute); }
        .cx-actions { margin-top: 8px; border-top: 1px solid var(--c-rule); }
        .site a.cx-action { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 68px; padding: 10px 4px; border-bottom: 1px solid var(--c-rule); color: var(--c-ink); text-decoration: none; }
        .cx-action:hover b { color: var(--action); }
        .cx-action > svg { flex: none; color: var(--c-mute); }
        .cx-action b { display: block; font-weight: 600; }
        .cx-action span span { display: block; font-size: 15px; color: var(--c-mute); }
        .cx-action--danger b, .cx-action--danger:hover b { color: var(--c-danger); }
        .cx-steps { margin: 12px 0 0; padding-left: 20px; display: grid; gap: 8px; }
        .cx-side { display: grid; gap: 16px; }
        .cx-box { border: 1px solid var(--c-rule); border-radius: 12px; padding: 20px 24px; }
        .cx-box--band { background: var(--c-band); border: 0; }
        .cx-sum { display: grid; gap: 8px; margin: 12px 0 0; }
        .cx-sum div { display: flex; justify-content: space-between; gap: 12px; }
        .cx-sum dt { color: var(--c-mute); }
        .cx-sum dd { margin: 0; color: var(--c-ink); font-weight: 500; }
        .cx-sum .total { padding-top: 8px; border-top: 1px solid var(--c-rule); font-weight: 600; }
        .cx-side .cx-btn { width: 100%; }
        .cx-list { margin: 12px 0 0; padding: 0; list-style: none; border: 1px solid var(--c-rule); border-radius: 8px; }
        .cx-list li { display: flex; justify-content: space-between; gap: 12px; padding: 10px 12px; }
        .cx-list li + li { border-top: 1px solid var(--c-rule); }
        .cx-list small { display: block; color: var(--c-mute); }
        .cx-wallet { background: #FFF8EB; border-color: #F1D9A8; }
    </style>

    <section class="cx-guest-hero">
        <div class="cx-guest-hero-inner">
            <div>
                <p class="cx-status cx-status--{{ $badgeTone }}">{{ $badge }}</p>
                <h1 class="cx-guest-title">{{ $headline }}</h1>
                <p class="cx-guest-lede">
                    @if (in_array($status, ['confirmed', 'on_hold'], true) && $booking->guest_email)
                        We've emailed your confirmation to {{ $booking->guest_email }}. Keep this page to manage your stay.
                    @elseif ($status === 'pending')
                        Your dates aren't confirmed until payment goes through.
                    @elseif ($status === 'checked_in')
                        Scan the QR code in your room to order food, ask for housekeeping or see your bill.
                    @else
                        Here's everything about your booking.
                    @endif
                </p>
            </div>
            <p class="cx-ref">Reference <strong>{{ $booking->booking_ref }}</strong></p>
        </div>
    </section>

    @if ($canPayBalance)
        <section class="cx-due" aria-label="Balance">
            <div>
                <p class="amount">₦{{ number_format($due) }} left to pay</p>
                <p>
                    @if ($booking->balance_due_at)Due by {{ $booking->balance_due_at->format('D j M') }}. @else Pay now, or at the front desk when you arrive. @endif
                    You've paid ₦{{ number_format($paid) }} of ₦{{ number_format($booking->total_amount) }}.
                </p>
            </div>
            <a href="{{ $guestUrl('/balance') }}" wire:navigate class="btn btn-inverse">Pay the balance</a>
        </section>
    @endif

    <div class="cx-guest-body">
        <div style="display: grid; gap: 28px; min-width: 0;">
            <section class="cx-stay">
                <div class="cx-stay-photo" @if ($photo) style="background-image: url('{{ \App\Site\Photo::url($photo) }}');" @endif>@unless ($photo)@include('site.partials.bars')@endunless</div>
                <div class="cx-stay-body">
                    <h2 class="cx-h3">{{ $category?->name ?? $booking->room?->name }}</h2>
                    @if ($address !== '')<p class="cx-address">{{ $address }}</p>@endif
                    <dl class="cx-dl">
                        <div><dt>Check in</dt><dd>{{ $booking->checkin_date->format('D, j M') }}@if ($inFrom), from {{ $inFrom }}@endif</dd></div>
                        <div><dt>Check out</dt><dd>{{ $booking->checkout_date->format('D, j M') }}@if ($outBy), by {{ $outBy }}@endif</dd></div>
                        <div><dt>Guests</dt><dd>{{ $booking->adults }} {{ $booking->adults === 1 ? 'adult' : 'adults' }}@if ($booking->children > 0), {{ $booking->children }} {{ $booking->children === 1 ? 'child' : 'children' }}@endif</dd></div>
                        <div><dt>Nights</dt><dd>{{ $booking->nights }}</dd></div>
                        @if ($booking->room && $category && $booking->room->name !== $category->name)
                            <div><dt>Room</dt><dd>{{ $booking->room->name }}</dd></div>
                        @endif
                    </dl>
                </div>
            </section>

            @php $bookingPackages = $booking->selectedPackages; @endphp
            @if ($bookingPackages->isNotEmpty())
                <section data-testid="show-packages">
                    <h2 class="cx-h3">Extras on your booking</h2>
                    <ul class="cx-list" style="background: var(--white);">
                        @foreach ($bookingPackages as $line)
                            <li data-testid="show-package-{{ $line->id }}">
                                <span>{{ $line->name_snapshot }}<small>Qty {{ $line->quantity }}</small></span>
                                <strong>{{ $line->is_complimentary_snapshot ? 'Complimentary' : '₦'.number_format($line->line_total) }}</strong>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($booking->booking_status->holdsInventory() || $canReview)
                <section>
                    <h2 class="cx-h3">Change your booking</h2>
                    <nav class="cx-actions" aria-label="Manage your booking">
                        @if ($booking->booking_status->holdsInventory())
                            <a href="{{ $guestUrl('/change') }}" wire:navigate class="cx-action">
                                <span><b>Change dates</b><span>Ask the front desk to move your stay.</span></span>
                                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.5 4.5 13 10l-5.5 5.5"/></svg>
                            </a>
                            <a href="{{ $guestUrl('/packages') }}" wire:navigate data-testid="packages-link" class="cx-action">
                                <span><b>{{ $bookingPackages->isEmpty() ? 'Add extras' : 'Manage extras' }}</b><span>Breakfast, airport pickup and more.</span></span>
                                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.5 4.5 13 10l-5.5 5.5"/></svg>
                            </a>
                        @endif
                        @if ($address !== '')
                            <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($address) }}" target="_blank" rel="noopener" class="cx-action">
                                <span><b>Get directions</b><span>Open the address in your maps app.</span></span>
                                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.5 4.5 13 10l-5.5 5.5"/></svg>
                            </a>
                        @endif
                        @if ($canReview)
                            <a href="{{ $guestUrl('/review') }}" wire:navigate data-testid="review-link" class="cx-action">
                                <span><b>{{ $hasReview ? 'View your review' : 'Leave a review' }}</b><span>Tell us how your stay went.</span></span>
                                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.5 4.5 13 10l-5.5 5.5"/></svg>
                            </a>
                        @endif
                        @if ($booking->booking_status->holdsInventory())
                            <a href="{{ $guestUrl('/cancel') }}" wire:navigate class="cx-action cx-action--danger">
                                <span><b>Cancel booking</b><span>See what you'd get back first.</span></span>
                                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.5 4.5 13 10l-5.5 5.5"/></svg>
                            </a>
                        @endif
                    </nav>
                </section>
            @endif

            @if (in_array($status, ['confirmed', 'on_hold', 'pending'], true))
                <section class="cx-box cx-box--band">
                    <h2 class="cx-h3">When you arrive</h2>
                    <ol class="cx-steps">
                        <li>Show your reference <strong style="color: var(--c-ink);">{{ $booking->booking_ref }}</strong> at the front desk.</li>
                        <li>Scan the QR code in your room to order food, ask for housekeeping or see your bill.</li>
                        <li>After your stay, you'll be able to leave a review here.</li>
                    </ol>
                </section>
            @endif
        </div>

        <aside class="cx-side">
            <section class="cx-box">
                <h2 class="cx-h3">Payment</h2>
                <dl class="cx-sum">
                    <div><dt>Total</dt><dd>₦{{ number_format($booking->total_amount) }}</dd></div>
                    @if ($paid > 0)
                        <div style="color: var(--emerald);"><dt style="color: inherit;">Paid</dt><dd>₦{{ number_format($paid) }}</dd></div>
                    @endif
                    @if ($due > 0)
                        <div class="total"><dt style="color: var(--ink);">Balance due</dt><dd style="color: var(--rose);">₦{{ number_format($due) }}</dd></div>
                    @else
                        <div class="total"><dt style="color: var(--ink);">Balance due</dt><dd>₦0</dd></div>
                    @endif
                </dl>
            </section>

            @if ($walletBalance > 0 || $recentWalletTx->isNotEmpty())
                <section class="cx-box cx-wallet" data-testid="guest-wallet-block">
                    <div style="font-size: 14px; color: #8A5A0B;">Wallet balance</div>
                    <div class="cx-price" data-testid="guest-wallet-balance">₦{{ number_format($walletBalance) }}</div>
                    <p style="margin: 4px 0 0; font-size: 13px; color: #8A5A0B;">Money on file with this property for future stays or purchases.</p>
                    @if ($walletCanCoverOutstanding)
                        <button type="button" wire:click="payFromWallet"
                                wire:confirm="Pay ₦{{ number_format($booking->outstandingTotal()) }} from your wallet?"
                                data-testid="guest-pay-from-wallet" class="cx-btn" style="margin-top: 12px;">Pay from wallet</button>
                    @endif
                    @if ($recentWalletTx->isNotEmpty())
                        <details style="margin-top: 12px; font-size: 14px;">
                            <summary style="cursor: pointer; color: #8A5A0B;">Recent activity ({{ $recentWalletTx->count() }})</summary>
                            <ul class="cx-list" style="background: var(--white);">
                                @foreach ($recentWalletTx as $tx)
                                    <li data-testid="guest-wallet-tx-{{ $tx->id }}">
                                        <span>{{ $tx->subtypeLabel() }}<small>{{ $tx->created_at->toDayDateTimeString() }}</small></span>
                                        <strong style="color: {{ $tx->amount_naira > 0 ? 'var(--emerald)' : 'var(--rose)' }};">{{ $tx->amount_naira > 0 ? '+' : '−' }}₦{{ number_format(abs((int) $tx->amount_naira)) }}</strong>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </section>
            @endif

            @php $tenantForContact = \App\Models\Tenant::current(); @endphp
            @if ($tenantForContact?->phone || $tenantForContact?->email)
                <section class="cx-box">
                    <h2 class="cx-h3">Questions about your stay?</h2>
                    <p style="margin: 4px 0 12px;">Message us about your booking.</p>
                    @if ($tenantForContact->phone)
                        <a href="https://wa.me/{{ preg_replace('/\D+/', '', $tenantForContact->phone) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-block">Message us on WhatsApp</a>
                    @else
                        <a href="mailto:{{ $tenantForContact->email }}" class="btn btn-secondary btn-block">Email us</a>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
