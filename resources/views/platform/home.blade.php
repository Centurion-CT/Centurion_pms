{{-- centurionpms.com home. Design: design/platform/superdesign/02-home-product-tour.png.
     The address field checks availability live (platform.address-check)
     and hands the slug to sign-up. --}}
@extends('platform.layouts.app')

@section('title', config('brand.name').' · Software for apartments and hotels')

@php($suffix = config('keverna.tenant_domain_suffix'))
@php($helpEmail = 'hello@'.config('keverna.central_domain'))

@section('content')
<header class="header">
    <div class="container">
        <a class="logo" href="{{ route('platform.home') }}"><span class="logo-mark"><img src="{{ asset(config('brand.logo')) }}" alt=""></span>{{ config('brand.name') }}</a>
        <nav class="nav" aria-label="Main">
            <a href="#tour">Front desk</a><a href="#tour">Branches</a><a href="#tour">Booking site</a><a href="#questions">Questions</a>
        </nav>
        <div class="end">
            <a class="btn btn-ghost btn-sm" href="{{ route('platform.login') }}">Log in</a>
            <a class="btn btn-light btn-sm" href="{{ route('platform.signup') }}">Create account</a>
        </div>
    </div>
</header>

<section class="hero on-navy">
    <div class="container">
        <h1 class="display">Run your apartments from one desk</h1>
        <p class="lead">Bookings, check-in, housekeeping, payments and your own booking website, made for serviced apartments and hotels in Nigeria.</p>

        <form class="picker" action="{{ route('platform.signup') }}" method="get" data-address-form>
            <label class="picker-label" for="addr">Choose your address to start</label>
            <div class="address">
                <input id="addr" name="slug" placeholder="your-business" autocomplete="off" spellcheck="false" maxlength="30" aria-describedby="addr-status" data-address-input>
                <span class="suffix">{{ $suffix }}</span>
                <button class="btn btn-primary" type="submit">Start</button>
            </div>
            <p class="astatus" id="addr-status" aria-live="polite" data-address-status></p>
            <p class="hero-foot">Already on {{ config('brand.name') }}? <a href="{{ route('platform.login') }}">Log in to your business</a></p>
        </form>

        <div class="compo" aria-hidden="true">
            <div class="frame back"><img src="{{ asset('platform/room-calendar.jpg') }}" alt=""></div>
            <div class="frame front"><img src="{{ asset('platform/desk-today.jpg') }}" alt=""></div>
            <div class="frame float">
                <div class="browser-bar"><span class="dots"><i></i><i></i><i></i></span><span class="burl">https://<b>grandpalace{{ $suffix }}</b></span></div>
                <div class="site"><img src="{{ asset('platform/booking-site.jpg') }}" alt=""></div>
            </div>
        </div>
    </div>
</section>

