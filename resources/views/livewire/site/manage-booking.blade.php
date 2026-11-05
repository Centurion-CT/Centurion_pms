{{-- Find a booking (App\Livewire\Site\ManageBooking). --}}
@php($site = app(\App\Site\BookingSite::class))
@php($business = $site->business())
@php($photo = $site->heroPhoto())
<div class="find">
    <main class="find-main">
        <a class="logo" href="{{ route('tenant.landing') }}">@if ($logo = $site->logo())<img src="{{ $logo }}" alt="">@endif<span>{{ $business->name }}</span></a>

        <div class="find-body">
            <h1 class="title">Find your booking</h1>
            <p class="lead">Pay your balance, change your dates, add extras or cancel.</p>
            <form wire:submit="find">
                <div class="field"><label for="ref">Booking reference</label><input class="input" id="ref" wire:model="ref" autocomplete="off" spellcheck="false" placeholder="BK-XXXXXXXX"><p class="hint">It's in your confirmation email.</p></div>
                <div class="field"><label for="contact">Email or phone you booked with</label><input class="input" id="contact" wire:model="contact" autocomplete="email"></div>
                @error('ref')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                @error('contact')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                <button class="btn btn-primary btn-lg btn-block" type="submit" wire:loading.attr="disabled" wire:target="find">Find my booking</button>
            </form>
            @if ($business->phone)
                <p class="meta after">Can't find it? Call <a href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}">{{ $business->phone }}</a></p>
            @endif
        </div>

        <footer class="find-foot">
            <span>Runs on Centurion</span>
            <nav aria-label="Footer"><a href="{{ route('legal.privacy') }}">Privacy</a><a href="{{ route('legal.terms') }}">Terms</a></nav>
        </footer>
    </main>

    <aside class="find-panel" aria-hidden="true">
        <p>{{ $business->primaryHost() }}</p>
        @if ($photo)
            <div class="hero-photo" style="background-image: url('{{ $photo }}');"></div>
        @else
            @include('site.partials.bars')
        @endif
    </aside>
</div>
