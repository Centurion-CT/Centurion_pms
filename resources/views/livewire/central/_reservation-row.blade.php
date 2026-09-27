{{-- Guest-dashboard reservation row. Pure presentation, the Reservations
     component + GuestReservationLookup have already resolved tenant +
     room + status. All amounts render in NGN kobo -> naira via number_format. --}}
<li class="p-5 sm:p-6" style="background: var(--color-card-deep); border-radius: 14px;"
    data-testid="reservation-row"
    data-booking-ref="{{ $booking->booking_ref }}">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex-1">
            <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase" style="color: var(--color-ink-mute);">
                {{ $booking->tenant->name ?? $booking->tenant->slug }}
            </p>
            <p class="lp-display mt-1 text-[17px] sm:text-[19px]" style="color: var(--color-ink); font-weight: 600;">
                {{ $booking->room->name ?? 'Reservation' }}
            </p>
            <p class="mt-1.5 text-[13.5px]" style="color: var(--color-ink-soft);">
                {{ $booking->checkin_date->format('D d M Y') }}
                &nbsp;→&nbsp;
                {{ $booking->checkout_date->format('D d M Y') }}
                &nbsp;·&nbsp;
                {{ $booking->nights }} night{{ $booking->nights === 1 ? '' : 's' }}
            </p>
            <p class="mt-1 text-[12.5px]" style="color: var(--color-ink-mute);">
                Ref {{ $booking->booking_ref }} · {{ $booking->booking_status->value }}
                @if ($booking->balance_due > 0)
                    · <span style="color: var(--color-accent-deep);">₦{{ number_format($booking->balance_due) }} balance due</span>
                @endif
            </p>
        </div>
        <div class="flex flex-col items-start gap-1 sm:items-end">
            <p class="lp-display text-[18px]" style="color: var(--color-ink); font-weight: 600;">
                ₦{{ number_format($booking->total_amount) }}
            </p>
            <p class="text-[11.5px]" style="color: var(--color-ink-mute);">
                {{ ucfirst($booking->payment_status->value) }}
            </p>
        </div>
    </div>
</li>
