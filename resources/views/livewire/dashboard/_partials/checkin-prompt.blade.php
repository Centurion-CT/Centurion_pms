{{--
    "Check in now?" prompt shown right after a manual reservation is
    created for today's date. Rendered by the reserve-modal host
    (Reservations Index / ManualCreate / calendar PropertyView) so it
    can reach the same Livewire component's state + actions.

    Props (via @include):
      $showFlag       — boolean model path controlling visibility
                        (e.g. 'postCreateShowCheckInPrompt').
      $guestNameFlag  — string model path with the guest's name.
      $bookingRefFlag — booking_ref model path, used for testids.
      $confirmMethod  — Livewire method name for the Yes button
                        (e.g. 'confirmPostCreateCheckIn').
      $skipMethod     — Livewire method name for the No button.
--}}

@php
    $showFlag       ??= 'postCreateShowCheckInPrompt';
    $guestNameFlag  ??= 'postCreateGuestName';
    $bookingRefFlag ??= 'postCreateBookingRef';
    $confirmMethod  ??= 'confirmPostCreateCheckIn';
    $skipMethod     ??= 'skipPostCreateCheckIn';
@endphp

{{-- Follows the same shape as extend-stay-modal.blade.php: teleport
     to body so no parent transform/overflow can trap the fixed layer,
     use the canonical `.lp-modal-backdrop` class (body-scoped) for
     centering, and put `.lp-page` on the CARD so the design tokens
     (colours, fonts) resolve without wrapping the fixed layer in an
     extra element. The previous shape put `.lp-page` on the outer
     wrapper and rendered the card in the top-left because the
     backdrop's fixed positioning wasn't being applied correctly. --}}
<template x-teleport="body">
<div x-data="{ open: @entangle($showFlag) }"
     x-show="open"
     x-cloak
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     @click.self="$wire.{{ $skipMethod }}()"
     data-testid="checkin-prompt-backdrop"
     class="lp-modal-backdrop"
     style="z-index: 80;
            background: rgba(20, 22, 24, 0.42);
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);">
    <div @click.stop
         data-testid="checkin-prompt-card"
         class="lp-page"
         style="width: 100%; max-width: 420px;
                background: var(--color-card);
                border-radius: 16px;
                border: 1px solid var(--color-line-soft);
                box-shadow: 0 24px 60px rgba(0,0,0,0.22), 0 2px 6px rgba(0,0,0,0.08);
                padding: 28px 26px 22px;
                text-align: center;">
        <div style="display: flex; align-items: center; justify-content: center;
                    width: 52px; height: 52px; margin: 0 auto 14px;
                    border-radius: 999px; background: #DCFCE7; color: #3D7B4F;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 12l6-6h6a2 2 0 0 1 2 2v6M10 20v-6H4M14 20h6M10 14l4 4"/>
            </svg>
        </div>
        <h2 class="lp-display" style="font-size: 20px; font-weight: 600; margin: 0;">
            Check in now?
        </h2>
        <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-soft); line-height: 1.5;">
            <strong style="color: var(--color-ink);"
                    x-text="$wire.get('{{ $guestNameFlag }}')"></strong>
            is arriving today. Would you like to check them in and
            move the room straight to In-house?
        </p>

        <div class="flex gap-3 mt-6" style="justify-content: center;">
            {{-- No thanks — just redirects to the reservation. --}}
            <button type="button"
                    wire:click="{{ $skipMethod }}"
                    wire:loading.attr="disabled"
                    wire:target="{{ $skipMethod }},{{ $confirmMethod }}"
                    data-testid="checkin-prompt-skip"
                    style="background: var(--color-card-deep);
                           color: var(--color-ink);
                           border: 0; padding: 10px 20px;
                           border-radius: 9px;
                           font-family: inherit; font-size: 13px; font-weight: 500;
                           cursor: pointer;">
                <span wire:loading.remove wire:target="{{ $skipMethod }}">No thanks</span>
                <span wire:loading wire:target="{{ $skipMethod }}">Opening…</span>
            </button>

            {{-- Yes — MarkCheckedIn + redirect, with a loader. --}}
            <button type="button"
                    wire:click="{{ $confirmMethod }}"
                    wire:loading.attr="disabled"
                    wire:target="{{ $skipMethod }},{{ $confirmMethod }}"
                    data-testid="checkin-prompt-confirm"
                    style="background: var(--color-accent);
                           color: var(--color-ink);
                           border: 0; padding: 10px 22px;
                           border-radius: 9px;
                           font-family: inherit; font-size: 13px; font-weight: 600;
                           cursor: pointer;
                           min-width: 132px;
                           display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <span wire:loading.remove wire:target="{{ $confirmMethod }}">
                    Yes, check in
                </span>
                <span wire:loading wire:target="{{ $confirmMethod }}"
                      style="display: inline-flex; align-items: center; gap: 8px;">
                    <span aria-hidden style="width: 12px; height: 12px;
                                             border: 2px solid rgba(20,22,24,0.35);
                                             border-top-color: var(--color-ink);
                                             border-radius: 999px;
                                             animation: kv-checkin-spin 720ms linear infinite;"></span>
                    Checking in…
                </span>
            </button>
        </div>
    </div>
</div>
</template>

<style>
    @@keyframes kv-checkin-spin {
        to { transform: rotate(360deg); }
    }
</style>
