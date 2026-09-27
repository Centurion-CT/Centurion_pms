<div class="lp-dash">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Sales</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Newsletter subscribers
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                {{ number_format($totals['active']) }} active ·
                {{ number_format($totals['unsubscribed']) }} unsubscribed ·
                {{ number_format($totals['bounced']) }} bounced.
            </p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <a href="{{ route('admin.newsletter.export', ['status' => $statusFilter]) }}"
               class="lp-pill" data-testid="admin-newsletter-export">
                Export CSV
                <span class="lp-pill-dot">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2v6M3 5l3 3 3-3M2 10h8"/></svg>
                </span>
            </a>
        </div>
    </div>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Filter</span>
        </div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="nl-search" class="lp-form-label">Search</label>
                <input id="nl-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Email address" class="lp-input"
                       data-testid="admin-newsletter-search">
            </div>
            <div>
                <label for="nl-status" class="lp-form-label">Status</label>
                <select id="nl-status" wire:model.live="statusFilter" class="lp-input">
                    <option value="">All</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($subs->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">Nothing here.</p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="admin-newsletter-table">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Subscribed</th>
                            <th>Unsubscribed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($subs as $s)
                            <tr data-testid="nl-row-{{ $s->id }}">
                                <td>
                                    <a href="mailto:{{ $s->email }}" style="color: var(--color-ink); font-weight: 500;">
                                        {{ $s->email }}
                                    </a>
                                </td>
                                <td class="text-[12.5px]" style="color: var(--color-ink-mute);">{{ $s->source }}</td>
                                <td>
                                    <select wire:change="markStatus({{ $s->id }}, $event.target.value)"
                                            class="lp-input" style="padding: 4px 8px; font-size: 12px;">
                                        @foreach ($statuses as $st)
                                            <option value="{{ $st }}" @selected($s->status === $st)>{{ ucfirst($st) }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                    {{ $s->subscribed_at?->diffForHumans() ?: '-' }}
                                </td>
                                <td class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                    {{ $s->unsubscribed_at?->diffForHumans() ?: '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <div class="mt-4">{{ $subs->links() }}</div>
    @endif
</div>
