@php($priceFrom = $priceFrom ?? null)

@if ($priceFrom)
    <span class="badge" data-testid="price-from-badge">
        {{ $attrs['prefix'] ?? 'From' }} ₦{{ number_format($priceFrom) }}
        {{ $attrs['suffix'] ?? '/ night' }}
    </span>
@endif
