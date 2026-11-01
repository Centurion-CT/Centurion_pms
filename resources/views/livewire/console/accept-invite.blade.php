<div style="max-width: 420px; margin: 48px auto 0;">
    @if (! $user)
        <h1>This link has expired</h1>
        <p class="lead">Links to set a console password work once, for {{ \App\Models\ConsoleUser::INVITE_DAYS }} days. Ask a console owner to send you a new one.</p>
    @else
        <h1>Choose a password</h1>
        <p class="lead">For {{ $user->email }}, {{ $user->role->label() }} on the {{ config('brand.name') }} console. Each sign-in also asks for a code sent to this address.</p>
        <form class="card" style="padding: 24px; margin-top: 24px; display: grid; gap: 16px;" wire:submit="save">
            <div class="field"><label for="password">Password</label><input class="input" id="password" type="password" wire:model="password" autocomplete="new-password" placeholder="At least 12 characters">@error('password')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="password_confirmation">Password again</label><input class="input" id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password"></div>
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled">Save password</button>
        </form>
    @endif
</div>
