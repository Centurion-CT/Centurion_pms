{{-- Staff activity — per-operator actions in the window, grouped by
     department. Every row is a `activity_log` entry attributed to
     an Operator: check-ins, housekeeping sign-offs, payments taken,
     POS sales, admin overrides. Filters + export mirror the URL
     state so a shareable link reproduces the same cut. --}}

@php
    $labelForDept = fn (?string $key) => $key && isset($departments[$key])
        ? $departments[$key]['label']
        : '-';
@endphp

{{-- ── KPI tiles ────────────────────────────────────────────────── --}}
<div class="grid gap-3 grid-cols-2 lg:grid-cols-3" data-testid="staff-kpis">
    <div class="lp-kpi">
        <span class="label">Total actions</span>
        <span class="value">{{ number_format($staffKpis['total']) }}</span>
        <span class="sub">in this window</span>
    </div>
    <div class="lp-kpi">
        <span class="label">Active staff</span>
        <span class="value">{{ number_format($staffKpis['staff']) }}</span>
        <span class="sub">with at least one action</span>
    </div>
    <div class="lp-kpi">
        <span class="label">Top event</span>
        <span class="value" style="font-size: 15px; font-weight: 600; text-transform: none;">
            {{ $staffKpis['top_event'] ? str_replace('_', ' ', $staffKpis['top_event']) : '-' }}
        </span>
        <span class="sub">most common in this window</span>
    </div>
</div>

{{-- ── Filters ─────────────────────────────────────────────────── --}}
<section class="lp-dash-card" data-testid="staff-filters">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">Filter staff activity</span>
        <a href="{{ $exportUrl }}"
           data-testid="staff-export"
           class="lp-mono text-[11.5px]"
           style="color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">
            Export CSV &darr;
        </a>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div>
            <label for="staff-dept" class="lp-form-label">Department</label>
            <select id="staff-dept" wire:model.live="departmentFilter"
                    data-testid="staff-dept" class="lp-input">
                <option value="">All departments</option>
                @foreach ($departments as $key => $dept)
                    <option value="{{ $key }}">{{ $dept['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="staff-picker" class="lp-form-label">Staff</label>
            <select id="staff-picker" wire:model.live="staffFilter"
                    data-testid="staff-picker" class="lp-input">
                <option value="">
                    {{ count($staffOptions) === 0
                        ? 'Nobody with activity in this window'
                        : 'All staff in this window' }}
                </option>
                @foreach ($staffOptions as $op)
                    <option value="{{ $op['id'] }}">
                        {{ $op['name'] }} · {{ $op['role'] }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="staff-log" class="lp-form-label">Domain</label>
            <select id="staff-log" wire:model.live="logNameFilter"
                    data-testid="staff-log" class="lp-input">
                <option value="">All domains</option>
                @foreach ($logNameOptions as $log)
                    <option value="{{ $log }}">{{ ucfirst(str_replace('_', ' ', $log)) }}</option>
                @endforeach
            </select>
        </div>
    </div>
</section>

{{-- ── Activity table ──────────────────────────────────────────── --}}
<section class="lp-dash-card" style="padding: 0;">
    <div class="lp-dash-card-head" style="padding: 14px 18px;">
        <span class="lp-dash-card-title">Actions</span>
        <span class="lp-dash-card-meta">
            {{ $activityRows->total() }} rows ·
            {{ $from->toFormattedDateString() }} → {{ $to->toFormattedDateString() }}
        </span>
    </div>

    @if ($activityRows->isEmpty())
        <p class="text-[13.5px]" style="padding: 24px 18px; color: var(--color-ink-mute);"
           data-testid="staff-empty">
            No staff actions matched this filter. Try widening the date range or clearing the department / staff picker.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="lp-table" data-testid="staff-activity-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Staff</th>
                        <th>Department</th>
                        <th>Domain</th>
                        <th>Event</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($activityRows as $a)
                        @php
                            /** @var \App\Models\Operator|null $causer */
                            $causer = $a->causer;
                            $role = $causer?->role;
                            $deptKey = \App\Livewire\Dashboard\Reports\Index::departmentForRole($role);
                        @endphp
                        <tr data-testid="staff-row-{{ $a->id }}">
                            <td style="color: var(--color-ink-soft); font-size: 12px; white-space: nowrap;">
                                {{ $a->created_at?->format('j M · g:i A') ?? '-' }}
                            </td>
                            <td style="color: var(--color-ink);">
                                @if ($causer)
                                    <div style="font-weight: 500;">{{ $causer->name }}</div>
                                    <div class="text-[11px]" style="color: var(--color-ink-mute);">
                                        {{ $role?->label() ?? '-' }}
                                    </div>
                                @else
                                    <span style="color: var(--color-ink-mute);">-</span>
                                @endif
                            </td>
                            <td>
                                @if ($deptKey)
                                    <span class="lp-mono text-[10.5px]"
                                          style="padding: 2px 8px; border-radius: 999px;
                                                 background: var(--color-card-deep); color: var(--color-ink);
                                                 letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600;">
                                        {{ $departments[$deptKey]['label'] }}
                                    </span>
                                @else
                                    <span style="color: var(--color-ink-mute); font-size: 12px;">-</span>
                                @endif
                            </td>
                            <td class="lp-mono text-[11.5px]" style="color: var(--color-ink-soft);">
                                {{ $a->log_name ?? '-' }}
                            </td>
                            <td class="lp-mono text-[11.5px]" style="color: var(--color-ink-soft);">
                                {{ $a->event ? str_replace('_', ' ', $a->event) : '-' }}
                            </td>
                            <td style="color: var(--color-ink); font-size: 12.5px;">
                                {{ $a->description ?: '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding: 10px 16px; border-top: 1px solid var(--color-line-soft);">
            {{ $activityRows->links() }}
        </div>
    @endif
</section>
