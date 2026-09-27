@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    @include('_partials._back-button')

    <h1 class="lp-display mt-4 text-[clamp(26px,3.4vw,36px)]"
        style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
        {{ $isEdit ? 'Edit booking' : 'New desk booking' }}
    </h1>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 20px;" data-testid="db-form">

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label" for="d-name">Customer name</label>
                <input id="d-name" type="text" wire:model.live="customer_name"
                       class="lp-input" required maxlength="120" data-testid="cust-name">
                @error('customer_name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="d-email">Email</label>
                <input id="d-email" type="email" wire:model.live="customer_email"
                       class="lp-input" maxlength="160" data-testid="cust-email">
            </div>
            <div>
                <label class="lp-form-label" for="d-phone">Phone</label>
                <input id="d-phone" type="text" wire:model.live="customer_phone"
                       class="lp-input" maxlength="32" data-testid="cust-phone">
            </div>
        </div>

        <div>
            <label class="lp-form-label" for="d-ws">Workspace</label>
            <select id="d-ws" wire:model.live="workspace_id" class="lp-input" required data-testid="workspace">
                <option value="">Choose a workspace…</option>
                @foreach ($workspaces as $w)
                    <option value="{{ $w->id }}">{{ $w->name }} · {{ $w->type->label() }}</option>
                @endforeach
            </select>
            @error('workspace_id') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label" for="d-start">Start</label>
                <input id="d-start" type="datetime-local" wire:model.live="start_at" class="lp-input" required data-testid="start">
                @error('start_at') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="d-end">End</label>
                <input id="d-end" type="datetime-local" wire:model.live="end_at" class="lp-input" required data-testid="end">
                @error('end_at') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="d-amt">Amount (₦)</label>
                <input id="d-amt" type="number" min="0" wire:model.live="amount_naira" class="lp-input" required data-testid="amount">
                @error('amount_naira') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="lp-form-label" for="d-status">Status</label>
            <select id="d-status" wire:model.live="status" class="lp-input" required data-testid="status">
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>

        @if (! $isEdit && $corporates->isNotEmpty())
            <fieldset class="lp-fieldset" data-testid="corporate-fieldset">
                <legend>Corporate (optional)</legend>
                <p class="lp-form-help" style="margin-top: 4px;">
                    Attribute this booking to a Keverna corporate. Negotiated rates apply automatically
                    and the cost is held against the corporate's wallet.
                </p>
                <div class="grid gap-5 sm:grid-cols-2 mt-3">
                    <div>
                        <label class="lp-form-label" for="d-corp">Corporate</label>
                        <select id="d-corp" wire:model.live="corporate_id" class="lp-input" data-testid="corporate-id">
                            <option value="">- Walk-in (no corporate) -</option>
                            @foreach ($corporates as $corp)
                                <option value="{{ $corp->id }}">{{ $corp->name }}</option>
                            @endforeach
                        </select>
                        @error('corporate_id') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lp-form-label" for="d-mem">Member</label>
                        <select id="d-mem" wire:model.live="corporate_member_id" class="lp-input"
                                data-testid="corporate-member-id"
                                @if ($corporate_id === null) disabled @endif>
                            <option value="">- Bill the company directly -</option>
                            @foreach ($corporateMembers as $mem)
                                <option value="{{ $mem->id }}">{{ $mem->name }} · {{ $mem->email }}</option>
                            @endforeach
                        </select>
                        @error('corporate_member_id') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>
        @endif

        <div>
            <label class="lp-form-label" for="d-notes">Notes</label>
            <textarea id="d-notes" wire:model.live="notes" rows="3" maxlength="5000"
                      class="lp-input" data-testid="notes"></textarea>
        </div>

        <div class="flex justify-end pt-2" style="border-top: 1px solid var(--color-line-soft);">
            <button type="submit" class="lp-pill" data-testid="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Save changes' : 'Create booking' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
