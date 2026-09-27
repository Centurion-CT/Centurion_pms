@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Brand</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Email templates
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Customise what your guests read when the system sends a confirmation, reminder or
            cancellation. Each template falls back to a sensible default until you edit it.
        </p>
    </div>

    <section class="lp-dash-card" style="padding: 0;">
        <div class="overflow-x-auto">
            <table data-testid="templates-table" class="lp-table">
                <thead>
                    <tr>
                        <th>Template</th>
                        <th>What it sends</th>
                        <th>Source</th>
                        <th>Last updated</th>
                        <th style="text-align: right;">Edit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-testid="template-row-{{ $row->key->value }}">
                            <td style="color: var(--color-ink); font-weight: 500;">
                                {{ $row->key->label() }}
                                <span class="text-[11px] mt-0.5" style="display: block; color: var(--color-ink-mute); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: 0.04em;">
                                    {{ $row->key->value }}
                                </span>
                            </td>
                            <td style="color: var(--color-ink-soft); max-width: 360px;">
                                {{ $row->key->description() }}
                            </td>
                            <td>
                                @if ($row->is_customised && $row->is_active)
                                    <span class="lp-tag lp-tag--confirmed">Customised</span>
                                @elseif ($row->is_customised && ! $row->is_active)
                                    <span class="lp-tag lp-tag--on-hold">Customised · paused</span>
                                @else
                                    <span class="lp-tag lp-tag--pending">Default</span>
                                @endif
                            </td>
                            <td style="color: var(--color-ink-soft);">
                                {{ $row->updated_at?->diffForHumans() ?? '-' }}
                            </td>
                            <td style="text-align: right;">
                                <a href="{{ route('tenant.email-templates.edit', $row->key->value) }}" wire:navigate
                                   class="lp-pill-sm"
                                   data-testid="template-edit-{{ $row->key->value }}">
                                    Edit
                                    <span class="dot">
                                        <svg width="10" height="10" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                                    </span>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
