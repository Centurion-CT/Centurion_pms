@php
    $status = $booking->booking_status->value;
    $first = \Illuminate\Support\Str::before(trim((string) $booking->guest_name), ' ') ?: 'there';
    [$badge, $badgeTone, $headline] = match ($status) {
        'pending'     => ['Awaiting payment', 'warn', "Almost there, {$first}."],
        'on_hold'     => ['Deposit paid', 'ok', "Your room is held, {$first}."],
        'confirmed'   => ['Confirmed', 'ok', "You're booked, {$first}."],
        'checked_in'  => ['Checked in', 'ok', "Welcome, {$first}."],
        'checked_out' => ['Stay complete', 'muted', "Thanks for staying, {$first}."],
        'cancelled'   => ['Cancelled', 'bad', 'This booking is cancelled.'],
        default       => [ucfirst(str_replace('_', ' ', $status)), 'muted', "Your booking, {$first}."],
    };
    $category = $booking->roomCategory ?? $booking->room?->category;
    $photo = $category?->heroImage() ?? $booking->room?->getMedia('gallery')->first();
    $paid = max(0, (int) $booking->total_amount - (int) $booking->balance_due);

    $property = $booking->room?->property ?? \App\Models\Tenant::current()?->property;
    $rules = $property?->houseRules() ?? [];
    $fmt = static function (string $t): string {
        if ($t === '' || ! preg_match('/^(\d{1,2}):(\d{2})$/', $t)) return '';
        try { return \Carbon\Carbon::createFromFormat('H:i', $t)->format('g:i A'); } catch (\Throwable) { return ''; }
    };
    $inFrom = $fmt($rules['check_in_from'] ?? '');
    $outBy = $fmt($rules['check_out_to'] ?? '') ?: $fmt($rules['check_out_from'] ?? '');
    $guestUrl = fn (string $suffix) => '/guest/booking/'.$booking->booking_ref.$suffix.'?token='.$token;
    $address = collect([$property?->address, $property?->city, $property?->state])->filter()->unique()->implode(', ');
@endphp

