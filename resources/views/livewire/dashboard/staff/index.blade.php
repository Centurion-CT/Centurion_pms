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
            cashier to use the portal. Housekeepers and porters can use the {{ config('brand.name') }} Staff app
            with a phone number and PIN.
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
                <label for="invite-email" class="lp-form-label">Email @if ($appRole)<span style="color: var(--color-ink-mute); font-weight: 400;">(optional)</span>@endif</label>
                <input id="invite-email" type="email" wire:model="inviteEmail" @if (! $appRole) required @endif maxlength="180"
                       data-testid="invite-email" class="lp-input" placeholder="chika@property.com">
                @error('inviteEmail') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>
        @if ($appRole)
            <div class="grid gap-5 sm:grid-cols-2" data-testid="invite-app-fields">
                <div>
                    <label for="invite-phone" class="lp-form-label">Phone number</label>
                    <input id="invite-phone" type="tel" wire:model="invitePhone" maxlength="32" autocomplete="off"
                           data-testid="invite-phone" class="lp-input" placeholder="0803 123 4567">
                    @error('invitePhone') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="invite-pin" class="lp-form-label">Staff app PIN</label>
                    <input id="invite-pin" type="text" inputmode="numeric" pattern="[0-9]*" wire:model="invitePin" maxlength="6" autocomplete="off"
                           data-testid="invite-pin" class="lp-input" placeholder="4 to 6 digits">
                    @error('invitePin') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <p class="lp-form-help sm:col-span-2" style="margin: 0;">
                    Housekeepers and porters sign in to the {{ config('brand.name') }} Staff app with this phone number and PIN.
                    Leave email empty if they only use the app; tell them the PIN in person.
                </p>
            </div>
        @endif
        <fieldset class="lp-fieldset">
            <legend>Role</legend>
            <p class="lp-form-help" style="margin: 0 0 8px;">
                Determines what this staffer sees in the dashboard.
            </p>
            <div class="grid gap-1 mt-2" data-testid="invite-role-group">
                @foreach ($roles as $r)
                    <label class="lp-radio-row">
                        <input type="radio" wire:model.live="inviteRole" value="{{ $r->value }}"
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
                <span wire:loading.remove wire:target="invite">{{ $appRole && trim($inviteEmail) === '' ? 'Add staffer' : 'Send invite' }}</span>
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
                            <td style="color: var(--color-ink-soft);">
                                {{ $op->isAppOnly() ? 'Staff app only' : $op->email }}
                                @if (in_array($opRole->value, ['house_cleaner', 'porter'], true))
                                    <span style="display: block; font-size: 12px; color: var(--color-ink-mute);" data-testid="staff-app-signin-{{ $op->id }}">
                                        @if ($op->hasStaffPin())
                                            App: {{ $op->phone }}, PIN set
                                        @else
                                            No app PIN yet
                                        @endif
                                    </span>
                                @endif
                            </td>
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
                                        @if (in_array($opRole->value, ['house_cleaner', 'porter'], true) && ! $op->isRevoked())
                                            <span x-data="{ open: false, phone: @js((string) $op->phone), pin: '' }" style="position: relative;">
                                                <button type="button" @click="open = ! open"
                                                        data-testid="staff-app-pin-{{ $op->id }}"
                                                        style="background: transparent; border: 0; cursor: pointer; font-family: inherit; color: var(--color-accent-deep); text-decoration: underline;">
                                                    {{ $op->hasStaffPin() ? 'Change PIN' : 'Set app PIN' }}
                                                </button>
                                                <form x-show="open" x-cloak @click.outside="open = false"
                                                      @submit.prevent="$wire.setAppPin({{ $op->id }}, phone, pin).then(() => { open = false; pin = '' })"
                                                      class="lp-dash-card"
                                                      style="position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; width: 260px; display: grid; gap: 10px; text-align: left; box-shadow: 0 12px 32px rgba(26,36,48,.14);">
                                                    <label class="lp-form-label" for="app-phone-{{ $op->id }}">Phone number</label>
                                                    <input id="app-phone-{{ $op->id }}" type="tel" x-model="phone" class="lp-input" placeholder="0803 123 4567" required
                                                           data-testid="staff-app-phone-input-{{ $op->id }}">
                                                    <label class="lp-form-label" for="app-pin-{{ $op->id }}">New PIN</label>
                                                    <input id="app-pin-{{ $op->id }}" type="text" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" x-model="pin" class="lp-input" placeholder="4 to 6 digits" required autocomplete="off"
                                                           data-testid="staff-app-pin-input-{{ $op->id }}">
                                                    <button type="submit" class="lp-pill" style="justify-self: start;" data-testid="staff-app-pin-save-{{ $op->id }}">Save</button>
                                                </form>
                                            </span>
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
