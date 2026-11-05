@php
    $tenant = \App\Models\Tenant::current();
    $property = $tenant?->property;
    $tenantDisplayName = $tenant?->name ?? 'Your property';
    $logo = $tenant?->logoUrl();
    $businessColor = \App\Support\BusinessColor::forSignIn($tenant);

    // The business's own photo, through the logo's three bars. With no
    // photo yet the bars are solid, so nobody gets someone else's room.
    $photo = $property?->getMedia('gallery')->first()
        ?? $property?->rooms()->get()->flatMap(fn ($r) => $r->getMedia('gallery'))->first();
    $photoUrl = $photo?->getUrl();
@endphp

{{-- ─── Staff auth shell ───────────────────────────────────────────────
     The business leads: a light form beside a panel in its colour, else
     Centurion's. Used by login, forgot password, reset password and
     invite activation; each page supplies the form. --}}
<div class="cx-login" @if ($businessColor) style="--cx-panel: {{ $businessColor }}; --cx-action: {{ $businessColor }};" @endif>
    @include('partials.auth.harbour-styles')

    <aside class="cx-panel" aria-hidden="true">
        <p class="cx-address">{{ $tenant?->primaryHost() }}</p>
        <span class="cx-band-brand">
            @if ($logo)
                <img class="cx-mark" src="{{ $logo }}" alt="">
            @else
                <span class="cx-mark cx-mark-letter">{{ mb_strtoupper(mb_substr($tenantDisplayName, 0, 1)) }}</span>
            @endif
            {{ $tenantDisplayName }}
        </span>
        @if ($photoUrl)
            <div class="cx-bars cx-bars-photo" style="background-image: url('{{ $photoUrl }}');"></div>
        @else
            <div class="cx-bars cx-bars-solid"><i></i><i></i><i></i></div>
        @endif
    </aside>

    <main class="cx-column">
        <a href="{{ url('/') }}" class="cx-brand">
            @if ($logo)
                <img class="cx-mark" src="{{ $logo }}" alt="">
            @else
                <span class="cx-mark cx-mark-letter" aria-hidden="true">{{ mb_strtoupper(mb_substr($tenantDisplayName, 0, 1)) }}</span>
            @endif
            <span>{{ $tenantDisplayName }}</span>
        </a>

        <div class="cx-body">
            {{ $slot }}
        </div>

        <footer class="cx-foot">
            <span>Runs on {{ config('brand.name') }}</span>
            <nav>
                <a href="{{ route('legal.privacy') }}">Privacy</a>
                <a href="{{ route('legal.terms') }}">Terms</a>
            </nav>
        </footer>
    </main>
</div>
