@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Back + header ────────────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip">{{ $customerId ? 'Edit customer' : 'New customer' }}</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $customerId ? 'Edit profile' : 'Add a customer' }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                @if ($customerId)
                    Update the details, tags, and lifecycle for this person.
                @else
                    Walk-in inquiry, repeat guest from another system, or someone you met at an event. Capture them here.
                @endif
            </p>
        </div>
    </div>

    {{-- ── Form ─────────────────────────────────────────────────── --}}
    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 22px;">

        {{-- Identity --}}
        <div>
            <label for="cust-name" class="lp-form-label">Full name</label>
            <input id="cust-name" type="text" wire:model="name" required maxlength="160"
                   data-testid="cust-name" class="lp-input" placeholder="Amara Okafor">
            @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="cust-email" class="lp-form-label">Email</label>
                <input id="cust-email" type="email" wire:model="email" maxlength="200"
                       data-testid="cust-email" class="lp-input" placeholder="amara@example.com">
                @error('email') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="cust-phone" class="lp-form-label">Phone</label>
                <input id="cust-phone" type="tel" wire:model="phone" maxlength="40"
                       data-testid="cust-phone" class="lp-input" placeholder="+234 800 000 0000">
                @error('phone') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Address --}}
        <fieldset class="lp-fieldset">
            <legend>Address</legend>
            <div class="grid gap-5 sm:grid-cols-2 mt-2">
                <div class="sm:col-span-2">
                    <label for="cust-address" class="lp-form-label">Street</label>
                    <input id="cust-address" type="text" wire:model="address" maxlength="240"
                           class="lp-input">
                </div>
                <div>
                    <label for="cust-city" class="lp-form-label">City</label>
                    <input id="cust-city" type="text" wire:model="city" maxlength="120" class="lp-input">
                </div>
                <div>
                    <label for="cust-state" class="lp-form-label">State</label>
                    <input id="cust-state" type="text" wire:model="state" maxlength="120" class="lp-input">
                </div>
                <div>
                    <label for="cust-country" class="lp-form-label">Country</label>
                    <input id="cust-country" type="text" wire:model="country" maxlength="80" class="lp-input">
                </div>
            </div>
        </fieldset>

        {{-- Lifecycle + flags --}}
        <fieldset class="lp-fieldset">
            <legend>Status</legend>
            <div class="grid gap-5 sm:grid-cols-3 mt-2 items-end">
                <div>
                    <label for="cust-lifecycle" class="lp-form-label">Lifecycle</label>
                    <select id="cust-lifecycle" wire:model="lifecycle"
                            data-testid="cust-lifecycle" class="lp-input">
                        @foreach ($lifecycleOptions as $opt)
                            <option value="{{ $opt->value }}">{{ $opt->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2 grid gap-2">
                    <label class="lp-check-row">
                        <input type="checkbox" wire:model="is_vip" data-testid="cust-vip">
                        <span><strong>VIP</strong>, flag as priority guest in lists + bookings</span>
                    </label>
                    <label class="lp-check-row">
                        <input type="checkbox" wire:model="marketing_opt_in" data-testid="cust-marketing">
                        <span>Customer has opted in to marketing communications</span>
                    </label>
                </div>
            </div>
        </fieldset>

        {{-- Tags --}}
        <div>
            <label class="lp-form-label">Tags <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· segment this customer</span></label>
            <div class="flex flex-wrap gap-2 mb-3">
                @foreach ($tags as $tag)
                    <span class="lp-tag-pill" data-testid="tag-{{ $tag }}">
                        {{ $tag }}
                        <button type="button" wire:click="removeTag('{{ $tag }}')"
                                data-testid="remove-tag-{{ $tag }}" aria-label="Remove">×</button>
                    </span>
                @endforeach
                @if (empty($tags))
                    <span class="text-[12px]" style="color: var(--color-ink-mute);">No tags yet.</span>
                @endif
            </div>
            <div class="flex gap-2">
                <input type="text" wire:model="newTag" maxlength="48"
                       wire:keydown.enter.prevent="addTag"
                       data-testid="new-tag" class="lp-input"
                       placeholder="e.g. VIP, Corporate, Diaspora">
                <button type="button" wire:click="addTag" data-testid="add-tag" class="lp-btn">Add</button>
            </div>
            @error('tags.*') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Notes --}}
        <div>
            <label for="cust-notes" class="lp-form-label">Notes</label>
            <textarea id="cust-notes" wire:model="notes" rows="4" maxlength="8000"
                      data-testid="cust-notes" class="lp-input"
                      placeholder="Anything important, preferences, allergies, history, who they came with…"></textarea>
            @error('notes') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Actions --}}
        <div class="flex flex-wrap justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ $customerId ? route('tenant.customers.show', ['customer' => $customerId]) : route('tenant.customers.index') }}"
               wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" data-testid="cust-save" class="lp-pill">
                <span wire:loading.remove wire:target="save">{{ $customerId ? 'Save changes' : 'Add customer' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
