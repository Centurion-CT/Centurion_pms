/*
 * Photo manager glue.
 *
 * Powers the <x-photo-manager /> Blade component: turns any element
 * carrying `data-photo-manager` into a rich uploader with:
 *   - drop-zone (drag files from the OS)
 *   - auto-upload as soon as a file is staged (no separate button click)
 *   - drag-to-reorder on the saved-media grid (Sortable.js)
 *   - multi-select tiles + floating bulk-delete bar
 *
 * The component itself declares which Livewire actions to call via
 * data-attributes (`data-upload-action`, `data-reorder-action`, etc.).
 * This keeps the JS agnostic — every parent Livewire component
 * (Branding\Photos, Rooms\MediaManager, RoomCategories\Form) exposes
 * the same action shape and things Just Work.
 */

import Sortable from 'sortablejs';

/* ------------------------------------------------------------------ */
/* Instance lifecycle                                                  */
/* ------------------------------------------------------------------ */

const INIT_ATTR = 'data-photo-manager-initialised';

function initAll(root = document) {
    root.querySelectorAll('[data-photo-manager]').forEach((el) => {
        if (el.hasAttribute(INIT_ATTR)) {
            // Sortable survives DOM morphs but drop-zone listeners on
            // detached children can leak — re-bind those specifically.
            rebindDropZone(el);
            rebindTiles(el);
            return;
        }
        el.setAttribute(INIT_ATTR, '1');
        initInstance(el);
    });

    // Single-file uploader (Branding\Photos logo tile). Shares the
    // drop-zone + auto-upload semantics but not the gallery grid.
    root.querySelectorAll('[data-single-uploader]').forEach((el) => {
        if (el.hasAttribute(INIT_ATTR)) return;
        el.setAttribute(INIT_ATTR, '1');
        initSingleUploader(el);
    });
}

function initInstance(root) {
    initDropZone(root);
    initSortable(root);
    initAutoUpload(root);
    initBulkSelect(root);
    initHeroSetters(root);
}

function componentIdFor(root) {
    const wired = root.closest('[wire\\:id]');
    return wired ? wired.getAttribute('wire:id') : null;
}

function callLivewire(root, action, ...args) {
    const id = componentIdFor(root);
    if (!id || !action) return;
    const comp = window.Livewire && window.Livewire.find(id);
    if (!comp) return;
    comp.call(action, ...args);
}

/* ------------------------------------------------------------------ */
/* Drop zone — drag OS files onto the panel                            */
/* ------------------------------------------------------------------ */

function initDropZone(root) {
    rebindDropZone(root);
}