<main>
    <section class="tour container" id="tour">
        <div class="tour-head">
            <h2 class="h2">A product tour, front desk to booking site</h2>
            <p class="lead" style="margin-top:14px">Everything a serviced-apartment business runs on, in one place. Pick a tab to see it.</p>
        </div>
        @php($tabs = [
            'desk' => ['Front desk', 'The Today screen is the whole front desk: who is arriving, who is in the house, who is leaving and the money collected so far.', ['Check in, check out, extend and move rooms in a couple of clicks', 'Take payment by cash, card, transfer or Paystack, with a receipt every time', "Bar, kitchen and extras go on the guest's bill"], 'desk-today.jpg', 'The front desk Today screen: arrivals, guests in the house, departures and money collected today', null],
            'calendar' => ['Room calendar', 'The room calendar shows who is in each room and who is coming. Drag a stay to change dates or move rooms. Click a gap to book it.', ['See every room and every night across one or two weeks at a glance', "Drag a stay's ends to change dates, drag it up or down to move rooms", 'Click a free gap to start a booking right there, with no double bookings'], 'room-calendar.jpg', 'Room calendar with stays drawn as bars across two weeks', null],
            'branches' => ['All branches', 'Run one building or twenty. Each branch has its own rooms, staff and money, and the owner sees all of it on one page.', ['Money collected, still to collect and spent, per branch and in total', 'Staff only see the branches you give them', 'A summary in your inbox every morning'], 'all-branches.jpg', "All branches page: money collected this month and tonight's occupancy for each branch", null],
            'site' => ['Booking site', 'Guests see live availability across all your branches and pay online. Every booking lands on the room calendar, with no double bookings.', ['Starts on your '.config('brand.name').' address, moves to your own domain when you\'re ready', 'Your photos, rooms, prices and logo', 'No commission on direct bookings'], 'booking-site.jpg', 'A booking website: a map with prices at each building', 'centurionapartments.ng'],
        ])
        <div class="tabs" role="tablist" aria-label="Product tour">
            @foreach ($tabs as $key => $tab)
                <button class="tab" type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}" aria-selected="{{ $key === 'calendar' ? 'true' : 'false' }}" data-tab="{{ $key }}">{{ $tab[0] }}</button>
            @endforeach
        </div>
        @foreach ($tabs as $key => [$label, $lead, $points, $image, $alt, $url])
            <div class="tabbody" role="tabpanel" id="panel-{{ $key }}" aria-labelledby="tab-{{ $key }}" @if ($key !== 'calendar') hidden @endif>
                <div>
                    <p class="lead">{{ $lead }}</p>
                    <ul>@foreach ($points as $point)<li>{{ $point }}</li>@endforeach</ul>
                </div>
                <div class="shot">
                    @if ($url)
                        <div class="browser-bar"><span class="dots"><i></i><i></i><i></i></span><span class="burl">https://<b>{{ $url }}</b></span></div>
                    @endif
                    <div @class(['tall' => in_array($key, ['branches', 'site'], true)])><img src="{{ asset('platform/'.$image) }}" alt="{{ $alt }}" loading="lazy"></div>
                </div>
            </div>
        @endforeach
    </section>

    <section class="band">
        <div class="container">
            <h2 class="h2">Made for how apartments here actually run</h2>
            <div class="facts">
                <div class="fact"><h3 class="h3">Paystack and bank transfers</h3><p>Online payments go straight to your Paystack account. Transfers are recorded against the right booking.</p></div>
                <div class="fact"><h3 class="h3">Company guests</h3><p>Corporate accounts with their own rates, a prepaid wallet and one invoice for the month.</p></div>
                <div class="fact"><h3 class="h3">Housekeeping on the phone</h3><p>Cleaners see which rooms to do next and mark them ready. The desk sees it at once.</p></div>
                <div class="fact"><h3 class="h3">Bar and kitchen till</h3><p>Sell from the bar or kitchen and charge it to a room, with stock counted as you go.</p></div>
                <div class="fact"><h3 class="h3">Long stays</h3><p>Monthly rates, leases and rent schedules for guests who stay for months.</p></div>
                <div class="fact"><h3 class="h3">Your data stays yours</h3><p>Each business is kept fully apart, on its own address, with its own staff and guests.</p></div>
            </div>
        </div>
    </section>

    <section class="story on-navy">
        <div class="container">
            <blockquote>Built at a real front desk</blockquote>
            <p class="lead" style="margin-top:20px;max-width:60ch">{{ config('brand.name') }} started as the system for Centurion Apartments' own five buildings in Abuja. Every screen has been used at a real front desk before you see it.</p>
            <div class="places"><span class="pill">Wuse 2</span><span class="pill">Zone 4</span><span class="pill">Jabi</span><span class="pill">Jabi Annex</span><span class="pill">Lifecamp</span></div>
        </div>
    </section>

    <section class="steps-sec container">
        <h2 class="h2">Getting started</h2>
        <ol class="steps">
            <li><h3 class="h3">Pick your address</h3><p>Your business name and a web address. It takes a minute.</p></li>
            <li><h3 class="h3">Add your rooms and prices</h3><p>Room types, rooms and rates for each branch. Invite your staff.</p></li>
            <li><h3 class="h3">Take bookings</h3><p>Use the desk from day one. Turn on your booking website when your photos are in.</p></li>
        </ol>
    </section>

    <section class="faq-sec" id="questions">
        <div class="container">
            <h2 class="h2">Questions</h2>
            <div class="faq faq-grid">
                <div>
                    <details open><summary>Can I use my own domain?</summary><p>Yes. You start on <b>your-business{{ $suffix }}</b>. When you're ready, add your own domain in Settings and we'll show you the DNS record to set. Both keep working.</p></details>
                    <details><summary>I have more than one building. Is that extra work?</summary><p>No. Add each building as a branch. Staff can work in one branch or several, and you see all of them together.</p></details>
                    <details><summary>Can other businesses on {{ config('brand.name') }} see my guests?</summary><p>No. Every business has its own address, staff and data. Nobody outside your business can see your bookings, guests or money.</p></details>
                </div>
                <div>
                    {{-- "What does it cost?" goes here once pricing is decided (docs/MULTITENANCY.md). --}}
                    <details><summary>Can I bring my bookings from another system?</summary><p>Yes. Email <a href="mailto:{{ $helpEmail }}">{{ $helpEmail }}</a> a spreadsheet of your upcoming bookings and guests and we'll help you load them.</p></details>
                </div>
            </div>
        </div>
    </section>

    <section class="closing on-navy">
        <div class="container">
            <h2 class="h1">Open your front desk today</h2>
            <p class="lead">Set up your first branch in a few minutes. No card needed.</p>
            <form class="picker" action="{{ route('platform.signup') }}" method="get" data-address-form>
                <div class="address">
                    <input name="slug" placeholder="your-business" autocomplete="off" spellcheck="false" maxlength="30" aria-label="Your address" aria-describedby="addr2-status" data-address-input>
                    <span class="suffix">{{ $suffix }}</span>
                    <button class="btn btn-primary" type="submit">Start</button>
                </div>
                <p class="astatus" id="addr2-status" aria-live="polite" data-address-status></p>
            </form>
        </div>
    </section>
