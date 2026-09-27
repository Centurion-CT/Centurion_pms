{{-- Custom confirm dialog that replaces the browser's default confirm()
     popup everywhere in the app. One partial, one script — inherits the
     app's ink/paper palette so it feels part of the surface instead of
     an OS-branded intrusion.

     Two surfaces are handled:

       1. Livewire's `wire:confirm="..."` (~60 call sites). Livewire's
          directive sets `el.__livewire_confirm` on the element when it
          scans the DOM; we intercept via `Object.defineProperty` so
          every assignment (initial + on re-hydration) is swapped for
          our async modal-driven callback. Livewire's request pipeline
          treats it as a plain callback so async is fine.

       2. Plain JS `window.kevernaConfirm({ message })` returning a
          Promise<boolean> — used by the handful of non-Livewire click
          handlers (POS layout forms, photo-manager bulk delete, arrival
          panel). Kept intentionally distinct from window.confirm so
          nothing accidentally awaits a shimmed `confirm()` call.

     Include in every layout whose pages either use wire:confirm or
     need to prompt from vanilla JS (tenant-auth, corporate-portal,
     pos-kiosk, admin/layout). Placed just before @livewireScripts. --}}
<div id="lp-confirm-modal" role="alertdialog" aria-hidden="true"
     aria-labelledby="lp-confirm-title" aria-describedby="lp-confirm-message"
     style="position: fixed; inset: 0; z-index: 10000;
            display: none; align-items: center; justify-content: center;
            background: rgba(20,17,12,0.42); backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            animation: lp-confirm-fade 160ms ease;">
    <div id="lp-confirm-card"
         style="width: min(420px, calc(100vw - 32px));
                background: var(--color-paper, #FFFFFF);
                border-radius: 16px;
                border: 1px solid var(--color-line, rgba(20,23,26,0.10));
                box-shadow: 0 30px 80px -20px rgba(20,17,12,0.35);
                padding: 22px 22px 18px;
                animation: lp-confirm-pop 180ms cubic-bezier(0.16, 1, 0.3, 1);">
        <div id="lp-confirm-title"
             style="font-family: var(--font-display, ui-serif, Georgia, serif);
                    font-weight: 600; font-size: 17px;
                    color: var(--color-ink, #14171A);
                    letter-spacing: -0.01em; line-height: 1.25;
                    margin-bottom: 6px;">
            Confirm
        </div>
        <div id="lp-confirm-message"
             style="font-size: 13.5px; line-height: 1.5;
                    color: var(--color-ink-soft, #3A4148);
                    white-space: pre-line;
                    margin-bottom: 18px;">
            Are you sure?
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" id="lp-confirm-cancel"
                    style="display: inline-flex; align-items: center; justify-content: center;
                           padding: 9px 16px;
                           font: inherit; font-size: 13px; font-weight: 500;
                           background: var(--color-card, #FCFAF5);
                           color: var(--color-ink, #14171A);
                           border: 1px solid var(--color-line, rgba(20,23,26,0.10));
                           border-radius: 9999px;
                           cursor: pointer;
                           transition: border-color 160ms, background 160ms;">
                Cancel
            </button>
            <button type="button" id="lp-confirm-ok"
                    style="display: inline-flex; align-items: center; justify-content: center;
                           padding: 9px 18px;
                           font: inherit; font-size: 13px; font-weight: 500;
                           background: var(--color-ink, #14171A);
                           color: var(--color-card, #FCFAF5);
                           border: 1px solid var(--color-ink, #14171A);
                           border-radius: 9999px;
                           cursor: pointer;
                           transition: background 160ms, border-color 160ms;">
                Confirm
            </button>
        </div>
    </div>
</div>
<style>
    @keyframes lp-confirm-fade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes lp-confirm-pop {
        from { opacity: 0; transform: translateY(6px) scale(0.98); }
        to   { opacity: 1; transform: translateY(0)   scale(1); }
    }
    #lp-confirm-cancel:hover { border-color: var(--color-ink, #14171A); }
    #lp-confirm-ok:hover     { background: var(--color-ink-deep, #1B1714); border-color: var(--color-ink-deep, #1B1714); }
    #lp-confirm-modal[data-tone="danger"] #lp-confirm-ok {
        background: #B85C42; border-color: #B85C42; color: #FFFFFF;
    }
    #lp-confirm-modal[data-tone="danger"] #lp-confirm-ok:hover {
        background: #9F4A31; border-color: #9F4A31;
    }
</style>
<script>
    (() => {
        const modal = document.getElementById('lp-confirm-modal');
        const card = document.getElementById('lp-confirm-card');
        const titleEl = document.getElementById('lp-confirm-title');
        const messageEl = document.getElementById('lp-confirm-message');
        const okBtn = document.getElementById('lp-confirm-ok');
        const cancelBtn = document.getElementById('lp-confirm-cancel');

        let pending = null; // { resolve, previouslyFocused }

        function close(result) {
            if (!pending) return;
            const { resolve, previouslyFocused } = pending;
            pending = null;
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            modal.removeAttribute('data-tone');
            document.removeEventListener('keydown', onKey, true);
            if (previouslyFocused && previouslyFocused.focus) {
                try { previouslyFocused.focus(); } catch (_) {}
            }
            resolve(result);
        }

        function onKey(e) {
            if (!pending) return;
            if (e.key === 'Escape') { e.preventDefault(); close(false); }
            else if (e.key === 'Enter') { e.preventDefault(); close(true); }
        }

        okBtn.addEventListener('click', () => close(true));
        cancelBtn.addEventListener('click', () => close(false));
        // Backdrop click cancels; ignore clicks inside the card.
        modal.addEventListener('click', (e) => {
            if (e.target === modal) close(false);
        });

        // Public API. Returns a Promise<boolean>.
        // opts: { message, title, confirmLabel, cancelLabel, tone: 'default'|'danger' }
        window.kevernaConfirm = function (opts) {
            const options = typeof opts === 'string' ? { message: opts } : (opts || {});
            const message = options.message || 'Are you sure?';
            const title = options.title || 'Confirm';
            const confirmLabel = options.confirmLabel || 'Confirm';
            const cancelLabel = options.cancelLabel || 'Cancel';
            const tone = options.tone === 'danger' ? 'danger' : 'default';

            // Resolve a pending prompt as cancelled before opening a new one.
            if (pending) close(false);

            titleEl.textContent = title;
            messageEl.textContent = message;
            okBtn.textContent = confirmLabel;
            cancelBtn.textContent = cancelLabel;
            if (tone === 'danger') modal.setAttribute('data-tone', 'danger');
            else modal.removeAttribute('data-tone');

            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            const previouslyFocused = document.activeElement;
            // Focus OK by default so Enter is the fast-path (matches
            // browser confirm behaviour). Users tab to Cancel if needed.
            setTimeout(() => okBtn.focus(), 20);
            document.addEventListener('keydown', onKey, true);

            return new Promise((resolve) => {
                pending = { resolve, previouslyFocused };
            });
        };

        // ── Form/link data-confirm interceptor ─────────────────────
        // Any <form data-confirm="msg"> or <a data-confirm="msg">
        // routes through the custom modal instead of the browser's
        // popup. Use this for plain HTML forms (logout, disconnect)
        // where wire:confirm isn't available.
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!form.matches?.('form[data-confirm]')) return;
            if (form.__kevernaConfirmed) {
                form.__kevernaConfirmed = false;
                return; // let this submit through
            }
            e.preventDefault();
            const message = form.getAttribute('data-confirm') || 'Are you sure?';
            const tone = form.getAttribute('data-confirm-tone') === 'danger' ? 'danger' : 'default';
            window.kevernaConfirm({ message, tone }).then((ok) => {
                if (!ok) return;
                form.__kevernaConfirmed = true;
                // Preserve the submitter (which button was clicked)
                // by falling back to requestSubmit when available.
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            });
        }, true);
        document.addEventListener('click', (e) => {
            const a = e.target.closest?.('a[data-confirm]');
            if (!a) return;
            if (a.__kevernaConfirmed) { a.__kevernaConfirmed = false; return; }
            e.preventDefault();
            const message = a.getAttribute('data-confirm') || 'Are you sure?';
            const tone = a.getAttribute('data-confirm-tone') === 'danger' ? 'danger' : 'default';
            window.kevernaConfirm({ message, tone }).then((ok) => {
                if (!ok) return;
                a.__kevernaConfirmed = true;
                a.click();
            });
        }, true);

        // ── Livewire wire:confirm override ─────────────────────────
        // Livewire's directive assigns `el.__livewire_confirm =
        // (action, instead) => { if (confirm(msg)) action(); else instead(); }`
        // at element init time. We install a property setter on each
        // wire:confirm element that intercepts Livewire's assignment
        // and stores our async version instead. Livewire's request
        // pipeline calls the callback with two thunks — no awaiting,
        // so async is fine.
        function guardEl(el) {
            if (el.__kevernaConfirmGuarded) return;
            el.__kevernaConfirmGuarded = true;
            let stored = null;
            const attrMessage = () => {
                const raw = el.getAttribute('wire:confirm') || 'Are you sure?';
                return raw.replaceAll('\\n', '\n');
            };
            Object.defineProperty(el, '__livewire_confirm', {
                configurable: true,
                get() { return stored; },
                set(_incoming) {
                    // Ignore whatever Livewire tried to install; use ours.
                    stored = (action, instead) => {
                        window.kevernaConfirm({ message: attrMessage() }).then((ok) => {
                            if (ok) action();
                            else if (typeof instead === 'function') instead();
                        });
                    };
                },
            });
        }

        function scanAndGuard(root) {
            if (!root) return;
            if (root.nodeType === 1 && root.matches && root.matches('[wire\\:confirm]')) {
                guardEl(root);
            }
            const list = root.querySelectorAll ? root.querySelectorAll('[wire\\:confirm]') : [];
            list.forEach(guardEl);
        }

        // Initial sweep once Livewire has scanned the DOM.
        document.addEventListener('livewire:init', () => scanAndGuard(document.body));
        // wire:navigate swaps the document — re-scan.
        document.addEventListener('livewire:navigated', () => scanAndGuard(document.body));
        // Livewire re-renders can inject fresh [wire:confirm] nodes.
        const observer = new MutationObserver((mutations) => {
            for (const m of mutations) {
                m.addedNodes.forEach((node) => {
                    if (node.nodeType !== 1) return;
                    scanAndGuard(node);
                });
            }
        });
        // body isn't there yet during head-parsed script — wait for DOMContentLoaded.
        if (document.body) {
            scanAndGuard(document.body);
            observer.observe(document.body, { childList: true, subtree: true });
        } else {
            document.addEventListener('DOMContentLoaded', () => {
                scanAndGuard(document.body);
                observer.observe(document.body, { childList: true, subtree: true });
            });
        }
    })();
</script>
