<div>
    <h1>Sign in to the console</h1>
    @if ($userId === null)<p class="lead">For the {{ config('brand.name') }} team. Business staff sign in at their own address.</p>@endif
    @if (session('console.status'))<p class="lead" role="status" style="color: var(--green);">{{ session('console.status') }}</p>@endif
    @if ($userId === null)
        <form class="guest-form" wire:submit="check">
            <div class="field"><label for="email">Email</label><input class="input" id="email" type="email" wire:model="email" autocomplete="username">@error('email')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="password">Password</label><input class="input" id="password" type="password" wire:model="password" autocomplete="current-password">@error('password')<p class="error">{{ $message }}</p>@enderror</div>
            @if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled">Continue</button>
            <p class="meta" style="margin: 0;">We'll email you a 6-digit code next. Forgot your password? Ask a console owner for a new link.</p>
        </form>
    @else
        <p class="lead">We sent a 6-digit code to <b>{{ $email }}</b>. It expires in {{ \App\Platform\ConsoleCode::TTL_MINUTES }} minutes.</p>
        <form class="guest-form" wire:submit="verify">
            <div class="field"><label for="code">Code</label><input class="input" id="code" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="letter-spacing: .3em; font-weight: 600;">@error('code')<p class="error">{{ $message }}</p>@enderror</div>
            @if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled">Sign in</button>
            <button class="btn btn-text" type="button" wire:click="again">Start again</button>
        </form>
    @endif
</div>
