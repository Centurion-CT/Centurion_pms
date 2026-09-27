@php
    $totalLow = collect($summaries)->sum(fn ($s) => (int) $s['low']);
    $totalOutlets = $summaries->count();
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + attention side panel ─────────────
         Identity block on the left; right side is the rolled
         up "N low across M rooms" pulse so the store keeper
         reads the state without opening a card. --}}
    <section class="lp-res-idx-hero" data-testid="supplies-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Operate · Housekeeping</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Supplies
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Housekeeping supply rooms, soaps, towels, bedsheets, amenities. See what&rsquo;s left before a turnover; raise a request to the store keeper when the trolley is empty.
                </p>
            </div>
            @if ($totalOutlets > 0)
                <aside class="lp-res-idx-hero-money" aria-label="Supplies attention">
                    <div class="lp-ih-hero-accrued">
                        <p class="lp-ih-hero-accrued-label">Attention</p>
                        <p class="lp-ih-hero-accrued-value {{ $totalLow > 0 ? 'lp-ih-hero-accrued-value--warn' : '' }}"
                           style="{{ $totalLow === 0 ? 'color: var(--color-accent-deep);' : '' }}"
                           data-testid="supplies-hero-low">
                            {{ number_format($totalLow) }}
                        </p>
                        <p class="lp-ih-hero-accrued-sub">
                            @if ($totalLow === 0)
                                Every supply room stocked
                            @else
                                low across {{ $totalOutlets }} {{ Str::plural('room', $totalOutlets) }}
                            @endif
                        </p>
                    </div>
                </aside>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="lp-dash-card" style="border-left: 4px solid var(--color-accent-deep);">
            <p class="text-[13.5px]" style="color: var(--color-ink);">{{ session('status') }}</p>
        </div>
    @endif

    @if ($summaries->isEmpty())
        <section class="lp-dash-card" data-testid="supplies-empty">
            @include('livewire.dashboard._partials._empty', [
                'icon'     => 'box',
                'title'    => 'No housekeeping supply room set up yet.',
                'sub'      => 'A Manager can add one from Inventory > New outlet. Pick "Housekeeping" as the type; once it exists, everyone with supplies access sees it here.',
                'ctaLabel' => 'New outlet',
                'ctaHref'  => route('tenant.inventory.outlets.create'),
            ])
        </section>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="supplies-grid">
            @foreach ($summaries as $s)
                @php
                    $outlet = $s['outlet'];
                    $lowLabel = $s['low'] === 0 ? 'all stocked' : $s['low'].' low';
                    $lowColor = $s['low'] === 0
                        ? 'var(--color-ink-mute)'
                        : '#B85C42';
                @endphp
                <a href="{{ route('tenant.supplies.show', $outlet) }}"
                   wire:navigate
                   class="lp-dash-card"
                   data-testid="supplies-card-{{ $outlet->id }}"
                   style="display: block; text-decoration: none; color: inherit;">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="lp-eyebrow-strip" style="color: var(--color-ink-mute);">
                                Housekeeping
                            </div>
                            <h2 class="mt-1 text-[17px]"
                                style="color: var(--color-ink); font-weight: 600;">
                                {{ $outlet->name }}
                            </h2>
                        </div>
                        @if ($usesCentralStore && $s['pending'] > 0)
                            <span class="lp-mono text-[10.5px]"
                                  style="padding: 3px 8px; border-radius: 999px;
                                         background: #FEF3C7; color: #92400E;
                                         letter-spacing: 0.06em; text-transform: uppercase;">
                                {{ $s['pending'] }} pending
                            </span>
                        @endif
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-3">
                        <div>
                            <dt class="text-[10.5px]"
                                style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                                Products
                            </dt>
                            <dd class="lp-mono mt-1"
                                style="font-size: 20px; font-weight: 600; color: var(--color-ink);">
                                {{ $s['total'] }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[10.5px]"
                                style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                                Attention
                            </dt>
                            <dd class="lp-mono mt-1"
                                style="font-size: 20px; font-weight: 600; color: {{ $lowColor }};">
                                {{ $lowLabel }}
                            </dd>
                        </div>
                    </dl>

                    {{-- Names the specific low SKUs so the operator doesn't
                         need to open the card just to answer "which two?" --}}
                    @if (! empty($s['low_names']))
                        <p class="mt-3 text-[12px]"
                           style="color: var(--color-ink-mute);"
                           data-testid="supplies-card-low-names-{{ $outlet->id }}">
                            {{ implode(', ', $s['low_names']) }}@if ($s['low_extra'] > 0)
                                <span> +{{ $s['low_extra'] }} more</span>
                            @endif
                        </p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</div>
