@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    <div>
        <span class="lp-eyebrow-strip">Team</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Members
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Invite team members, set per-person spend caps, suspend or offboard.
        </p>
    </div>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Invite member</span>
        </div>

        <form wire:submit="invite" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="invite-form">
            <div>
                <label class="lp-form-label" for="m-name">Name</label>
                <input id="m-name" type="text" wire:model.live="name" class="lp-input" required maxlength="160">
                @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="m-em">Work email</label>
                <input id="m-em" type="email" wire:model.live="email" class="lp-input" required maxlength="180">
                @error('email') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="m-ph">Phone</label>
                <input id="m-ph" type="text" wire:model.live="phone" class="lp-input" maxlength="32">
            </div>
            <div>
                <label class="lp-form-label" for="m-eid">Employee ID</label>
                <input id="m-eid" type="text" wire:model.live="employee_id" class="lp-input" maxlength="64" placeholder="Optional">
            </div>
            <div>
                <label class="lp-form-label" for="m-rl">Role</label>
                <select id="m-rl" wire:model.live="role" class="lp-input">
                    @foreach ($roles as $r)
                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                    @endforeach
                </select>
                <p class="lp-form-help" style="margin-top: 6px;">
                    Admins get a portal sign-in password. Members just book using their email.
                </p>
            </div>
            <div>
                <label class="lp-form-label" for="m-dc">Daily cap (₦)</label>
                <input id="m-dc" type="number" min="0" wire:model.live="daily_cap_naira" class="lp-input" placeholder="Unlimited">
            </div>
            <div>
                <label class="lp-form-label" for="m-mc">Monthly cap (₦)</label>
                <input id="m-mc" type="number" min="0" wire:model.live="monthly_cap_naira" class="lp-input" placeholder="Unlimited">
            </div>
            <div class="sm:col-span-2 lg:col-span-3 flex justify-end">
                <button type="submit" class="lp-pill" data-testid="invite-btn">
                    Invite member <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </button>
            </div>
        </form>
    </section>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head"><span class="lp-dash-card-title">Filter</span></div>
        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="mf-q">Search</label>
                <input id="mf-q" type="search" wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Name, email or employee ID">
            </div>
            <div>
                <label class="lp-form-label" for="mf-st">Status</label>
                <select id="mf-st" wire:model.live="statusFilter" class="lp-input">
                    <option value="">All</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    @if ($members->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p class="text-[18px]" style="color: var(--color-ink); font-weight: 500;">
                No members match the filter.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Daily cap</th>
                            <th>Monthly cap</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $self = auth('corporate')->user(); @endphp
                        @foreach ($members as $m)
                            @php
                                $stClass = match($m->status->value) {
                                    'active'    => 'lp-tag--confirmed',
                                    'invited'   => 'lp-tag--checked-in',
                                    default     => 'lp-tag--checked-out',
                                };
                            @endphp
                            <tr data-testid="member-row-{{ $m->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $m->name }}
                                    @if ($self !== null && $m->id === $self->getKey())
                                        <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 10.5px; margin-left: 6px;">(you)</span>
                                    @endif
                                </td>
                                <td style="color: var(--color-ink-soft);">{{ $m->email }}</td>
                                <td>
                                    <span class="lp-tag {{ $m->role->value === 'admin' ? 'lp-tag--confirmed' : 'lp-tag--checked-in' }}">
                                        {{ $m->role->label() }}
                                    </span>
                                </td>
                                <td><span class="lp-tag {{ $stClass }}">{{ $m->status->label() }}</span></td>
                                <td style="color: var(--color-ink-soft);">{{ $m->daily_cap_naira ? '₦'.number_format($m->daily_cap_naira) : '-' }}</td>
                                <td style="color: var(--color-ink-soft);">{{ $m->monthly_cap_naira ? '₦'.number_format($m->monthly_cap_naira) : '-' }}</td>
                                <td style="text-align: right;">
                                    <div class="inline-flex items-center gap-3 text-[12.5px]" style="color: var(--color-ink-soft);">
                                        <button type="button" wire:click="toggleStatus({{ $m->id }})"
                                                style="background: none; border: 0; cursor: pointer; color: var(--color-ink-soft);">
                                            {{ $m->status->value === 'active' ? 'Suspend' : 'Activate' }}
                                        </button>
                                        <button type="button" wire:click="offboard({{ $m->id }})"
                                                wire:confirm="Offboard this member? They lose portal access immediately."
                                                style="background: none; border: 0; cursor: pointer; color: var(--color-danger, #b00);">
                                            Offboard
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $members->links() }}</div>
        </section>
    @endif
</div>
