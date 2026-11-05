{{-- Booking site checkout (App\Livewire\Site\Checkout). --}}
@php($backUrl = route('site.apartment', ['branch' => $branch->slug, 'type' => $type->slug] + $stay->query()))
@php($hasDeposit = $quote !== null && $quote->deposit > 0 && $quote->deposit < $quote->total)
@php($payNow = $quote === null ? 0 : ($pay === 'deposit' && $hasDeposit ? $quote->deposit : $quote->total))
@php($business = app(\App\Site\BookingSite::class)->business())
<div>
    @include('site.partials.header', ['nav' => false, 'help' => true])

    <main class="band">
        <div class="container container--narrow">
            <a class="back" href="{{ $backUrl }}">Back to {{ $type->name }}</a>

            @if (! $available || ! $quote)
                <h1 class="title">{{ $type->name }} isn't free for {{ $stay->label() }}</h1>
            @elseif ($canPay)
                <h1 class="title">Finish your booking</h1>
            @else
                <h1 class="title">Book {{ $type->name }}</h1>
            @endif

            <div class="layout">
                @if (! $available || ! $quote)
                    <div class="form">
                        <section class="panel">
                            <p class="lead">Someone booked it, or it isn't open for these dates. Pick another apartment or other dates.</p>
                            <p><a class="btn btn-primary" href="{{ route('site.results', $stay->query()) }}">See what's free</a></p>
                        </section>
                    </div>
                @elseif (! $canPay)
                    <div class="form">
                        <section class="panel panel--dark">
                            <h2 class="subhead">Book by phone</h2>
                            <p>{{ $noPaymentsMessage }}</p>
                            @if ($business->phone)
                                <a class="btn btn-inverse" href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}">Call {{ $business->phone }}</a>
                            @endif
                        </section>
                    </div>
                @else
                    <form class="form" id="checkout" wire:submit="book">
                        <section class="panel">
                            <h2 class="subhead">Who's staying?</h2>
                            <div class="fields">
                                <div class="field"><label for="first">First name</label><input class="input" id="first" wire:model="firstName" autocomplete="given-name">@error('firstName')<p class="error">{{ $message }}</p>@enderror</div>
                                <div class="field"><label for="last">Last name</label><input class="input" id="last" wire:model="lastName" autocomplete="family-name">@error('lastName')<p class="error">{{ $message }}</p>@enderror</div>
                                <div class="field"><label for="phone">Phone</label><input class="input" id="phone" type="tel" wire:model="phone" autocomplete="tel" placeholder="0803 000 0000"><p class="hint">We call this number if anything changes.</p>@error('phone')<p class="error">{{ $message }}</p>@enderror</div>
                                <div class="field"><label for="email">Email</label><input class="input" id="email" type="email" wire:model="email" autocomplete="email" placeholder="you@company.com"><p class="hint">Your receipt and booking link go here.</p>@error('email')<p class="error">{{ $message }}</p>@enderror</div>
                                <div class="field"><label for="arrive">Arriving around</label>
                                    <select class="input" id="arrive" wire:model="arrival">@foreach (\App\Livewire\Site\Checkout::ARRIVALS as $a)<option>{{ $a }}</option>@endforeach</select>
                                </div>
                                <div class="field"><label for="company">Company <span>Optional</span></label><input class="input" id="company" wire:model="company" placeholder="For the invoice" autocomplete="organization"></div>
                                <div class="field field--full"><label for="note">Anything we should know? <span>Optional</span></label><textarea class="input" id="note" wire:model="notes" placeholder="Early check-in, airport pickup, an extra bed…"></textarea></div>
                            </div>
                        </section>

                        @if ($hasDeposit)
                            <section class="panel">
                                <h2 class="subhead">How much do you want to pay now?</h2>
                                <div class="pay">
                                    <label class="pay-option"><input type="radio" wire:model.live="pay" value="full"><span><b>Pay in full</b><small>Nothing to pay when you arrive.</small></span><span class="amount">₦{{ number_format($quote->total) }}</span></label>
                                    <label class="pay-option"><input type="radio" wire:model.live="pay" value="deposit"><span><b>Pay a deposit</b><small>₦{{ number_format($quote->total - $quote->deposit) }} when you arrive.</small></span><span class="amount">₦{{ number_format($quote->deposit) }}</span></label>
                                </div>
                            </section>
                        @endif
                    </form>
                @endif

                <aside class="summary">
                    @if ($photo)<img src="{{ $photo }}" alt="">@endif
                    <div class="summary-body">
                        <h2>{{ $type->name }}</h2>
                        <p class="meta">{{ $branch->address ? $branch->address.', ' : '' }}{{ $branch->name }}</p>
                        @php($checkIn = \App\Site\Stay::time($rules['check_in_from'] ?? ''))
                        @php($checkOut = \App\Site\Stay::time($rules['check_out_to'] ?? ''))
                        <dl class="lines">
                            <dt>Check in</dt><dd>{{ $stay->checkin->format('D j M') }}{{ $checkIn ? ', from '.$checkIn : '' }}</dd>
                            <dt>Check out</dt><dd>{{ $stay->checkout->format('D j M') }}{{ $checkOut ? ', by '.$checkOut : '' }}</dd>
                            <dt>Guests</dt><dd>{{ $stay->adults }} {{ \Illuminate\Support\Str::plural('adult', $stay->adults) }}</dd>
                            @if ($quote)
                                <dt>₦{{ number_format($quote->wasPerNight() ?? $quote->perNight()) }} × {{ $quote->nights }} {{ \Illuminate\Support\Str::plural('night', $quote->nights) }}</dt><dd>₦{{ number_format($quote->subtotal) }}</dd>
                                @if ($quote->discount > 0)<dt>Discount</dt><dd class="save">−₦{{ number_format($quote->discount) }}</dd>@endif
                                @if ($quote->fees > 0)<dt>Card and booking fees</dt><dd>₦{{ number_format($quote->fees) }}</dd>@endif
                                <dt class="total">Total</dt><dd class="total">₦{{ number_format($quote->total) }}</dd>
                            @endif
                        </dl>
                        @if ($available && $quote && $canPay)
                            <div class="pay-bar">
                                @error('booking')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                                @if ($errors->hasAny(['firstName', 'lastName', 'phone', 'email']))<p class="pay-check">Check your details above.</p>@endif
                                <div class="pay-row">
                                    <p class="pay-now" style="margin:0"><span>Pay now</span><b>₦{{ number_format($payNow) }}</b></p>
                                    <button class="btn btn-primary btn-lg btn-block" type="submit" form="checkout" wire:loading.attr="disabled" wire:target="book">
                                        <span wire:loading.remove wire:target="book"><span class="hide-sm">Pay ₦{{ number_format($payNow) }}</span><span class="show-sm">Pay with Paystack</span></span>
                                        <span wire:loading wire:target="book">Opening Paystack…</span>
                                    </button>
                                </div>
                                <p class="meta">Secured by Paystack. By paying you agree to the <a href="{{ route('legal.terms') }}">terms</a>{{ ($rules['cancellation_policy'] ?? '') !== '' ? ' and the cancellation policy' : '' }}.</p>
                            </div>
                        @else
                            @error('booking')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                        @endif
                        @if (($rules['cancellation_policy'] ?? '') !== '')<p class="meta policy">{{ $rules['cancellation_policy'] }}</p>@endif
                    </div>
                </aside>
            </div>
        </div>
    </main>
</div>
