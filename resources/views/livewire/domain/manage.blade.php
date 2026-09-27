@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
    $domainStatus = $tenant->custom_domain_status?->value;
    $domainStatusClass = match ($domainStatus) {
        'active'  => 'lp-tag--confirmed',
        'pending' => 'lp-tag--pending',
        'error'   => 'lp-tag--cancelled',
        default   => 'lp-tag--pending',
    };
@endphp

<div class="lp-dash">

    {{-- ── Page header ────────────────────────────────────────────── --}}
    <div>
        <span class="lp-eyebrow-strip">Business</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Domain
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Where guests find your booking page.
        </p>
    </div>

    {{-- Save confirmations surface via the global notify modal. --}}

    {{-- ── Subdomain ────────────────────────────────────────────── --}}
    <section data-testid="slug-section" class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Subdomain</span>
            <span class="lp-dash-card-meta">included with every plan</span>
        </div>
        <p class="text-[13.5px]" style="color: var(--color-ink-soft);">
            Your booking site lives at
            <span class="lp-mono" style="color: var(--color-ink); font-weight: 500;">{{ $tenant->slug }}.keverna.com</span>.
            You can rename it once per year and the old URL keeps redirecting for 90 days.
        </p>

        <form wire:submit="renameSlug" data-testid="slug-form" class="mt-5" style="display: grid; gap: 14px;">
            <div>
                <label for="slug-input" class="lp-form-label">New slug</label>
                <div class="flex items-center gap-3">
                    <input id="slug-input" type="text" wire:model="slug"
                           data-testid="slug-input" required class="lp-input" style="max-width: 280px;">
                    <span class="lp-mono text-[13px]" style="color: var(--color-ink-mute);">.keverna.com</span>
                </div>
                @error('slug') <p data-error="slug" class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end">
                <button type="submit" class="lp-btn lp-btn--primary">
                    <span wire:loading.remove wire:target="renameSlug">Rename slug</span>
                    <span wire:loading wire:target="renameSlug">Renaming…</span>
                </button>
            </div>
        </form>
    </section>

    {{-- ── Custom domain ────────────────────────────────────────── --}}
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Custom domain</span>
            @if ($tenant->custom_domain)
                <span data-testid="domain-status" class="lp-tag {{ $domainStatusClass }}">
                    {{ $domainStatus ?? 'pending' }}
                </span>
            @endif
        </div>

        <p class="text-[13.5px]" style="color: var(--color-ink-soft);">
            Use your own domain
            <span class="lp-mono" style="color: var(--color-ink);">reservations.yourbrand.com</span>
            for your booking page. Your guests never see a Keverna URL.
        </p>

        <form wire:submit="attach" data-testid="attach-form" class="mt-5" style="display: grid; gap: 14px;">
            <div>
                <label class="lp-form-label">Custom domain</label>
                <input type="text" wire:model="custom_domain"
                       placeholder="reservations.yourbrand.com" required class="lp-input">
                @error('custom_domain') <p data-error="custom_domain" class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end">
                <button type="submit" class="lp-pill">
                    <span wire:loading.remove wire:target="attach">
                        {{ $tenant->custom_domain ? 'Update domain' : 'Attach domain' }}
                    </span>
                    <span wire:loading wire:target="attach">Saving…</span>
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </button>
            </div>
        </form>

        {{-- ── DNS instructions ──────────────────────────────────── --}}
        @if ($tenant->custom_domain)
            <div data-testid="dns-instructions" class="mt-6 pt-6"
                 style="border-top: 1px solid var(--color-line-soft); display: grid; gap: 16px;">
                <div>
                    <p class="lp-form-label">DNS records to add</p>
                    <p class="text-[13px] mt-1" style="color: var(--color-ink-soft);">
                        Add the two records below at your domain registrar. We verify automatically every
                        15 minutes for up to 48 hours.
                    </p>
                </div>

                <div class="overflow-x-auto" style="border: 1px solid var(--color-line); border-radius: 12px;">
                    <table class="lp-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Name</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <span class="lp-tag lp-tag--pending" style="font-size: 10px;">CNAME</span>
                                </td>
                                <td>
                                    <span class="lp-mono text-[11.5px]" style="color: var(--color-ink-soft);">
                                        {{ $tenant->custom_domain }}
                                    </span>
                                </td>
                                <td>
                                    <span data-testid="cname-target" class="lp-mono text-[11.5px]"
                                          style="color: var(--color-ink); font-weight: 500;">
                                        {{ $cnameTarget }}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <span class="lp-tag lp-tag--pending" style="font-size: 10px;">TXT</span>
                                </td>
                                <td>
                                    <span class="lp-mono text-[11.5px]" style="color: var(--color-ink-soft);">
                                        {{ $tenant->custom_domain }}
                                    </span>
                                </td>
                                <td>
                                    <span data-testid="txt-token" class="lp-mono text-[11.5px]"
                                          style="color: var(--color-ink); font-weight: 500; word-break: break-all;">
                                        {{ $tenant->custom_domain_verification_token }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end">
                    <button type="button" wire:click="reverify" data-testid="reverify" class="lp-btn">
                        <svg width="13" height="13" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11.5 3.5v3h-3M2.5 10.5v-3h3"/>
                            <path d="M11 6a4 4 0 0 0-7.6-1M3 8a4 4 0 0 0 7.6 1"/>
                        </svg>
                        <span wire:loading.remove wire:target="reverify">Re-check now</span>
                        <span wire:loading wire:target="reverify">Checking…</span>
                    </button>
                </div>
            </div>
        @endif
    </section>
</div>
