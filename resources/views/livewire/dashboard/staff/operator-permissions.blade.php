<div class="lp-dash" data-testid="operator-permissions-page">

    <div>
        @include('_partials._back-button')
        <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
            <div>
                <span class="lp-eyebrow-strip">Permissions</span>
                <h1 class="lp-display mt-3 text-[clamp(24px,3vw,32px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.05;">
                    {{ $operatorName }}
                </h1>
                <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-soft);">
                    {{ $operatorEmail }}
                    <span style="color: var(--color-ink-mute);"> &middot; </span>
                    Role: <span style="color: var(--color-ink);">{{ $operatorRoleLabel }}</span>
                </p>
            </div>
            <span class="lp-tag {{ $statusClass }}" data-testid="operator-permissions-status">{{ $statusLabel }}</span>
        </div>
    </div>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Grantable capabilities</span>
            <span class="lp-dash-card-meta">Additive on top of the role's defaults</span>
        </div>
        <p class="text-[13.5px]" style="color: var(--color-ink-soft); max-width: 620px;">
            The role above sets what {{ $operatorName }} sees and does by default.
            Ticking a capability below adds it to that baseline, nothing here ever
            removes what the role already permits.
        </p>

        <form wire:submit="save" style="margin-top: 20px; display: grid; gap: 12px;">
            @foreach ($permissions as $perm)
                <label class="lp-radio-row"
                       style="display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px;
                              border: 1px solid var(--color-line-soft); border-radius: 10px;
                              background: var(--color-card); cursor: pointer;">
                    <input type="checkbox"
                           wire:model="selected"
                           value="{{ $perm->value }}"
                           data-testid="permission-{{ $perm->value }}"
                           style="margin-top: 3px; flex-shrink: 0;">
                    <span>
                        <strong style="color: var(--color-ink);">{{ $perm->label() }}</strong>
                        <span class="lp-mono text-[10.5px]"
                              style="color: var(--color-ink-mute); margin-left: 6px;">
                            {{ $perm->value }}
                        </span>
                        <p class="text-[13px]" style="color: var(--color-ink-soft); margin: 4px 0 0;">
                            {{ $perm->description() }}
                        </p>
                    </span>
                </label>
            @endforeach

            {{-- Branch access — only shown when the org has more than
                 one property (single-branch orgs get nothing here,
                 the operator is scoped to the only property that
                 exists) AND the operator being edited is not a
                 Manager (Managers bypass access rules by role). --}}
            @if ($branches->count() > 1 && ! $isManagerBeingEdited)
                <div style="border-top: 1px solid var(--color-line-soft); margin-top: 18px; padding-top: 18px;">
                    <h2 class="lp-display text-[16px]" style="color: var(--color-ink); font-weight: 600; margin-bottom: 4px;">
                        Branch access
                    </h2>
                    <p class="text-[13px]" style="color: var(--color-ink-soft); max-width: 620px; margin-bottom: 12px;">
                        Which branches {{ $operatorName }} can work in. Leave every box
                        unticked to grant access to all branches (the default). Tick one
                        or more to restrict them to that specific set.
                    </p>
                    @foreach ($branches as $branch)
                        <label class="lp-radio-row"
                               style="display: flex; align-items: flex-start; gap: 12px; padding: 10px 14px; margin-bottom: 6px;
                                      border: 1px solid var(--color-line-soft); border-radius: 10px;
                                      background: var(--color-card); cursor: pointer;">
                            <input type="checkbox"
                                   wire:model="selectedBranchIds"
                                   value="{{ $branch->id }}"
                                   data-testid="branch-access-{{ $branch->slug }}"
                                   style="margin-top: 3px; flex-shrink: 0;">
                            <span>
                                <strong style="color: var(--color-ink);">{{ $branch->name }}</strong>
                                @if ($branch->city || $branch->state)
                                    <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); margin-left: 6px;">
                                        {{ trim(collect([$branch->city, $branch->state])->filter()->implode(', ')) }}
                                    </span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif

            <div class="flex justify-end gap-3 pt-4"
                 style="border-top: 1px solid var(--color-line-soft); margin-top: 10px;">
                <a href="{{ route('tenant.staff.index') }}" wire:navigate class="lp-btn">Cancel</a>
                <button type="submit"
                        class="lp-btn lp-btn--primary"
                        data-testid="operator-permissions-save"
                        wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">Save permissions</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </form>
    </section>
</div>
