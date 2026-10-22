{{-- Alpine data for <x-gp-otp> (PIN / one-time-code cells). --}}
<script>
        // Registers a global Alpine data function so every gp-otp instance
        // shares the same behaviour without re-declaring the closure.
        //
        // Loaded once by the guest-portal layout so it's registered before
        // any PIN step is swapped in (scripts inside Livewire-morphed HTML
        // don't run). Works whether Alpine has booted yet or not.
        (function () {
            const factory = (length, modelKey, wire) => ({
                length,
                modelKey,
                wire,

                init() {
                    // Bind the cell listeners here rather than with x-on on each
                    // <input>: these cells arrive via a Livewire DOM swap, and the
                    // per-cell directives weren't reliably initialised there.
                    this.cells().forEach((cell, idx) => {
                        cell.addEventListener('input', (e) => this.onInput(e, idx));
                        cell.addEventListener('keydown', (e) => this.onKeydown(e, idx));
                        cell.addEventListener('paste', (e) => this.onPaste(e));
                        cell.addEventListener('focus', (e) => e.target.select());
                    });

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
