{{--
    Cookie consent banner. Shared partial mounted on the marketing
    landing (welcome.blade.php), the marketing layout that /demo +
    /api-access use, and every tenant's public landing page.

    Storage:
      - localStorage["kv_cookie_consent"] = 'all' | 'essential'
      - First-party cookie of the same name (fallback for embedded
        webviews that block localStorage)
    Fires a `cookie-consent-changed` CustomEvent with the choice so
    downstream analytics/pixels can defer loading until it's set.

    Accepted values:
      - 'all'       -> non-essential cookies (analytics, marketing) OK
      - 'essential' -> only strictly-necessary (session, CSRF, cart)

    No cookies are set by this site beyond what Laravel already needs
    for the session + CSRF; the banner is scaffolding so we can turn
    analytics on later without another compliance pass.
--}}
<style>
    .kv-cc {
        position: fixed;
        left: 16px; right: 16px; bottom: 16px;
        z-index: 200;
        max-width: 640px;
        margin: 0 auto;
        padding: 18px 20px;
        background: #0F1215;
        color: #F5EFE1;
        border-radius: 14px;
        box-shadow: 0 40px 80px -30px rgba(0, 0, 0, 0.45);
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        font-size: 13.5px;
        line-height: 1.55;
        display: none;
        transform: translateY(20px);
        opacity: 0;
        transition: transform 240ms ease, opacity 240ms ease;
    }
    .kv-cc.is-open {
        display: block;
        transform: translateY(0);
        opacity: 1;
    }
    .kv-cc-body {
        display: flex; flex-direction: column; gap: 12px;
    }
    @media (min-width: 700px) {
        .kv-cc { left: auto; bottom: 20px; right: 20px; padding: 20px 22px; }
        .kv-cc-body { flex-direction: row; align-items: center; gap: 18px; }
        .kv-cc-body p { flex: 1; }
    }
    .kv-cc a {
        color: #F5EFE1;
        text-decoration: underline;
        text-underline-offset: 3px;
        text-decoration-color: rgba(245, 239, 225, 0.4);
    }
    .kv-cc a:hover { text-decoration-color: rgba(245, 239, 225, 0.85); }
    .kv-cc-actions {
        display: flex; gap: 8px; flex-shrink: 0;
    }
    .kv-cc-btn {
        font-family: inherit;
        font-size: 13px;
        font-weight: 500;
        border: 0;
        border-radius: 9999px;
        padding: 9px 16px;
        cursor: pointer;
        transition: background 160ms;
    }
    .kv-cc-btn--primary {
        background: #336799; color: #FFFFFF;
    }
    .kv-cc-btn--primary:hover { background: #285480; }
    .kv-cc-btn--ghost {
        background: transparent;
        color: rgba(245, 239, 225, 0.85);
        border: 1px solid rgba(245, 239, 225, 0.28);
    }
    .kv-cc-btn--ghost:hover { border-color: rgba(245, 239, 225, 0.55); color: #F5EFE1; }
</style>

<div class="kv-cc" id="kv-cookie-consent" role="dialog" aria-live="polite"
     aria-label="Cookie preferences" data-testid="cookie-consent">
    <div class="kv-cc-body">
        <p style="margin: 0;">
            We use a handful of cookies to keep you signed in and to
            understand how the site is used. See our
            <a href="/cookies">cookie policy</a> and
            <a href="/privacy">privacy notice</a>.
        </p>
        <div class="kv-cc-actions">
            <button type="button" class="kv-cc-btn kv-cc-btn--ghost"
                    data-kv-cc="essential" data-testid="cookie-consent-essential">
                Essential only
            </button>
            <button type="button" class="kv-cc-btn kv-cc-btn--primary"
                    data-kv-cc="all" data-testid="cookie-consent-all">
                Accept all
            </button>
        </div>
    </div>
</div>

<script>
    (function () {
        var KEY = 'kv_cookie_consent';
        var el  = document.getElementById('kv-cookie-consent');
        if (! el) return;

        function existing() {
            try {
                var v = localStorage.getItem(KEY);
                if (v) return v;
            } catch (_) { /* private mode */ }
            var m = document.cookie.match(/(?:^|;\s*)kv_cookie_consent=([^;]+)/);
            return m ? decodeURIComponent(m[1]) : null;
        }

        function persist(value) {
            try { localStorage.setItem(KEY, value); } catch (_) { /* private mode */ }
            // 6-month first-party cookie so the choice survives across
            // sessions on the same device even when localStorage isn't
            // usable (some in-app webviews).
            var expires = new Date(Date.now() + 15552000 * 1000).toUTCString();
            document.cookie = 'kv_cookie_consent=' + encodeURIComponent(value)
                + '; expires=' + expires + '; path=/; SameSite=Lax';
            document.dispatchEvent(new CustomEvent('cookie-consent-changed', {
                detail: { choice: value },
            }));
        }

        function show() { el.classList.add('is-open'); }
        function hide() { el.classList.remove('is-open'); }

        // Skip the banner on repeat visits.
        if (existing()) return;

        // Delay show one frame so the fade-in transition triggers.
        requestAnimationFrame(function () {
            requestAnimationFrame(show);
        });

        el.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-kv-cc]');
            if (! btn) return;
            var choice = btn.getAttribute('data-kv-cc');
            persist(choice);
            hide();
        });
    })();
</script>
