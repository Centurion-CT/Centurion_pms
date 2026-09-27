/*
 * "Needs attention" panel for POS sales the server permanently rejected
 * on flush — out-of-stock, booking status changed, malformed payload,
 * etc. Without this, pos-offline.js used to console.warn and drop them
 * silently; the operator would think they took payment for a sale that
 * never landed.
 *
 * The store itself lives in IndexedDB via pos-db.js. This module owns
 * the UI: a modal listing each failed sale with its reason, and Retry
 * (re-enqueue) / Dismiss (delete) actions.
 *
 * Also mounts the "device revoked" overlay when the queue detects a
 * 401 during flush — the paired session is dead and every future
 * flush would 401 too; the operator needs a clear message rather than
 * an infinite spinner.
 */

const REASON_COPY = {
    device_revoked:       'This device was unlinked from the POS.',
    not_assigned_to_outlet: "You aren't assigned to this outlet.",
    outlet_missing:       'That outlet no longer exists.',
    booking_ref_required: 'Charge-to-room needed a booking reference.',
    booking_not_found:    'That booking reference no longer exists.',
    booking_wrong_status: 'Booking status changed — the guest may have checked out.',
    server_error:         'Server error during flush.',
    unauthenticated:      'Signed out on this device.',
};

function reasonLabel(code) {
    return REASON_COPY[code] ?? `Rejected: ${code || 'unknown'}`;
}

function fmtNaira(cents) {
    return '₦' + Number(cents || 0).toLocaleString();
}

function fmtTime(iso) {
    if (!iso) return '';
    try {
        return new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
    } catch { return iso; }
}

let modalEl = null;
let overlayEl = null;

function ensureModal() {
    if (modalEl) return modalEl;
    modalEl = document.createElement('div');
    modalEl.className = 'kv-pos-failed-modal';
    modalEl.setAttribute('data-testid', 'pos-failed-modal');
    modalEl.style.cssText = `
        position: fixed; inset: 0; background: rgba(20, 20, 30, 0.55);
        display: none; align-items: flex-start; justify-content: center;
        z-index: 9998; padding: 40px 16px; overflow-y: auto;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    `;
    modalEl.innerHTML = `
        <div style="background: #fffcf5; max-width: 560px; width: 100%; border-radius: 14px;
                    box-shadow: 0 40px 100px rgba(0,0,0,0.35); padding: 24px 26px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                <h2 style="margin: 0; font-family: 'Fraunces', serif; font-size: 22px; font-weight: 600;">Needs attention</h2>
                <button type="button" data-close aria-label="Close"
                        style="background: none; border: 0; font-size: 22px; cursor: pointer; color: #4a4a55;">×</button>
            </div>
            <p style="margin: 0 0 16px; color: #4a4a55; font-size: 13.5px; line-height: 1.5;">
                These queued sales were rejected by the server. Retry sends them again;
                Dismiss deletes the local record — you'll need to re-ring the sale manually if it never made it in.
            </p>
            <div data-failed-list></div>
            <div style="display: flex; justify-content: flex-end; margin-top: 12px;">
                <button type="button" data-close class="lp-btn">Close</button>
            </div>
        </div>
    `;
    modalEl.addEventListener('click', (e) => {
        if (e.target === modalEl) closePanel();
        if (e.target.matches('[data-close]')) closePanel();
    });
    document.body.appendChild(modalEl);
    return modalEl;
}

