<section class="lp-dash-card" data-testid="summary-subscribers">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">Report subscribers</span>
        <span class="lp-dash-card-meta">
            weekly PDF Monday 06:00 &middot; monthly PDF 1st of month 06:00
        </span>
    </div>

    @if ($flash !== '')
        @php $isErrorFlash = str_starts_with($flash, 'Send failed'); @endphp
        <div class="lp-mono text-[11px] mb-3"
             style="color: {{ $isErrorFlash ? 'var(--color-warn)' : 'var(--color-good)' }}; letter-spacing: 0.04em;"
             wire:key="flash-{{ $flash }}">
            {{ $flash }}
        </div>
    @endif

    {{-- Existing subscribers --}}
    @if ($subs->isEmpty())
        <p class="text-[13.5px]" style="color: var(--color-ink-mute); margin-bottom: 12px;">
            No one is subscribed yet. Add an email below.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="lp-table">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th style="text-align: center; width: 90px;">Weekly</th>
                        <th style="text-align: center; width: 90px;">Monthly</th>
                        <th style="width: 80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($subs as $sub)
                        <tr data-testid="subscriber-row">
                            <td style="color: var(--color-ink); font-weight: 500;">
                                {{ $sub->email }}
                                @if ($sub->createdBy)
                                    <span class="lp-mono text-[10.5px]" style="display: block; color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                                        added by {{ $sub->createdBy->name }}
                                    </span>
                                @endif
                            </td>
                            <td style="text-align: center;">
                                <button type="button"
                                        wire:click="toggle({{ $sub->id }}, 'weekly')"
                                        class="lp-chip {{ $sub->weekly ? 'is-active' : '' }}"
                                        style="min-width: 56px;">
                                    {{ $sub->weekly ? 'On' : 'Off' }}
                                </button>
                            </td>
                            <td style="text-align: center;">
                                <button type="button"
                                        wire:click="toggle({{ $sub->id }}, 'monthly')"
                                        class="lp-chip {{ $sub->monthly ? 'is-active' : '' }}"
                                        style="min-width: 56px;">
                                    {{ $sub->monthly ? 'On' : 'Off' }}
                                </button>
                            </td>
                            <td style="text-align: right;">
                                <button type="button"
                                        wire:click="remove({{ $sub->id }})"
                                        wire:confirm="Remove {{ $sub->email }} from summary reports?"
                                        class="lp-mono text-[11px]"
                                        style="color: var(--color-warn); background: none; border: none; cursor: pointer; padding: 4px 6px;">
                                    Remove
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Add form --}}
    <div style="border-top: 1px solid var(--color-line); margin-top: 12px; padding-top: 12px;">
        <form wire:submit="add" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
            <input type="email" wire:model="email" placeholder="name@example.com"
                   data-testid="subscriber-email"
                   class="lp-input"
                   style="font-size: 12.5px; padding: 6px 10px; flex: 1; min-width: 220px;">
            <label class="lp-chip {{ $weekly ? 'is-active' : '' }}" style="cursor: pointer; user-select: none;">
                <input type="checkbox" wire:model.live="weekly" style="display: none;">
                Weekly
            </label>
            <label class="lp-chip {{ $monthly ? 'is-active' : '' }}" style="cursor: pointer; user-select: none;">
                <input type="checkbox" wire:model.live="monthly" style="display: none;">
                Monthly
            </label>
            <button type="submit" class="lp-btn" data-testid="subscriber-add">Add</button>
        </form>
        @error('email')
            <span class="lp-mono text-[11px]" style="color: var(--color-warn); display: block; margin-top: 6px;">
                {{ $message }}
            </span>
        @enderror
    </div>

    {{-- Send test --}}
    <div style="border-top: 1px solid var(--color-line); margin-top: 12px; padding-top: 12px;">
        <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">
            Send test
        </span>
        <div class="flex flex-wrap items-center gap-2 mt-2">
            <input type="email" wire:model="testEmail" placeholder="you@example.com"
                   data-testid="subscriber-test-email"
                   class="lp-input"
                   style="font-size: 12.5px; padding: 6px 10px; flex: 1; min-width: 220px;">
            <button type="button" wire:click="sendTest" class="lp-btn"
                    data-testid="subscriber-send-test">
                Send preview PDF
            </button>
        </div>
        @error('testEmail')
            <span class="lp-mono text-[11px]" style="color: var(--color-warn); display: block; margin-top: 6px;">
                {{ $message }}
            </span>
        @enderror
    </div>
</section>
