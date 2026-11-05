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
                            <input id="slug" wire:model="slug" placeholder="your-business" autocomplete="off" spellcheck="false" aria-describedby="slug-note"
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

                <div class="divider"></div>

                <form wire:submit="sendLinks">
                    <div class="field">
                        <label for="email">Don't know your address?</label>
                        <input class="input" id="email" type="email" wire:model="email" autocomplete="email" placeholder="Your work email">
                        @error('email')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button class="btn btn-outline btn-lg btn-block" type="submit" wire:loading.attr="disabled" wire:target="sendLinks">Email me my login links</button>
                </form>
                @if (config('keverna.signup_open'))
                    <p class="meta" style="margin-top:32px">New to {{ config('brand.name') }}? <a href="{{ route('platform.signup') }}">Create an account</a></p>
                @endif
            @else
                <h1 class="h1">Check your email</h1>
                <p class="lead">If <b>{{ $email }}</b> belongs to anyone on {{ config('brand.name') }}, we've sent a link to each business they work at.</p>
                <p class="meta" style="margin-top:24px">Nothing arrived after a few minutes? Check spam, or ask your manager for your business's address.</p>
                <p class="meta" style="margin-top:24px"><button class="link" type="button" wire:click="again">Try another email</button></p>
            @endif
        </div>

        <footer class="slab-foot"><span>© {{ date('Y') }} {{ config('brand.name') }}</span><a href="mailto:{{ $helpEmail }}">Need help?</a></footer>
    </section>

    <aside class="side" aria-hidden="true">
        <div>
            <p>Your business, at its own address</p>
            <p class="side-address">{!! str_replace('.', '.<wbr>', e('grandpalace'.$suffix)) !!}</p>
        </div>
        <div class="side-bars" aria-hidden="true"><i></i><i></i><i></i></div>
    </aside>
</div>
