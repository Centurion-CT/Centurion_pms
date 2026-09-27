<div class="mx-auto max-w-2xl px-4 py-12">
    <div class="kv-card space-y-6">
        <div>
            <p class="kv-muted">Booking</p>
            <h1 class="kv-heading">{{ $booking->booking_ref }}</h1>
            <p class="mt-2 text-slate-700">Hi {{ $booking->guest_name }}.</p>
        </div>

        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 border-y border-slate-200 py-4 text-sm">
            <div>
                <dt class="kv-muted">Status</dt>
                <dd class="font-medium text-slate-900">{{ $booking->booking_status->value }}</dd>
            </div>
            <div>
                <dt class="kv-muted">Room</dt>
                <dd class="font-medium text-slate-900">{{ $booking->room->name }}</dd>
            </div>
            <div>
                <dt class="kv-muted">Check-in</dt>
                <dd class="font-medium text-slate-900">{{ $booking->checkin_date->toFormattedDateString() }}</dd>
            </div>
            <div>
                <dt class="kv-muted">Check-out</dt>
                <dd class="font-medium text-slate-900">
                    {{ $booking->checkout_date->toFormattedDateString() }}
                    <span class="font-normal text-slate-500">({{ $booking->nights }} nights)</span>
                </dd>
            </div>
            <div>
                <dt class="kv-muted">Guests</dt>
                <dd class="font-medium text-slate-900">
                    {{ $booking->adults }} adult(s){{ $booking->children > 0 ? ', '.$booking->children.' child(ren)' : '' }}
                </dd>
            </div>
            <div>
                <dt class="kv-muted">Total</dt>
                <dd class="font-semibold text-slate-900">₦{{ number_format($booking->total_amount) }}</dd>
            </div>
            @if ($booking->balance_due > 0)
                <div class="col-span-2">
                    <dt class="kv-muted">Outstanding balance</dt>
                    <dd class="text-lg font-semibold text-amber-700">
                        ₦{{ number_format($booking->balance_due) }}
                    </dd>
                </div>
            @endif
        </dl>

        @php $bookingPackages = $booking->selectedPackages; @endphp
        @if ($bookingPackages->isNotEmpty())
            <section data-testid="show-packages">
                <h2 class="text-sm font-semibold text-slate-900">Add-ons on your booking</h2>
                <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200">
                    @foreach ($bookingPackages as $line)
                        <li class="flex items-start justify-between gap-3 px-3 py-2"
                            data-testid="show-package-{{ $line->id }}">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $line->name_snapshot }}</p>
                                <p class="text-xs text-slate-500">Qty {{ $line->quantity }}</p>
                            </div>
                            <div class="text-right text-sm">
                                @if ($line->is_complimentary_snapshot)
                                    <span class="font-medium text-emerald-700">Complimentary</span>
                                @else
                                    <span class="font-semibold text-slate-900">₦{{ number_format($line->line_total) }}</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ── Wallet section ─────────────────────────────────────
             Only rendered when the guest has a wallet on file with
             this property. Balance + last 5 transactions + a "Pay
             from wallet" button when their balance covers the
             outstanding total. --}}
        @if ($walletBalance > 0 || $recentWalletTx->isNotEmpty())
            <section data-testid="guest-wallet-block" class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="kv-muted">Wallet balance</p>
                        <p class="text-2xl font-semibold text-amber-900"
                           data-testid="guest-wallet-balance">
                            ₦{{ number_format($walletBalance) }}
                        </p>
                        <p class="mt-1 text-xs text-amber-800/80">
                            Cash on file with this property. Spend it on future stays or purchases.
                        </p>
                    </div>
                    @if ($walletCanCoverOutstanding)
                        <button type="button"
                                wire:click="payFromWallet"
                                wire:confirm="Pay ₦{{ number_format($booking->outstandingTotal()) }} from your wallet?"
                                data-testid="guest-pay-from-wallet"
                                class="kv-btn-primary whitespace-nowrap">
                            Pay from wallet
                        </button>
                    @endif
                </div>

                @if ($recentWalletTx->isNotEmpty())
                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer text-amber-900/80 hover:text-amber-900">
                            Recent activity ({{ $recentWalletTx->count() }})
                        </summary>
                        <ul class="mt-2 divide-y divide-amber-200/70 rounded border border-amber-200/70 bg-white/60">
                            @foreach ($recentWalletTx as $tx)
                                <li class="flex items-start justify-between gap-3 px-3 py-2"
                                    data-testid="guest-wallet-tx-{{ $tx->id }}">
                                    <div>
                                        <p class="text-xs font-medium text-slate-900">{{ $tx->subtypeLabel() }}</p>
                                        <p class="text-[11px] text-slate-500">{{ $tx->created_at->toDayDateTimeString() }}</p>
                                    </div>
                                    <p class="text-sm font-semibold {{ $tx->amount_naira > 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                        {{ $tx->amount_naira > 0 ? '+ ' : '- ' }}₦{{ number_format(abs((int) $tx->amount_naira)) }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>
        @endif

        <nav aria-label="Manage your booking" class="space-y-2">
            @if ($canPayBalance)
                <a href="/guest/booking/{{ $booking->booking_ref }}/balance?token={{ $token }}"
                   wire:navigate class="kv-btn-primary w-full">
                    Pay outstanding balance
                </a>
            @endif

            @if ($booking->booking_status->holdsInventory())
                <a href="/guest/booking/{{ $booking->booking_ref }}/packages?token={{ $token }}"
                   wire:navigate data-testid="packages-link" class="kv-btn-secondary w-full">
                    {{ $bookingPackages->isEmpty() ? 'Add a package' : 'Manage add-ons' }}
                </a>

                <a href="/guest/booking/{{ $booking->booking_ref }}/change?token={{ $token }}"
                   wire:navigate class="kv-btn-secondary w-full">
                    Request date change
                </a>

                <a href="/guest/booking/{{ $booking->booking_ref }}/cancel?token={{ $token }}"
                   wire:navigate class="kv-btn-secondary w-full text-red-600 hover:bg-red-50">
                    Cancel booking
                </a>
            @endif

            @if ($canReview)
                <a href="/guest/booking/{{ $booking->booking_ref }}/review?token={{ $token }}"
                   wire:navigate
                   data-testid="review-link"
                   class="kv-btn-primary w-full">
                    {{ $hasReview ? 'View your review' : 'Leave a review' }}
                </a>
            @endif
        </nav>
    </div>
</div>
