<div class="lp-dash">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Sales</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Demo requests
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Leads coming in through /demo on the marketing site.
                Work the "New" tab first.
            </p>
        </div>
    </div>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Filter</span>
        </div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="dr-search" class="lp-form-label">Search</label>
                <input id="dr-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Name, property, email, phone" class="lp-input"
                       data-testid="admin-demo-search">
            </div>
            <div>
                <label for="dr-status" class="lp-form-label">Status</label>
                <select id="dr-status" wire:model.live="statusFilter" class="lp-input"
                        data-testid="admin-demo-status">
                    <option value="">All</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s }}">{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($requests->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="no-demos" class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">
                Nothing here.
            </p>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute);">
                No demo requests match the current filter.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="admin-demo-table">
                    <thead>
                        <tr>
                            <th>Prospect</th>
                            <th>Property</th>
                            <th>Contact</th>
                            <th>Size / When</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>When</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $r)
                            <tr data-testid="dr-row-{{ $r->id }}">
                                <td>
                                    <div style="font-weight: 600; color: var(--color-ink);">{{ $r->name }}</div>
                                    @if (! empty($issuedCodes[$r->id]))
                                        <div class="lp-mono text-[11px] mt-1"
                                             style="padding: 3px 8px; background: rgba(138, 174, 58, 0.14); color: var(--color-accent-deep); border-radius: 6px; display: inline-block;"
                                             data-testid="dr-issued-{{ $r->id }}">
                                            {{ $issuedCodes[$r->id] }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div>{{ $r->property_name }}</div>
                                    @if ($r->city)
                                        <div class="text-[11.5px]" style="color: var(--color-ink-mute);">{{ $r->city }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="lp-mono text-[12px]">
                                        <a href="https://wa.me/{{ preg_replace('/\D+/', '', $r->phone) }}"
                                           target="_blank" rel="noopener"
                                           style="color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">
                                            {{ $r->phone }}
                                        </a>
                                    </div>
                                    <div class="lp-mono text-[11.5px]" style="color: var(--color-ink-mute);">
                                        <a href="mailto:{{ $r->email }}">{{ $r->email }}</a>
                                    </div>
                                </td>
                                <td class="text-[12.5px]">
                                    {{ $r->property_size ?: '-' }}
                                    <div style="color: var(--color-ink-mute);">
                                        {{ $r->preferred_window ?: 'anytime' }}
                                    </div>
                                </td>
                                <td>
                                    <select wire:change="markStatus({{ $r->id }}, $event.target.value)"
                                            class="lp-input" style="padding: 4px 8px; font-size: 12px;"
                                            data-testid="dr-status-{{ $r->id }}">
                                        @foreach ($statuses as $s)
                                            <option value="{{ $s }}" @selected($r->status === $s)>
                                                {{ ucfirst(str_replace('_', ' ', $s)) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="text-[11.5px]" style="color: var(--color-ink-mute);">{{ $r->source }}</td>
                                <td class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                    {{ $r->created_at?->diffForHumans() }}
                                </td>
                                <td style="text-align: right;">
                                    @if (empty($issuedCodes[$r->id]))
                                        <button type="button" wire:click="issueCode({{ $r->id }})"
                                                class="lp-btn lp-btn--sm"
                                                data-testid="dr-issue-{{ $r->id }}">
                                            Issue code
                                        </button>
                                    @else
                                        <span class="text-[11.5px]" style="color: var(--color-accent-deep); font-weight: 600;">
                                            ✓ Issued
                                        </span>
                                    @endif
                                    @if ($r->notes)
                                        <button type="button"
                                                x-data
                                                @click="alert(@js($r->notes))"
                                                class="lp-btn lp-btn--sm lp-btn--ghost"
                                                style="margin-left: 6px;">
                                            Notes
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-4">{{ $requests->links() }}</div>
    @endif
</div>
