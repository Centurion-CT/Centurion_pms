{{-- centurionpms.com/login (App\Livewire\Platform\FindBusiness). Design: design/platform/superdesign/07-login-find.png. --}}
@php($suffix = config('keverna.tenant_domain_suffix'))
@php($helpEmail = 'hello@'.config('keverna.central_domain'))
<div class="page">
    <section class="slab">
        <a class="logo" href="{{ route('platform.home') }}"><span class="logo-mark"><img src="{{ asset(config('brand.logo')) }}" alt=""></span>{{ config('brand.name') }}</a>

        <div class="slab-body">
            @if (! $sent)
                <h1 class="h1">Log in to your business</h1>
                <p class="lead">Each business on {{ config('brand.name') }} has its own address. Enter yours to go to its login.</p>

                <form wire:submit="go">
                    <div class="field">
                        <label for="slug">Your business address</label>
                        <div class="address">
                            <input id="slug" wire:model="slug" placeholder="your-business" autocomplete="off" spellcheck="false" maxlength="30" aria-describedby="slug-note" autofocus
                                   x-on:input="$el.value = $el.value.toLowerCase().replace(/[^a-z0-9-]/g, '')">
                            <span class="suffix">{{ $suffix }}</span>
                            <button class="btn btn-primary" type="submit">Go</button>
                        </div>
                        @error('slug')
                            <p class="error" id="slug-note">{{ $message }}</p>
                        @else
                            <p class="hint" id="slug-note">If your business uses its own domain, you can log in there too.</p>
                        @enderror
                    </div>
                </form>

                <p class="or">Don't know your address?</p>
                <form wire:submit="sendLinks" style="margin-top:16px">
                    <div class="field">
                        <label for="email">Your work email</label>
                        <input class="input" id="email" type="email" wire:model="email" autocomplete="email" placeholder="you@yourbusiness.com">
                        @error('email')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button class="btn btn-outline-light btn-lg btn-block" type="submit" wire:loading.attr="disabled">Email me my login links</button>
                </form>
                <p class="meta" style="margin-top:32px">New to {{ config('brand.name') }}? <a href="{{ route('platform.signup') }}">Create an account</a></p>
            @else
                <h1 class="h1">Check your email</h1>
                <p class="lead">If <b>{{ $email }}</b> belongs to anyone on {{ config('brand.name') }}, we've sent a link to each business they work at.</p>
                <p class="meta" style="margin-top:24px">Nothing arrived after a few minutes? Check spam, or ask your manager for your business's address.</p>
                <p class="meta" style="margin-top:24px"><button class="link" type="button" wire:click="again">Try another email</button></p>
            @endif
        </div>

        <footer class="slab-foot"><span>© {{ date('Y') }} {{ config('brand.name') }}</span><a href="mailto:{{ $helpEmail }}">Need help?</a></footer>
    </section>

    <aside class="side" aria-label="What the login links email looks like">
        <div class="card" style="background:#fff;border-radius:12px;box-shadow:var(--e-soft)">
            <div class="mail-head"><span>From {{ config('brand.name') }}</span><b>Your {{ config('brand.name') }} login links</b></div>
            <div class="mail-body">
                <p>Pick the business you want to log in to:</p>
                <div class="mail-row"><span class="biz-mark">G</span><span><b>Grand Palace Suites</b><small>grandpalace{{ $suffix }} · Manager</small></span><span class="btn btn-primary btn-sm">Log in</span></div>
                <div class="mail-row"><span class="biz-mark" style="background:#2E7D6B">S</span><span><b>Sunrise Court</b><small>sunrise-court{{ $suffix }} · Front desk</small></span><span class="btn btn-primary btn-sm">Log in</span></div>
                <p class="meta">The links open your business's login page. Nobody can log in without your password.</p>
            </div>
        </div>
    </aside>
</div>
