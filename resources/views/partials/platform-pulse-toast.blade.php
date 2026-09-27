{{--
    Floating pill container for live platform-pulse toasts. Rendered
    fixed to the bottom-left on desktop, bottom-center on mobile, so it
    never fights the whatsapp-float chip on the right. Toast items are
    injected + removed by resources/js/platform-pulse.js.
--}}
<div id="kv-pulse-toast" aria-live="polite" aria-atomic="false"></div>

<style>
    #kv-pulse-toast {
        position: fixed;
        left: 20px;
        bottom: 20px;
        z-index: 2147483000;
        display: flex;
        flex-direction: column;
        gap: 8px;
        pointer-events: none;
        max-width: min(360px, calc(100vw - 40px));
    }
    @media (max-width: 640px) {
        #kv-pulse-toast {
            left: 50%;
            transform: translateX(-50%);
            bottom: 16px;
            align-items: center;
        }
    }
    .kv-pulse-toast-item {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        border-radius: 999px;
        background: #1a1a1a;
        color: #f5efe4;
        box-shadow: 0 12px 32px rgba(0, 0, 0, 0.24), 0 2px 6px rgba(0, 0, 0, 0.14);
        font-size: 13.5px;
        letter-spacing: 0.005em;
        line-height: 1;
        white-space: nowrap;
        opacity: 0;
        transform: translateY(14px);
        transition: opacity 340ms ease, transform 340ms cubic-bezier(0.2, 0.7, 0.2, 1);
        pointer-events: auto;
    }
    .kv-pulse-toast-item.is-in {
        opacity: 1;
        transform: translateY(0);
    }
    .kv-pulse-toast-item.is-out {
        opacity: 0;
        transform: translateY(-8px);
    }
    .kv-pulse-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #7ab77a;
        box-shadow: 0 0 0 4px rgba(122, 183, 122, 0.22);
        flex: 0 0 auto;
    }
    .kv-pulse-amount {
        font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
        font-weight: 600;
        letter-spacing: 0.01em;
        color: #ffffff;
    }
    .kv-pulse-sep {
        color: rgba(245, 239, 228, 0.35);
        margin: 0 -2px;
    }
    .kv-pulse-label {
        color: rgba(245, 239, 228, 0.72);
        font-size: 12.5px;
    }
    @media (prefers-reduced-motion: reduce) {
        .kv-pulse-toast-item {
            transition: opacity 120ms linear;
            transform: none !important;
        }
    }
</style>
