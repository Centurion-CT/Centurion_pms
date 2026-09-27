@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Workspace</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Staff
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Everyone with access to this dashboard. Invite a front-desk staffer, manager, or POS
            cashier to use the portal.
        </p>
    </div>

    {{-- Invite form --}}
    <form wire:submit="invite" class="lp-dash-card" style="display: grid; gap: 18px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Invite a staffer</span>
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="invite-name" class="lp-form-label">Full name</label>
                <input id="invite-name" type="text" wire:model="inviteName" required maxlength="120"
                       data-testid="invite-name" class="lp-input" placeholder="Chika Okafor">
                @error('inviteName') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="invite-email" class="lp-form-label">Email</label>
                <input id="invite-email" type="email" wire:model="inviteEmail" required maxlength="180"
                       data-testid="invite-email" class="lp-input" placeholder="chika@property.com">
                @error('inviteEmail') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <fieldset class="lp-fieldset">
            <legend>Role</legend>
            <p class="lp-form-help" style="margin: 0 0 8px;">
                Determines what this staffer sees in the dashboard.
            </p>
            <div class="grid gap-1 mt-2" data-testid="invite-role-group">
                @foreach ($roles as $r)
                    <label class="lp-radio-row">
                        <input type="radio" wire:model="inviteRole" value="{{ $r->value }}"
                               data-testid="invite-role-{{ $r->value }}">
                        <span>
                            <strong>{{ $r->label() }}</strong>
                            <span style="color: var(--color-ink-mute); display: block; font-size: 12.5px; margin-top: 2px;">
                                {{ $r->description() }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('inviteRole') <p class="lp-form-error">{{ $message }}</p> @enderror
        </fieldset>

        <div class="flex justify-end">
            <button type="submit" data-testid="invite-submit" class="lp-pill">
                <span wire:loading.remove wire:target="invite">Send invite</span>
                <span wire:loading wire:target="invite">Inviting&hellip;</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>

    {{-- Roster --}}
    <section class="lp-dash-card" style="padding: 0;" data-testid="staff-roster">
        <div class="overflow-x-auto">
            <table class="lp-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last login</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($operators as $op)
                        @php
                            $opRole = $op->role ?? \App\Enums\OperatorRole::Manager;
                            $roleTagClass = match ($opRole->value) {
                                'manager'             => 'lp-tag--checked-in',
                                'reservation_officer' => 'lp-tag--confirmed',
                                'frontdesk'           => 'lp-tag--pending',
                                'house_cleaner'       => 'lp-tag--on-hold',
                                default               => '',
                            };
                            $status = match (true) {
                                $op->isRevoked()          => ['label' => 'Revoked',   'class' => 'lp-tag--cancelled'],
                                $op->isSuspended()        => ['label' => 'Suspended', 'class' => 'lp-tag--on-hold'],
                                $op->activated_at === null=> ['label' => 'Pending',   'class' => 'lp-tag--pending'],
                                default                   => ['label' => 'Active',    'class' => 'lp-tag--confirmed'],
                            };
                            $grantedCount = count((array) $op->permissions ?? []);
                            $isSelf = $op->id === $currentOperatorId;
                        @endphp
                        <tr data-testid="staff-row-{{ $op->id }}">
                            <td style="color: var(--color-ink); font-weight: 500;">
                                {{ $op->name }}
                                @if ($isSelf)
                                    <span class="lp-tag lp-tag--confirmed" style="margin-left: 6px; font-size: 10px;">You</span>
                                @endif
                            </td>
                            <td style="color: var(--color-ink-soft);">{{ $op->email }}</td>
                            <td>
                                <div class="inline-flex items-center gap-2">
                                    <span class="lp-tag {{ $roleTagClass }}">{{ $opRole->label() }}</span>
                                    <select wire:change="updateRole({{ $op->id }}, $event.target.value)"
                                            data-testid="staff-role-{{ $op->id }}"
                                            class="lp-input"
                                            style="font-size: 11px; padding: 4px 22px 4px 8px; width: auto; background-color: transparent; border: 0; color: var(--color-ink-mute); cursor: pointer;"
                                            title="Change role">
                                        <option value="">&#8646;</option>
                                        @foreach ($roles as $r)
                                            @if ($r !== $opRole)
                                                <option value="{{ $r->value }}">{{ $r->label() }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                            </td>
                            <td>
                                <span class="lp-tag {{ $status['class'] }}"
                                      data-testid="staff-status-{{ $op->id }}">
                                    {{ $status['label'] }}
                                </span>
                                @if ($grantedCount > 0)
                                    <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); margin-left: 4px;">
                                        +{{ $grantedCount }} perm{{ $grantedCount === 1 ? '' : 's' }}
                                    </span>
                                @endif
                            </td>
                            <td style="color: var(--color-ink-soft);">
                                {{ $op->last_login_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td style="text-align: right;">
                                @if ($isSelf)
                                    <a href="{{ route('tenant.staff.permissions', $op->id) }}" wire:navigate
                                       data-testid="staff-manage-permissions-{{ $op->id }}"
                                       style="font-family: inherit; font-size: 12.5px; color: var(--color-accent-deep); text-decoration: underline;">
                                        Permissions
                                    </a>
                                @else
                                    <div class="inline-flex items-center gap-3" style="font-size: 12.5px;">
                                        @if ($op->activated_at === null && ! $op->isRevoked())
                                            <button type="button" wire:click="resendInvite({{ $op->id }})"
                                                    data-testid="staff-resend-invite-{{ $op->id }}"
                                                    style="background: transparent; border: 0; cursor: pointer; font-family: inherit; color: var(--color-accent-deep); text-decoration: underline;">
                                                Resend invite
                                            </button>
                                        @endif
                                        <a href="{{ route('tenant.staff.permissions', $op->id) }}" wire:navigate
                                           data-testid="staff-manage-permissions-{{ $op->id }}"
                                           style="font-family: inherit; color: var(--color-ink-soft); text-decoration: underline;">
                                            Permissions
                                        </a>
                                        @if ($op->isSuspended())
                                            <button type="button" wire:click="unsuspend({{ $op->id }})"
                                                    data-testid="staff-unsuspend-{{ $op->id }}"
                                                    style="background: transparent; border: 0; cursor: pointer; font-family: inherit; color: var(--color-accent-deep); text-decoration: underline;">
                                                Unsuspend
                                            </button>
                                        @elseif (! $op->isRevoked())
                                            <button type="button" wire:click="suspend({{ $op->id }})"
                                                    wire:confirm="Suspend {{ $op->name }}? They will not be able to sign in until you restore access."
                                                    data-testid="staff-suspend-{{ $op->id }}"
                                                    style="background: transparent; border: 0; cursor: pointer; font-family: inherit; color: var(--color-ink-soft); text-decoration: underline;">
                                                Suspend
                                            </button>
                                        @endif
                                        @if ($op->isRevoked())
                                            <button type="button" wire:click="unrevoke({{ $op->id }})"
                                                    data-testid="staff-unrevoke-{{ $op->id }}"
                                                    style="background: transparent; border: 0; cursor: pointer; font-family: inherit; color: var(--color-accent-deep); text-decoration: underline;">
                                                Restore
                                            </button>
                                        @elseif (! ($op->activated_at === null))
                                            {{-- Active + Suspended → Revoke path only.
                                                 Pending + Revoked → skip revoke, go straight
                                                 to Delete (below). --}}
                                            <button type="button" wire:click="revoke({{ $op->id }})"
                                                    wire:confirm="Revoke {{ $op->name }}'s access to this property? Their account stays for audit but they cannot sign in."
                                                    data-testid="staff-revoke-{{ $op->id }}"
                                                    style="background: transparent; border: 0; cursor: pointer; font-family: inherit; color: #E40014; text-decoration: underline;">
                                                Revoke
                                            </button>
                                        @endif

                                        @if ($op->isRevoked() || $op->activated_at === null)
                                            <button type="button" wire:click="remove({{ $op->id }})"
                                                    wire:confirm="Delete {{ $op->name }} permanently? This clears their account row + identity link. Cannot be undone."
                                                    data-testid="staff-delete-{{ $op->id }}"
                                                    style="background: transparent; border: 0; cursor: pointer; font-family: inherit; color: #E40014; text-decoration: underline;">
                                                Delete
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

</div>
