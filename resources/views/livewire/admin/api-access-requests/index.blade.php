<div class="lp-dash">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lp-eyebrow-strip">Sales</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                API early-access waitlist
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Developers submitting from /api-access on the marketing site.
            </p>
        </div>
    </div>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Filter</span>
        </div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="ar-search" class="lp-form-label">Search</label>
                <input id="ar-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Name, email, company, use case" class="lp-input"
                       data-testid="admin-api-search">
            </div>
            <div>
                <label for="ar-status" class="lp-form-label">Status</label>
                <select id="ar-status" wire:model.live="statusFilter" class="lp-input"
                        data-testid="admin-api-status">
                    <option value="">All</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($requests->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="no-api-requests" class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">
                Nothing here.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="admin-api-table">
                    <thead>
                        <tr>
                            <th>Developer</th>
                            <th>Company</th>
                            <th>Use case</th>
                            <th>Integrations</th>
                            <th>Status</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $r)
                            <tr data-testid="ar-row-{{ $r->id }}">
                                <td>
                                    <div style="font-weight: 600; color: var(--color-ink);">{{ $r->name }}</div>
                                    <div class="lp-mono text-[11.5px]" style="color: var(--color-ink-mute);">
                                        <a href="mailto:{{ $r->email }}">{{ $r->email }}</a>
                                    </div>
                                    @if ($r->github_url)
                                        <div class="lp-mono text-[11px] mt-1">
                                            <a href="{{ $r->github_url }}" target="_blank" rel="noopener"
                                               style="color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">
                                                {{ str_replace(['https://', 'http://'], '', $r->github_url) }}
                                            </a>
                                        </div>
                                    @endif
                                </td>
                                <td class="text-[13px]">{{ $r->company ?: '-' }}</td>
                                <td style="max-width: 320px; font-size: 13px; color: var(--color-ink-soft);">
                                    {{ $r->use_case ?: '-' }}
                                </td>
                                <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                    {{ $r->integrations ?: '-' }}
                                </td>
                                <td>
                                    <select wire:change="markStatus({{ $r->id }}, $event.target.value)"
                                            class="lp-input" style="padding: 4px 8px; font-size: 12px;"
                                            data-testid="ar-status-{{ $r->id }}">
                                        @foreach ($statuses as $s)
                                            <option value="{{ $s }}" @selected($r->status === $s)>
                                                {{ ucfirst($s) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                    {{ $r->created_at?->diffForHumans() }}
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