{{-- Guest booking page / payment confirmation (approved mockup 4). --}}
<div class="cx-guest">
    <style>
        .cx-guest-hero { background: var(--navy); color: var(--white); }
        .cx-guest-hero-inner { max-width: 1080px; margin: 0 auto; padding: 48px 20px; display: flex; flex-wrap: wrap; align-items: flex-end; gap: 24px 40px; }
        @media (min-width: 768px) { .cx-guest-hero-inner { padding: 48px 40px; } }
        .cx-badge { display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 12px; border-radius: 8px; font-size: 14px; font-weight: 600; }
        .cx-badge--ok { background: rgba(31, 122, 80, 0.22); color: #B7EBCB; }
        .cx-badge--warn { background: rgba(227, 168, 58, 0.2); color: #F6D79A; }
        .cx-badge--bad { background: rgba(194, 65, 75, 0.25); color: #F7C1C6; }
        .cx-badge--muted { background: rgba(255, 255, 255, 0.1); color: var(--blue-200); }
        .cx-guest-title { font-family: var(--display); font-weight: 600; font-size: clamp(34px, 5vw, 48px); line-height: 1.08; margin: 14px 0 0; color: var(--white); }
        .cx-guest-lede { color: var(--blue-200); font-size: 17px; margin: 8px 0 0; max-width: 60ch; }
        .cx-ref { margin-left: auto; text-align: right; }
        .cx-ref span { display: block; font-size: 14px; color: var(--blue-200); }
        .cx-ref strong { font-family: var(--display); font-weight: 600; font-size: 30px; letter-spacing: 0.04em; }
        .cx-guest-body { max-width: 1080px; margin: 0 auto; padding: 40px 20px 72px; display: grid; gap: 28px; grid-template-columns: 1fr; align-items: start; }
        @media (min-width: 768px) { .cx-guest-body { padding: 40px 40px 72px; } }
        @media (min-width: 960px) { .cx-guest-body { grid-template-columns: minmax(0, 1fr) 340px; } }
        .cx-stay { display: grid; grid-template-columns: 1fr; background: var(--white); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
        @media (min-width: 640px) { .cx-stay { grid-template-columns: 240px 1fr; } }
        .cx-stay-photo { min-height: 180px; background: var(--blue-50) center / cover no-repeat; }
        .cx-stay-body { padding: 22px 24px; }
        .cx-dl { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; margin: 14px 0 0; }
        .cx-dl dt { font-size: 14px; color: var(--ink-mute); }
        .cx-dl dd { margin: 2px 0 0; font-weight: 600; }
        .cx-h3 { font-family: var(--display); font-weight: 600; font-size: 22px; margin: 0; }
        .cx-actions { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); margin-top: 14px; }
        .cx-action { display: flex; gap: 14px; padding: 18px; background: var(--white); border: 1px solid var(--line); border-radius: 12px; text-decoration: none; color: var(--ink); transition: border-color 150ms ease; }
        .cx-action:hover { border-color: var(--blue); }
        .cx-action svg { flex-shrink: 0; color: var(--blue); }
        .cx-action b { display: block; }
        .cx-action span { font-size: 14px; color: var(--ink-soft); }
        .cx-action--danger svg, .cx-action--danger b { color: var(--rose); }
        .cx-steps { margin: 14px 0 0; padding: 0; list-style: none; display: grid; gap: 12px; color: var(--ink-soft); }
        .cx-steps li { display: flex; gap: 12px; }
        .cx-steps i { font-style: normal; flex-shrink: 0; width: 24px; height: 24px; border-radius: 999px; background: var(--blue-50); color: var(--blue-600); font-size: 13px; font-weight: 600; display: grid; place-items: center; }
        .cx-side { display: grid; gap: 18px; }
        .cx-box { background: var(--white); border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
        .cx-sum { display: grid; gap: 8px; margin: 12px 0 0; font-size: 15px; }
        .cx-sum div { display: flex; justify-content: space-between; gap: 12px; }
        .cx-sum dt { color: var(--ink-soft); }
        .cx-sum dd { margin: 0; }
        .cx-sum .total { padding-top: 8px; border-top: 1px solid var(--line); font-weight: 600; }
        .cx-side .cx-btn { width: 100%; }
        .cx-note { font-size: 13px; color: var(--ink-mute); text-align: center; margin: 8px 0 0; }
        .cx-list { margin: 12px 0 0; padding: 0; list-style: none; border: 1px solid var(--line); border-radius: 8px; }
        .cx-list li { display: flex; justify-content: space-between; gap: 12px; padding: 10px 12px; }
        .cx-list li + li { border-top: 1px solid var(--line); }
        .cx-list small { display: block; color: var(--ink-mute); }
        .cx-wallet { background: #FFF8EB; border-color: #F1D9A8; }
    </style>

    <section class="cx-guest-hero">
        <div class="cx-guest-hero-inner">
            <div>
                <span class="cx-badge cx-badge--{{ $badgeTone }}">{{ $badge }}</span>
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
            <div class="cx-ref"><span>Booking reference</span><strong>{{ $booking->booking_ref }}</strong></div>
        </div>
    </section>

    <div class="cx-guest-body">
        <div style="display: grid; gap: 28px; min-width: 0;">
            <section class="cx-stay">
                <div class="cx-stay-photo" @if ($photo) style="background-image: url('{{ $photo->getUrl('medium') }}');" @endif></div>
                <div class="cx-stay-body">
                    <h2 class="cx-h3">{{ $category?->name ?? $booking->room?->name }}</h2>
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
                    <h2 class="cx-h3" style="font-size: 20px;">Extras on your booking</h2>
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
                    <h2 class="cx-h3">Manage your booking</h2>
                    <nav class="cx-actions" aria-label="Manage your booking">
                        @if ($booking->booking_status->holdsInventory())
                            <a href="{{ $guestUrl('/change') }}" wire:navigate class="cx-action">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 15h8"/></svg>
                                <span><b>Change dates</b><span>Ask the front desk to move your stay.</span></span>
                            </a>
                            <a href="{{ $guestUrl('/packages') }}" wire:navigate data-testid="packages-link" class="cx-action">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>
                                <span><b>{{ $bookingPackages->isEmpty() ? 'Add extras' : 'Manage extras' }}</b><span>Breakfast, airport pickup and more.</span></span>
                            </a>
                        @endif
                        @if ($address !== '')
                            <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($address) }}" target="_blank" rel="noopener" class="cx-action">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
                                <span><b>Get directions</b><span>Open the address in your maps app.</span></span>
                            </a>
                        @endif
                        @if ($canReview)
                            <a href="{{ $guestUrl('/review') }}" wire:navigate data-testid="review-link" class="cx-action">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
                                <span><b>{{ $hasReview ? 'View your review' : 'Leave a review' }}</b><span>Tell us how your stay went.</span></span>
                            </a>
                        @endif
                        @if ($booking->booking_status->holdsInventory())
                            <a href="{{ $guestUrl('/cancel') }}" wire:navigate class="cx-action cx-action--danger">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                                <span><b>Cancel booking</b><span>See what you'd get back first.</span></span>
                            </a>
                        @endif
                    </nav>
                </section>
            @endif

            @if (in_array($status, ['confirmed', 'on_hold', 'pending'], true))
                <section class="cx-box">
                    <h2 class="cx-h3" style="font-size: 20px;">When you arrive</h2>
                    <ol class="cx-steps">
                        <li><i>1</i><span>Show your reference <strong style="color: var(--ink);">{{ $booking->booking_ref }}</strong> at the front desk.</span></li>
                        <li><i>2</i><span>Scan the QR code in your room to order food, ask for housekeeping or see your bill.</span></li>
                        <li><i>3</i><span>After your stay, you'll be able to leave a review here.</span></li>
                    </ol>
                </section>
            @endif
        </div>

        <aside class="cx-side">
            <section class="cx-box">
                <h2 class="cx-h3" style="font-size: 20px;">Payment</h2>
                <dl class="cx-sum">
                    <div><dt>Total</dt><dd>₦{{ number_format($booking->total_amount) }}</dd></div>
                    @if ($paid > 0)
                        <div style="color: var(--emerald);"><dt style="color: inherit;">Paid</dt><dd>₦{{ number_format($paid) }}</dd></div>
                    @endif
                    @if ($booking->balance_due > 0)
                        <div class="total"><dt style="color: var(--ink);">Balance due</dt><dd style="color: var(--rose);">₦{{ number_format($booking->balance_due) }}</dd></div>
                    @else
                        <div class="total"><dt style="color: var(--ink);">Balance due</dt><dd>₦0</dd></div>
                    @endif
                </dl>
                @if ($canPayBalance)
                    <a href="{{ $guestUrl('/balance') }}" wire:navigate class="cx-btn" style="margin-top: 14px;">Pay balance now</a>
                    <p class="cx-note">Or pay at the front desk when you arrive.</p>
                @endif
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
                <section class="cx-box" style="background: var(--navy); color: var(--white); border: 0;">
                    <div style="font-weight: 600;">Questions about your stay?</div>
                    <p style="margin: 4px 0 0; font-size: 14px; color: var(--blue-200);">Contact the front desk.</p>
                    @if ($tenantForContact->phone)
                        <a href="https://wa.me/{{ preg_replace('/\D+/', '', $tenantForContact->phone) }}" target="_blank" rel="noopener" class="cx-btn cx-btn--light" style="margin-top: 14px;">Message on WhatsApp</a>
                    @else
                        <a href="mailto:{{ $tenantForContact->email }}" class="cx-btn cx-btn--light" style="margin-top: 14px;">Email the front desk</a>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
