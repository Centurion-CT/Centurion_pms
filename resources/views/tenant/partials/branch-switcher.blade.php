@php
    /**
     * Guest-facing branch switcher — appears in the topbar of every
     * per-branch public page (landing, rooms browse, room detail,
     * category detail). Self-hides for single-branch tenants so the
     * pre-multi-property surface stays unchanged.
     *
     * Inclusion contract:
     *   @include('tenant.partials.branch-switcher', [
     *       'branches' => $branches,   // Collection<Property>
     *       'current'  => $property,   // Property|null (highlights current)
     *   ])
     */
    if (($branches ?? collect())->count() < 2) {
        return;
    }
    $current = $current ?? null;
@endphp

<div class="bsw-wrap"
     x-data="{ open: false }"
     x-on:keydown.escape.window="open = false"
     x-on:click.outside="open = false"
     data-testid="branch-switcher">
    <button type="button"
            class="bsw-trigger"
            x-on:click="open = !open"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="listbox"
            data-testid="branch-switcher-trigger">
        <span class="bsw-label">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 21s-7-6.5-7-12a7 7 0 1 1 14 0c0 5.5-7 12-7 12z"/>
                <circle cx="12" cy="9" r="2.5"/>
            </svg>
            <span>{{ $current?->name ?? 'Choose a location' }}</span>
        </span>
        <svg width="10" height="10" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 4.5L6 8l3-3.5"/>
        </svg>
    </button>

    <div x-show="open"
         x-transition.opacity.duration.120ms
         x-cloak
         class="bsw-menu"
         role="listbox"
         data-testid="branch-switcher-menu">
        @foreach ($branches as $branch)
            <a href="{{ route('tenant.branch.show', ['property' => $branch->slug]) }}"
               class="bsw-item @if ($current && $current->getKey() === $branch->getKey()) is-current @endif"
               role="option"
               aria-selected="{{ $current && $current->getKey() === $branch->getKey() ? 'true' : 'false' }}"
               data-testid="branch-switcher-item-{{ $branch->slug }}">
                <span class="bsw-item-name">{{ $branch->name }}</span>
                @if ($branch->city || $branch->state)
                    <span class="bsw-item-location">{{ trim(collect([$branch->city, $branch->state])->filter()->implode(', ')) }}</span>
                @endif
            </a>
        @endforeach
    </div>
</div>

<style>
    .bsw-wrap { position: relative; }
    .bsw-trigger {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 12px;
        background: transparent;
        border: 1px solid var(--rb-line, var(--rd-line, #ECE6D8));
        border-radius: 999px;
        color: var(--rb-ink, var(--rd-ink, #14171A));
        font-size: 13px; font-weight: 500;
        cursor: pointer;
        transition: background 120ms ease, border-color 120ms ease;
    }
    .bsw-trigger:hover {
        background: var(--rb-paper, var(--rd-paper, #FAF7F2));
        border-color: var(--rb-ink-mute, var(--rd-ink-mute, #7A7F87));
    }
    .bsw-label { display: inline-flex; align-items: center; gap: 6px; }
    .bsw-menu {
        position: absolute; top: calc(100% + 6px); right: 0;
        z-index: 40;
        min-width: 240px;
        background: #fff;
        border: 1px solid var(--rb-line, var(--rd-line, #ECE6D8));
        border-radius: 12px;
        box-shadow: 0 12px 30px rgba(20,17,12,0.10);
        overflow: hidden;
        padding: 4px;
    }
    .bsw-item {
        display: block; padding: 10px 12px;
        text-decoration: none; color: var(--rb-ink, var(--rd-ink, #14171A));
        border-radius: 8px;
        font-size: 13.5px; line-height: 1.4;
    }
    .bsw-item:hover { background: var(--rb-paper, var(--rd-paper, #F2EEE6)); }
    .bsw-item.is-current { background: var(--rb-paper, var(--rd-paper, #F2EEE6)); font-weight: 600; }
    .bsw-item-name { display: block; }
    .bsw-item-location {
        display: block; margin-top: 2px;
        font-size: 11.5px; color: var(--rb-ink-mute, var(--rd-ink-mute, #7A7F87));
    }
    [x-cloak] { display: none !important; }
</style>
