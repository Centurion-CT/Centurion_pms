<div style="max-width: 420px; margin: 48px auto 0;">
    <h1>Sign in to the console</h1>
    @if (! $sent)
        <p class="lead">We'll email a 6-digit code to your address.</p>
        <form class="card" style="padding: 24px; margin-top: 24px; display: grid; gap: 14px;" wire:submit="send">
            <label for="email" style="font-weight: 600; color: var(--ink-900); font-size: 14px;">Email</label>
            <input class="input" id="email" type="email" wire:model="email" autocomplete="email" autofocus>
            @error('email')<p class="error">{{ $message }}</p>@enderror
            @if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled">Email me a code</button>
        </form>
    @else
        <p class="lead">If <b>{{ $email }}</b> may use the console, a code is on its way. It expires in {{ \App\Platform\ConsoleCode::TTL_MINUTES }} minutes.</p>
        <form class="card" style="padding: 24px; margin-top: 24px; display: grid; gap: 14px;" wire:submit="verify">
            <label for="code" style="font-weight: 600; color: var(--ink-900); font-size: 14px;">Code</label>
            <input class="input" id="code" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus style="letter-spacing: .3em; font-weight: 600;">
            @error('code')<p class="error">{{ $message }}</p>@enderror
            @if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled">Sign in</button>
            <button class="btn" type="button" wire:click="again" style="background: none; color: var(--blue); height: auto;">Use another email</button>
        </form>
    @endif
</div>
