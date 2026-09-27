@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-shell" style="padding-block: 0;">

    <header class="lp-card lp-card-cream" style="padding: 14px 18px;">
        <div class="flex items-center justify-between">
            <a href="/" class="flex items-center gap-2.5">
                <span aria-hidden class="inline-flex h-7 w-7 items-center justify-center"
                      style="background: var(--color-ink); color: var(--color-card); border-radius: 8px;">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/>
                    </svg>
                </span>
                <span class="lp-display text-[20px] tracking-tight" style="color: var(--color-ink); font-weight: 600;">Keverna</span>
            </a>
            <div class="flex items-center gap-5 text-[13.5px]" style="color: var(--color-ink-soft);">
                <a href="{{ route('central.choose') }}" style="text-decoration: underline; text-underline-offset: 4px;">Switch account</a>
                <form method="POST" action="{{ route('central.logout') }}" class="inline">
                    @csrf
                    <button type="submit">Sign out</button>
                </form>
            </div>
        </div>
    </header>

    <section class="lp-card lp-card-cream">
        <div class="lp-card-body">
            <span class="lp-eyebrow-strip">Signed in as {{ $identity->name }}</span>
            <h1 class="lp-display mt-4 text-[clamp(28px,4vw,44px)]" style="color: var(--color-ink); line-height: 1.06;">
                Your reservations.
            </h1>
            <p class="mt-3 max-w-[520px] text-[15px] leading-[1.65]" style="color: var(--color-ink-soft);">
                Every stay you have across every Keverna property, all in one place.
            </p>

            @if ($upcoming->isEmpty() && $past->isEmpty())
                <div class="mt-10 p-6" style="background: var(--color-card-deep); border-radius: 16px;">
                    <p class="lp-display text-[18px]" style="color: var(--color-ink);">Nothing here yet.</p>
                    <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft);">
                        We're linked to your email but haven't found any bookings tied to it. New reservations show up here
                        automatically the next time you book with a Keverna property.
                    </p>
                </div>
            @endif

            @if ($upcoming->isNotEmpty())
                <div class="mt-10">
                    <p class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">
                        Upcoming ({{ $upcoming->count() }})
                    </p>
                    <ul class="mt-4 grid gap-3">
                        @foreach ($upcoming as $booking)
                            @include('livewire.central._reservation-row', ['booking' => $booking])
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($past->isNotEmpty())
                <div class="mt-12">
                    <p class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">
                        Past stays ({{ $past->count() }})
                    </p>
                    <ul class="mt-4 grid gap-3">
                        @foreach ($past as $booking)
                            @include('livewire.central._reservation-row', ['booking' => $booking])
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </section>

</div>
