{{-- Booking site checkout (App\Livewire\Site\Checkout). Design: design/website/c-map/checkout.html. --}}
@php($backUrl = route('site.results', ['open' => $type->id] + $stay->query()))
<div>
    @include('site.partials.header', ['nav' => false, 'help' => true])

    <main class="container">
        <div class="top">
            <a class="btn btn-ghost btn-sm" href="{{ $backUrl }}">← Back to apartments</a>
            <ol class="stepper" aria-label="Booking steps"><li class="done">Apartment</li><li class="now" aria-current="step">Your details</li><li>Payment</li></ol>
        </div>

        <div class="layout">
            @if (! $available || ! $quote)
                <div class="form">
                    <div class="group">
                        <h1 class="h2">{{ $type->name }} isn't free for {{ $stay->label() }}</h1>
                        <p class="lead full">Someone booked it, or it isn't open for these dates. Pick another apartment or other dates.</p>
                        <p class="full"><a class="btn btn-primary" href="{{ route('site.results', $stay->query()) }}">See what's free</a></p>
                    </div>
                </div>
            @else
                <form class="form" wire:submit="book">
                    <div class="group">
                        <h1 class="h2">Who's staying?</h1>
                        <div class="field"><label for="first">First name</label><input class="input" id="first" wire:model="firstName" autocomplete="given-name">@error('firstName')<p class="error">{{ $message }}</p>@enderror</div>
                        <div class="field"><label for="last">Last name</label><input class="input" id="last" wire:model="lastName" autocomplete="family-name">@error('lastName')<p class="error">{{ $message }}</p>@enderror</div>
                        <div class="field"><label for="phone">Phone</label><input class="input" id="phone" type="tel" wire:model="phone" autocomplete="tel" placeholder="0803 000 0000"><span class="hint">The front desk calls this number if anything changes.</span>@error('phone')<p class="error">{{ $message }}</p>@enderror</div>
                        <div class="field"><label for="email">Email</label><input class="input" id="email" type="email" wire:model="email" autocomplete="email" placeholder="you@company.com"><span class="hint">Your receipt and booking link go here.</span>@error('email')<p class="error">{{ $message }}</p>@enderror</div>
                        <div class="field"><label for="arrive">Arriving around</label>
                            <select class="input" id="arrive" wire:model="arrival">@foreach (\App\Livewire\Site\Checkout::ARRIVALS as $a)<option>{{ $a }}</option>@endforeach</select>
                        </div>
                        <div class="field"><label for="company">Company <span class="meta" style="display:inline">(optional)</span></label><input class="input" id="company" wire:model="company" placeholder="For the invoice" autocomplete="organization"></div>
                        <div class="field full"><label for="note">Anything we should know? <span class="meta" style="display:inline">(optional)</span></label><textarea class="input" id="note" wire:model="notes" placeholder="Early check-in, airport pickup, an extra bed…"></textarea></div>
                    </div>

                    <div class="group">
                        <h2 class="h2">How would you like to pay?</h2>
                        <div class="pay full">
                            <label class="radio-card"><input type="radio" wire:model.live="pay" value="full"><span><span class="h3" style="font-size:var(--t-md)">Pay now by card or bank transfer</span><p class="meta">Secured by Paystack. Your apartment is confirmed straight away.</p></span></label>
                            @if ($quote->deposit > 0 && $quote->deposit < $quote->total)
                                <label class="radio-card"><input type="radio" wire:model.live="pay" value="deposit"><span><span class="h3" style="font-size:var(--t-md)">Pay a deposit now, the rest at check-in</span><p class="meta">₦{{ number_format($quote->deposit) }} now, ₦{{ number_format($quote->total - $quote->deposit) }} when you arrive.</p></span></label>
                            @endif
                        </div>
                    </div>

                    <div class="submit">
                        <button class="btn btn-primary btn-lg" type="submit" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="book">Pay ₦{{ number_format($pay === 'deposit' && $quote->deposit > 0 ? $quote->deposit : $quote->total) }}</span>
                            <span wire:loading wire:target="book">Opening Paystack…</span>
                        </button>
                        <p class="meta">By paying you agree to the <a href="{{ route('legal.terms') }}">terms</a>{{ ($rules['cancellation_policy'] ?? '') !== '' ? ' and the cancellation policy' : '' }}.</p>
                        @error('booking')<p class="form-error" role="alert" style="flex-basis:100%">{{ $message }}</p>@enderror
                    </div>
                </form>
            @endif

            <aside class="card summary">
                @if ($photo)<img src="{{ $photo }}" alt="">@endif
                <div class="card-pad">
                    <div><h2 class="h3">{{ $type->name }}</h2><p class="meta">{{ $branch->name }}@if ($branch->address) · {{ $branch->address }}@endif</p></div>
                    <div class="when">
                        <div><span class="seg-label">Check in</span><div class="seg-value">{{ $stay->checkin->format('D j M') }}</div>@if ($t = \App\Site\Stay::time($rules['check_in_from'] ?? ''))<span class="meta">from {{ $t }}</span>@endif</div>
                        <div><span class="seg-label">Check out</span><div class="seg-value">{{ $stay->checkout->format('D j M') }}</div>@if ($t = \App\Site\Stay::time($rules['check_out_to'] ?? ''))<span class="meta">by {{ $t }}</span>@endif</div>
                    </div>
                    @if ($quote)
                        <dl class="lines">
                            <dt>₦{{ number_format($quote->wasPerNight() ?? $quote->perNight()) }} × {{ $quote->nights }} {{ \Illuminate\Support\Str::plural('night', $quote->nights) }}</dt><dd>₦{{ number_format($quote->subtotal) }}</dd>
                            @if ($quote->discount > 0)<dt>Discount</dt><dd class="save">−₦{{ number_format($quote->discount) }}</dd>@endif
                            @if ($quote->fees > 0)<dt>Card and booking fees</dt><dd>₦{{ number_format($quote->fees) }}</dd>@endif
                            <dt>Guests</dt><dd>{{ $stay->adults }} {{ \Illuminate\Support\Str::plural('adult', $stay->adults) }}</dd>
                            <dt class="total">Total</dt><dd class="total">₦{{ number_format($quote->total) }}</dd>
                        </dl>
                    @endif
                    @if (($rules['cancellation_policy'] ?? '') !== '')<p class="meta">{{ $rules['cancellation_policy'] }}</p>@endif
                </div>
            </aside>
        </div>
    </main>
</div>
