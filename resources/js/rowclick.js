/*
 * Whole-row navigation for list tables.
 *
 * Any element with `data-row-href="/some/url"` (typically a <tr>) becomes
 * clickable, feeling SPA-like via Livewire's navigate when available.
 * The delegated listener guards against the usual footguns:
 *   - clicks that land on a button / link / form input keep their own
 *     behaviour (so a "Remove" button inside the row doesn't accidentally
 *     open the detail page)
 *   - text selection inside a row cancels the navigate — the operator
 *     was reading, not clicking
 *   - cmd/ctrl-click opens in a new tab, matching normal <a> semantics
 *   - middle-click / auxclick also opens in a new tab
 *
 * The row template should also expose the same href on a real <a> for
 * a11y / right-click / bookmark reasons — see the tables that use it.
 */

function navigateTo(url, newTab) {
    if (!url) return;
    if (newTab) {
        window.open(url, '_blank', 'noopener');
        return;
    }
    // Prefer Livewire's navigate so the sidebar's @persist survives —
    // matches the rest of the dashboard's feel.
    if (window.Livewire && typeof window.Livewire.navigate === 'function') {
        window.Livewire.navigate(url);
        return;
    }
    window.location.assign(url);
}

function shouldSkip(event) {
    // Text selection: the operator was reading, not clicking.
    const sel = window.getSelection && window.getSelection();
    if (sel && sel.toString().length > 0) return true;

    // Any interactive descendant handles its own click.
    if (event.target.closest('a, button, input, select, textarea, label, [role="button"], [role="link"]')) {
        return true;
    }

    return false;
}

document.addEventListener('click', function (event) {
    if (event.button !== 0) return; // ignore right-click
    const row = event.target.closest('[data-row-href]');
    if (!row) return;
    if (shouldSkip(event)) return;

    const url = row.dataset.rowHref;
    if (!url) return;

    event.preventDefault();
    navigateTo(url, event.metaKey || event.ctrlKey);
});

// Middle-click / mouse-back-forward: browsers fire `auxclick` for those.
// Only treat middle-button as "open in new tab".
document.addEventListener('auxclick', function (event) {
    if (event.button !== 1) return;
    const row = event.target.closest('[data-row-href]');
    if (!row) return;
    if (shouldSkip(event)) return;
    event.preventDefault();
    navigateTo(row.dataset.rowHref, true);
});

// Keyboard: Enter on a focused row triggers the navigate. Rows opt into
// this by getting `tabindex="0"` in the template.
document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter') return;
    const row = event.target.closest('[data-row-href]');
    if (!row) return;
    // Don't hijack Enter when focus is on an interactive element inside the row.
    if (event.target !== row) return;
    event.preventDefault();
    navigateTo(row.dataset.rowHref, event.metaKey || event.ctrlKey);
});
