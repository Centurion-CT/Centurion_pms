<div class="lp-dash">

    {{-- ── Hero: title + connection status ────────────────
         Right side shows how many integrations are already
         connected out of the total catalog. --}}
    @php
        $connectedCount = collect($integrations)->where('connected', true)->count();
        $totalIntegrations = count($integrations);
    @endphp
    <section class="lp-res-idx-hero" data-testid="integrations-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Business</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Integrations
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Connect your business to the services you already use. Each integration is optional. Anything not connected keeps running on the platform default.
                </p>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Integration count">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Connected</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ $connectedCount > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};"
                       data-testid="integrations-connected-count">
                        {{ number_format($connectedCount) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        of {{ number_format($totalIntegrations) }} in the catalog
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Catalog grid ──────────────────────────────────────────── --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="integrations-catalog">
        @foreach ($integrations as $it)
            <a href="{{ route($it['route']) }}" wire:navigate
               class="lp-dash-card lp-integration-card"
               data-testid="integration-{{ $it['key'] }}"
               style="display: grid; gap: 12px; text-decoration: none; color: inherit;">

                <div class="flex items-start justify-between gap-3">
                    <span class="lp-mono text-[10.5px]"
                          style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">
                        {{ $it['category'] }}
                    </span>
                    <span class="lp-mono text-[10.5px]"
                          data-testid="integration-{{ $it['key'] }}-status"
                          style="display:inline-flex; align-items:center; gap:6px;
                                 padding: 3px 10px; border-radius: 999px;
                                 letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;
                                 background: {{ $it['connected'] ? 'var(--color-accent-soft, rgba(51, 103, 153,0.18))' : 'var(--color-card-deep)' }};
                                 color: {{ $it['connected'] ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};">
                        <span style="width:6px; height:6px; border-radius:50%;
                                     background: {{ $it['connected'] ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }}; display:inline-block;"></span>
                        {{ $it['connected'] ? 'Connected' : 'Not connected' }}
                    </span>
                </div>

                <div>
                    <h2 class="text-[20px]" style="color: var(--color-ink); font-weight: 600; line-height: 1.1;">
                        {{ $it['name'] }}
                    </h2>
                    <p class="mt-1 lp-mono text-[11.5px]" style="color: var(--color-ink-mute);">
                        {{ $it['status_line'] }}
                    </p>
                </div>

                <p class="text-[13px]" style="color: var(--color-ink-soft); line-height: 1.5;">
                    {{ $it['description'] }}
                </p>

                <div class="flex items-center justify-between mt-1">
                    <span class="lp-mono text-[11px]" style="color: var(--color-accent-deep); letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;">
                        {{ $it['connected'] ? 'Manage' : 'Configure' }} &rarr;
                    </span>
                </div>
            </a>
        @endforeach
    </div>

    <p class="text-[12.5px] mt-6" style="color: var(--color-ink-mute);">
        More integrations coming soon. If you need something specific, tell us at <a href="mailto:support@keverna.com" style="color: var(--color-accent-deep); text-decoration: underline;">support@keverna.com</a>.
    </p>
</div>
