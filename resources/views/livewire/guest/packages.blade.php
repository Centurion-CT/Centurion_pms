<div class="mx-auto max-w-2xl px-4 py-12">
    <div class="kv-card space-y-6">
        <div>
            <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}"
               wire:navigate
               class="text-sm text-slate-500 hover:text-slate-700">
                &larr; Back to booking
            </a>
            <h1 class="kv-heading mt-2">Add-ons &amp; packages</h1>
            <p class="kv-muted mt-1">
                Booking {{ $booking->booking_ref }} &middot; {{ $booking->guest_name }}
            </p>
        </div>

        @error('package')
            <p class="text-sm text-red-600" data-testid="guest-packages-error">{{ $message }}</p>
        @enderror

        {{-- ── Already on the booking ──────────────────────────── --}}
        <section data-testid="selected-packages">
            <h2 class="text-sm font-semibold text-slate-900">Already on your booking</h2>
            @if ($selected->isEmpty())
                <p class="mt-2 text-sm text-slate-500">No add-ons yet.</p>
            @else
                <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200">
                    @foreach ($selected as $line)
                        <li class="flex items-start justify-between gap-3 px-3 py-2"
                            data-testid="selected-line-{{ $line->id }}">
                            <div>
                                <p class="text-sm font-medium text-slate-900">{{ $line->name_snapshot }}</p>
                                <p class="text-xs text-slate-500">
                                    Qty {{ $line->quantity }}
                                    @if ($line->added_at === 'post_booking')
                                        &middot; added after booking
                                    @endif
                                </p>
                            </div>
                            <div class="text-right text-sm">
                                @if ($line->is_complimentary_snapshot)
                                    <span class="font-medium text-emerald-700">Complimentary</span>
                                @else
                                    <span class="font-semibold text-slate-900">
                                        ₦{{ number_format($line->line_total) }}
                                    </span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ── Available to add ────────────────────────────────── --}}
        <section data-testid="available-packages">
            <h2 class="text-sm font-semibold text-slate-900">Available add-ons</h2>
            @if ($available->isEmpty())
                <p class="mt-2 text-sm text-slate-500">
                    No more add-ons to choose from right now.
                </p>
            @else
                <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200">
                    @foreach ($available as $pkg)
                        <li class="flex items-start justify-between gap-3 px-3 py-2"
                            data-testid="available-package-{{ $pkg->id }}">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-slate-900">{{ $pkg->name }}</p>
                                @if ($pkg->description)
                                    <p class="text-xs text-slate-500">{{ $pkg->description }}</p>
                                @endif
                                <p class="mt-1 text-xs">
                                    @if ($pkg->is_complimentary)
                                        <span class="font-medium text-emerald-700">Complimentary</span>
                                    @else
                                        <span class="font-semibold text-slate-900">
                                            ₦{{ number_format($pkg->effectivePrice()) }}
                                        </span>
                                        <span class="text-slate-500">&middot; added to your outstanding balance</span>
                                    @endif
                                </p>
                            </div>
                            <button type="button"
                                    wire:click="add({{ $pkg->id }})"
                                    wire:loading.attr="disabled"
                                    data-testid="add-package-{{ $pkg->id }}"
                                    class="kv-btn-primary text-sm whitespace-nowrap">
                                <span wire:loading.remove wire:target="add({{ $pkg->id }})">Add</span>
                                <span wire:loading wire:target="add({{ $pkg->id }})">Adding…</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($outstandingTotal > 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm">
                <p class="font-semibold text-amber-800">
                    Outstanding balance: ₦{{ number_format($outstandingTotal) }}
                </p>
                <a href="/guest/booking/{{ $booking->booking_ref }}/balance?token={{ $token }}"
                   wire:navigate
                   class="mt-1 inline-block font-medium text-amber-900 underline">
                    Pay outstanding balance &rarr;
                </a>
            </div>
        @endif
    </div>
</div>
