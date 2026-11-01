@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    @include('_partials._back-button')

    <h1 class="lp-display mt-4 text-[clamp(26px,3.4vw,36px)]"
        style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
        {{ $isEdit ? 'Edit membership' : 'New membership' }}
    </h1>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 20px;" data-testid="membership-form">

        {{-- Customer fields --}}
        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label" for="c-name">Member name</label>
                <input id="c-name" type="text" wire:model.live="customer_name"
                       class="lp-input" required maxlength="120" data-testid="cust-name">
                @error('customer_name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="c-email">Email</label>
                <input id="c-email" type="email" wire:model.live="customer_email"
                       class="lp-input" maxlength="160" data-testid="cust-email">
                @error('customer_email') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="c-phone">Phone</label>
                <input id="c-phone" type="text" wire:model.live="customer_phone"
                       class="lp-input" maxlength="32" data-testid="cust-phone">
            </div>
        </div>

        {{-- Plan + workspace --}}
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="lp-form-label" for="m-plan">Plan</label>
                <select id="m-plan" wire:model.live="workspace_plan_id" class="lp-input" required data-testid="plan">
                    <option value="">Choose a plan…</option>
                    @foreach ($plans as $p)
                        <option value="{{ $p->id }}">{{ $p->name }} · {{ $p->priceLabel() }}</option>
                    @endforeach
                </select>
                @error('workspace_plan_id') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="m-ws">Assigned workspace</label>
                <select id="m-ws" wire:model.live="workspace_id" class="lp-input" data-testid="workspace">
                    <option value="">No specific workspace</option>
                    @foreach ($workspaces as $w)
                        <option value="{{ $w->id }}">{{ $w->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Dates + billing --}}
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="lp-form-label" for="m-start">Started</label>
                <input id="m-start" type="date" wire:model.live="started_at" class="lp-input" required data-testid="started">
                @error('started_at') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="m-next">Next renewal</label>
                <input id="m-next" type="date" wire:model.live="next_renewal_at" class="lp-input" data-testid="next">
            </div>
            <div>
                <label class="lp-form-label" for="m-exp">Expires</label>
                <input id="m-exp" type="date" wire:model.live="expires_at" class="lp-input" data-testid="expires">
            </div>
            <div>
                <label class="lp-form-label" for="m-amt">Amount (₦)</label>
                <input id="m-amt" type="number" min="0" wire:model.live="amount_naira" class="lp-input" required data-testid="amount">
                @error('amount_naira') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label" for="m-int">Billing interval</label>
                <select id="m-int" wire:model.live="billing_interval" class="lp-input" data-testid="interval">
                    @foreach ($intervals as $i)
                        <option value="{{ $i->value }}">{{ $i->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="lp-form-label" for="m-st">Status</label>
                <select id="m-st" wire:model.live="status" class="lp-input" required data-testid="status">
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="lp-form-label" for="m-sub">Paystack subscription code</label>
                <input id="m-sub" type="text" wire:model.live="paystack_subscription_code"
                       class="lp-input" maxlength="64" data-testid="paystack-code" placeholder="Optional">
            </div>
        </div>

        <div>
            <label class="lp-form-label" for="m-notes">Notes</label>
            <textarea id="m-notes" wire:model.live="notes" rows="3" maxlength="5000"
                      class="lp-input" data-testid="notes"></textarea>
        </div>

        @if (! $isEdit && $corporates->isNotEmpty())
            <fieldset class="lp-fieldset" data-testid="corporate-fieldset">
                <legend>Corporate (optional)</legend>
                <p class="lp-form-help" style="margin-top: 4px;">
                    Attribute this membership to one of your corporate clients. The first period is held
                    against the corporate's wallet at the negotiated rate.
                </p>
                <div class="grid gap-5 sm:grid-cols-2 mt-3">
                    <div>
                        <label class="lp-form-label" for="m-corp">Corporate</label>
                        <select id="m-corp" wire:model.live="corporate_id" class="lp-input" data-testid="corporate-id">
                            <option value="">- No corporate -</option>
                            @foreach ($corporates as $corp)
                                <option value="{{ $corp->id }}">{{ $corp->name }}</option>
                            @endforeach
                        </select>
                        @error('corporate_id') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lp-form-label" for="m-mem">Member</label>
                        <select id="m-mem" wire:model.live="corporate_member_id" class="lp-input"
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

        <div class="flex justify-end pt-2" style="border-top: 1px solid var(--color-line-soft);">
            <button type="submit" class="lp-pill" data-testid="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Save changes' : 'Create membership' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