function rebindDropZone(root) {
    const zone = root.querySelector('[data-role="drop-zone"]');
    const input = root.querySelector('input[type="file"]');
    if (!zone || !input) return;
    if (zone.hasAttribute('data-drop-bound')) return;
    zone.setAttribute('data-drop-bound', '1');

    const stop = (e) => {
        e.preventDefault();
        e.stopPropagation();
    };

    ['dragenter', 'dragover'].forEach((ev) =>
        zone.addEventListener(ev, (e) => {
            stop(e);
            zone.classList.add('is-dragging-over');
        })
    );

    ['dragleave', 'dragend'].forEach((ev) =>
        zone.addEventListener(ev, (e) => {
            stop(e);
            // Only unset when leaving the zone entirely, not moving between children.
            if (e.type === 'dragleave' && zone.contains(e.relatedTarget)) return;
            zone.classList.remove('is-dragging-over');
        })
    );

    zone.addEventListener('drop', (e) => {
        stop(e);
        zone.classList.remove('is-dragging-over');
        if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;

        // A DataTransfer can hold non-image junk (folders, .DS_Store).
        // Filter to the accept attribute the input declared.
        const accept = (input.getAttribute('accept') || '').split(',').map((s) => s.trim()).filter(Boolean);
        const files = Array.from(e.dataTransfer.files).filter((f) =>
            accept.length === 0 ||
            accept.some((a) => (a.endsWith('/*') ? f.type.startsWith(a.slice(0, -1)) : f.type === a))
        );
        if (files.length === 0) return;

        // Hand-off to the native input so Livewire's WithFileUploads
        // picks it up as if the user had clicked the picker.
        const dt = new DataTransfer();
        files.forEach((f) => dt.items.add(f));
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

/* ------------------------------------------------------------------ */
/* Auto-upload — fire the parent's upload action after staging         */
/* ------------------------------------------------------------------ */

function initAutoUpload(root) {
    const input = root.querySelector('input[type="file"]');
    if (!input || input.hasAttribute('data-auto-upload-bound')) return;
    input.setAttribute('data-auto-upload-bound', '1');

    const action = root.getAttribute('data-upload-action');
    if (!action) return;

    // Livewire fires `livewire-upload-finish` on the file input's
    // parent after temporary staging succeeds. Use that as the signal
    // to auto-call the parent's upload() action — the user never has
    // to press a button.
    input.addEventListener('livewire-upload-finish', () => {
        callLivewire(root, action);
    });
}

/* ------------------------------------------------------------------ */
/* Single-file uploader — logo tile flavour                            */
/* ------------------------------------------------------------------ */
/*
 * The Branding logo section reuses the drop-zone + auto-upload
 * behaviour from the gallery, but there's no sortable grid, no bulk
 * bar, and no per-tile hero flag. Keep the JS surface small: bind a
 * drop target (the whole `<label>`), forward files into the hidden
 * `<input type="file">`, and auto-fire the Livewire upload action
 * once staging finishes.
 */
function initSingleUploader(root) {
    const dropTarget = root.querySelector('.logo-uploader__drop') || root;
    const input = root.querySelector('input[type="file"]');
    if (!dropTarget || !input) return;

    // Drop zone.
    const stop = (e) => { e.preventDefault(); e.stopPropagation(); };
    ['dragenter', 'dragover'].forEach((ev) =>
        dropTarget.addEventListener(ev, (e) => {
            stop(e);
            dropTarget.classList.add('is-dragging-over');
        })
    );
    ['dragleave', 'dragend'].forEach((ev) =>
        dropTarget.addEventListener(ev, (e) => {
            stop(e);
            if (e.type === 'dragleave' && dropTarget.contains(e.relatedTarget)) return;
            dropTarget.classList.remove('is-dragging-over');
        })
    );
    dropTarget.addEventListener('drop', (e) => {
        stop(e);
        dropTarget.classList.remove('is-dragging-over');
        if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;

        // Filter to the input's accept list — the label may catch
        // stray file types the picker itself would've rejected.
        const accept = (input.getAttribute('accept') || '').split(',').map((s) => s.trim()).filter(Boolean);
        const file = Array.from(e.dataTransfer.files).find((f) =>
            accept.length === 0 ||
            accept.some((a) => (a.endsWith('/*') ? f.type.startsWith(a.slice(0, -1)) : f.type === a))
        );
        if (!file) return;

        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });

    // Auto-upload once Livewire finishes staging the temp file.
    const action = root.getAttribute('data-upload-action');
    if (action) {
        input.addEventListener('livewire-upload-finish', () => {
            callLivewire(root, action);
        });
    }
}

/* ------------------------------------------------------------------ */
/* Sortable — drag to reorder saved tiles                              */
/* ------------------------------------------------------------------ */

function initSortable(root) {
    const grid = root.querySelector('[data-role="grid"]');
    if (!grid || grid.hasAttribute('data-sortable-bound')) return;
    grid.setAttribute('data-sortable-bound', '1');

    const action = root.getAttribute('data-reorder-action');
    if (!action) return;

    Sortable.create(grid, {
        animation: 160,
        handle: '[data-role="drag-handle"]',
        draggable: '[data-media-id]',
        ghostClass: 'is-ghost',
        dragClass: 'is-dragging',
        onEnd: () => {
            const ids = Array.from(grid.querySelectorAll('[data-media-id]'))
                .map((el) => Number(el.getAttribute('data-media-id')))
                .filter((n) => Number.isFinite(n) && n > 0);
            callLivewire(root, action, ids);
        },
    });
}

/* ------------------------------------------------------------------ */
/* Bulk select — checkbox per tile, floating action bar                */
/* ------------------------------------------------------------------ */

function initBulkSelect(root) {
    rebindTiles(root);

    const bar = root.querySelector('[data-role="bulk-bar"]');
    if (!bar || bar.hasAttribute('data-bulk-bound')) return;
    bar.setAttribute('data-bulk-bound', '1');

    const deleteBtn = bar.querySelector('[data-action="delete-selected"]');
    const clearBtn = bar.querySelector('[data-action="clear-selection"]');
    const deleteAction = root.getAttribute('data-delete-many-action');

    if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
            const ids = selectedIds(root);
            if (ids.length === 0) return;
            const message = `Delete ${ids.length} photo${ids.length === 1 ? '' : 's'}?`;
            const ok = window.kevernaConfirm
                ? await window.kevernaConfirm({ message, tone: 'danger' })
                : window.confirm(message);
            if (!ok) return;
            if (!deleteAction) return;
            callLivewire(root, deleteAction, ids);
            clearSelection(root);
        });
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', () => clearSelection(root));
    }
}

