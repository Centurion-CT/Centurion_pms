@props([
    'media',                  // Spatie Media instance
    'conversion' => 'medium', // which conversion to load as the "real" image
    'alt' => '',
    'loading' => 'lazy',      // 'eager' for above-the-fold (hero, first card)
    'wrapperClass' => '',
    'imgClass' => '',
])

@php
    // Resolve URLs, if the requested conversion hasn't been generated
    // yet (queue lag, upload before the conversion was defined) fall
    // back to the original so the browser never renders a broken <img>.
    // Note: $media->getUrl($conversion) may still return a valid-looking
    // URL that 404s, so we can't rely on empty-string checks, use
    // hasGeneratedConversion() as the source of truth.
    $realUrl = $media->hasGeneratedConversion($conversion)
        ? $media->getUrl($conversion)
        : $media->getUrl();

    $placeholderUrl = $media->hasGeneratedConversion('placeholder')
        ? $media->getUrl('placeholder')
        : $realUrl;

    // Use the placeholder as the wrapper's background, browser shows
    // it immediately while the <img> streams in, then the <img>
    // covers it. The ::before pseudo blur is applied in CSS via
    // the .blurred-img wrapper class (defined in app.css).
@endphp

<span {{ $attributes->merge(['class' => 'blurred-img '.$wrapperClass])
        ->merge(['style' => '--blurred-img-placeholder: url('.$placeholderUrl.');']) }}>
    <img src="{{ $realUrl }}"
         alt="{{ $alt }}"
         loading="{{ $loading }}"
         decoding="async"
         class="blurred-img__real {{ $imgClass }}"
         onload="this.parentNode.classList.add('blurred-img--loaded')">
</span>
