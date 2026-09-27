@props([
    // Array of filter definitions. Each shape:
    //   [
    //     'id'       => 'method',                 // Livewire prop name
    //     'label'    => 'Method',                 // Human label
    //     'value'    => $current,                 // Current bound value
    //     'default'  => 'all',                    // Value that means "not active"
    //     'display'  => 'Cash',                   // Text after the colon on the chip
    //     'options'  => [                         // Values the "+ Add filter" menu offers
    //         ['value' => 'cash', 'label' => 'Cash'],
    //         ...
    //     ],
    //     'wireDirective' => '$set(\'method\', \'cash\')', // optional per-option override
    //   ]
    'filters' => [],
    // A right-aligned "search" slot lives above the chip strip in the
    // same row. Passed via a search slot.
])

@php
    $activeFilters = collect($filters)
        ->filter(fn ($f) => ($f['value'] ?? null) !== null
            && $f['value'] !== ''
            && $f['value'] !== ($f['default'] ?? null))
        ->values();
    $availableFilters = collect($filters)
        ->reject(fn ($f) => $activeFilters->pluck('id')->contains($f['id']))
        ->values();
@endphp

<div {{ $attributes->merge(['class' => 'lp-filter-chips']) }}
     x-data="{
        addOpen: false,
        expandedId: null,
     }"
     x-on:click.outside="addOpen = false; expandedId = null"
     x-on:keydown.escape.window="addOpen = false; expandedId = null">

    @isset($search)
        <div class="lp-filter-chips__search">{{ $search }}</div>
    @endisset

    <div class="lp-filter-chips__row">
        {{-- Active chips ----------------------------------------------- --}}
        @foreach ($activeFilters as $f)
            <span class="lp-filter-chip" data-testid="filter-chip-{{ $f['id'] }}">
                <span class="lp-filter-chip__key">{{ $f['label'] }}</span>
                <span class="lp-filter-chip__val">{{ $f['display'] ?? $f['value'] }}</span>
                <button type="button"
                        class="lp-filter-chip__clear"
                        wire:click="$set('{{ $f['id'] }}', @js($f['default'] ?? null))"
                        aria-label="Clear {{ $f['label'] }} filter">
                    <svg width="9" height="9" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <path d="M3 3l6 6M9 3l-6 6"/>
                    </svg>
                </button>
            </span>
        @endforeach

        {{-- + Add filter menu ----------------------------------------- --}}
        @if ($availableFilters->isNotEmpty())
            <div class="lp-filter-chips__add">
                <button type="button"
                        class="lp-filter-add-btn"
                        x-on:click="addOpen = !addOpen; expandedId = null"
                        x-bind:aria-expanded="addOpen ? 'true' : 'false'"
                        aria-haspopup="menu"
                        data-testid="filter-add-btn">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 3v6M3 6h6"/>
                    </svg>
                    Add filter
                </button>
                <div x-show="addOpen"
                     x-cloak
                     x-transition.opacity.duration.120ms
                     class="lp-filter-add-menu"
                     role="menu">
                    @foreach ($availableFilters as $f)
                        <div class="lp-filter-add-menu__group"
                             x-data="{ id: '{{ $f['id'] }}' }">
                            <button type="button"
                                    class="lp-filter-add-menu__row"
                                    x-on:click="expandedId = (expandedId === id ? null : id)"
                                    x-bind:aria-expanded="expandedId === id ? 'true' : 'false'"
                                    data-testid="filter-add-{{ $f['id'] }}">
                                <span>{{ $f['label'] }}</span>
                                <svg width="10" height="10" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
                                    <path d="M4.5 3L7.5 6l-3 3"/>
                                </svg>
                            </button>
                            <div x-show="expandedId === id"
                                 x-cloak
                                 class="lp-filter-add-menu__options">
                                @foreach (($f['options'] ?? []) as $opt)
                                    @if (($opt['value'] ?? null) === ($f['default'] ?? null))
                                        @continue
                                    @endif
                                    <button type="button"
                                            class="lp-filter-add-menu__opt"
                                            wire:click="$set('{{ $f['id'] }}', @js($opt['value']))"
                                            x-on:click="addOpen = false; expandedId = null"
                                            data-testid="filter-opt-{{ $f['id'] }}-{{ $opt['value'] }}">
                                        {{ $opt['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Clear-all when 2+ chips are active ------------------------ --}}
        @if ($activeFilters->count() >= 2)
            <button type="button"
                    class="lp-filter-clear-all"
                    data-testid="filter-clear-all"
                    wire:click="$dispatch('filter-clear-all')">
                Clear all
            </button>
        @endif
    </div>
</div>

@once
    <style>
        .lp-page .lp-filter-chips [x-cloak] { display: none !important; }
        .lp-page .lp-filter-chips__search {
            margin-bottom: 10px;
        }
        .lp-page .lp-filter-chips__row {
            display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
        }
        .lp-page .lp-filter-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 4px 5px 10px;
            border-radius: 999px;
            background: color-mix(in srgb, var(--color-ink) 6%, transparent);
            color: var(--color-ink);
            font-size: 12.5px;
            font-weight: 500;
            line-height: 1;
            border: 1px solid transparent;
        }
        .lp-page .lp-filter-chip__key {
            color: var(--color-ink-soft);
            font-weight: 500;
        }
        .lp-page .lp-filter-chip__val {
            font-weight: 600;
        }
        .lp-page .lp-filter-chip__clear {
            display: inline-flex; align-items: center; justify-content: center;
            width: 18px; height: 18px;
            border-radius: 999px;
            background: transparent;
            border: 0;
            color: var(--color-ink-mute);
            cursor: pointer;
            padding: 0;
        }
        .lp-page .lp-filter-chip__clear:hover {
            background: color-mix(in srgb, var(--color-ink) 12%, transparent);
            color: var(--color-ink);
        }

        .lp-page .lp-filter-chips__add { position: relative; }
        .lp-page .lp-filter-add-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            background: transparent;
            border: 1px dashed color-mix(in srgb, var(--color-ink) 20%, transparent);
            color: var(--color-ink-soft);
            font-size: 12.5px;
            font-weight: 500;
            line-height: 1;
            cursor: pointer;
            transition: background 100ms ease, border-color 100ms ease, color 100ms ease;
        }
        .lp-page .lp-filter-add-btn:hover {
            border-color: var(--color-ink);
            color: var(--color-ink);
            background: color-mix(in srgb, var(--color-ink) 4%, transparent);
        }
        .lp-page .lp-filter-add-menu {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            min-width: 220px;
            max-height: 60vh;
            overflow-y: auto;
            padding: 6px;
            background: var(--color-card);
            border: 1px solid var(--color-line);
            border-radius: 10px;
            box-shadow: 0 12px 40px -12px rgba(11, 9, 6, 0.24);
            z-index: 30;
        }
        .lp-page .lp-filter-add-menu__group + .lp-filter-add-menu__group {
            margin-top: 2px;
        }
        .lp-page .lp-filter-add-menu__row {
            display: flex; align-items: center; justify-content: space-between;
            width: 100%;
            padding: 8px 12px;
            border-radius: 6px;
            background: transparent;
            border: 0;
            color: var(--color-ink);
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            text-align: left;
        }
        .lp-page .lp-filter-add-menu__row:hover {
            background: color-mix(in srgb, var(--color-ink) 4%, transparent);
        }
        .lp-page .lp-filter-add-menu__options {
            padding: 4px 0 6px 4px;
            display: grid;
            gap: 2px;
            border-left: 2px solid color-mix(in srgb, var(--color-ink) 8%, transparent);
            margin: 2px 0 4px 8px;
        }
        .lp-page .lp-filter-add-menu__opt {
            display: block; width: 100%;
            padding: 6px 12px;
            border-radius: 6px;
            background: transparent;
            border: 0;
            color: var(--color-ink-soft);
            font-size: 13px;
            cursor: pointer;
            text-align: left;
        }
        .lp-page .lp-filter-add-menu__opt:hover {
            background: color-mix(in srgb, var(--color-ink) 5%, transparent);
            color: var(--color-ink);
        }

        .lp-page .lp-filter-clear-all {
            padding: 5px 10px;
            background: transparent;
            border: 0;
            color: var(--color-ink-mute);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: underline;
            text-underline-offset: 3px;
            text-decoration-color: color-mix(in srgb, var(--color-ink-mute) 40%, transparent);
        }
        .lp-page .lp-filter-clear-all:hover {
            color: var(--color-ink);
        }
    </style>
@endonce