function rebindTiles(root) {
    root.querySelectorAll('[data-role="tile-select"]').forEach((box) => {
        if (box.hasAttribute('data-select-bound')) return;
        box.setAttribute('data-select-bound', '1');
        box.addEventListener('change', () => {
            const tile = box.closest('[data-media-id]');
            if (tile) tile.classList.toggle('is-selected', box.checked);
            refreshBulkBar(root);
        });
    });
    refreshBulkBar(root);
}

function selectedIds(root) {
    return Array.from(root.querySelectorAll('[data-role="tile-select"]:checked'))
        .map((box) => {
            const tile = box.closest('[data-media-id]');
            return tile ? Number(tile.getAttribute('data-media-id')) : NaN;
        })
        .filter((n) => Number.isFinite(n) && n > 0);
}

function refreshBulkBar(root) {
    const bar = root.querySelector('[data-role="bulk-bar"]');
    if (!bar) return;
    const ids = selectedIds(root);
    const count = ids.length;
    bar.classList.toggle('is-open', count > 0);
    const counter = bar.querySelector('[data-role="bulk-count"]');
    if (counter) counter.textContent = String(count);
}

function clearSelection(root) {
    root.querySelectorAll('[data-role="tile-select"]').forEach((box) => {
        box.checked = false;
        const tile = box.closest('[data-media-id]');
        if (tile) tile.classList.remove('is-selected');
    });
    refreshBulkBar(root);
}

/* ------------------------------------------------------------------ */
/* Hero setter — one tile at a time                                    */
/* ------------------------------------------------------------------ */

function initHeroSetters(root) {
    const action = root.getAttribute('data-set-hero-action');
    if (!action) return;
    root.querySelectorAll('[data-role="set-hero"]').forEach((btn) => {
        if (btn.hasAttribute('data-hero-bound')) return;
        btn.setAttribute('data-hero-bound', '1');
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const tile = btn.closest('[data-media-id]');
            if (!tile) return;
            const id = Number(tile.getAttribute('data-media-id'));
            if (!Number.isFinite(id) || id <= 0) return;
            callLivewire(root, action, id);
        });
    });
}

/* ------------------------------------------------------------------ */
/* Wire into the Livewire lifecycle                                    */
/* ------------------------------------------------------------------ */

document.addEventListener('livewire:init', () => {
    initAll();
    // Rebind after Livewire morphs the DOM (adding/removing tiles).
    window.Livewire.hook('morph.updated', ({ el }) => initAll(el));
    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(() => initAll());
    });
});

// Fallback for pages that get wire:navigate'd in without a livewire:init.
document.addEventListener('livewire:navigated', () => initAll());
