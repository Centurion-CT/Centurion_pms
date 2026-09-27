<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">POS</span>
        <div class="flex flex-wrap items-start justify-between gap-3 mt-3">
            <div>
                <h1 class="lp-display text-[clamp(26px,3.4vw,36px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    Pick an outlet
                </h1>
                <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                    You&rsquo;ll open the POS terminal for the outlet you choose.
                </p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('tenant.pos.link-device') }}" wire:navigate class="lp-btn" data-testid="pos-picker-link-device">Link phone</a>
                <a href="{{ route('tenant.pos.sales.index') }}" wire:navigate class="lp-btn">Recent sales</a>
            </div>
        </div>
    </div>

    @if ($outlets->isEmpty())
        <section class="lp-dash-card" data-testid="picker-empty" style="text-align: center; padding: 48px 24px;">
            <h2 class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">
                {{ $isManager ? 'No active outlets yet.' : 'No outlets assigned to you.' }}
            </h2>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute); line-height: 1.5;">
                @if ($isManager)
                    Create one in the Inventory module.
                @else
                    Ask a manager to assign you to an outlet from Inventory &rarr; the outlet &rarr; Edit.
                @endif
            </p>
            @if ($isManager)
                <a href="{{ route('tenant.inventory.outlets.create') }}" wire:navigate class="lp-btn lp-btn--primary mt-4" style="display: inline-block;">Create outlet</a>
            @endif
        </section>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="picker-grid">
            @foreach ($outlets as $outlet)
                <a href="{{ route('tenant.pos.terminal', $outlet) }}" wire:navigate
                   class="lp-dash-card lp-integration-card"
                   data-testid="pick-outlet-{{ $outlet->slug }}"
                   style="display: grid; gap: 12px; text-decoration: none; color: inherit;">
                    <span class="lp-mono text-[10.5px]"
                          style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">
                        {{ $outlet->type->label() }}
                    </span>
                    <h2 class="text-[22px]" style="color: var(--color-ink); font-weight: 600; line-height: 1.05;">
                        {{ $outlet->name }}
                    </h2>
                    <span class="lp-mono text-[11px] mt-1"
                          style="color: var(--color-accent-deep); letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;">
                        Open terminal &rarr;
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</div>
