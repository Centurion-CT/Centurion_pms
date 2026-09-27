@props([
    'wireModel',       // Livewire property name to sync (e.g. 'pin', 'pinConfirm', 'resetOtp')
    'length' => 4,     // Number of cells
    'testid' => null,  // data-testid on the wrapper (existing tests target this)
    'autofocus' => false,
    // Set to 'one-time-code' on the reset OTP so iOS surfaces the WhatsApp
    // code from the notification bar as a paste suggestion.
    'autocomplete' => null,
])

{{-- Guest-portal OTP entry — N cells, auto-advance, paste-distributes,
     backspace-retreats, numeric keyboard on mobile. Syncs a single
     concatenated string back to $wire[$wireModel] via $wire.set() so
     the existing Livewire submit handlers keep working unchanged.

     Rendered as a real form of <input>s (not <template x-for>) so
     iOS one-time-code autofill and 1Password can find the fields. --}}

<div class="gp-otp"
     data-testid="{{ $testid }}"
     x-data="gpOtp({{ $length }}, @js($wireModel), $wire)"
     x-init="$nextTick(() => { {{ $autofocus ? 'focusFirst();' : '' }} })"
     wire:ignore>
    @for ($i = 0; $i < $length; $i++)
        <input
            type="text"
            inputmode="numeric"
            pattern="[0-9]"
            maxlength="1"
            class="gp-otp-cell"
            aria-label="Digit {{ $i + 1 }} of {{ $length }}"
            data-cell-index="{{ $i }}"
            @if ($i === 0 && $autocomplete)
                autocomplete="{{ $autocomplete }}"
            @else
                autocomplete="off"
            @endif
            x-on:input="onInput($event, {{ $i }})"
            x-on:keydown="onKeydown($event, {{ $i }})"
            x-on:paste="onPaste($event)"
            x-on:focus="$event.target.select()"
        />
    @endfor
</div>

@once
    <style>
        .gp-otp {
            display: flex;
            gap: 10px;
            justify-content: center;
            width: 100%;
        }
        .gp-otp-cell {
            flex: 1 1 0;
            min-width: 0;
            /* Cap so 6-cell reset doesn't stretch too wide on desktop. */
            max-width: 64px;
            height: 56px;
            padding: 0;
            border: 1px solid rgba(20, 23, 26, 0.18);
            border-radius: 10px;
            background: var(--gp-paper);
            color: var(--gp-ink);
            font-family: 'SF Mono', 'JetBrains Mono', ui-monospace, Menlo, monospace;
            font-size: 24px;
            font-weight: 600;
            font-variant-numeric: tabular-nums lining-nums;
            text-align: center;
            letter-spacing: 0;
            /* iOS Safari squishes text unless we lock the line-height too. */
            line-height: 56px;
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
            /* No browser-native suggestion arrows or clear-buttons. */
            -webkit-appearance: none;
            appearance: none;
        }
        .gp-otp-cell:focus {
            outline: none;
            border-color: rgba(20, 23, 26, 0.55);
            box-shadow: 0 0 0 3px rgba(20, 23, 26, 0.08);
            background: #fff;
        }
        .gp-otp-cell.is-filled {
            border-color: rgba(20, 23, 26, 0.45);
        }
        .gp-otp-cell.is-error {
            border-color: var(--gp-danger, #B85C42);
            animation: gpOtpShake .28s ease;
        }
        @keyframes gpOtpShake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-3px); }
            75% { transform: translateX(3px); }
        }
        @media (max-width: 380px) {
            .gp-otp { gap: 8px; }
            .gp-otp-cell { height: 52px; line-height: 52px; font-size: 22px; }
        }
    </style>

    <script>
        // Registers a global Alpine data function so every gp-otp instance
        // shares the same behaviour without re-declaring the closure.
        //
        // Alpine.data() must be callable both BEFORE alpine:init (initial
        // page load) AND AFTER it (this component's once-only <script> only
        // ships when its first use lands, which is often after Livewire
        // swaps the DOM into the PIN step, well past Alpine's initial
        // boot). Guard both paths.
        (function () {
            const factory = (length, modelKey, wire) => ({
                length,
                modelKey,
                wire,

                init() {
                    // If Livewire already has a value (e.g. after a validation
                    // re-render), pre-fill the cells so the guest doesn't see
                    // an empty row after a failed submit.
                    const existing = String(this.wire[this.modelKey] ?? '');
                    if (existing.length > 0) this.setFromString(existing);
                },

                cells() {
                    return Array.from(this.$el.querySelectorAll('.gp-otp-cell'));
                },

                sync() {
                    const value = this.cells().map(c => c.value).join('').slice(0, this.length);
                    this.wire.set(this.modelKey, value, false);
                    // Reflect fill state visually.
                    this.cells().forEach(c => {
                        c.classList.toggle('is-filled', (c.value || '').length > 0);
                    });
                },

                setFromString(str) {
                    const chars = String(str).replace(/\D/g, '').slice(0, this.length).split('');
                    const cells = this.cells();
                    cells.forEach((c, i) => { c.value = chars[i] ?? ''; });
                    this.sync();
                },

                focusFirst() {
                    this.cells()[0]?.focus();
                },

                onInput(event, idx) {
                    const cell = event.target;
                    // Strip non-digits — a stray keyboard char can't land.
                    const digit = (cell.value || '').replace(/\D/g, '').slice(-1);
                    cell.value = digit;
                    if (digit) {
                        const next = this.cells()[idx + 1];
                        if (next) next.focus();
                    }
                    this.sync();
                },

                onKeydown(event, idx) {
                    const cells = this.cells();
                    if (event.key === 'Backspace' && !cells[idx].value && idx > 0) {
                        // Empty cell + Backspace → jump back and clear.
                        event.preventDefault();
                        cells[idx - 1].value = '';
                        cells[idx - 1].focus();
                        this.sync();
                    } else if (event.key === 'ArrowLeft' && idx > 0) {
                        event.preventDefault();
                        cells[idx - 1].focus();
                    } else if (event.key === 'ArrowRight' && idx < cells.length - 1) {
                        event.preventDefault();
                        cells[idx + 1].focus();
                    } else if (event.key === 'Enter') {
                        // Let the parent form / wire:submit pick it up.
                    }
                },

                onPaste(event) {
                    const text = (event.clipboardData || window.clipboardData)?.getData('text') || '';
                    const digits = text.replace(/\D/g, '');
                    if (!digits) return;
                    event.preventDefault();
                    this.setFromString(digits);
                    // Focus the first empty cell, or the last one if all full.
                    const cells = this.cells();
                    const target = cells.find(c => !c.value) || cells[cells.length - 1];
                    target?.focus();
                },
            });
            const register = () => window.Alpine.data('gpOtp', factory);
            if (window.Alpine) {
                register();
            } else {
                document.addEventListener('alpine:init', register);
            }
        })();
    </script>
@endonce
