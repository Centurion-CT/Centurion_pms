@props([
    // Already-saved media (Spatie MediaCollection or Collection<Media>).
    'media' => null,

    // Files currently staged on the parent Livewire component but not
    // yet persisted. Pass the raw property from the parent view:
    //     :pending="$gallery"
    'pending' => null,

    // Livewire property on the parent that holds pending uploads -
    // used by the file input's `wire:model` binding.
    'wireModel' => 'photos',

    // Parent Livewire methods. Each may be null to hide the
    // corresponding affordance.
    'wireUpload' => null,
    'wireReorder' => null,
    'wireSetHero' => null,
    'wireDelete' => null,
    'wireDeleteMany' => null,
    'wireSetAlt' => null,

    // Constraints.
    'maxFiles' => 10,
    'accept' => 'image/jpeg,image/png,image/webp',

    // Copy overrides.
    'label' => 'Photos',
    'help' => 'Drag photos here or click to browse.',
    'emptyLabel' => 'No photos yet.',

    // Per-tile alt-text input (Rooms use this; Branding + Categories don't).
    'altEditable' => false,
])

@php
    $savedMedia = $media instanceof \Illuminate\Support\Collection
        ? $media
        : ($media === null ? collect() : collect($media));
    $pendingFiles = $pending instanceof \Illuminate\Support\Collection
        ? $pending
        : ($pending === null ? collect() : collect($pending));

    $savedCount = $savedMedia->count();
    $remaining = max(0, ((int) $maxFiles) - $savedCount);
    $slotsLabel = $remaining === 0
        ? 'Full, remove one to add more'
        : $remaining.' slot'.($remaining === 1 ? '' : 's').' left';
@endphp

