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
            <form method="POST" action="{{ route('central.logout') }}">
                @csrf
                <button type="submit" class="text-[13.5px]" style="color: var(--color-ink-soft);">Sign out</button>
            </form>
        </div>
    </header>

    <section class="lp-card lp-card-cream">
        <div class="lp-card-body">
            <span class="lp-eyebrow-strip">Signed in as {{ $identity->name }}</span>
            <h1 class="lp-display mt-4 text-[clamp(28px,4vw,44px)]" style="color: var(--color-ink); line-height: 1.06;">
                Where to?
            </h1>
            <p class="mt-3 max-w-[520px] text-[15px] leading-[1.65]" style="color: var(--color-ink-soft);">
                Pick where you want to continue. You can come back to this screen any time from your account menu.
            </p>

            @if ($links->isEmpty() && ! $hasGuestLinks)
                <div class="mt-10 p-6" style="background: var(--color-card-deep); border-radius: 16px;">
                    <p class="lp-display text-[18px]" style="color: var(--color-ink);">No linked accounts yet.</p>
                    <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft);">
                        Your identity is set up but not attached to a property or a reservation. If you were expecting to see a
                        property here, contact <a href="mailto:hello@keverna.com" style="text-decoration: underline;">hello@keverna.com</a>.
                    </p>
                </div>
            @else
                <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">

                    @foreach ($links as $link)
                        <a href="{{ route('central.handoff', ['link' => $link->id]) }}"
                           class="lp-tile block p-6 sm:p-7 hover:shadow-lg transition-shadow"
                           style="background: var(--color-card-deep); border-radius: 16px;"
                           data-testid="chooser-operator-card"
                           data-link-id="{{ $link->id }}">
                            <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase" style="color: var(--color-ink-mute);">
                                Property
                            </p>
                            <p class="lp-display mt-2 text-[20px]" style="color: var(--color-ink); font-weight: 600;">
                                {{ $link->tenant->name ?? $link->tenant->slug }}
                            </p>
                            <p class="mt-1 text-[13px]" style="color: var(--color-ink-soft);">
                                {{ $link->operator->name }}
                            </p>
                            <div class="mt-6 inline-flex items-center gap-2 text-[13px]" style="color: var(--color-ink);">
                                Open dashboard
                                <span aria-hidden>{!! $pillArrow !!}</span>
                            </div>
                        </a>
                    @endforeach

                    @if ($hasGuestLinks)
                        <a href="{{ route('central.reservations') }}"
                           class="lp-tile block p-6 sm:p-7 hover:shadow-lg transition-shadow"
                           style="background: var(--color-card-deep); border-radius: 16px;"
                           data-testid="chooser-guest-card">
                            <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase" style="color: var(--color-ink-mute);">
                                Reservations
                            </p>
                            <p class="lp-display mt-2 text-[20px]" style="color: var(--color-ink); font-weight: 600;">
                                My reservations
                            </p>
                            <div class="mt-6 inline-flex items-center gap-2 text-[13px]" style="color: var(--color-ink);">
                                View all
                                <span aria-hidden>{!! $pillArrow !!}</span>
                            </div>
                        </a>
                    @endif

                </div>
            @endif
        </div>
    </section>

</div>
