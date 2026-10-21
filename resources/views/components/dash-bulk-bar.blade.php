@props([
    // Count of currently-selected rows on the parent Livewire
    // component. Rendered inline in the "N selected" label.
    'count' => 0,
    // Optional custom singular/plural noun ("row" by default).
    'noun' => 'row',
    // Optional test id.
    'testid' => 'bulk-bar',
])

{{-- Floating action bar for multi-select tables. Appears at the
     bottom-center of the viewport whenever the parent Livewire
     component's $selectedIds is non-empty. The parent renders it
     conditionally (@if ($this->hasSelection())), so this component
     doesn't own visibility — it only owns the visual chrome + the
     "clear selection" affordance that all bulk surfaces share.

     Callers pass their bulk actions into the `actions` slot as a
     row of <button wire:click="..."> elements — Superhuman-style.

     Kept below the fixed sidebar via z-index 60 (sidebar sits at 50);
     backdrop-blur so it reads on both cream + inked pages without
     needing a solid opaque background. --}}

<div class="lp-dash-bulk-bar"
     role="region"
     aria-label="Bulk actions"
     data-testid="{{ $testid }}"
     x-data
     x-init="$el.classList.add('is-mounted')">
    <span class="lp-dash-bulk-bar__count" data-testid="{{ $testid }}-count">
        <strong>{{ $count }}</strong>
        {{ $count === 1 ? $noun : $noun.'s' }} selected
    </span>

    @isset($actions)
        <span class="lp-dash-bulk-bar__actions">{{ $actions }}</span>
    @endisset

    <button type="button"
            class="lp-dash-bulk-bar__cancel"
            wire:click="clearSelection"
            data-testid="{{ $testid }}-cancel">
        Cancel
        <kbd>esc</kbd>
    </button>
</div>

@once
    <style>
        .lp-page .lp-dash-bulk-bar {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translateX(-50%);
            display: inline-flex;
            align-items: center;
            gap: 14px;
            padding: 10px 14px;
            background: rgba(20, 23, 26, 0.94);
            color: #F5F1E8;
            border-radius: 999px;
            box-shadow:
                0 18px 44px -14px rgba(11, 9, 6, 0.55),
                0 0 0 1px rgba(245, 241, 232, 0.06);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 60;
            font-size: 13px;
            font-weight: 500;
            opacity: 0;
            transform: translate(-50%, 8px);
            transition: opacity 160ms ease, transform 160ms ease;
        }
        .lp-page .lp-dash-bulk-bar.is-mounted {
            opacity: 1;
            transform: translate(-50%, 0);
        }
        .lp-page .lp-dash-bulk-bar__count {
            font-variant-numeric: tabular-nums;
            color: rgba(245, 241, 232, 0.75);
        }
        .lp-page .lp-dash-bulk-bar__count strong {
            color: #F5F1E8;
            font-weight: 700;
            margin-right: 2px;
        }
        .lp-page .lp-dash-bulk-bar__actions {
            display: inline-flex; align-items: center; gap: 6px;
            padding-left: 12px;
            border-left: 1px solid rgba(245, 241, 232, 0.14);
        }
        .lp-page .lp-dash-bulk-bar__actions .lp-dash-bulk-action,
        .lp-page .lp-dash-bulk-bar__actions button {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px;
            background: transparent;
            border: 0;
            border-radius: 999px;
            color: #F5F1E8;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background 100ms ease;
        }
        .lp-page .lp-dash-bulk-bar__actions .lp-dash-bulk-action:hover,
        .lp-page .lp-dash-bulk-bar__actions button:hover {
            background: rgba(245, 241, 232, 0.08);
        }
        .lp-page .lp-dash-bulk-bar__actions .lp-dash-bulk-action--primary {
            background: var(--color-accent);
            color: var(--color-on-accent);
        }
        .lp-page .lp-dash-bulk-bar__actions .lp-dash-bulk-action--primary:hover {
            background: var(--color-accent-deep);
        }
        .lp-page .lp-dash-bulk-bar__cancel {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px;
            background: transparent;
            border: 0;
            border-radius: 999px;
            color: rgba(245, 241, 232, 0.75);
            font-size: 12.5px;
            font-weight: 500;
            cursor: pointer;
            padding-left: 12px;
            border-left: 1px solid rgba(245, 241, 232, 0.14);
        }
        .lp-page .lp-dash-bulk-bar__cancel:hover { color: #F5F1E8; }
        .lp-page .lp-dash-bulk-bar__cancel kbd {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 22px; height: 18px;
            padding: 0 5px;
            background: rgba(245, 241, 232, 0.10);
            color: rgba(245, 241, 232, 0.75);
            border-radius: 4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 10px;
            letter-spacing: 0.04em;
        }
        /* Checkbox column visuals shared across the three tables. */
        .lp-page .lp-bulk-checkbox {
            width: 15px; height: 15px;
            margin: 0;
            accent-color: var(--color-accent-deep);
            cursor: pointer;
        }
        .lp-page .lp-bulk-col { width: 32px; padding-right: 0 !important; }
    </style>

    <script>
        // Esc anywhere clears selection on any Livewire component
        // that has $selectedIds. Uses window.Livewire.dispatch so any
        // component with a matching #[On('bulk-clear')] listener acts.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            const bars = document.querySelectorAll('.lp-dash-bulk-bar');
            if (bars.length === 0) return;
            // Don't hijack Escape inside inputs / modals where it has
            // its own meaning; only fire when the active element is
            // the body or a table row.
            const tag = (document.activeElement?.tagName || '').toUpperCase();
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;
            e.preventDefault();
            window.Livewire?.dispatch('bulk-clear');
        });
    </script>
@endonce
