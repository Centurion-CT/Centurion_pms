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

    {{-- Alpine behaviour lives in partials/gp-otp-script, loaded by the
         guest-portal layout: a <script> here would arrive via a Livewire
         DOM swap and never execute. --}}
@endonce