async function renderList() {
    if (!window.KevernaPosDB) return;
    const items = await window.KevernaPosDB.getFailed();
    const list = modalEl.querySelector('[data-failed-list]');
    if (items.length === 0) {
        list.innerHTML = `
            <p style="text-align: center; padding: 20px; color: #7a7a85; font-size: 13.5px;"
               data-testid="pos-failed-empty">
                Nothing needs attention right now.
            </p>
        `;
        return;
    }
    list.innerHTML = items.map((it) => {
        const total = it.payload?.tenders
            ? it.payload.tenders.reduce((s, t) => s + Number(t.amount || 0), 0)
            : 0;
        const lines = (it.payload?.cart ?? []).map((l) => `${l.quantity}× ${escapeHtml(l.name || 'item')}`).join(', ');
        return `
            <div class="kv-failed-row" data-key="${escapeAttr(it.client_key)}"
                 data-testid="pos-failed-row"
                 style="border: 1px solid #e6e2d7; border-radius: 8px; padding: 12px; margin-bottom: 8px;">
                <div style="display: flex; justify-content: space-between; gap: 12px; margin-bottom: 6px;">
                    <div style="font-weight: 500; color: #1a1a22;">${escapeHtml(reasonLabel(it.reason))}</div>
                    <div class="lp-mono" style="color: #1a1a22; font-weight: 500;">${fmtNaira(total)}</div>
                </div>
                <div style="font-size: 12px; color: #7a7a85;">${escapeHtml(lines || 'No cart data')}</div>
                <div style="font-size: 11px; color: #a0a0a8; margin-top: 4px;">Rejected ${escapeHtml(fmtTime(it.failed_at))}</div>
                <div style="display: flex; gap: 8px; margin-top: 10px;">
                    <button type="button" data-action="retry" class="lp-btn" data-testid="pos-failed-retry"
                            style="font-size: 12px; padding: 4px 10px;">Retry</button>
                    <button type="button" data-action="dismiss" class="lp-btn" data-testid="pos-failed-dismiss"
                            style="font-size: 12px; padding: 4px 10px;">Dismiss</button>
                </div>
            </div>
        `;
    }).join('');

    list.querySelectorAll('.kv-failed-row').forEach((row) => {
        const key = row.dataset.key;
        row.querySelector('[data-action="retry"]').addEventListener('click', async () => {
            const items = await window.KevernaPosDB.getFailed();
            const item = items.find((i) => i.client_key === key);
            if (!item) return;
            await window.KevernaPosDB.removeFailed(key);
            dispatchEvent(new CustomEvent('keverna-pos:failed-changed'));
            if (window.KevernaPos) {
                window.KevernaPos.enqueue(item.payload);
            }
            renderList();
        });
        row.querySelector('[data-action="dismiss"]').addEventListener('click', async () => {
            await window.KevernaPosDB.removeFailed(key);
            dispatchEvent(new CustomEvent('keverna-pos:failed-changed'));
            renderList();
        });
    });
}

function openPanel() {
    ensureModal();
    modalEl.style.display = 'flex';
    renderList();
}

function closePanel() {
    if (modalEl) modalEl.style.display = 'none';
}

function ensureRevokedOverlay() {
    if (overlayEl) return overlayEl;
    overlayEl = document.createElement('div');
    overlayEl.setAttribute('data-testid', 'pos-revoked-overlay');
    overlayEl.style.cssText = `
        position: fixed; inset: 0; background: rgba(20, 20, 30, 0.92);
        display: none; align-items: center; justify-content: center; z-index: 9999;
        color: #fffcf5; padding: 32px 20px;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    `;
    overlayEl.innerHTML = `
        <div style="max-width: 420px; text-align: center;">
            <h2 style="font-family: 'Fraunces', serif; font-size: 28px; font-weight: 600; margin: 0 0 12px;">
                This device was unlinked.
            </h2>
            <p style="margin: 0 0 20px; color: rgba(255, 252, 245, 0.75); font-size: 15px; line-height: 1.55;">
                An admin revoked this phone's POS access. Any queued sales
                have been moved to <em>Needs attention</em> — a manager can
                re-run them from a working terminal.
            </p>
            <button type="button" data-action="reload" class="lp-btn"
                    style="background: #fffcf5; color: #1a1a22;">
                Reload
            </button>
        </div>
    `;
    overlayEl.querySelector('[data-action="reload"]').addEventListener('click', () => location.reload());
    document.body.appendChild(overlayEl);
    return overlayEl;
}

function showRevokedOverlay() {
    ensureRevokedOverlay();
    overlayEl.style.display = 'flex';
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}
function escapeAttr(s) { return escapeHtml(s); }

window.KevernaPosFailed = { openPanel, closePanel, renderList, reasonLabel };

window.addEventListener('keverna-pos:device-revoked', showRevokedOverlay);
