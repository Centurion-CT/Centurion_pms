<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Branches</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Manage your branches
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Every location you run under this account, with a jump to per-branch
            rooms, photos, house rules and payouts.
        </p>
    </div>

    {{-- ── Existing branches ─────────────────────────────────────── --}}
    <section class="lp-dash-card" style="margin-top: 20px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Your branches</span>
            <span class="lp-dash-card-meta">{{ $branches->count() }} total</span>
        </div>

        <div class="overflow-x-auto">
            <table class="lp-table" data-testid="branches-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Location</th>
                        <th>Type</th>
                        <th>Public</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($branches as $branch)
                        <tr data-testid="branch-row-{{ $branch->slug }}">
                            <td style="color: var(--color-ink); font-weight: 500;">
                                {{ $branch->name }}
                                @if ($current && $current->getKey() === $branch->getKey())
                                    <span class="lp-tag lp-tag--confirmed" style="margin-left: 6px;">Current</span>
                                @endif
                                <div class="lp-mono text-[11px] mt-1" style="color: var(--color-ink-mute); letter-spacing: 0.04em;">
                                    /branches/{{ $branch->slug }}
                                </div>
                            </td>
                            <td style="color: var(--color-ink-soft);">
                                {{ trim(collect([$branch->city, $branch->state])->filter()->implode(', ')) ?: '-' }}
                            </td>
                            <td style="color: var(--color-ink-soft);">
                                {{ $branch->type?->label() ?? ucfirst((string) $branch->type?->value) }}
                            </td>
                            <td>
                                @if ($branch->is_live)
                                    <span class="lp-tag lp-tag--confirmed">Live</span>
                                @else
                                    <span class="lp-tag lp-tag--on-hold">Not visible</span>
                                @endif
                            </td>
                            <td style="text-align: right;">
                                <div class="inline-flex items-center gap-3">
                                    <a href="{{ route('tenant.branches.qr', $branch) }}"
                                       target="_blank" rel="noopener"
                                       data-testid="branch-qr-{{ $branch->slug }}"
                                       title="Download the printable in-lobby portal QR"
                                       style="color: var(--color-ink-soft); text-decoration: underline; text-underline-offset: 4px; font-size: 12.5px;">
                                        QR
                                    </a>
                                    <form method="POST" action="{{ route('tenant.branches.switch') }}" style="display:inline;">
                                        @csrf
                                        <input type="hidden" name="property_id" value="{{ $branch->getKey() }}">
                                        <input type="hidden" name="return_to" value="{{ route('tenant.settings.property') }}">
                                        <button type="submit" class="lp-btn"
                                                data-testid="branch-edit-{{ $branch->slug }}">
                                            Settings &rarr;
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Add a branch ──────────────────────────────────────────── --}}
    <section class="lp-dash-card" style="margin-top: 20px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Add a branch</span>
            <span class="lp-dash-card-meta">Photos, house rules + payouts are set on the branch's settings page after creation.</span>
        </div>

        <form wire:submit="create" style="display: grid; gap: 16px;">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="branch-name" class="lp-form-label">Branch name</label>
                    <input id="branch-name" type="text" wire:model="newName" required maxlength="120"
                           placeholder="e.g. Lagos Marina"
                           data-testid="new-branch-name" class="lp-input">
                    @error('newName') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="branch-type" class="lp-form-label">Type</label>
                    <select id="branch-type" wire:model="newType"
                            data-testid="new-branch-type" class="lp-input">
                        @foreach ($propertyTypes as $t)
                            <option value="{{ $t->value }}">{{ $t->label() ?? ucfirst($t->value) }}</option>
                        @endforeach
                    </select>
                    @error('newType') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="branch-address" class="lp-form-label">Address</label>
                    <input id="branch-address" type="text" wire:model="newAddress" required maxlength="240"
                           placeholder="Street + number"
                           data-testid="new-branch-address" class="lp-input">
                    @error('newAddress') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="branch-city" class="lp-form-label">City</label>
                    <input id="branch-city" type="text" wire:model="newCity" maxlength="120"
                           data-testid="new-branch-city" class="lp-input">
                </div>
                <div>
                    <label for="branch-state" class="lp-form-label">State</label>
                    <input id="branch-state" type="text" wire:model="newState" maxlength="120"
                           data-testid="new-branch-state" class="lp-input">
                </div>
            </div>

            <div>
                <button type="submit" class="lp-btn lp-btn--primary" data-testid="create-branch">
                    Create branch
                </button>
                <p class="lp-form-help mt-2">
                    New branches start un-published so guests don't see them on the switcher until you're ready.
                </p>
            </div>
        </form>
    </section>
</div>
