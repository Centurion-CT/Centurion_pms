<div class="lp-dash">

    <div>
        <span class="lp-eyebrow-strip">Audit</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Activity log
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Cross-tenant feed of every recorded action, admin extensions, tenant edits, system jobs.
        </p>
    </div>

    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Filter</span>
            <button type="button" wire:click="clearFilters"
                    data-testid="clear-filters"
                    style="background: none; border: 0; cursor: pointer; color: var(--color-ink-soft); font-size: 12px; text-decoration: underline; text-underline-offset: 4px;">
                Clear all
            </button>
        </div>

        <form wire:submit.prevent class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2">
                <label class="lp-form-label" for="act-q">Search</label>
                <input id="act-q" type="search"
                       wire:model.live.debounce.300ms="search"
                       class="lp-input" placeholder="Description, event, subject"
                       data-testid="search">
            </div>
            <div>
                <label class="lp-form-label" for="act-log">Log channel</label>
                <select id="act-log" wire:model.live="logFilter" class="lp-input"
                        data-testid="log-filter">
                    <option value="">Any log</option>
                    @foreach ($logOptions as $opt)
                        <option value="{{ $opt }}">{{ $opt }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="lp-form-label" for="act-event">Event</label>
                <select id="act-event" wire:model.live="eventFilter" class="lp-input"
                        data-testid="event-filter">
                    <option value="">Any event</option>
                    @foreach ($eventOptions as $opt)
                        <option value="{{ $opt }}">{{ $opt }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="lp-form-label" for="act-subject">Subject type</label>
                <input id="act-subject" type="text"
                       wire:model.live.debounce.300ms="subjectFilter"
                       class="lp-input" placeholder="e.g. Tenant, Operator"
                       data-testid="subject-filter">
            </div>
            <div>
                <label class="lp-form-label" for="act-from">From</label>
                <input id="act-from" type="date"
                       wire:model.live="fromDate"
                       class="lp-input" data-testid="from-date">
            </div>
            <div>
                <label class="lp-form-label" for="act-to">To</label>
                <input id="act-to" type="date"
                       wire:model.live="toDate"
                       class="lp-input" data-testid="to-date">
            </div>
        </form>
    </section>

    @if ($rows->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="empty" class="text-[18px]"
               style="color: var(--color-ink); font-weight: 500;">
                No activity matches the filter.
            </p>
            <p class="mt-2 text-[13px]" style="color: var(--color-ink-soft);">
                Try widening the date range or clearing the filters.
            </p>
        </section>
    @else
        <section class="lp-dash-card" style="padding: 0;">
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="activity-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Log</th>
                            <th>Event</th>
                            <th>Causer</th>
                            <th>Subject</th>
                            <th>Tenant</th>
                            <th>Description</th>
                            <th style="text-align: right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $causer = $row->causer;
                                $subject = $row->subject;
                                $causerLabel = match (true) {
                                    $causer instanceof \App\Models\Admin    => $causer->email,
                                    $causer instanceof \App\Models\Operator => $causer->email,
                                    $causer !== null                        => class_basename($causer).' #'.$causer->getKey(),
                                    default                                 => 'system',
                                };
                                $subjectShort = $row->subject_type
                                    ? class_basename($row->subject_type).' #'.$row->subject_id
                                    : '-';
                                $eventClass = match ($row->event) {
                                    'created'                      => 'lp-tag--confirmed',
                                    'updated', 'extended'          => 'lp-tag--checked-in',
                                    'deleted', 'deactivated'       => 'lp-tag--checked-out',
                                    default                        => 'lp-tag--checked-in',
                                };
                                $properties = $row->properties;
                                $hasProperties = $properties !== null && $properties->isNotEmpty();
                                $expanded = $expandedId === $row->id;
                            @endphp
                            <tr data-testid="activity-row-{{ $row->id }}">
                                <td class="lp-mono"
                                    style="color: var(--color-ink-soft); font-size: 12px; white-space: nowrap;">
                                    {{ $row->created_at?->format('d M Y · g:i A') }}
                                </td>
                                <td>
                                    <span class="lp-mono"
                                          style="color: var(--color-ink-soft); font-size: 11.5px;">
                                        {{ $row->log_name ?? '-' }}
                                    </span>
                                </td>
                                <td>
                                    @if ($row->event)
                                        <span class="lp-tag {{ $eventClass }}">{{ $row->event }}</span>
                                    @else
                                        <span style="color: var(--color-ink-mute);">-</span>
                                    @endif
                                </td>
                                <td style="color: var(--color-ink-soft); font-size: 12.5px;">
                                    {{ $causerLabel }}
                                </td>
                                <td class="lp-mono"
                                    style="color: var(--color-ink-soft); font-size: 11.5px;">
                                    {{ $subjectShort }}
                                </td>
                                <td class="lp-mono"
                                    style="color: var(--color-ink-mute); font-size: 11.5px;">
                                    {{ $row->tenant_id ? '#'.$row->tenant_id : '-' }}
                                </td>
                                <td style="color: var(--color-ink); font-size: 13px;">
                                    {{ $row->description }}
                                </td>
                                <td style="text-align: right;">
                                    @if ($hasProperties)
                                        <button type="button"
                                                wire:click="toggleExpanded({{ $row->id }})"
                                                data-testid="expand-{{ $row->id }}"
                                                style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px; font-size: 12px; background: none; border: 0; cursor: pointer;">
                                            {{ $expanded ? 'Hide' : 'Details' }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                            @if ($expanded && $hasProperties)
                                <tr data-testid="activity-detail-{{ $row->id }}">
                                    <td colspan="8" style="background: var(--color-card-deep);">
                                        <pre class="lp-mono"
                                             style="margin: 0; padding: 14px 18px;
                                                    font-size: 11.5px; color: var(--color-ink);
                                                    white-space: pre-wrap; word-break: break-word;">{{ json_encode($properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4">{{ $rows->links() }}</div>
        </section>
    @endif
</div>
