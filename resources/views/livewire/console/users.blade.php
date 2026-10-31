{{-- Console users (App\Livewire\Console\Users), owners only. --}}
<div>
    <h1>Console users</h1>
    <p class="lead">Owners manage this list and the businesses. Admins can pause and resume businesses.</p>

    <form class="card invite-grid" style="padding: 20px; margin: 24px 0;" wire:submit="invite">
        <div class="field"><label for="name">Name</label><input class="input" id="name" wire:model="name">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="email">Email</label><input class="input" id="email" type="email" wire:model="email">@error('email')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="role">Role</label>
            <select class="input" id="role" wire:model="role">@foreach (\App\Enums\ConsoleRole::cases() as $r)<option value="{{ $r->value }}">{{ $r->label() }}</option>@endforeach</select>
        </div>
        <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" style="height: 44px;">Send invite</button>
    </form>

    <div class="card" style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; min-width: 720px;">
            <thead>
                <tr style="text-align: left; color: var(--ink-500); font-size: 12px; font-weight: 600;">
                    <th style="padding: 12px 16px;">Person</th><th style="padding: 12px 8px;">Role</th><th style="padding: 12px 8px;">Status</th><th style="padding: 12px 16px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $u)
                    @php($self = $u->id === $me->id)
                    <tr wire:key="cu-{{ $u->id }}" style="border-top: 1px solid var(--line); vertical-align: middle;">
                        <td style="padding: 14px 16px;"><b style="color: var(--ink-900);">{{ $u->name }}</b>@if ($self) <span class="meta">(you)</span>@endif<div class="meta">{{ $u->email }}</div></td>
                        <td style="padding: 14px 8px;">
                            @if ($self)
                                {{ $u->role->label() }}
                            @else
                                <select class="input" style="height: 32px; width: 110px; font-size: 13px;" aria-label="Role for {{ $u->name }}" wire:change="changeRole({{ $u->id }}, $event.target.value)">
                                    @foreach (\App\Enums\ConsoleRole::cases() as $r)<option value="{{ $r->value }}" @selected($u->role === $r)>{{ $r->label() }}</option>@endforeach
                                </select>
                            @endif
                        </td>
                        <td style="padding: 14px 8px;">
                            @if ($u->isActive())
                                <span class="tag tag-ok">Active</span>
                                <div class="meta">{{ $u->last_signed_in_at ? 'signed in '.$u->last_signed_in_at->diffForHumans() : 'not signed in yet' }}</div>
                            @else
                                <span class="tag tag-warn">{{ $u->invite_expires_at?->isFuture() ? 'Invited' : 'Invite expired' }}</span>
                                <div class="meta">{{ $u->invitedBy ? 'by '.$u->invitedBy->name : '' }}</div>
                            @endif
                        </td>
                        <td style="padding: 14px 16px; text-align: right; white-space: nowrap;">
                            @unless ($self)
                                <button class="btn btn-quiet" type="button" wire:click="resetPassword({{ $u->id }})" wire:confirm="{{ $u->isActive() ? 'Send '.$u->name.' a link to set a new password? Their current password stops working.' : 'Send '.$u->name.' a new invite link?' }}">{{ $u->isActive() ? 'Reset password' : 'Resend invite' }}</button>
                                <button class="btn btn-quiet btn-danger" type="button" wire:click="remove({{ $u->id }})" wire:confirm="Remove {{ $u->name }}? They lose console access straight away.">Remove</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
