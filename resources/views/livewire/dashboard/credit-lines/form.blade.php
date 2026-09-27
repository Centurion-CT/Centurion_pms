<div class="lp-dash">

    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">{{ $creditLineId ? 'Edit credit line' : 'New credit line' }}</span>
            <h1 class="lp-display mt-3 text-[clamp(24px,3vw,32px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.05;">
                {{ $creditLineId ? 'Edit credit line' : 'Open a credit line' }}
            </h1>
        </div>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 22px;">

        <div>
            <label for="cl-name" class="lp-form-label">Line name</label>
            <input id="cl-name" type="text" wire:model="name" required maxlength="120"
                   data-testid="cl-name" class="lp-input"
                   placeholder='e.g. "Okoye family tab"'>
            @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Customer picker, shared trait. --}}
        <div data-testid="cl-customer-lookup">
            <label class="lp-form-label">Customer</label>
            @if ($this->pickedCustomer)
                <div class="lp-picked-customer" data-testid="cl-picked-customer">
                    <span class="lp-avatar @if ($this->pickedCustomer->is_vip) lp-avatar--vip @endif" style="width: 32px; height: 32px; font-size: 11px;">
                        {{ $this->pickedCustomer->avatarInitials() }}
                    </span>
                    <div class="lp-picked-customer-body">
                        <span class="name">
                            @if ($this->pickedCustomer->is_vip)
                                <span style="color: var(--color-accent-deep); margin-right: 4px;">★</span>
                            @endif
                            {{ $this->pickedCustomer->name }}
                        </span>
                        <span class="meta">{{ $this->pickedCustomer->phone }} · {{ $this->pickedCustomer->email }}</span>
                    </div>
                    <button type="button" wire:click="clearCustomer" class="lp-btn">Change</button>
                </div>
            @else
                <input type="text" wire:model.live.debounce.300ms="customerSearch"
                       placeholder="Search by name, email or phone…"
                       data-testid="cl-customer-search"
                       class="lp-input">
                @if (! empty($this->customerMatches))
                    <ul class="lp-search-results" data-testid="cl-customer-matches">
                        @foreach ($this->customerMatches as $c)
                            <li>
                                <button type="button" wire:click="pickCustomer({{ $c->id }})">
                                    <strong>{{ $c->name }}</strong>
                                    <span>{{ $c->phone }} · {{ $c->email }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
            @error('selectedCustomerId') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Rooms attached. --}}
        <fieldset class="lp-fieldset">
            <legend>Rooms attached</legend>
            <p class="text-[13px] mb-2" style="color: var(--color-ink-soft);">
                Any of these rooms can carry charges that accrue to this line.
            </p>
            @if ($rooms->isEmpty())
                <p class="text-[12.5px]" style="color: var(--color-ink-mute); font-style: italic;">
                    No active rooms on this property yet.
                </p>
            @else
                <div class="grid gap-1" style="grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));">
                    @foreach ($rooms as $r)
                        <label class="lp-radio-row" style="align-items: center;">
                            <input type="checkbox" wire:model="roomIds" value="{{ $r->id }}"
                                   data-testid="cl-room-{{ $r->id }}">
                            <span>
                                <strong>Rm {{ $r->room_number }}</strong>
                                @if ($r->category)
                                    <span style="color: var(--color-ink-mute);">&middot; {{ $r->category->name }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
            @error('roomIds') <p class="lp-form-error">{{ $message }}</p> @enderror
        </fieldset>

        {{-- Status + spending limit. --}}
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="cl-status" class="lp-form-label">Status</label>
                <select id="cl-status" wire:model="status" data-testid="cl-status" class="lp-input">
                    @foreach ($statusOptions as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="cl-limit" class="lp-form-label">
                    Spending limit (&#8358;)
                    <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">
                        · optional
                    </span>
                </label>
                <input id="cl-limit" type="number" min="0" wire:model="spending_limit"
                       data-testid="cl-limit" class="lp-input" placeholder="e.g. 500000">
                <p class="lp-form-help">Blank = no cap. Over-limit charges surface a warning but still record.</p>
            </div>
        </div>

        <div>
            <label for="cl-notes" class="lp-form-label">
                Notes <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· staff-only</span>
            </label>
            <textarea id="cl-notes" wire:model="notes" rows="3" maxlength="5000"
                      data-testid="cl-notes" class="lp-input"
                      placeholder="Any instructions the front desk should keep in mind."></textarea>
            @error('notes') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.credit-lines.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" class="lp-pill"
                    wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $creditLineId ? 'Save changes' : 'Open credit line' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </form>
</div>
