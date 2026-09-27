/*
 * POS camera-based barcode scanner
 * --------------------------------
 *
 * Opens a fullscreen modal with the device camera, uses html5-qrcode to
 * detect 1D/2D barcodes (UPC/EAN/Code128/QR), and writes the decoded
 * value into a target `<input>` field. Also fires a native input+change
 * event so any Livewire wire:model on that input picks up the new
 * value, then invokes a callback (e.g. the scan-add button) to submit.
 *
 * The library ships as ~140KB of ES2015. To avoid loading it on every
 * dashboard page — most pages have no scanner — we dynamic-import on
 * first click. First scan pays the network cost; subsequent scans are
 * warm.
 *
 * Usage in Blade:
 *   <input id="pos-scan-input" wire:model="barcodeInput">
 *   <button type="button" data-camera-scan
 *           data-camera-scan-target="#pos-scan-input"
 *           data-camera-scan-submit="#pos-scan-add">
 *     Scan camera
 *   </button>
 *
 * The `data-camera-scan-submit` selector is optional — if provided, the
 * button/form matching that selector is clicked once a scan completes,
 * so operators don't have to tap Add manually.
 */

let scannerInstance = null;
let modalEl = null;

async function openScanner(targetSelector, submitSelector) {
    const targetInput = document.querySelector(targetSelector);
    if (!targetInput) {
        console.warn('[keverna-pos-camera] Target input not found:', targetSelector);
        return;
    }

    // Guard against multiple opens.
    if (modalEl) return;

    ensureModal();
    setStatus('Loading camera…');
    modalEl.hidden = false;

    let Html5Qrcode;
    try {
        ({ Html5Qrcode } = await import('html5-qrcode'));
    } catch (e) {
        setStatus('Failed to load scanner library. Check your connection.');
        console.error('[keverna-pos-camera] Import failed:', e);
        return;
    }

    // Prefer the rear camera on phones/tablets. If enumeration fails
    // (permissions denied, HTTPS required, older browser), fall back to
    // a facingMode constraint which most browsers honour.
    let cameraId;
    try {
        const cams = await Html5Qrcode.getCameras();
        if (cams && cams.length > 0) {
            const rear = cams.find(c => /back|rear|environment/i.test(c.label));
            cameraId = (rear || cams[cams.length - 1]).id;
        }
    } catch {
        // fall through to facingMode
    }

    scannerInstance = new Html5Qrcode('pos-camera-scan-region');
    const config = {
        fps: 10,
        qrbox: (viewfinderW, viewfinderH) => {
            // A wide, letter-boxed viewfinder works better for retail
            // 1D barcodes than a square QR-only box.
            const w = Math.floor(viewfinderW * 0.85);
            const h = Math.min(Math.floor(viewfinderH * 0.35), 180);
            return { width: w, height: h };
        },
        aspectRatio: 1.7778, // 16:9 approx
    };

    const onDecoded = (decoded) => {
        // Stop the camera first so a rapid second decode doesn't double-fire.
        cleanupScanner().finally(() => {
            targetInput.value = decoded;
            targetInput.dispatchEvent(new Event('input', { bubbles: true }));
            targetInput.dispatchEvent(new Event('change', { bubbles: true }));
            targetInput.focus();

            hideModal();

            // Trigger submission if requested (Add button, form submit, etc.).
            if (submitSelector) {
                const submitEl = document.querySelector(submitSelector);
                if (submitEl) {
                    // Small tick so Livewire has time to receive the value
                    // via the wire:model debounce before we submit.
                    setTimeout(() => submitEl.click(), 150);
                }
            }
        });
    };

    const onError = () => { /* per-frame miss; noisy — swallow */ };

    try {
        await scannerInstance.start(
            cameraId || { facingMode: { ideal: 'environment' } },
            config,
            onDecoded,
            onError,
        );
        setStatus('Point camera at the barcode.');
    } catch (e) {
        setStatus('Camera access failed: ' + (e && e.message ? e.message : e));
        console.error('[keverna-pos-camera] Start failed:', e);
    }
}

async function cleanupScanner() {
    if (!scannerInstance) return;
    try {
        // start() may reject; stop() then throws "not running". Guard.
        if (typeof scannerInstance.getState === 'function' && scannerInstance.getState() === 2) {
            await scannerInstance.stop();
        }
        scannerInstance.clear();
    } catch (e) {
        // Non-fatal — most commonly "camera not started".
    } finally {
        scannerInstance = null;
    }
}

function ensureModal() {
    if (modalEl) return;
    modalEl = document.createElement('div');
    modalEl.className = 'lp-camera-modal';
    modalEl.hidden = true;
    modalEl.innerHTML = `
        <div class="lp-camera-modal__panel">
            <header class="lp-camera-modal__head">
                <span class="lp-camera-modal__title">Scan a barcode</span>
                <button type="button" class="lp-camera-modal__close" aria-label="Close">&times;</button>
            </header>
            <div class="lp-camera-modal__region" id="pos-camera-scan-region"></div>
            <p class="lp-camera-modal__status" data-camera-status></p>
        </div>
    `;
    modalEl.addEventListener('click', (e) => {
        if (e.target === modalEl || e.target.classList.contains('lp-camera-modal__close')) {
            hideModal();
        }
    });
    document.body.appendChild(modalEl);
    document.addEventListener('keydown', escapeHandler);
}

function escapeHandler(e) {
    if (e.key === 'Escape' && modalEl && !modalEl.hidden) {
        hideModal();
    }
}

function hideModal() {
    if (!modalEl) return;
    modalEl.hidden = true;
    cleanupScanner();
}

function setStatus(msg) {
    if (!modalEl) return;
    const el = modalEl.querySelector('[data-camera-status]');
    if (el) el.textContent = msg;
}

// Delegated click handler — survives Livewire re-renders + wire:navigate
// transitions without a hook per component.
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-camera-scan]');
    if (!btn) return;
    e.preventDefault();
    openScanner(
        btn.dataset.cameraScanTarget,
        btn.dataset.cameraScanSubmit || null,
    );
});
