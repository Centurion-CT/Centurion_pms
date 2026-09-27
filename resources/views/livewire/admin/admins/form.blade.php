@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    @include('_partials._back-button')

    <div class="mt-4">
        <span class="lp-eyebrow-strip">Access</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            {{ $isEdit ? 'Edit admin' : 'Invite admin' }}
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            @if ($isEdit)
                Update name, role and access state. Leave the password blank to keep the existing one.
            @else
                A one-time password is generated and shown to you once if you don't pick one.
            @endif
        </p>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 20px;"
          data-testid="admin-form">

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="lp-form-label" for="a-name">Full name</label>
                <input id="a-name" type="text" wire:model.live="name"
                       class="lp-input" required maxlength="120" data-testid="name">
                @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="a-email">Email</label>
                <input id="a-email" type="email" wire:model.live="email"
                       class="lp-input" required maxlength="180" data-testid="email">
                @error('email') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <fieldset class="lp-fieldset">
            <legend>Role</legend>
            <div class="grid gap-3 sm:grid-cols-2 mt-2">
                @foreach ([['super', 'Super', 'Full access, can invite, deactivate and remove other admins.'],
                          ['support', 'Support', 'Read tenants, extend subscriptions, generate impersonation links. Cannot manage admins.']] as [$value, $label, $desc])
                    <label class="lp-fieldset-card"
                           style="display: grid; gap: 4px; padding: 14px 16px;
                                  border: 1px solid var(--color-line); border-radius: 14px; cursor: pointer;
                                  {{ $role === $value ? 'border-color: var(--color-ink); background: var(--color-card-deep);' : '' }}">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="radio" wire:model.live="role" value="{{ $value }}"
                                   data-testid="role-{{ $value }}"
                                   style="accent-color: var(--color-accent-deep);">
                            <span class="lp-display" style="font-weight: 600; font-size: 15px;">{{ $label }}</span>
                        </div>
                        <p class="text-[12.5px]" style="color: var(--color-ink-soft); line-height: 1.5;">{{ $desc }}</p>
                    </label>
                @endforeach
            </div>
            @error('role') <p class="lp-form-error">{{ $message }}</p> @enderror
        </fieldset>

        <div>
            <label class="lp-form-label" for="a-pw">
                Password
                @if ($isEdit) <span style="color: var(--color-ink-mute);">(leave blank to keep existing)</span> @endif
            </label>
            <input id="a-pw" type="password" wire:model.live="password"
                   class="lp-input" minlength="12" autocomplete="new-password"
                   placeholder="{{ $isEdit ? '••••••••' : 'Min 12 chars, leave blank for auto-generated' }}"
                   data-testid="password">
            @error('password') <p class="lp-form-error">{{ $message }}</p> @enderror
            <p class="lp-form-help" style="margin-top: 6px;">
                @if ($isEdit)
                    Changing this will require the admin to re-login on their next request.
                @else
                    Auto-generated passwords are 20 characters and surfaced once after invite.
                @endif
            </p>
        </div>

        <label class="flex items-center gap-2 text-[13px]" style="color: var(--color-ink-soft);">
            <input type="checkbox" wire:model.live="is_active"
                   data-testid="active"
                   style="accent-color: var(--color-accent-deep);">
            Active, can sign in
        </label>

        <div class="flex justify-end pt-2" style="border-top: 1px solid var(--color-line-soft);">
            <button type="submit" class="lp-pill" data-testid="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Save changes' : 'Invite admin' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
