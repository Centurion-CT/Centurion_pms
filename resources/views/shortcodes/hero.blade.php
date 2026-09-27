@php
    $heroSlides = $heroSlides ?? collect();
    $heroUrl = $heroUrl ?? null;
@endphp

<header data-testid="hero" class="hero">
    @if ($heroSlides->isNotEmpty())
        <div class="hero-slides" data-carousel="hero">
            @foreach ($heroSlides as $i => $url)
                <div class="hero-slide{{ $i === 0 ? ' active' : '' }}"
                     style="background-image: url('{{ $url }}');"
                     data-slide></div>
            @endforeach
        </div>
    @endif

    <div class="hero-content">
        @if (isset($rating) && $rating['count'] > 0)
            <span class="badge rating">★ {{ $rating['average'] }} · {{ $rating['count'] }} review{{ $rating['count'] === 1 ? '' : 's' }}</span>
        @endif
        <h1>{{ $attrs['title'] ?? $property->name }}</h1>
        @if ($property->tagline)
            <p class="tagline">{{ $attrs['subtitle'] ?? $property->tagline }}</p>
        @endif
        <a href="#rooms" class="cta">{{ $attrs['cta'] ?? 'Check availability' }}</a>
    </div>

    @if ($heroSlides->count() > 1)
        <div class="hero-dots" data-carousel-dots="hero">
            @foreach ($heroSlides as $i => $url)
                <button type="button" class="hero-dot{{ $i === 0 ? ' active' : '' }}"
                        data-dot-index="{{ $i }}" aria-label="Slide {{ $i + 1 }}"></button>
            @endforeach
        </div>
    @endif
</header>
