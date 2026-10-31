<div style="max-width: 460px; margin: 48px auto 0;">
    <h1>Set up the console</h1>
    @if ($step === 'details')
        <p class="lead">Create the first owner account. You'll need the setup key from whoever runs the servers. This page closes once an owner exists.</p>
        <form class="card" style="padding: 24px; margin-top: 24px; display: grid; gap: 16px;" wire:submit="submit">
            <div class="field"><label for="key">Setup key</label><input class="input" id="key" type="password" wire:model="setupKey" autocomplete="off">@error('setupKey')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="name">Your name</label><input class="input" id="name" wire:model="name" autocomplete="name">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="email">Email</label><input class="input" id="email" type="email" wire:model="email" autocomplete="email">@error('email')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="password">Password</label><input class="input" id="password" type="password" wire:model="password" autocomplete="new-password" placeholder="At least 12 characters">@error('password')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="password_confirmation">Password again</label><input class="input" id="password_confirmation" type="password" wire:model="password_confirmation" autocomplete="new-password"></div>
            @if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled">Email me a code</button>
        </form>
    @else
        <p class="lead">We sent a 6-digit code to <b>{{ $email }}</b>. Enter it to create your owner account.</p>
        <form class="card" style="padding: 24px; margin-top: 24px; display: grid; gap: 16px;" wire:submit="confirm">
            <div class="field"><label for="code">Code</label><input class="input" id="code" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="letter-spacing: .3em; font-weight: 600;">@error('code')<p class="error">{{ $message }}</p>@enderror</div>
            @if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled">Create my account</button>
        </form>
    @endif
</div>
