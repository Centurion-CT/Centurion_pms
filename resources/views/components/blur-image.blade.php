@props([
    'media' => null,
    'src' => null,
    'placeholder' => null,
    'conversion' => 'medium',
    'alt' => '',
    'loading' => 'lazy',
    'decoding' => 'async',
    'imgClass' => '',
    'imgStyle' => '',
])
@php
    // Prefer the Media instance so we can pull the pre-generated
    // "placeholder" conversion off the same file. Fall back to plain
    // src/placeholder URLs when the caller only has strings.
    $fullUrl = $src;
    $placeholderUrl = $placeholder;
    if ($media !== null) {
        $fullUrl = $media->getUrl($conversion);
        if (method_exists($media, 'hasGeneratedConversion')
            && $media->hasGeneratedConversion('placeholder')) {
            $placeholderUrl = $media->getUrl('placeholder');
        }
    }
    $hasPlaceholder = ! empty($placeholderUrl);
@endphp
<span {{ $attributes->merge(['class' => 'kv-blur' . ($hasPlaceholder ? ' has-placeholder' : '')]) }}
      @if ($hasPlaceholder) style="--kv-blur-src: url('{{ $placeholderUrl }}');" @endif>
    @if ($fullUrl)
        <img src="{{ $fullUrl }}"
             alt="{{ $alt }}"
             loading="{{ $loading }}"
             decoding="{{ $decoding }}"
             @if ($imgClass) class="{{ $imgClass }}" @endif
             @if ($imgStyle) style="{{ $imgStyle }}" @endif
             onload="this.parentElement.classList.add('is-loaded')">
    @endif
</span>
