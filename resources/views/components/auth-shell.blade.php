@props(['subtitle' => 'Staff sign-in'])
@php
    $tenant = \App\Models\Tenant::current();
    $property = $tenant?->property;
    $tenantDisplayName = $tenant?->name ?? 'Your property';

    // Right-hand photo: the hotel's own gallery photo, else a room photo,
    // else the bundled default (public/brand/login-default.jpg — Unsplash
    // photo-1611892440504, free to use under the Unsplash License).
    $photo = $property?->getMedia('gallery')->first()
        ?? $property?->rooms()->get()->flatMap(fn ($r) => $r->getMedia('gallery'))->first();
    $photoUrl = $photo?->getUrl() ?? asset('brand/login-default.jpg');
    $photoCaption = $photo?->getCustomProperty('caption');
@endphp

{{-- ─── Staff auth shell (Centurion "Harbour") ────────────────────────
     Navy form slab on the left, a photo on the right (the property's
     own, else a bundled default). Used by login, forgot password, reset
     password and invite activation; each page supplies the slab body. --}}
<div class="cx-login">
    @include('partials.auth.harbour-styles')

    <section class="cx-slab">
        <a href="{{ url('/') }}" class="cx-brand">
            <span class="cx-brand-mark"><img src="{{ $tenant?->logoUrl() ?? asset(config('brand.logo')) }}" alt="" style="object-fit: contain;"></span>
            <span>
                <span class="cx-brand-name" style="display:block;">{{ $tenantDisplayName }}</span>
                <span class="cx-brand-sub">{{ $subtitle }}</span>
            </span>
        </a>

        <div class="cx-body">
            {{ $slot }}
        </div>

        <footer class="cx-foot">
            <span>{{ $tenantDisplayName }} · runs on {{ config('brand.name') }}</span>
            <nav>
                <a href="{{ route('legal.privacy') }}">Privacy</a>
                <a href="{{ route('legal.terms') }}">Terms</a>
            </nav>
        </footer>
    </section>

    <aside class="cx-photo" style="background-image: url('{{ $photoUrl }}');" aria-hidden="true">
        @if ($photoCaption)
            <div class="cx-photo-caption"><strong>{{ $photoCaption }}</strong><span>{{ $tenantDisplayName }}</span></div>
        @endif
    </aside>
</div>
