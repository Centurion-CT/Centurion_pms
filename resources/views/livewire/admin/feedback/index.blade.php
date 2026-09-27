<div class="lp-dash">

    <x-dash-toolbar
        eyebrow="Voice of operator"
        title="Feedback"
        subtitle="{{ number_format($total) }} response(s) across every dashboard module." />

    {{-- Per-module rollup --}}
    @if ($rollup->isNotEmpty())
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 22px;">
            @foreach ($rollup as $row)
                <div class="lp-card" style="padding: 14px 16px;">
                    <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: #6B6B6B;">
                        {{ $row->module }}
                    </div>
                    <div style="display: flex; align-items: baseline; gap: 8px; margin-top: 4px;">
                        <span style="font-size: 22px; font-weight: 700;">
                            <span style="color: #E7AB2E;">★</span> {{ number_format($row->avg_rating, 1) }}
                        </span>
                        <span style="font-size: 12px; color: #6B6B6B;">
                            {{ $row->count }} {{ $row->count === 1 ? 'response' : 'responses' }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Filters --}}
    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 16px; flex-wrap: wrap;">
        <select wire:model.live="moduleFilter" class="lp-input" style="max-width: 200px;">
            <option value="">All modules</option>
            @foreach ($modules as $m)
                <option value="{{ $m }}">{{ $m }}</option>
            @endforeach
        </select>
        <select wire:model.live="ratingFilter" class="lp-input" style="max-width: 200px;">
            <option value="">All ratings</option>
            @for ($r = 1; $r <= 5; $r++)
                <option value="{{ $r }}">{{ $r }} star{{ $r === 1 ? '' : 's' }}</option>
            @endfor
        </select>
    </div>

    {{-- Table --}}
    @if ($submissions->isEmpty())
        <div class="lp-card" style="padding: 32px; text-align: center; color: #6B6B6B;">
            No feedback yet. Once operators start responding to the in-dashboard prompt,
            answers land here in real time.
        </div>
    @else
        <div class="lp-card" style="padding: 0; overflow: hidden;">
            <table class="lp-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="text-align: left;">Operator</th>
                        <th style="text-align: left;">Tenant</th>
                        <th style="text-align: left;">Module</th>
                        <th style="text-align: left;">Rating</th>
                        <th style="text-align: left;">Feedback</th>
                        <th style="text-align: left;">When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($submissions as $s)
                        <tr data-testid="feedback-row-{{ $s->id }}">
                            <td>
                                <div style="font-weight: 600;">{{ $s->operator?->name ?? '—' }}</div>
                                <div style="font-size: 12px; color: #6B6B6B;">{{ $s->operator?->email ?? '' }}</div>
                            </td>
                            <td style="color: #444;">{{ $s->tenant?->name ?? '—' }}</td>
                            <td>
                                <span class="lp-tag">{{ $s->module }}</span>
                            </td>
                            <td>
                                <span style="color: #E7AB2E;">{!! str_repeat('★', $s->rating) !!}</span><span style="color: #DADADA;">{!! str_repeat('★', 5 - $s->rating) !!}</span>
                            </td>
                            <td style="max-width: 420px; color: #333;">
                                {{ $s->body ?: '—' }}
                                @if ($s->route)
                                    <div style="font-size: 11px; color: #999; margin-top: 4px;">{{ $s->route }}</div>
                                @endif
                            </td>
                            <td style="white-space: nowrap; color: #6B6B6B; font-size: 12px;">
                                {{ $s->created_at?->diffForHumans() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top: 12px;">
            {{ $submissions->links() }}
        </div>
    @endif
</div>
