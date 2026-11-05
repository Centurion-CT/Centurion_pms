{{-- Sign-up (App\Livewire\Platform\SignUp). Design: design/platform/superdesign/04–06. --}}
@php($suffix = config('keverna.tenant_domain_suffix'))
@php($helpEmail = 'hello@'.config('keverna.central_domain'))
@php($order = ['business', 'account', 'code'])
<div class="page">
    <section class="slab">
        <a class="logo" href="{{ route('platform.home') }}"><span class="logo-mark"><img src="{{ asset(config('brand.logo')) }}" alt=""></span>{{ config('brand.name') }}</a>

        <div class="slab-body">
            @if ($step !== 'done')
                <p class="step-of">Step {{ array_search($step, $order, true) + 1 }} of {{ count($order) }}</p>
            @endif

            @if ($step === 'business')
                <h1 class="h1">Tell us about your business</h1>
                <form wire:submit="submitBusiness">
                    <div class="field">
                        <label for="name">Business name</label>
                        <input class="input" id="name" wire:model="name" wire:input.debounce.300ms="$refresh" placeholder="e.g. Grand Palace Suites" autocomplete="organization" autofocus>
                        @error('name')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="slug">Your address</label>
                        <div class="address">
                            <input id="slug" wire:model="slug" wire:input.debounce.300ms="$refresh" placeholder="your-business" autocomplete="off" spellcheck="false" maxlength="30" aria-describedby="slug-status">
                            <span class="suffix">{{ $suffix }}</span>
                        </div>
                        @error('slug')
                            <p class="astatus is-taken" id="slug-status">{{ $message }}</p>
                        @else
                            <p @class(['astatus', 'is-free' => $addressCheck['available'] ?? false, 'is-taken' => $addressCheck && ! $addressCheck['available']]) id="slug-status" aria-live="polite">{{ $addressCheck['message'] ?? 'Letters, numbers and dashes. You can add your own domain later.' }}</p>
                        @enderror
                    </div>
                    <div class="field">
                        <span class="label" id="kind-label">What do you run?</span>
                        <div class="choice-row" role="radiogroup" aria-labelledby="kind-label">
                            @foreach (\App\Livewire\Platform\SignUp::KINDS as $value => [$label])
                                <label class="choice"><input type="radio" wire:model.live="kind" value="{{ $value }}"><span>{{ $label }}</span></label>
                            @endforeach
                        </div>
                    </div>
                    <div class="two">
                        <div class="field">
                            <label for="city">City</label>
                            <input class="input" id="city" wire:model="city" placeholder="Abuja" autocomplete="address-level2">
                            @error('city')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="size">Rooms, roughly</label>
                            <select class="input" id="size" wire:model="size">
                                @foreach (array_keys(\App\Livewire\Platform\SignUp::SIZES) as $label)
                                    <option value="{{ $label }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="actions">
                        <button class="btn btn-primary btn-lg" type="submit" wire:loading.attr="disabled" wire:target="submitBusiness">Continue</button>
                        <p class="meta">Already on {{ config('brand.name') }}? <a href="{{ route('platform.login') }}">Log in</a></p>
                    </div>
                </form>
            @elseif ($step === 'account')
                <h1 class="h1">Create your manager account</h1>
                <p class="lead">You'll be the manager of <b>{{ $name }}</b>. You can invite your staff once you're in.</p>
                <form wire:submit="submitAccount">
                    <div class="field">
                        <label for="managerName">Your full name</label>
                        <input class="input" id="managerName" wire:model="managerName" autocomplete="name" autofocus>
                        @error('managerName')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="email">Work email</label>
                        <input class="input" id="email" type="email" wire:model="email" autocomplete="email" placeholder="you@yourbusiness.com">
                        <span class="hint">We'll send a 6-digit code to confirm it.</span>
                        @error('email')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="phone">Phone</label>
                        <input class="input" id="phone" type="tel" wire:model="phone" autocomplete="tel" placeholder="0803 000 0000">
                        @error('phone')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input class="input" id="password" type="password" wire:model="password" autocomplete="new-password" placeholder="At least 10 characters">
                        @error('password')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="actions">
                        <button class="btn btn-primary btn-lg" type="submit" wire:loading.attr="disabled" wire:target="submitAccount">Send my code</button>
                        <button class="link" type="button" wire:click="back('business')">Back</button>
                    </div>
                </form>
            @elseif ($step === 'code')
                <h1 class="h1">Check your email</h1>
                <p class="lead">We sent a 6-digit code to <b>{{ $email }}</b>. It works for {{ \App\Platform\SignUpCode::TTL_MINUTES }} minutes.</p>
                <form wire:submit="confirm"
                      x-data="{ d: ['', '', '', '', '', ''],
                                sync() { $wire.code = this.d.join('') },
                                type(i, e) { const v = e.target.value.replace(/\D/g, ''); this.d[i] = v.slice(-1); this.sync(); if (v && i < 5) this.$refs['b' + (i + 1)].focus() },
                                back(i, e) { if (e.key === 'Backspace' && !this.d[i] && i > 0) this.$refs['b' + (i - 1)].focus() },
                                paste(e) { const v = (e.clipboardData.getData('text').match(/\d/g) || []).slice(0, 6); if (!v.length) return; e.preventDefault(); this.d = [0,1,2,3,4,5].map(i => v[i] || ''); this.sync(); this.$refs['b' + Math.min(v.length, 5)].focus() } }">
                    <div class="code" role="group" aria-label="6-digit code">
                        @foreach (range(0, 5) as $i)
                            @if ($i === 3)<span class="gap"></span>@endif
                            <input x-ref="b{{ $i }}" inputmode="numeric" autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}" maxlength="1" aria-label="Digit {{ $i + 1 }}" :value="d[{{ $i }}]" @input="type({{ $i }}, $event)" @keydown="back({{ $i }}, $event)" @paste="paste($event)" @if ($i === 0) autofocus @endif>
                        @endforeach
                    </div>
                    @if ($codeError)<p class="error" role="alert">{{ $codeError }}</p>@endif
                    <div class="actions">
                        <button class="btn btn-primary btn-lg" type="submit" wire:loading.attr="disabled" wire:target="confirm">Create my business</button>
                        <button class="link" type="button" wire:click="back('account')">Wrong email?</button>
                    </div>
                    <p class="meta">No email after a minute? Check spam, or <button class="link" type="button" wire:click="resend">send a new code</button>.</p>
                </form>
            @else
                <h1 class="h1">You're all set</h1>
                <p class="lead">{{ $name }} is ready. Here's what we just did.</p>
                <ul class="checklist">
                    <li>Reserved {{ $host }}</li>
                    <li>Created your first branch</li>
                    <li>Made you the manager</li>
                    <li>Set up your booking website</li>
                </ul>
                <div class="done-card">
                    <p class="meta">Your desk lives at</p>
                    <p class="url">https://{{ $host }}</p>
                    <p class="meta" style="margin-top:12px">We've emailed you this address. Bookmark it: it's where you and your staff log in.</p>
                    <div class="actions" style="margin-top:20px">
                        <a class="btn btn-primary btn-lg btn-block" href="{{ route('platform.signup.open') }}">Open my dashboard</a>
                    </div>
                </div>
            @endif
        </div>

        <footer class="slab-foot"><span>© {{ date('Y') }} {{ config('brand.name') }}</span><a href="mailto:{{ $helpEmail }}">Need help?</a></footer>
    </section>

    <aside class="side">
        <div>
            <p>{{ ['code' => 'Almost yours', 'done' => 'Your booking site is live'][$step] ?? 'Your booking site and staff login' }}</p>
            <p class="side-address">{!! str_replace('.', '.<wbr>', e($previewHost)) !!}</p>
            @if ($step === 'business')
                <p class="side-note">One branch to start. Add more buildings and your own domain any time.</p>
            @endif
        </div>
        <div class="side-bars" aria-hidden="true"><i></i><i></i><i></i></div>
    </aside>
</div>
