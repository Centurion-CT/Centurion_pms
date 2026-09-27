<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Platform</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            AI spend
        </h1>
        <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute); max-width: 60ch;">
            Live audit of every external content-extraction call from the concierge
            import pipeline. Read-only; enforcement is at the service layer via the
            <span class="lp-mono">CONTENT_IMPORT_*_CAP_KOBO</span> environment values.
        </p>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        @php
            $pct = $hardCap > 0 ? min(100, (int) round($spendKobo * 100 / $hardCap)) : 0;
            $spendColor = $spendKobo >= $softCap ? '#B85C42' : 'var(--color-ink)';
        @endphp
        <div class="lp-dash-card" data-testid="ai-spend-total">
            <div class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                Spent this month
            </div>
            <div class="lp-mono mt-1" style="font-size: 28px; font-weight: 700; color: {{ $spendColor }};">
                ₦{{ number_format((int) ($spendKobo / 100)) }}
            </div>
            <div class="text-[12px]" style="color: var(--color-ink-mute);">
                {{ $pct }}% of hard cap
            </div>
        </div>
        <div class="lp-dash-card">
            <div class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                Soft cap
            </div>
            <div class="lp-mono mt-1" style="font-size: 22px; font-weight: 700; color: var(--color-ink);">
                ₦{{ number_format((int) ($softCap / 100)) }}
            </div>
            <div class="text-[12px]" style="color: var(--color-ink-mute);">
                Alert threshold, does not block calls
            </div>
        </div>
        <div class="lp-dash-card">
            <div class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                Hard cap
            </div>
            <div class="lp-mono mt-1" style="font-size: 22px; font-weight: 700; color: var(--color-ink);">
                ₦{{ number_format((int) ($hardCap / 100)) }}
            </div>
            <div class="text-[12px]" style="color: var(--color-ink-mute);">
                New calls refused beyond this
            </div>
        </div>
    </div>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">By provider · this month</span>
        </div>
        <table class="lp-table">
            <thead>
                <tr>
                    <th>Provider</th>
                    <th class="text-right">Calls</th>
                    <th class="text-right">Errors</th>
                    <th class="text-right">Cost (₦)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($byProvider as $row)
                    <tr>
                        <td class="lp-mono text-[11px]" style="text-transform: uppercase; letter-spacing: 0.06em;">
                            {{ $row->provider }}
                        </td>
                        <td class="text-right lp-mono">{{ (int) $row->calls }}</td>
                        <td class="text-right lp-mono" style="color: {{ $row->errors > 0 ? '#B85C42' : 'inherit' }};">
                            {{ (int) $row->errors }}
                        </td>
                        <td class="text-right lp-mono">
                            ₦{{ number_format((int) ($row->cost_kobo / 100)) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-[12.5px]" style="color: var(--color-ink-mute); text-align: center;">
                            No extractions this month yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Recent extractions</span>
            <span class="lp-dash-card-meta">latest 50</span>
        </div>
        <table class="lp-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Provider</th>
                    <th>Model</th>
                    <th>URL</th>
                    <th class="text-right">In</th>
                    <th class="text-right">Out</th>
                    <th class="text-right">Cache</th>
                    <th class="text-right">Cost</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recent as $r)
                    <tr>
                        <td class="lp-mono text-[11px]" style="color: var(--color-ink-soft); white-space: nowrap;">
                            {{ $r->ran_at?->format('M j · g:i A') }}
                        </td>
                        <td class="lp-mono text-[11px]" style="text-transform: uppercase;">
                            {{ $r->provider }}
                        </td>
                        <td class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                            {{ $r->model ?? '-' }}
                        </td>
                        <td class="text-[11.5px]" style="max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            {{ $r->source_url ?? '-' }}
                        </td>
                        <td class="text-right lp-mono">{{ number_format((int) $r->tokens_in) }}</td>
                        <td class="text-right lp-mono">{{ number_format((int) $r->tokens_out) }}</td>
                        <td class="text-right lp-mono">{{ number_format((int) $r->cache_read_tokens) }}</td>
                        <td class="text-right lp-mono">
                            ₦{{ number_format((int) ($r->cost_kobo / 100)) }}
                        </td>
                        <td>
                            <span class="lp-mono text-[10px]"
                                  style="padding: 2px 6px; border-radius: 999px; text-transform: uppercase;
                                         background: {{ $r->succeeded ? '#D1FAE5' : '#FEE2E2' }};
                                         color: {{ $r->succeeded ? '#065F46' : '#7F1D1D' }};">
                                {{ $r->succeeded ? 'OK' : 'ERR' }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
