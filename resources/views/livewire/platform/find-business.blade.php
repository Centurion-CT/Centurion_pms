{{-- centurionpms.com/login (App\Livewire\Platform\FindBusiness). --}}
@php($suffix = config('keverna.tenant_domain_suffix'))
@php($helpEmail = 'hello@'.config('keverna.central_domain'))
<div class="page">
    <section class="slab">
        <a class="logo" href="{{ route('platform.home') }}"><span class="logo-mark"><img src="{{ asset(config('brand.logo')) }}" alt=""></span>{{ config('brand.name') }}</a>

        <div class="slab-body">
            @if ($choices !== [])
                <h1 class="h1">Choose a business</h1>
                <p class="lead">Your account works at more than one. Which do you want to open?</p>
                <div class="businesses">
                    @foreach ($choices as $choice)
                        <button class="business" type="button" wire:click="open({{ $choice['id'] }})" wire:loading.attr="disabled" wire:target="open" data-testid="business-choice-{{ $choice['id'] }}">
                            <b>{{ $choice['name'] }}</b>
                            <span>{{ $choice['role'] }} · {{ $choice['host'] }}</span>
                        </button>
                    @endforeach
                </div>
            @elseif ($sent)
                <h1 class="h1">Check your email</h1>
                <p class="lead">If <b>{{ $email }}</b> belongs to anyone on {{ config('brand.name') }}, we've sent a link to each business they work at.</p>
                <p class="meta" style="margin-top:24px">Nothing arrived after a few minutes? Check spam, or ask your manager for your business's address.</p>
                <p class="meta" style="margin-top:24px"><button class="link" type="button" wire:click="again">Back to log in</button></p>
            @elseif ($mode === 'address')
                <h1 class="h1">Go to your business</h1>
                <p class="lead">Enter your business's address to go to its login.</p>
                <form wire:submit="go">
                    <div class="field">
                        <label for="slug">Your business address</label>
                        <div class="address">
                            <input id="slug" wire:model="slug" placeholder="your-business" autocomplete="off" spellcheck="false" aria-describedby="slug-note" maxlength="30"
                                   x-on:input="$el.value = $el.value.toLowerCase().replace(/[^a-z0-9-]/g, '')">
                            <span class="suffix">{{ $suffix }}</span>
                        </div>
                        @error('slug')
                            <p class="error" id="slug-note">{{ $message }}</p>
                        @else
                            <p class="hint" id="slug-note">If your business uses its own domain, you can log in there too.</p>
                        @enderror
                    </div>
                    <button class="btn btn-primary btn-lg btn-block" type="submit">Go to my login</button>
                </form>
                <p class="meta" style="margin-top:24px"><button class="link" type="button" wire:click="show('password')">Log in with email and password</button></p>
            @elseif ($mode === 'links')
                <h1 class="h1">Get your login links</h1>
                <p class="lead">We'll email a link to each business you work at. You can reset your password from there.</p>
                <form wire:submit="sendLinks">
                    <div class="field">
                        <label for="email">Your work email</label>
                        <input class="input" id="email" type="email" wire:model="email" autocomplete="email">
                        @error('email')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button class="btn btn-primary btn-lg btn-block" type="submit" wire:loading.attr="disabled" wire:target="sendLinks">Email me my login links</button>
                </form>
                <p class="meta" style="margin-top:24px"><button class="link" type="button" wire:click="show('password')">Log in with email and password</button></p>
            @else
                <h1 class="h1">Log in to your business</h1>
                <p class="lead">We'll take you straight to your business.</p>
                @error('signIn')<p class="alert" role="alert">{{ $message }}</p>@enderror
                <form wire:submit="signIn">
                    <div class="field">
                        <label for="email">Work email</label>
                        <input class="input" id="email" type="email" wire:model="email" autocomplete="username" required>
                        @error('email')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input class="input" id="password" type="password" wire:model="password" autocomplete="current-password" required>
                        @error('password')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button class="btn btn-primary btn-lg btn-block" type="submit" wire:loading.attr="disabled" wire:target="signIn">Log in</button>
                </form>
                <p class="meta" style="margin-top:20px"><button class="link" type="button" wire:click="show('links')">Forgot your password?</button></p>
                <p class="meta" style="margin-top:8px"><button class="link" type="button" wire:click="show('address')">Use my business address instead</button></p>
                @if (config('keverna.signup_open'))
                    <p class="meta" style="margin-top:24px">New to {{ config('brand.name') }}? <a href="{{ route('platform.signup') }}">Create an account</a></p>
                @endif
            @endif
        </div>

        <footer class="slab-foot"><span>© {{ date('Y') }} {{ config('brand.name') }}</span><a href="mailto:{{ $helpEmail }}">Need help?</a></footer>
    </section>

    <aside class="side" aria-hidden="true">
        <div class="side-bars" aria-hidden="true"><i></i><i></i><i></i></div>
    </aside>
</div>
