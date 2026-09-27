{{--
    Global notify modal, replaces inline ".lp-status" flashes.
    Any Livewire component can fire:

        $this->dispatch('notify',
            title: 'Cleaning submitted',
            message: 'Porter can verify it now.',
            kind: 'ok',           // 'ok' (default) or 'err'
        );

    The modal lives once in the tenant layout so every authed
    page inherits it. Success auto-dismisses in ~2.6s; errors
    stay until the operator dismisses so they can't be missed
    on the way past.
--}}
<div x-data="{
        show: false,
        title: '',
        message: '',
        kind: 'ok',
        timer: null,
        open(detail) {
            const d = detail || {};
            this.kind = d.kind === 'err' ? 'err' : 'ok';
            this.title = d.title || (this.kind === 'err' ? 'Something went wrong' : 'Done');
            this.message = d.message || '';
            this.show = true;
            if (this.timer) clearTimeout(this.timer);
            if (this.kind === 'ok') {
                this.timer = setTimeout(() => this.close(), 2600);
            }
        },
        close() {
            this.show = false;
            if (this.timer) { clearTimeout(this.timer); this.timer = null; }
        },
     }"
     @notify.window="open($event.detail)"
     @keydown.escape.window="show && close()"
     x-show="show"
     x-cloak
     x-transition.opacity.duration.150ms
     class="lp-notify-backdrop"
     data-testid="notify-modal"
     @click.self="close()"
     role="presentation">

    <div class="lp-notify-card"
         :class="kind === 'err' ? 'lp-notify-card--err' : 'lp-notify-card--ok'"
         :data-testid="'notify-modal-' + kind"
         role="alertdialog"
         aria-live="polite"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-1 scale-[0.98]"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100">

        <div class="lp-notify-icon" aria-hidden="true">
            <template x-if="kind === 'ok'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-6"/>
                </svg>
            </template>
            <template x-if="kind === 'err'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><path d="M12 7v6M12 17h.01"/>
                </svg>
            </template>
        </div>

        <div class="lp-notify-title" x-text="title" data-testid="notify-title"></div>
        <div class="lp-notify-message" x-show="message" x-text="message" data-testid="notify-message"></div>

        <button type="button" @click="close()"
                class="lp-notify-close"
                data-testid="notify-close">
            <span x-text="kind === 'err' ? 'Dismiss' : 'Done'"></span>
        </button>
    </div>
</div>

{{-- Session-flash bridge, the modal above only listens for the
     `notify` Alpine event, which is fired by Livewire dispatches.
     Full-page redirect flashes (e.g. session()->flash('status', …)
     after a POST/redirect controller) never dispatch. This tiny
     script re-fires them as a `notify` event on first paint so the
     same modal handles both flows. `session()->error()` (via ->
     with('error', …)) surfaces as kind=err; anything else is ok. --}}
@php
    // Legacy session-flash keys used across the codebase. We check
    // them explicitly (rather than sniffing all session keys) so a
    // stray session value with a wrong name can't accidentally
    // trigger the modal.
    $flashSuccess = session('status')
        ?? session('success')
        ?? session('photos-status')
        ?? session('pages-status')
        ?? session('reviews-status');
    $flashError = session('error') ?? session('errors_message');
@endphp
@if ($flashSuccess || $flashError)
    <script data-testid="notify-session-bridge">
        (function () {
            var fire = function () {
                @if ($flashError)
                    window.dispatchEvent(new CustomEvent('notify', { detail: {
                        kind: 'err',
                        title: 'Something went wrong',
                        message: @json((string) $flashError),
                    }}));
                @else
                    window.dispatchEvent(new CustomEvent('notify', { detail: {
                        kind: 'ok',
                        title: 'Done',
                        message: @json((string) $flashSuccess),
                    }}));
                @endif
            };
            if (window.Alpine) {
                queueMicrotask(fire);
            } else {
                document.addEventListener('alpine:init', function () { queueMicrotask(fire); });
            }
        })();
    </script>
@endif