</main>

<footer class="footer">
    <div class="container">
        <span>© {{ date('Y') }} {{ config('brand.name') }}</span>
        <nav aria-label="Footer">
            <a href="{{ route('platform.login') }}">Log in</a>
            <a href="{{ route('platform.signup') }}">Create account</a>
            <a href="mailto:{{ $helpEmail }}">{{ $helpEmail }}</a>
        </nav>
    </div>
</footer>

<script>
    // Product tour tabs.
    document.querySelectorAll('[data-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('[data-tab]').forEach(function (t) {
                var on = t === tab;
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                document.getElementById('panel-' + t.dataset.tab).hidden = !on;
            });
        });
    });

    // Address fields: tidy what's typed, ask the server whether it's free.
    document.querySelectorAll('[data-address-form]').forEach(function (form) {
        var input = form.querySelector('[data-address-input]');
        var status = form.querySelector('[data-address-status]');
        var timer, last = '';
        function check() {
            var slug = input.value.replace(/^-+|-+$/g, '');
            if (slug === last) return;
            last = slug;
            if (!slug) { status.textContent = ''; status.className = 'astatus'; return; }
            fetch(@json(route('platform.address-check')) + '?slug=' + encodeURIComponent(slug), { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.slug !== input.value.replace(/^-+|-+$/g, '')) return;
                    status.textContent = d.message;
                    status.className = 'astatus ' + (d.available ? 'is-free' : 'is-taken');
                })
                .catch(function () {});
        }
        input.addEventListener('input', function () {
            input.value = input.value.toLowerCase().replace(/[^a-z0-9-]/g, '').replace(/-{2,}/g, '-');
            clearTimeout(timer);
            timer = setTimeout(check, 250);
        });
    });
</script>
@endsection
