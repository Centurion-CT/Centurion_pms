{{--
    Searchable room picker. Alpine-driven combobox that replaces the
    plain <select> the manual-reservation forms used to render:
    trigger button showing the current pick, click to open a panel
    with a search input + filtered list, click a row to select.

    Selection goes through wire:click="$set(...)" so any Livewire
    updater hooks the host component wired against the model path
    (e.g. updatedReserveRoomId, updatedRoomId) still fire — pricing
    preview, breakfast-tier gating etc. all keep working.

    Props:
      $rooms       — Collection of Room models (each with ->room_number
                     and ->category preloaded).
      $modelPath   — Livewire prop the picker writes to, e.g.
                     'reserveRoomId' or 'roomId'.
      $idPrefix    — html id + data-testid prefix so multiple pickers
                     on the same page don't collide.
      $currentId   — currently-selected id, used to render the trigger
                     label without needing a round-trip.
      $placeholder — text shown when nothing is picked; also used when
                     $rooms is empty.
      $disabled    — bool; when true the trigger is inert.
--}}

@props([
    'rooms',
    'modelPath',
    'idPrefix' => 'room-picker',
    'currentId' => null,
    'placeholder' => 'Pick a room',
    'disabled' => false,
])

@php
    // Precompute a lowercase haystack per room so Alpine's filter
    // is a single .includes() lookup — no per-keystroke JS work
    // over collection methods.
    $searchIndex = $rooms->mapWithKeys(function ($r) {
        $bits = array_filter([
            'rm', (string) $r->room_number,
            $r->room_number ? '#'.$r->room_number : null,
            $r->category?->name,
        ]);
        return [(int) $r->id => mb_strtolower(implode(' ', $bits))];
    })->all();

    $optionsPayload = $rooms->map(fn ($r) => [
        'id' => (int) $r->id,
        'number' => $r->room_number,
        'category' => $r->category?->name,
    ])->values()->all();

    $currentRoom = $currentId !== null && $currentId !== ''
        ? $rooms->firstWhere('id', (int) $currentId)
        : null;
@endphp

<div x-data="{
        open: false,
        query: '',
        options: {{ \Illuminate\Support\Js::from($optionsPayload) }},
        index: {{ \Illuminate\Support\Js::from($searchIndex) }},
        currentId: @js((int) ($currentId ?? 0)),
        matches(id) {
            if (this.query.trim() === '') return true;
            const q = this.query.trim().toLowerCase();
            return (this.index[id] || '').includes(q);
        },
        anyMatches() {
            if (this.query.trim() === '') return this.options.length > 0;
            return this.options.some(o => this.matches(o.id));
        },
        openPanel() {
            if ({{ $disabled ? 'true' : 'false' }}) return;
            this.open = true;
            this.$nextTick(() => this.$refs.searchInput?.focus());
        },
        closePanel() { this.open = false; this.query = ''; },
     }"
     x-on:click.outside="closePanel()"
     x-on:keydown.escape.window="if (open) closePanel()"
     class="lp-search-select"
     data-testid="{{ $idPrefix }}">

    {{-- Trigger. Renders the current pick or the placeholder. --}}
    <button type="button"
            @click="openPanel()"
            :aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="listbox"
            data-testid="{{ $idPrefix }}-trigger"
            @disabled($disabled)
            class="lp-search-select-trigger">
        <span class="lp-search-select-trigger-label">
            @if ($currentRoom !== null)
                <span class="lp-search-select-current"
                      data-testid="{{ $idPrefix }}-current">
                    <span class="num">Rm {{ $currentRoom->room_number ?? '-' }}</span>
                    @if ($currentRoom->category)
                        <span class="cat">{{ $currentRoom->category->name }}</span>
                    @endif
                </span>
            @else
                <span style="color: var(--color-ink-mute);">{{ $placeholder }}</span>
            @endif
        </span>
        <svg width="12" height="12" viewBox="0 0 12 12" fill="none"
             stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
             style="opacity: 0.55; transition: transform 140ms;"
             :style="open ? 'transform: rotate(180deg); opacity: 0.85;' : ''">
            <path d="M3 4.5l3 3 3-3"/>
        </svg>
    </button>

    {{-- Dropdown panel. Search input pinned at top, filtered list
         below. Empty-query lists everything; empty-result state gets
         its own message. --}}
    <div x-show="open" x-cloak
         x-transition:enter="lp-search-select-enter"
         class="lp-search-select-panel"
         role="listbox">
        <div class="lp-search-select-search-wrap">
            <svg class="lp-search-select-search-icon" viewBox="0 0 16 16" fill="none"
                 stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="7" cy="7" r="4.5"/><path d="M13.5 13.5L10.5 10.5"/>
            </svg>
            <input type="search"
                   x-ref="searchInput"
                   x-model="query"
                   placeholder="Search room number or category…"
                   data-testid="{{ $idPrefix }}-search"
                   class="lp-search-select-search"
                   autocomplete="off">
        </div>

        <ul class="lp-search-select-list">
            @foreach ($rooms as $r)
                <li x-show="matches({{ (int) $r->id }})">
                    <button type="button"
                            wire:click="$set('{{ $modelPath }}', {{ $r->id }})"
                            @click="currentId = {{ $r->id }}; closePanel()"
                            data-testid="{{ $idPrefix }}-option-{{ $r->id }}"
                            :class="currentId === {{ (int) $r->id }} ? 'is-picked' : ''"
                            class="lp-search-select-row">
                        <span class="lp-search-select-row-body">
                            <span class="num">Rm {{ $r->room_number ?? '-' }}</span>
                            @if ($r->category)
                                <span class="cat">{{ $r->category->name }}</span>
                            @endif
                        </span>
                        <span class="lp-search-select-check"
                              x-show="currentId === {{ (int) $r->id }}"
                              aria-hidden>
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none"
                                 stroke="currentColor" stroke-width="1.8"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2.5 6.5l2.5 2.5 4.5-5"/>
                            </svg>
                        </span>
                    </button>
                </li>
            @endforeach
            <li x-show="!anyMatches()" x-cloak
                data-testid="{{ $idPrefix }}-empty"
                class="lp-search-select-empty">
                @if ($rooms->isEmpty())
                    No rooms available for these dates.
                @else
                    No match for "<span x-text="query"></span>".
                @endif
            </li>
        </ul>
    </div>
</div>