<div class="photo-manager"
     data-photo-manager
     @if ($wireUpload) data-upload-action="{{ $wireUpload }}" @endif
     @if ($wireReorder) data-reorder-action="{{ $wireReorder }}" @endif
     @if ($wireSetHero) data-set-hero-action="{{ $wireSetHero }}" @endif
     @if ($wireDeleteMany) data-delete-many-action="{{ $wireDeleteMany }}" @endif
     {{ $attributes }}>

    {{-- ── Header ─────────────────────────────────────────────── --}}
    <div class="photo-manager__header">
        <span class="photo-manager__title">{{ $label }}</span>
        <span class="photo-manager__meta" data-testid="{{ $wireModel }}-meta">
            {{ $savedCount }} of {{ $maxFiles }} &middot; {{ $slotsLabel }}
        </span>
    </div>

    {{-- ── Drop zone ──────────────────────────────────────────── --}}
    <div class="photo-manager__drop {{ $remaining === 0 ? 'is-disabled' : '' }}"
         data-role="drop-zone"
         data-testid="{{ $wireModel }}-drop">
        <svg class="photo-manager__drop-icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 3v14M6 9l6-6 6 6"/>
        </svg>
        <p class="photo-manager__drop-copy">{{ $help }}</p>
        <span class="photo-manager__drop-help">
            {{ strtoupper(str_replace('image/', '', str_replace(',', ' / ', $accept))) }} &middot; up to {{ $maxFiles }} files
        </span>
        <input type="file"
               class="photo-manager__file-input"
               wire:model="{{ $wireModel }}"
               multiple
               accept="{{ $accept }}"
               @disabled($remaining === 0)
               data-testid="{{ $wireModel }}-input">
    </div>
    <p class="photo-manager__loading" wire:loading wire:target="{{ $wireModel }}">
        Staging preview&hellip;
    </p>

    {{-- ── Pending (staged, not yet saved) previews ───────────── --}}
    @if ($pendingFiles->isNotEmpty())
        @php
            // Room Categories stage files here and commit on form save
            // (no auto-upload wired), call that out honestly. Elsewhere
            // (Branding, Rooms) the file auto-uploads immediately so the
            // chip reads as an in-flight state.
            $pendingChip = $wireUpload ? 'Uploading…' : 'Ready to save';
            $pendingHint = $wireUpload
                ? 'These are staging in the background. They\'ll appear in the gallery once uploaded.'
                : 'Selected but not saved, press the form\'s Save button to attach them.';
        @endphp
        <div data-testid="{{ $wireModel }}-pending-block">
            <p class="photo-manager__pending-title">
                Selected &middot; {{ $pendingFiles->count() }} {{ $wireUpload ? 'pending upload' : 'ready to save' }}
            </p>
            <p class="photo-manager__pending-help">{{ $pendingHint }}</p>
            <div class="photo-manager__grid">
                @foreach ($pendingFiles as $i => $file)
                    <div class="photo-manager__pending-tile"
                         wire:key="pending-{{ $wireModel }}-{{ $i }}-{{ is_object($file) ? spl_object_id($file) : md5((string) $file) }}"
                         data-testid="{{ $wireModel }}-pending-{{ $i }}">
                        @if (is_object($file) && method_exists($file, 'isPreviewable') && $file->isPreviewable())
                            <img src="{{ $file->temporaryUrl() }}"
                                 alt="{{ $file->getClientOriginalName() }}"
                                 onerror="this.style.display='none'; this.parentNode.querySelector('.photo-manager__pending-fallback')?.style.setProperty('display','flex');">
                            <div class="photo-manager__pending-fallback" style="display:none;">
                                <span class="photo-manager__pending-fallback-name">
                                    {{ \Illuminate\Support\Str::limit((string) $file->getClientOriginalName(), 24) }}
                                </span>
                                <span class="photo-manager__pending-fallback-note">Preview unavailable</span>
                            </div>
                        @else
                            <div class="photo-manager__pending-fallback" style="display:flex;">
                                <span class="photo-manager__pending-fallback-name">
                                    {{ \Illuminate\Support\Str::limit((string) (is_object($file) ? $file->getClientOriginalName() : $file), 24) }}
                                </span>
                            </div>
                        @endif
                        <span class="photo-manager__pending-chip">{{ $pendingChip }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Saved-media grid ───────────────────────────────────── --}}
    @if ($savedCount === 0)
        <p class="photo-manager__empty" data-testid="{{ $wireModel }}-empty">
            {{ $emptyLabel }}
        </p>
    @else
        <div class="photo-manager__grid" data-role="grid" data-testid="{{ $wireModel }}-grid">
            @foreach ($savedMedia as $m)
                @php $isHero = (bool) $m->getCustomProperty('is_hero'); @endphp
                <div class="photo-manager__tile {{ $isHero ? 'is-hero' : '' }}"
                     data-media-id="{{ $m->id }}"
                     data-is-hero="{{ $isHero ? '1' : '0' }}"
                     data-testid="{{ $wireModel }}-tile-{{ $m->id }}">

                    <input type="checkbox"
                           class="photo-manager__tile-select"
                           data-role="tile-select"
                           aria-label="Select photo"
                           data-testid="{{ $wireModel }}-select-{{ $m->id }}">

                    @if ($wireReorder)
                        <span class="photo-manager__drag-handle" data-role="drag-handle" aria-label="Drag to reorder" title="Drag to reorder">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                                <path d="M3 3h6M3 6h6M3 9h6"/>
                            </svg>
                        </span>
                    @endif

                    @if ($isHero)
                        <span class="photo-manager__hero-badge" data-testid="{{ $wireModel }}-hero-{{ $m->id }}">Hero</span>
                    @endif

                    <x-blurred-image :media="$m" conversion="thumb"
                        :alt="(string) ($m->getCustomProperty('alt') ?? '')" />

                    <div class="photo-manager__tile-actions">
                        @if ($wireSetHero && ! $isHero)
                            <button type="button"
                                    class="photo-manager__tile-btn"
                                    data-role="set-hero"
                                    aria-label="Set as hero"
                                    data-testid="{{ $wireModel }}-set-hero-{{ $m->id }}"
                                    title="Set as hero">
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor">
                                    <path d="M6 1l1.5 3.1L11 4.6 8.5 7l.6 3.5L6 8.8 2.9 10.5 3.5 7 1 4.6l3.5-.5z"/>
                                </svg>
                            </button>
                        @endif
                        @if ($wireDelete)
                            <button type="button"
                                    class="photo-manager__tile-btn"
                                    wire:click="{{ $wireDelete }}({{ $m->id }})"
                                    wire:confirm="Remove this photo?"
                                    aria-label="Remove"
                                    data-testid="{{ $wireModel }}-delete-{{ $m->id }}">
                                <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                                    <path d="M3 3l6 6M9 3l-6 6"/>
                                </svg>
                            </button>
                        @endif
                    </div>

                    @if ($altEditable && $wireSetAlt)
                        <div class="photo-manager__tile-body">
                            <input type="text"
                                   value="{{ $m->getCustomProperty('alt') }}"
                                   placeholder="Alt text (accessibility)"
                                   wire:change="{{ $wireSetAlt }}({{ $m->id }}, $event.target.value)"
                                   data-testid="{{ $wireModel }}-alt-{{ $m->id }}"
                                   class="lp-input"
                                   style="font-size: 12.5px; padding: 6px 10px;">
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ── Bulk-select floating bar ────────────────────────── --}}
        @if ($wireDeleteMany)
            <div class="photo-manager__bulk-bar" data-role="bulk-bar" data-testid="{{ $wireModel }}-bulk-bar">
                <span class="photo-manager__bulk-copy">
                    <span data-role="bulk-count">0</span> selected
                </span>
                <div class="photo-manager__bulk-actions">
                    <button type="button" class="photo-manager__bulk-btn"
                            data-action="clear-selection"
                            data-testid="{{ $wireModel }}-bulk-clear">Cancel</button>
                    <button type="button" class="photo-manager__bulk-btn photo-manager__bulk-btn--danger"
                            data-action="delete-selected"
                            data-testid="{{ $wireModel }}-bulk-delete">Delete selected</button>
                </div>
            </div>
        @endif
    @endif
</div>
