<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Store</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Central store isn&rsquo;t turned on for this branch.
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft); max-width: 60ch;">
            @if ($property !== null)
                <strong>{{ $property->name }}</strong> is currently on
            @else
                This branch is currently on
            @endif
            <span class="lp-mono">per-department</span> inventory, where each
            outlet (bar, kitchen, laundry, gift shop, housekeeping) owns its
            own stock and receives directly from suppliers. There&rsquo;s no
            central store to receive into, issue from, or raise purchase
            orders against, so the Store and Purchasing surfaces are hidden.
        </p>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft); max-width: 60ch;">
            To restock in this mode, open the outlet under Inventory and
            hit <strong>Receive stock</strong>. Switch to
            <span class="lp-mono">central store + requisition</span> in
            property settings if you&rsquo;d rather have one stock keeper
            receive everything and issue it out on daily requests.
        </p>
    </div>

    <div class="lp-dash-card" style="display: grid; gap: 14px;">
        <div>
            <div class="lp-eyebrow-strip" style="color: var(--color-ink-mute);">Current mode</div>
            <div class="mt-1 text-[15px]" style="color: var(--color-ink);">
                {{ $currentMode->label() }}
            </div>
            <p class="mt-1 text-[13px]" style="color: var(--color-ink-mute); max-width: 60ch;">
                {{ $currentMode->description() }}
            </p>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('tenant.settings.property') }}"
               wire:navigate
               class="lp-btn lp-btn--primary"
               data-testid="store-off-open-settings">
                Open property settings
            </a>
            <a href="{{ route('tenant.inventory.index') }}"
               wire:navigate
               class="lp-btn"
               data-testid="store-off-open-inventory">
                Go to Inventory instead
            </a>
        </div>
    </div>
</div>
