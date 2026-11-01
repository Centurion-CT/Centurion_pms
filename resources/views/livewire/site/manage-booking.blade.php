{{-- Find a booking (App\Livewire\Site\ManageBooking). --}}
<div>
    @include('site.partials.header', ['nav' => false, 'help' => true])
    <main class="container page">
        <div class="narrow">
            <h1 class="h1">Manage your booking</h1>
            <p class="lead" style="margin-top:var(--s-3)">See your booking, pay the balance, change dates or cancel.</p>
            <form class="card card-pad" style="margin-top:var(--s-8);display:grid;gap:var(--s-5)" wire:submit="find">
                <div class="field"><label for="ref">Booking reference</label><input class="input" id="ref" wire:model="ref" autocomplete="off" spellcheck="false" placeholder="From your confirmation email"></div>
                <div class="field"><label for="contact">Email or phone you booked with</label><input class="input" id="contact" wire:model="contact" autocomplete="email"></div>
                @error('ref')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                @error('contact')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                <div><button class="btn btn-primary btn-lg" type="submit" wire:loading.attr="disabled">Find my booking</button></div>
            </form>
        </div>
    </main>
    @include('site.partials.footer')
</div>
